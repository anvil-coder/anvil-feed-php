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

    /**
     * sdk.version is what tells support which SDK a customer is running. Spelled twice, it drifts on the
     * first tag and the answer support reads is the old one; this is the test that reddens on a release
     * bump that forgets the constant.
     */
    public function testTheSdkVersionHasExactlyOneSource(): void
    {
        $payload = $this->factory()->fromThrowable(new \RuntimeException('boom'))->payload;

        self::assertSame(EventFactory::SDK_VERSION, $payload['sdk']['version']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', EventFactory::SDK_VERSION);
        self::assertSame('anvil-feed-php', $payload['sdk']['name']);
        self::assertSame('server', $payload['sdk']['runtime']);
    }

    /**
     * in_app is what IncidentFingerprint.topFrames partitions on, so a vendor frame that claims to be
     * application code changes the fingerprint — and a Windows developer machine would then open its own
     * incident for a fault production already has.
     */
    public function testVendorFramesAreNotInAppOnEitherPathSeparator(): void
    {
        self::assertFalse(EventFactory::isInApp('/app/vendor/anvil-coder/anvil-feed-php/src/Client.php'));
        self::assertFalse(EventFactory::isInApp('C:\\proj\\vendor\\anvil-coder\\src\\Client.php'));
        self::assertFalse(EventFactory::isInApp(''));

        self::assertTrue(EventFactory::isInApp('/app/src/CheckoutService.php'));
        self::assertTrue(EventFactory::isInApp('C:\\proj\\src\\CheckoutService.php'));
        // "vendorish" is application code; the rule is a path segment, not a substring.
        self::assertTrue(EventFactory::isInApp('/app/vendorish/Checkout.php'));
    }

    /** The same rule, reached through a real trace: PHPUnit's own frames live under vendor/. */
    public function testARealVendorFrameIsMarkedNotInApp(): void
    {
        $frames = $this->factory()->fromThrowable(new \RuntimeException('boom'))->payload['exception']['stacktrace'];
        $vendor = array_values(array_filter(
            $frames,
            static fn (array $f) => str_contains((string) $f['file'], '/vendor/')
        ));

        self::assertNotEmpty($vendor, 'this test only means something if PHPUnit frames are in the trace');
        foreach ($vendor as $frame) {
            self::assertFalse($frame['in_app'], $frame['file'] . ' is vendor code');
        }
        self::assertTrue($frames[0]['in_app'], 'the throw site is this test file, which is app code');
    }

    /** Finding 1, at the factory: the blocks the contract types as objects are dropped, never emptied. */
    public function testBlocksThatEndUpEmptyAreDroppedRatherThanSentAsAnEmptyArray(): void
    {
        $payload = $this->factory()->fromThrowable(new \RuntimeException('boom'), 'error', [
            'request' => ['url' => 'file:///etc/passwd'],
            'tags' => ['user.ip_hash' => 'deadbeef'],
        ])->payload;

        self::assertArrayNotHasKey('request', $payload);
        self::assertArrayNotHasKey('tags', $payload);
        self::assertStringNotContainsString('"request":[]', $payload === [] ? '' : json_encode($payload));
        self::assertStringNotContainsString('"tags":[]', json_encode($payload));
    }

    /** Z1: the canonical JSON is what goes on the wire, and nothing may re-order it. */
    public function testCanonicalJsonStartsWithTheSchemaVersion(): void
    {
        $json = $this->factory()->fromThrowable(new \RuntimeException('boom'))->canonicalJson();

        self::assertStringStartsWith('{"schema_version":"1.0","event_id":"', $json);
    }

    /**
     * `finalise()` is public precisely so that a `beforeSend` hook's output takes the same pipeline as the
     * payload this factory built, and its docblock promises the second pass "cannot double-filter". It could:
     * the scrubber's already-filtered guard let a quoted marker through, so every quoted secret in the
     * message, in a breadcrumb and in every tag grew by two characters on the second pass — after the caps of
     * the first pass and before those of the second, so only the second bound kept the event legal at all.
     */
    public function testFinalisingAnAlreadyFinalisedPayloadChangesNothing(): void
    {
        $factory = $this->factory(['context_lines' => 0]);
        $payload = $factory->fromThrowable(
            new \RuntimeException('cfg token="abc123" and $api_key = \'sk-live-99887766\'; failed'),
            'error',
            [
                'tags' => ['note' => 'password="hunter2"', 'tenant' => 'kunde-x'],
                'breadcrumbs' => ['GET /callback?token=abc123', "config: secret='shh' loaded"],
            ]
        )->payload;

        self::assertStringContainsString('token="[Filtered]"', $payload['exception']['message'],
            'the first pass must have scrubbed the quoted value, or this guard proves nothing');
        self::assertSame($payload, $factory->finalise($payload),
            'a second pass over an already finalised payload changed it');
    }
}
