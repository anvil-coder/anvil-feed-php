<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\Config;
use AnvilFeed\EventFactory;
use AnvilFeed\GitContext;
use AnvilFeed\Scrubber;
use PHPUnit\Framework\TestCase;

final class EventFactoryTest extends TestCase
{
    private function factory(array $options = []): EventFactory
    {
        $config = Config::fromArray(array_merge([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
            'environment' => 'production',
        ], $options));

        return new EventFactory($config, new Scrubber(Config::DEFAULT_DENYLIST), GitContext::none());
    }

    public function testAMessageEventStillCarriesAnException(): void
    {
        $payload = $this->factory()->fromMessage('Reconciliation skipped', 'warning')->payload;

        self::assertSame(EventFactory::MESSAGE_TYPE, $payload['exception']['type']);
        self::assertSame('Reconciliation skipped', $payload['exception']['message']);
        self::assertNotEmpty($payload['exception']['stacktrace']);
    }

    public function testTheTestEventIsAcceptableToTheIngest(): void
    {
        $payload = $this->factory()->testEvent()->payload;

        self::assertSame('Anvil Feed test event', $payload['title']);
        self::assertSame(EventFactory::TEST_TYPE, $payload['exception']['type']);
    }

    public function testKeysAreInCanonicalOrder(): void
    {
        $payload = $this->factory()->fromThrowable(new \RuntimeException('boom'))->payload;

        self::assertSame(
            ['schema_version', 'event_id', 'timestamp', 'title', 'level', 'environment', 'service',
                'language', 'sdk', 'exception'],
            array_slice(array_keys($payload), 0, 10)
        );
    }

    public function testEventIdIsACanonicalUuid(): void
    {
        $id = $this->factory()->fromThrowable(new \RuntimeException('boom'))->id;

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
    }

    public function testAnEnvironmentTheIngestWouldRefuseIsRepairedRatherThanSent(): void
    {
        $payload = $this->factory(['environment' => 'Prod_EU'])->fromThrowable(new \RuntimeException('x'))->payload;

        self::assertSame('prod-eu', $payload['environment']);
        self::assertMatchesRegularExpression(Config::ENVIRONMENT_PATTERN, $payload['environment']);
    }

    public function testReleaseIsOmittedRatherThanGuessed(): void
    {
        $payload = $this->factory()->fromThrowable(new \RuntimeException('boom'))->payload;

        self::assertArrayNotHasKey('release', $payload);
    }

    /**
     * The event id is what the acknowledgement is matched against, so it has to be the id in the payload
     * as well — a resend keyed on anything else is deduplicated against nothing.
     */
    public function testTheIdIsThePayloadsEventId(): void
    {
        $event = $this->factory()->fromThrowable(new \RuntimeException('boom'));

        self::assertSame($event->id, $event->payload['event_id']);
    }

    /** A level the ingest has never heard of becomes "error" rather than an unreadable event. */
    public function testAnUnknownLevelFallsBackToError(): void
    {
        $payload = $this->factory()->fromThrowable(new \RuntimeException('boom'), 'critical')->payload;

        self::assertSame('error', $payload['level']);
    }

    /** MAX_BREADCRUMBS is 30 at the ingest and the tail is what explains the fault. */
    public function testOnlyTheLastThirtyBreadcrumbsSurvive(): void
    {
        $crumbs = array_map(static fn (int $i) => 'step-' . $i, range(1, 40));
        $payload = $this->factory()
            ->fromThrowable(new \RuntimeException('boom'), 'error', ['breadcrumbs' => $crumbs])
            ->payload;

        self::assertCount(30, $payload['breadcrumbs']);
        self::assertSame('step-11', $payload['breadcrumbs'][0]);
        self::assertSame('step-40', $payload['breadcrumbs'][29]);
    }

    /** Context: the request URL is scrubbed parameter by parameter, and only the denied one. */
    public function testTheRequestUrlIsScrubbedButKeepsItsShape(): void
    {
        $payload = $this->factory()->fromThrowable(new \RuntimeException('boom'), 'error', [
            'request' => ['method' => 'POST', 'url' => 'https://app.example/reset?token=abc123&user=7',
                'status_code' => 401],
            'user_id' => 'u-4711',
        ])->payload;

        self::assertSame(
            ['method' => 'POST', 'url' => 'https://app.example/reset?token=[Filtered]&user=7',
                'status_code' => 401],
            $payload['request']
        );
        self::assertSame(['id' => 'u-4711'], $payload['user']);
    }

    /** Z1: the canonical JSON is what goes on the wire, and nothing may re-order it. */
    public function testCanonicalJsonStartsWithTheSchemaVersion(): void
    {
        $json = $this->factory()->fromThrowable(new \RuntimeException('boom'))->canonicalJson();

        self::assertStringStartsWith('{"schema_version":"1.0","event_id":"', $json);
    }
}
