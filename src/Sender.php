<?php

declare(strict_types=1);

namespace AnvilFeed;

use AnvilFeed\Transport\Response;
use AnvilFeed\Transport\Transport;

/**
 * The retry rules of FR-4, read off the published ingest contract (docs/anvil-feed-ingest.md and
 * AnvilFeedIngestController) rather than off the specification:
 *
 *   202  every event carries a verdict — accepted or rejected — and both are final. Clear them.
 *   400  the sender is wrong. Drop the batch, say so ONCE, keep sending later events. Never retried.
 *   401  the credential is gone. Stop sending for the process lifetime, log ONCE, and drain the queue:
 *        a stopped sender that keeps queueing is a memory leak with a nice name.
 *   403  an Origin header reached the ingest with a server token. We never set one, so something in the
 *        path did. Drop, say so once, keep going — retrying cannot change it.
 *   413  the body was too large. Halve the batch and try the halves; a single event over the limit is
 *        dropped, because there is nothing left to split.
 *   429  pause for exactly Retry-After. Keep the events. Never drop for it.
 *   503  repeat the WHOLE batch after Retry-After. The delivery key is (organisation, event_id), so a
 *        repeat of events that already landed is counted once.
 *   5xx / timeout / DNS  exponential backoff with jitter, capped; the events stay in the queue.
 *
 * Nothing here throws. Every public method is total (FR-2 b): a transport that throws in breach of its own
 * contract, a payload that will not encode, a logger that explodes — each is absorbed, and the worst that
 * happens to the host application is a line in its log.
 */
final class Sender
{
    private const BACKOFF_BASE_MS = 1000;
    private const BACKOFF_CAP_MS = 30000;
    /** Under the ingest's 262144 so that a 413 is a bug in this class, not a routine answer. */
    private const MAX_BODY_BYTES = 250000;
    /** docs/anvil-feed-ingest.md sends `Retry-After: 60` with every 429. This is what we use when it is missing. */
    private const DEFAULT_RATE_LIMIT_PAUSE_SECONDS = 60;
    /** …and `Retry-After: 30` with every 503. */
    private const DEFAULT_TRANSIENT_PAUSE_SECONDS = 30;

    /**
     * "Once per process" (ruling P3) lives HERE and not in the Logger: Logger::once() is per instance, so
     * a host that news up a second Logger — a Laravel provider binding one per service is the obvious way
     * — would get every "said once" line twice, and the symptom is a repeated paragraph in a log nobody
     * files a ticket about. A Sender is one per process by design, so this flag is the process flag.
     *
     * @var array<string, true>
     */
    private array $said = [];

    /**
     * Events the ingest answered 202 for without naming them in `accepted` or `rejected`. They get one
     * more attempt and are then dropped — see applyVerdicts().
     *
     * @var array<string, true>
     */
    private array $awaitingSecondTry = [];

    private float $pausedUntil = 0.0;
    private int $consecutiveFailures = 0;
    private bool $stopped = false;
    /** The bound of the flush currently running; INF outside one. */
    private float $deadline = INF;
    /** @var \Closure(): float */
    private \Closure $clock;

    public function __construct(
        private readonly Config $config,
        private readonly Queue $queue,
        private readonly Transport $transport,
        private readonly Logger $logger,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): float => microtime(true);
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function pausedUntil(): float
    {
        return $this->pausedUntil;
    }

    /**
     * Drains the queue. FR-2 c: the whole call is bounded by a hard deadline — by default
     * request_timeout_ms from now, 2 s at the defaults — so that a shutdown hook can never hold a
     * response open. Whatever the deadline cuts short stays in the queue for the next flush.
     */
    public function flush(?float $deadline = null): void
    {
        if (!$this->config->enabled) {
            return;
        }
        $now = ($this->clock)();
        $this->deadline = $deadline ?? ($now + ($this->config->requestTimeoutMs / 1000));

        try {
            if ($this->stopped) {
                $this->queue->clear();

                return;
            }
            if ($now < $this->pausedUntil) {
                return;
            }

            while ($this->queue->count() > 0 && ($this->clock)() < $this->deadline && !$this->stopped) {
                $batch = $this->queue->take($this->config->batchSize);
                if ($batch === []) {
                    return;
                }
                if (!$this->send($batch)) {
                    return;   // paused or stopped; whatever had to be kept is back in the queue
                }
            }
        } catch (\Throwable $neverTheHostsProblem) {
            // The last line of FR-2 b. Everything below is already defensive; this is what makes the
            // promise true even for the case nobody thought of.
            $this->sayOnce('flush-defect', 'anvil-feed: internal error while sending — '
                . $neverTheHostsProblem->getMessage() . '. Events are dropped rather than raised.');
        } finally {
            $this->deadline = INF;
        }
    }

    /**
     * @param list<Event> $batch
     * @return bool true when the sender may continue with the next batch
     */
    private function send(array $batch): bool
    {
        if (($this->clock)() >= $this->deadline) {
            $this->queue->unshiftAll($batch);

            return false;
        }

        [$body, $batch] = $this->encode($batch);
        if ($batch === []) {
            return true;
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->splitAndSend($batch);
        }

        try {
            $response = $this->transport->post(
                count($batch) === 1 ? $this->config->eventsUrl() : $this->config->batchUrl(),
                $body,
                $this->remainingBudgetMs()
            );
        } catch (\Throwable $never) {
            // A Transport is contractually not allowed to throw. If one does, it is treated as a
            // transport failure rather than being allowed to reach the host application (FR-2 b).
            $response = Response::failure($never->getMessage());
        }

        return $this->apply($batch, $response);
    }

    /**
     * What is left of the flush deadline, in milliseconds. FR-2 (c)'s 2 s is a bound on the CALL, so it
     * has to reach the socket: a deadline that only decides whether the next request may start still
     * lets a flush run to deadline + request_timeout_ms.
     */
    private function remainingBudgetMs(): int
    {
        if (!is_finite($this->deadline)) {
            return $this->config->requestTimeoutMs;
        }

        return max(1, (int) round(($this->deadline - ($this->clock)()) * 1000));
    }

    /**
     * Serialises the batch, dropping the events that cannot be encoded. Event::canonicalJson() throws on
     * a payload with, say, invalid UTF-8 in a customer's exception message — and one such event must not
     * cost the forty-nine beside it, nor sit at the head of the queue for ever.
     *
     * @param list<Event> $batch
     * @return array{0: string, 1: list<Event>}
     */
    private function encode(array $batch): array
    {
        $parts = [];
        $encodable = [];
        foreach ($batch as $event) {
            try {
                $parts[] = $event->canonicalJson();
                $encodable[] = $event;
            } catch (\Throwable $unencodable) {
                $this->logger->warning(sprintf(
                    'anvil-feed: dropping event %s — it cannot be encoded as JSON (%s).',
                    $event->id,
                    $unencodable->getMessage()
                ));
            }
        }
        if ($parts === []) {
            return ['', []];
        }

        return [count($parts) === 1 ? $parts[0] : '[' . implode(',', $parts) . ']', $encodable];
    }

    /**
     * The ingest refused the body for its size. Said once — the halves that follow will say it again
     * otherwise — and then split: the reason code is what tells an operator this is a size problem and
     * not the schema violation a 400 would be.
     *
     * @param list<Event> $batch
     */
    private function tooLarge(array $batch, Response $response): bool
    {
        $this->consecutiveFailures = 0;
        $this->sayOnce('status:413', sprintf(
            'anvil-feed: the ingest answered 413 (%s): %s Halving the batch and trying the halves.',
            self::firstReasonCode($response),
            self::firstMessage($response)
        ));

        return $this->splitAndSend($batch);
    }

    /**
     * Halves the batch. The second half goes back to the FRONT of the queue before the first is attempted,
     * so that a pause on the first half cannot leave the second one on the floor — and so that the queue
     * order is the original one either way.
     *
     * @param list<Event> $batch
     */
    private function splitAndSend(array $batch): bool
    {
        if (count($batch) === 1) {
            $this->logger->warning(sprintf(
                'anvil-feed: dropping event %s — on its own it is larger than the ingest accepts.',
                $batch[0]->id
            ));

            return true;
        }
        $half = intdiv(count($batch), 2);
        $this->queue->unshiftAll(array_slice($batch, $half));

        return $this->send(array_slice($batch, 0, $half));
    }

    /** @param list<Event> $batch */
    private function apply(array $batch, Response $response): bool
    {
        $now = ($this->clock)();

        if ($response->transportFailure) {
            return $this->backOff($batch, $now,
                'transport failure: ' . Response::redact($response->failureReason));
        }

        return match (true) {
            $response->status === 202 => $this->applyVerdicts($batch, $response),
            $response->status === 400 => $this->drop($batch, 400, 'the ingest answered 400 ('
                . self::firstReasonCode($response) . '): it refused the whole request — '
                . self::firstMessage($response) . '. This is a defect in the SDK or a batch above 50; '
                . 'retrying cannot change it.'),
            $response->status === 401 => $this->stop('the ingest answered 401 — the token is unknown, '
                . 'revoked or wrong. Sending is switched off for this process.'),
            $response->status === 403 => $this->drop($batch, 403, 'the ingest answered 403. A server token '
                . 'presented with an Origin header is treated as leaked; this SDK sets none, so something '
                . 'in the request path did. Rotate the token.'),
            $response->status === 413 => $this->tooLarge($batch, $response),
            $response->status === 429 => $this->pause($batch, $now,
                $response->retryAfterSeconds ?? self::DEFAULT_RATE_LIMIT_PAUSE_SECONDS, 'rate limited'),
            $response->status === 503 => $this->pause($batch, $now,
                $response->retryAfterSeconds ?? self::DEFAULT_TRANSIENT_PAUSE_SECONDS,
                'transient store failure; the whole batch is repeated'),
            $response->status >= 500 => $this->backOff($batch, $now, 'the ingest answered '
                . $response->status),
            $response->status >= 300 && $response->status < 400 => $this->redirected($batch, $response),
            default => $this->drop($batch, $response->status,
                'the ingest answered an unexpected ' . $response->status),
        };
    }

    /**
     * A 202 is final for every event the answer NAMES — accepted and rejected alike
     * (responses/index.json: "a verdict, even a refusing one, still clears that event from the buffer").
     *
     * An event the answer does not name is a different case. The ingest promises exactly one verdict per
     * event, so this cannot happen against a healthy server — but a truncated body, a proxy that rewrote
     * the response, or an `accepted` list re-spelled in lower case all produce it, and clearing the whole
     * batch on the strength of the status code alone loses those events with no line anywhere. They get
     * ONE more attempt — (organisation, event_id) is the delivery key, so a repeat of something that did
     * land is counted once — and are then dropped, by name, because the alternative is a queue that never
     * empties.
     *
     * @param list<Event> $batch
     */
    private function applyVerdicts(array $batch, Response $response): bool
    {
        $this->consecutiveFailures = 0;
        $verdicts = $this->readVerdicts($response);

        $requeue = [];
        foreach ($batch as $event) {
            if (isset($verdicts[$event->id])) {
                unset($this->awaitingSecondTry[$event->id]);
                continue;
            }
            if (isset($this->awaitingSecondTry[$event->id])) {
                unset($this->awaitingSecondTry[$event->id]);
                $this->logger->warning(sprintf(
                    'anvil-feed: dropping event %s — the ingest answered 202 twice without naming it in '
                    . 'accepted or rejected. Something between this process and the ingest is rewriting '
                    . 'the answer.',
                    $event->id
                ));
                continue;
            }
            $this->awaitingSecondTry[$event->id] = true;
            $requeue[] = $event;
        }

        if ($requeue !== []) {
            $this->sayOnce('unverdicted', sprintf(
                'anvil-feed: the ingest answered 202 without a verdict for %d event(s) of the batch; they '
                . 'are sent once more and then dropped. Every event is supposed to be named in accepted '
                . 'or in rejected.',
                count($requeue)
            ));
            $this->queue->unshiftAll($requeue);
        }

        return true;
    }

    /**
     * The ids the answer names, spelled exactly as the ingest returned them — which is exactly as the
     * sender posted them (AnvilFeedIngestController keeps the sender's spelling on purpose). Logs each
     * rejection on the way past.
     *
     * @return array<string, true>
     */
    private function readVerdicts(Response $response): array
    {
        $answer = json_decode($response->body, true);
        if (!is_array($answer)) {
            return [];
        }

        $verdicts = [];
        foreach (is_array($answer['accepted'] ?? null) ? $answer['accepted'] : [] as $id) {
            if (is_string($id)) {
                $verdicts[$id] = true;
            }
        }
        foreach (is_array($answer['rejected'] ?? null) ? $answer['rejected'] : [] as $rejection) {
            if (!is_array($rejection)) {
                continue;
            }
            if (is_string($rejection['event_id'] ?? null)) {
                $verdicts[$rejection['event_id']] = true;
            }
            $code = (string) ($rejection['reason_code'] ?? 'unknown');
            $message = sprintf(
                'anvil-feed: event %s rejected (%s): %s',
                (string) ($rejection['event_id'] ?? 'unknown'),
                $code,
                (string) ($rejection['message'] ?? '')
            );
            // Two of the seven reason codes are the operator's problem, not the developer's, and they will
            // otherwise repeat on every single event until somebody notices.
            in_array($code, ['no_binding', 'binding_disabled'], true)
                ? $this->sayOnce('reason:' . $code, $message
                    . ' — connect the environment in the Anvil cockpit.')
                : $this->logger->warning($message);
        }

        return $verdicts;
    }

    /** @param list<Event> $batch */
    private function pause(array $batch, float $now, int $seconds, string $why): bool
    {
        $seconds = max(0, $seconds);
        // A 429 or a 503 is a healthy answer from a reachable ingest: the connection works and the server
        // is talking to us. Carrying a transport-failure streak across it would resume at the escalated
        // delay after a pause that proved the opposite.
        $this->consecutiveFailures = 0;
        $this->queue->unshiftAll($batch);
        $this->pausedUntil = $now + $seconds;
        $this->logger->warning(sprintf('anvil-feed: paused for %ds (%s).', $seconds, $why));

        return false;
    }

    /** @param list<Event> $batch */
    private function backOff(array $batch, float $now, string $why): bool
    {
        $this->queue->unshiftAll($batch);
        $this->consecutiveFailures++;
        $delayMs = min(self::BACKOFF_CAP_MS, self::BACKOFF_BASE_MS * (2 ** ($this->consecutiveFailures - 1)));
        // Jitter is not optional (X13): without it every pod of a deployment retries in the same second.
        $jittered = $delayMs * (0.8 + (random_int(0, 400) / 1000));
        $this->pausedUntil = $now + ($jittered / 1000);
        $this->logger->warning(sprintf(
            'anvil-feed: %s; retrying in %dms (attempt %d).',
            $why,
            (int) $jittered,
            $this->consecutiveFailures
        ));

        return false;
    }

    /**
     * A 4xx the ingest will answer the same way for ever. The events go; the explanation is said ONCE per
     * process and per status, because the alternative is one identical paragraph per batch in a log
     * somebody is reading to find something else.
     *
     * @param list<Event> $batch
     */
    private function drop(array $batch, int $status, string $why): bool
    {
        $this->consecutiveFailures = 0;
        $this->sayOnce('status:' . $status, sprintf(
            'anvil-feed: dropped %d event(s) — %s Further events refused this way are dropped silently.',
            count($batch),
            $why
        ));

        return true;
    }

    /**
     * A redirect is never followed, on any path (see DirectTransport). Two reasons, either of which is
     * enough: following it presents the `anv_srv_` token to a host the customer never configured, and the
     * redirect degrades POST to GET, so the events would not even be in the second request — and the
     * redirect target's 2xx would be read as a delivery.
     *
     * @param list<Event> $batch
     */
    private function redirected(array $batch, Response $response): bool
    {
        $this->consecutiveFailures = 0;
        $this->sayOnce('redirect', sprintf(
            'anvil-feed: %s answered %d%s and this SDK never follows a redirect — the ingest token is '
            . 'presented to the configured endpoint and to nothing else. Configure "endpoint" as the '
            . 'address the ingest actually answers on. %d event(s) dropped; further ones are dropped '
            . 'silently.',
            $this->config->endpoint,
            $response->status,
            $response->location === null ? '' : ' (Location: ' . $response->location . ')',
            count($batch)
        ));

        return true;
    }

    private function stop(string $why): bool
    {
        $this->stopped = true;
        $this->queue->clear();
        $this->sayOnce('stopped', 'anvil-feed: ' . $why);

        return false;
    }

    /** Ruling P3's "once per process". See $said. */
    private function sayOnce(string $key, string $message): void
    {
        if (isset($this->said[$key])) {
            return;
        }
        $this->said[$key] = true;
        $this->logger->warning($message);
    }

    private static function firstMessage(Response $response): string
    {
        return self::firstRejection($response, 'message', '');
    }

    /**
     * The reason code of a refusal is the one field a reader can act on: `schema_violation` is a defect
     * here, `payload_too_large` is a batch size, and the two are told apart by nothing else in the body.
     */
    private static function firstReasonCode(Response $response): string
    {
        return self::firstRejection($response, 'reason_code', 'no reason code');
    }

    private static function firstRejection(Response $response, string $field, string $fallback): string
    {
        $answer = json_decode($response->body, true);
        if (!is_array($answer) || !is_array($answer['rejected'][0] ?? null)) {
            return $fallback;
        }

        return (string) ($answer['rejected'][0][$field] ?? $fallback);
    }
}
