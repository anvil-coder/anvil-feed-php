<?php

declare(strict_types=1);

namespace AnvilFeed;

use AnvilFeed\Transport\DirectTransport;
use AnvilFeed\Transport\Transport;

/**
 * The whole SDK from the caller's side. Every public method here is on the request path, so every one of
 * them does the same three things: check whether it is allowed to run at all, build and queue, and return.
 * Nothing sends. Sending happens in flush(), which the framework adapters call after the response has
 * gone out (Z4: no network call in the request path, p99 under one millisecond).
 *
 * FR-2 b is enforced at exactly one place — the try/catch around each capture. Nothing below is allowed to
 * reach the caller, including a failure of the logger and including a failure of the host's own
 * beforeSend hook.
 */
final class Client
{
    /** @var null|callable(array<string, mixed>): ?array<string, mixed> */
    private $beforeSend = null;
    private bool $shutdownRegistered = false;
    private bool $beforeSendFailureSaid = false;
    private bool $beforeSendReadOnlySaid = false;
    private bool $beforeSendUnsendableSaid = false;

    /** Top-level members a `beforeSend` hook may read but not move. See withReadOnlyFieldsRestored(). */
    private const READ_ONLY = ['schema_version', 'event_id', 'timestamp', 'sdk', 'service', 'environment',
        'title'];

    /** What schema 1.0 requires of every event, checked again after a hook has been over it. */
    private const REQUIRED = ['schema_version', 'event_id', 'timestamp', 'title', 'level', 'environment',
        'service', 'language'];

    public function __construct(
        private readonly Config $config,
        private readonly EventFactory $factory,
        private readonly Queue $queue,
        private readonly Sender $sender,
        private readonly RateGate $rateGate,
        private readonly LoopGuard $loopGuard,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param null|callable(): float $clock seconds; the gate and the sender share it. Injectable so a
     *        host's own test suite can drive a window without sleeping, and so a host with a monotonic
     *        clock of its own can hand it over.
     */
    public static function create(
        Config $config,
        ?Transport $transport = null,
        ?Logger $logger = null,
        ?GitContext $git = null,
        ?callable $clock = null,
    ): self {
        $logger ??= new Logger();
        $git ??= GitContext::discover(getcwd() ?: __DIR__);
        $scrubber = new Scrubber($config->denylist);
        $queue = new Queue($config->maxQueueSize, $logger);

        return new self(
            $config,
            new EventFactory($config, $scrubber, $git),
            $queue,
            new Sender($config, $queue, $transport ?? new DirectTransport($config), $logger, $clock),
            new RateGate($config->fingerprintCap, $config->fingerprintWindowMs, $clock),
            new LoopGuard($config->ignoredRequestPaths),
            $logger
        );
    }

    /** @param array<string, mixed> $context */
    public function captureException(\Throwable $error, array $context = []): ?string
    {
        $level = isset($context['level']) && is_string($context['level']) ? $context['level'] : 'error';

        return $this->capture(fn (): Event => $this->factory->fromThrowable($error, $level, $context), $context);
    }

    /**
     * There is no message-only event. The ingest requires exception.type, so EventFactory synthesises an
     * AnvilFeed.Message exception carrying the caller's own stack — which is also what gives this event a
     * fingerprint worth capping, since a log line repeated in a loop is exactly the storm FR-2 d is about.
     *
     * @param array<string, mixed> $context
     */
    public function captureMessage(string $message, string $level = 'warning', array $context = []): ?string
    {
        $caller = $this->callerTrace();

        return $this->capture(
            fn (): Event => $this->factory->fromMessage($message, $level, $context, $caller),
            $context
        );
    }

    /** FR-8 a: the explicit verification call an integration guide ends with. */
    public function captureTestEvent(): ?string
    {
        $caller = $this->callerTrace();

        return $this->capture(fn (): Event => $this->factory->testEvent($caller), []);
    }

    /**
     * Taken HERE, at the public entry point, and not inside the factory. A backtrace taken further down
     * carries however many SDK frames the call chain happens to have that week — a number that changes
     * whenever this class is refactored, and one no test of the factory alone would notice changing.
     *
     * @return list<array<string, mixed>>
     */
    private function callerTrace(): array
    {
        return array_values(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->config->maxFrames + 8));
    }

    /**
     * The hook of FR-5 and the escape hatch for anything this SDK cannot know about. It receives the fully
     * built, already scrubbed payload and returns a payload or null to drop the event.
     *
     * <p>What the hook cannot do, and what happens when it tries (task-3 review, findings 3 and 4):
     * <ul>
     *   <li>The identity fields — {@code event_id}, {@code timestamp}, {@code sdk}, {@code service},
     *       {@code environment} and the fingerprint inputs — are read-only: a changed value is restored to
     *       the original and logged once. A rewritten id would desync the server's delivery key.</li>
     *   <li>The returned payload goes through scrub → bounds → prune AGAIN before it is queued, so a hook
     *       cannot put PII or an over-cap value on the wire (FR-5 is SDK-side, the server is a backstop).</li>
     *   <li>A hook that THROWS costs the modification, not the event: the exception is logged once and the
     *       unmodified event is sent (FR-2 — host code cannot take the host down through us).</li>
     *   <li>A hook that RETURNS a payload the schema cannot accept (a required field removed, say) drops
     *       the event locally with one log line. That is deliberate and stricter than sending: the ingest
     *       would answer 400, which the retry rules never retry, so the event would be lost either way —
     *       this way the loss is named in the host's own log.</li>
     * </ul>
     *
     * @param null|callable(array<string, mixed>): ?array<string, mixed> $hook
     */
    public function beforeSend(?callable $hook): void
    {
        $this->beforeSend = $hook;
    }

    public function flush(?float $deadline = null): void
    {
        if (!$this->loopGuard->enterSend()) {
            return;
        }
        try {
            $this->sender->flush($deadline);
        } catch (\Throwable $ours) {
            $this->logger->warning('anvil-feed: flush failed: ' . $ours->getMessage());
        } finally {
            $this->loopGuard->leaveSend();
        }
    }

    /**
     * PHP has no background thread, so the flush rides the end of the process. In Laravel the service
     * provider also registers a terminating() callback, which runs after the response is on the wire;
     * this is the safety net for CLI, queue workers and every non-Laravel host.
     */
    public function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->flush();
        });
    }

    public function queue(): Queue
    {
        return $this->queue;
    }

    public function sender(): Sender
    {
        return $this->sender;
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * @param callable(): Event $build
     * @param array<string, mixed> $context
     */
    private function capture(callable $build, array $context): ?string
    {
        try {
            if (!$this->config->enabled || $this->sender->isStopped()) {
                return null;
            }
            if ($this->loopGuard->isSending()) {
                return null;   // the sender's own failure. Reporting it is the loop.
            }
            if ($this->loopGuard->ignoresPath($this->requestPath($context))) {
                return null;
            }

            $event = $build();
            $fingerprint = Fingerprint::ofEvent($event->payload);
            if (!$this->rateGate->allow($fingerprint)) {
                if ($this->rateGate->firstSuppression($fingerprint)) {
                    $this->sayTheStorm($fingerprint);
                }

                return null;
            }

            $payload = $this->throughBeforeSend($event->payload);
            if ($payload === null) {
                return null;
            }

            $this->queue->push(new Event($event->id, $payload));
            $this->registerShutdownFlush();

            return $event->id;
        } catch (\Throwable $ours) {
            // FR-2 b, and the last line of it: an error in the error handler must not recurse.
            try {
                $this->logger->warning('anvil-feed: capture failed: ' . $ours->getMessage());
            } catch (\Throwable) {
            }

            return null;
        }
    }

    /**
     * The host's hook is host code, and FR-2 says this SDK cannot take the host down. So an exception out
     * of it costs the modification, not the event: the original payload — the one this SDK built and
     * scrubbed itself — is sent, and the failure is said once rather than once per event, because a hook
     * that throws throws on every single event of the storm that follows.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null null means the hook dropped the event
     */
    private function throughBeforeSend(array $payload): ?array
    {
        if ($this->beforeSend === null) {
            return $payload;
        }
        try {
            $modified = ($this->beforeSend)($payload);
        } catch (\Throwable $theirs) {
            if (!$this->beforeSendFailureSaid) {
                $this->beforeSendFailureSaid = true;
                $this->logger->warning('anvil-feed: beforeSend threw ' . $theirs::class . ': '
                    . $theirs->getMessage() . '; the event is sent unmodified and this is said once.');
            }

            return $payload;
        }
        if ($modified === null || !is_array($modified) || $modified === []) {
            return null;   // null is the documented drop; anything unusable is treated as one
        }

        // The hook edits a VIEW, not the event. Everything below is put back, and then the whole thing
        // goes through the factory's own scrub → bound → prune pipeline a second time.
        $modified = $this->withReadOnlyFieldsRestored($modified, $payload);
        $modified = Event::inCanonicalOrder($this->factory->finalise($modified));

        return $this->isSendable($modified) ? $modified : null;
    }

    /**
     * The fields a hook may not move, and why each one is on the list.
     *
     * `event_id` keys the delivery: Sender matches the ingest's verdicts on the Event's id, and a body
     * naming a different one is retried once and then dropped with a log line blaming the network. It is
     * also the id captureTestEvent() hands back for the operator to search for.
     * `timestamp`, `sdk`, `service`, `environment` are the SDK's own statement about itself and about the
     * binding the ingest resolves — a hook rewriting `service` would post a customer's events into
     * another project's incident stream.
     * `title`, `exception.type` and `exception.stacktrace` are the FINGERPRINT inputs: the local cap has
     * already been charged against the identity computed from them, so a hook that changed them would
     * cap one group and send another.
     *
     * @param array<string, mixed> $modified
     * @param array<string, mixed> $original
     * @return array<string, mixed>
     */
    private function withReadOnlyFieldsRestored(array $modified, array $original): array
    {
        $refused = [];
        foreach (self::READ_ONLY as $key) {
            $ours = $original[$key] ?? null;
            if (($modified[$key] ?? null) !== $ours) {
                $refused[] = $key;
            }
            if ($ours === null) {
                unset($modified[$key]);
            } else {
                $modified[$key] = $ours;
            }
        }
        foreach (['type', 'stacktrace'] as $member) {
            $ours = $original['exception'][$member] ?? null;
            if ((($modified['exception'] ?? [])[$member] ?? null) !== $ours) {
                $refused[] = 'exception.' . $member;
            }
            if (!is_array($modified['exception'] ?? null)) {
                $modified['exception'] = $original['exception'] ?? [];
                break;
            }
            if ($ours === null) {
                unset($modified['exception'][$member]);
            } else {
                $modified['exception'][$member] = $ours;
            }
        }

        if ($refused !== [] && !$this->beforeSendReadOnlySaid) {
            $this->beforeSendReadOnlySaid = true;
            $this->logger->warning('anvil-feed: beforeSend changed ' . implode(', ', $refused)
                . ', which this SDK owns; the change was ignored and this is said once.');
        }

        return $modified;
    }

    /**
     * What the ingest requires, checked on the hook's output only. An event this SDK built correctly and
     * host code then broke must not be posted for the ingest to answer 400 schema_violation about, with
     * nothing in the log naming the cause.
     *
     * @param array<string, mixed> $payload
     */
    private function isSendable(array $payload): bool
    {
        $missing = [];
        foreach (self::REQUIRED as $key) {
            if (($payload[$key] ?? null) === null || $payload[$key] === '') {
                $missing[] = $key;
            }
        }
        if (($payload['exception']['type'] ?? null) === null) {
            $missing[] = 'exception.type';
        }
        if ($missing === []) {
            return true;
        }
        if (!$this->beforeSendUnsendableSaid) {
            $this->beforeSendUnsendableSaid = true;
            $this->logger->warning('anvil-feed: beforeSend returned an event without '
                . implode(', ', $missing) . '; the ingest would refuse it, so it was dropped here. '
                . 'This is said once.');
        }

        return false;
    }

    /**
     * One log line per window per fingerprint — the storm itself must not become the log flood. The
     * "once" is decided by `RateGate::firstSuppression()`, inside the gate: reading
     * `suppressed(...) !== 1` here was a SECOND question about a number the gate had already moved past,
     * and after `suppressed()` stopped falling back to the shared bucket it could not see an overflowed
     * storm at all. The gate now hands the edge back with the verdict and consumes it (the Java twin's
     * task-8 review finding 3).
     */
    private function sayTheStorm(string $fingerprint): void
    {
        $this->logger->warning(sprintf(
            'anvil-feed: more than %d occurrences of %s per %dms; suppressing locally. '
            . 'The ingest counts occurrences server-side, so nothing is lost that matters.',
            $this->config->fingerprintCap,
            substr($fingerprint, 0, 12),
            $this->config->fingerprintWindowMs
        ));
    }

    /**
     * What the host says it is serving. An explicit context wins over the superglobal, because a queue
     * worker and a CLI command inherit whatever REQUEST_URI the process happened to start with.
     *
     * @param array<string, mixed> $context
     */
    private function requestPath(array $context): ?string
    {
        $url = $context['request']['url'] ?? null;
        if (is_string($url) && $url !== '') {
            return $url;
        }
        $serverUri = $_SERVER['REQUEST_URI'] ?? null;

        return is_string($serverUri) && $serverUri !== '' ? $serverUri : null;
    }
}
