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

    /**
     * Stated as "the address is gone", not as an exact-array equality. The equality used to read
     * `['user' => ['id' => 'u-1'], 'tags' => []]`, which quietly made an empty `tags` map — a shape schema
     * 1.0 refuses — part of what this guard promised. The emptiness is EventFactory's to drop (its own
     * test does that); what belongs here is that no address of any kind survives.
     */
    public function testNeverLeavesAnAddressBehind(): void
    {
        $scrubbed = $this->scrubber()->scrub([
            'user' => ['id' => 'u-1', 'ip' => '203.0.113.9', 'ip_hash' => 'deadbeef'],
            'tags' => ['user.ip_hash' => 'deadbeef', 'tenant' => 'kunde-x'],
        ]);

        self::assertSame(['id' => 'u-1'], $scrubbed['user']);
        self::assertArrayNotHasKey('user.ip_hash', $scrubbed['tags']);
        self::assertSame(['tenant' => 'kunde-x'], $scrubbed['tags']);
        self::assertStringNotContainsString('203.0.113.9', json_encode($scrubbed, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('deadbeef', json_encode($scrubbed, JSON_THROW_ON_ERROR));
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

    /**
     * FR-5 e. A credential in the userinfo position has no key at all, so neither the parameter walk here
     * nor IncidentRedaction's KV_SECRET on the server would ever see it. The shape survives so a reader
     * knows a credential was in play.
     */
    public function testUrlCredentialsNeverTravel(): void
    {
        self::assertSame(
            'https://[Filtered]@internal.example/api',
            $this->scrubber()->scrubUrl('https://admin:hunter2@internal.example/api')
        );
        self::assertSame(
            'https://[Filtered]@internal.example/api?token=[Filtered]',
            $this->scrubber()->scrubUrl('https://admin:hunter2@internal.example/api?token=abc')
        );
    }

    /** ";" is the legacy query separator some PHP stacks still emit (arg_separator.input). */
    public function testTheLegacySemicolonSeparatorIsScrubbedToo(): void
    {
        self::assertSame(
            'https://a.example/x?a=1;token=[Filtered];b=2',
            $this->scrubber()->scrubUrl('https://a.example/x?a=1;token=abc;b=2')
        );
    }

    /**
     * The scrubbed URL has to be the sent URL minus its secrets — that is what a reader of the cockpit
     * assumes. Folding the fragment into the last parameter's value silently ate it.
     */
    public function testTheFragmentSurvivesTheLastScrubbedParameter(): void
    {
        self::assertSame(
            'https://app.example/reset?token=[Filtered]#section-2',
            $this->scrubber()->scrubUrl('https://app.example/reset?token=abc123#section-2')
        );
        self::assertSame(
            'https://app.example/reset#section-2',
            $this->scrubber()->scrubUrl('https://app.example/reset#section-2')
        );
    }

    /** FR-2 b: a denylist a customer wrote by hand may hold anything, and none of it may raise. */
    public function testANonStringDenylistEntryIsIgnoredRatherThanCast(): void
    {
        $scrubber = new Scrubber(['token', ['nested'], 42, null, '  ', 'iban']);

        self::assertSame(
            ['token' => '[Filtered]', 'iban' => '[Filtered]', 'tenant' => 'kunde-x'],
            $scrubber->scrub(['token' => 'abc', 'iban' => 'CH93', 'tenant' => 'kunde-x'])
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
