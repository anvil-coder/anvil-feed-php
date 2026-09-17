<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\Config;
use AnvilFeed\EventFactory;
use AnvilFeed\GitContext;
use AnvilFeed\Scrubber;
use PHPUnit\Framework\TestCase;

final class ScrubberTest extends TestCase
{
    private const CONFORMANCE = __DIR__ . '/../../../conformance';

    private function scrubber(): Scrubber
    {
        return new Scrubber(Config::DEFAULT_DENYLIST);
    }

    /** FR-5 d: the unit test the specification demands, driven by the language-independent pair. */
    public function testScrubsTheConformancePair(): void
    {
        $raw = json_decode(file_get_contents(self::CONFORMANCE . '/pii/raw.json'), true, 512, JSON_THROW_ON_ERROR);
        $expected = json_decode(file_get_contents(self::CONFORMANCE . '/pii/expected.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($expected, $this->scrubber()->scrub($raw));
    }

    public function testNeverLeavesAnAddressBehind(): void
    {
        $scrubbed = $this->scrubber()->scrub([
            'user' => ['id' => 'u-1', 'ip' => '203.0.113.9', 'ip_hash' => 'deadbeef'],
            'tags' => ['user.ip_hash' => 'deadbeef'],
        ]);

        self::assertSame(['user' => ['id' => 'u-1'], 'tags' => []], $scrubbed);
    }

    public function testKeepsTheBearerSchemeSoAReaderKnowsWhatWasThere(): void
    {
        self::assertSame(
            'Authorization: Bearer [Filtered]',
            $this->scrubber()->scrubText('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.abc')
        );
    }

    public function testScrubsOnlyTheMatchingQueryParameter(): void
    {
        self::assertSame(
            'https://app.example/reset?token=[Filtered]&user=7',
            $this->scrubber()->scrubUrl('https://app.example/reset?token=abc123&user=7')
        );
    }

    /** No structural field name of schema 1.0 contains a denylist term. This is the guard for that. */
    public function testDoesNotFilterStructuralFields(): void
    {
        $event = json_decode(
            file_get_contents(self::CONFORMANCE . '/valid/section-3-example.json'),
            true, 512, JSON_THROW_ON_ERROR
        );

        self::assertSame($event, $this->scrubber()->scrub($event));
    }

    /**
     * FR-5 d, the wider half: an event carrying one denylist term in every field it can reach, serialised,
     * and then read back as the bytes that would go on the socket. Not one of the nine values may be found
     * in it. context_lines is 0 here on purpose — with context on, the frame would quote THIS file, and the
     * literals below would come back as source code rather than as scrubbed fields, which would redden the
     * test for a reason that has nothing to do with the scrubber. context_code scrubbing is pinned by the
     * conformance pair above instead.
     */
    public function testNoDenylistValueSurvivesSerialisation(): void
    {
        $secrets = [
            'password' => 'hunter2-pw',
            'token' => 'tok-abc123',
            'secret' => 'shh-xyz',
            'authorization' => 'eyJhbGciOiJIUzI1NiJ9.zzz',
            'api_key' => 'sk-live-99887766',
            'cookie' => 'sid-abc',
            'email' => 'ada@example.org',
            'credit_card' => '4111111111111111',
            'iban' => 'CH9300762011623852957',
        ];

        $config = Config::fromArray([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
            'environment' => 'production',
            'context_lines' => 0,
        ]);
        $factory = new EventFactory($config, $this->scrubber(), GitContext::none());

        $error = new \RuntimeException(sprintf(
            'login failed: password=%s, Authorization: Bearer %s, iban=%s',
            $secrets['password'],
            $secrets['authorization'],
            $secrets['iban']
        ));

        $json = $factory->fromThrowable($error, 'error', [
            'request' => [
                'method' => 'POST',
                'url' => 'https://app.example/login?password=' . $secrets['password']
                    . '&token=' . $secrets['token']
                    . '&secret=' . $secrets['secret']
                    . '&api_key=' . $secrets['api_key']
                    . '&email=' . $secrets['email']
                    . '&user=7',
                'status_code' => 401,
            ],
            'user_id' => 'u-4711',
            'tags' => array_merge($secrets, ['tenant' => 'kunde-x']),
            'breadcrumbs' => array_map(
                static fn (string $key, string $value) => $key . '=' . $value,
                array_keys($secrets),
                array_values($secrets)
            ),
        ])->canonicalJson();

        foreach ($secrets as $key => $value) {
            self::assertStringNotContainsString($value, $json, sprintf('the %s value survived', $key));
        }

        // What must survive, so that the test cannot pass by scrubbing everything.
        self::assertStringContainsString(Scrubber::FILTERED, $json);
        self::assertStringContainsString('"tenant":"kunde-x"', $json);
        self::assertStringContainsString('&user=7', $json);
        self::assertStringContainsString('"id":"u-4711"', $json);
        self::assertStringContainsString('"url":"https://app.example/login?', $json);
    }
}
