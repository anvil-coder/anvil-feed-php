<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Conformance;

use AnvilFeed\Config;
use AnvilFeed\Event;
use AnvilFeed\Logger;
use AnvilFeed\Queue;
use AnvilFeed\Sender;
use AnvilFeed\Tests\Support\RecordingLogger;
use AnvilFeed\Tests\Support\RecordingTransport;
use AnvilFeed\Transport\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `conformance/README.md`: "Feed the `body` of the matching entry in `responses/index.json` to the SDK's
 * response parser and assert the resulting decision (drop, retry, pause, stop) against that entry's
 * `status` and `retry_after` — the actual HTTP status and header the SDK's retry rule keys on, not the
 * file name."
 *
 * This is that gate, and it is driven by the index rather than by a transcription of it: a response the
 * server learns to give is added in one place, and this suite starts exercising it. The retry table of
 * `docs/anvil-feed-ingest.md` is the only reason any of these decisions are what they are.
 */
final class ResponseDecisionTest extends TestCase
{
    private const RESPONSES = __DIR__ . '/../../../../conformance/responses';

    private float $now = 1_000_000.0;

    /** @return array<string, array{array<string, mixed>}> */
    public static function fixtures(): array
    {
        $index = json_decode((string) file_get_contents(self::RESPONSES . '/index.json'), true);
        self::assertIsArray($index);

        $cases = [];
        foreach ($index as $entry) {
            self::assertIsArray($entry);
            $cases[(string) $entry['file']] = [$entry];
        }
        self::assertNotSame([], $cases);

        return $cases;
    }

    public function testTheIndexNamesEveryResponseFixture(): void
    {
        $indexed = array_keys(self::fixtures());
        $onDisk = array_values(array_filter(
            array_map('basename', (array) glob(self::RESPONSES . '/*.json')),
            static fn (string $name): bool => $name !== 'index.json'
        ));
        sort($indexed);
        sort($onDisk);

        self::assertSame($onDisk, $indexed,
            'a response fixture the index does not name is a decision no SDK test makes');
    }

    /** @param array<string, mixed> $entry */
    #[DataProvider('fixtures')]
    public function testTheSenderMakesTheDecisionTheIndexNames(array $entry): void
    {
        $written = new RecordingLogger();
        $logger = new Logger($written);
        $queue = new Queue(1000, $logger);
        $queue->push($this->event('f4b1a0c2-0f4a-4a1e-9d7a-2b6c5d8e1f30'));
        $queue->push($this->event('9b1c7f2e-5a34-4c18-8e0d-1f7a2b3c4d5e'));

        $status = (int) $entry['status'];
        $retryAfter = $entry['retry_after'] === null ? null : (int) $entry['retry_after'];
        $body = (string) file_get_contents(self::RESPONSES . '/' . $entry['file']);
        // Everything after the first answer is a plain 202, so that a decision of "split and try the
        // halves" can finish and be seen as what it is rather than as an endless loop.
        $transport = new RecordingTransport(new Response($status, $body, $retryAfter));

        $sender = new Sender($this->config(), $queue, $transport, $logger, fn (): float => $this->now);
        $sender->flush();

        self::assertFalse($sender->isStopped(),
            'none of these responses is a credential failure; only a 401 stops the process');

        switch ($entry['retry']) {
            case 'none':
                self::assertSame(0, $queue->count(), $entry['file']
                    . ': the verdict is final — nothing of this batch may be sent again');
                self::assertLessThanOrEqual($this->now, $sender->pausedUntil(),
                    $entry['file'] . ': a final verdict is not a reason to stop sending anything else');
                break;

            case 'after-delay':
                self::assertSame(2, $queue->count(), $entry['file']
                    . ': a rate limit keeps the events — it is the one status that must never drop one');
                self::assertSame($this->now + (float) $retryAfter, $sender->pausedUntil(),
                    $entry['file'] . ': the pause is exactly Retry-After, not a backoff of our own');
                break;

            case 'whole-batch':
                self::assertSame(2, $queue->count(), $entry['file']
                    . ': the WHOLE batch is repeated — (organisation, event_id) makes that idempotent');
                self::assertSame($this->now + (float) $retryAfter, $sender->pausedUntil(),
                    $entry['file'] . ': the pause is exactly Retry-After');
                break;

            default:
                self::fail('unknown retry verdict ' . var_export($entry['retry'], true));
        }

        foreach ($this->reasonCodes($body) as $code) {
            self::assertNotSame([], $written->matching($code), $entry['file']
                . ": the reason code {$code} is the whole message; an SDK that swallows it leaves the "
                . 'customer with an empty inbox and no explanation');
        }
    }

    /** `no_binding` and `binding_disabled` are the operator's to fix, and are said once, not per event. */
    public function testAnOperatorSideReasonCodeIsSaidOnceAndNamesTheCockpit(): void
    {
        $written = new RecordingLogger();
        $logger = new Logger($written);
        $queue = new Queue(1000, $logger);
        $queue->push($this->event('f4b1a0c2-0f4a-4a1e-9d7a-2b6c5d8e1f30'));
        $body = (string) file_get_contents(self::RESPONSES . '/202-binding-disabled.json');
        $transport = new RecordingTransport(new Response(202, $body), new Response(202, $body));

        $sender = new Sender($this->config(), $queue, $transport, $logger, fn (): float => $this->now);
        $sender->flush();
        $queue->push($this->event('9b1c7f2e-5a34-4c18-8e0d-1f7a2b3c4d5e'));
        $sender->flush();

        self::assertCount(1, $written->matching('binding_disabled'));
        self::assertNotSame([], $written->matching('cockpit'));
    }

    private function config(): Config
    {
        return Config::fromArray([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
        ], new Logger());
    }

    private function event(string $id): Event
    {
        return new Event($id, ['event_id' => $id, 'service' => 'disponio-api']);
    }

    /** @return list<string> */
    private function reasonCodes(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !is_array($decoded['rejected'] ?? null)) {
            return [];
        }
        $codes = [];
        foreach ($decoded['rejected'] as $rejection) {
            if (is_array($rejection) && isset($rejection['reason_code'])) {
                $codes[] = (string) $rejection['reason_code'];
            }
        }

        return $codes;
    }
}
