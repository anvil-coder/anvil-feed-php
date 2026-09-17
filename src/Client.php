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

    public static function create(
        Config $config,
        ?Transport $transport = null,
        ?Logger $logger = null,
        ?GitContext $git = null,
    ): self {
        $logger ??= new Logger();
        $git ??= GitContext::discover(getcwd() ?: __DIR__);
        $scrubber = new Scrubber($config->denylist);
        $queue = new Queue($config->maxQueueSize, $logger);

        return new self(
            $config,
            new EventFactory($config, $scrubber, $git),
            $queue,
            new Sender($config, $queue, $transport ?? new DirectTransport($config), $logger),
            new RateGate($config->fingerprintCap, $config->fingerprintWindowMs),
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
        return $this->capture(fn (): Event => $this->factory->fromMessage($message, $level, $context), $context);
    }

    /** FR-8 a: the explicit verification call an integration guide ends with. */
    public function captureTestEvent(): ?string
    {
        return $this->capture(fn (): Event => $this->factory->testEvent(), []);
    }

    /**
     * The hook of FR-5 and the escape hatch for anything this SDK cannot know about. It receives the fully
     * built, already scrubbed payload and returns a payload or null to drop the event.
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
                $this->sayTheStormOnce($fingerprint);

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
        if ($modified === null) {
            return null;   // the documented way to drop an event
        }
        if (!is_array($modified) || $modified === []) {
            return null;
        }

        return Event::inCanonicalOrder($modified);
    }

    /** One log line per window per fingerprint — the storm itself must not become the log flood. */
    private function sayTheStormOnce(string $fingerprint): void
    {
        if ($this->rateGate->suppressed($fingerprint) !== 1) {
            return;
        }
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
