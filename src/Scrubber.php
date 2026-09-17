<?php

declare(strict_types=1);

namespace AnvilFeed;

/**
 * FR-5: scrubbing happens in the SDK, before anything leaves the process — not on the server.
 *
 * Three rules, in this order, and the order is what makes the result readable:
 *   1. A map key that matches the denylist replaces its whole value, whatever the value is.
 *   2. Inside any remaining text, "Bearer <value>" keeps the scheme and loses the value: a reader has to
 *      be able to tell what kind of credential was in play.
 *   3. Inside any remaining text, "<denied key><separator><value>" loses the value and keeps the key.
 *
 * Plus two absolutes that are not configurable: request.url is scrubbed parameter by parameter, and
 * user.ip / user.ip_hash are removed outright (FR-5 c). The ingest forms the pseudonym itself from the
 * connection address; a value we sent would be either a guess or an address.
 *
 * It is pattern work, not a proof. A secret with no recognisable shape survives, which is why the server
 * redacts again on its own side. What this buys is that it never leaves this process in the first place.
 */
final class Scrubber
{
    public const FILTERED = '[Filtered]';

    private readonly string $textPattern;
    /** @var list<string> */
    private readonly array $normalizedTerms;

    /** @param list<string> $denylist */
    public function __construct(array $denylist)
    {
        $terms = array_values(array_filter(array_map(
            static fn ($term) => strtolower(trim((string) $term)),
            $denylist
        ), static fn (string $term) => $term !== ''));

        $this->normalizedTerms = array_map(
            static fn (string $term) => preg_replace('/[^a-z0-9]/', '', $term) ?? $term,
            $terms
        );

        // "api_key" also matches "api-key", "apiKey" and "API KEY"; "credit_card" likewise.
        $alternatives = implode('|', array_map(
            static fn (string $term) => str_replace('_', '[-_ ]?', preg_quote($term, '/')),
            $terms
        ));
        $this->textPattern = '/\b(' . $alternatives . ')\b(\s*[:=]\s*)'
            . '(?!' . preg_quote(self::FILTERED, '/') . ')(?!Bearer\b)(["\']?)([^\s"\',;&)}\]]+)\3?/i';
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    public function scrub(array $payload): array
    {
        if (isset($payload['user']) && is_array($payload['user'])) {
            unset($payload['user']['ip'], $payload['user']['ip_hash']);
        }
        if (isset($payload['tags']) && is_array($payload['tags'])) {
            unset($payload['tags']['user.ip_hash']);
        }
        if (isset($payload['request']['url']) && is_string($payload['request']['url'])) {
            $payload['request']['url'] = $this->scrubUrl($payload['request']['url']);
            $payload['request']['__url_done'] = true;
        }

        $scrubbed = $this->walk($payload);
        if (is_array($scrubbed['request'] ?? null)) {
            unset($scrubbed['request']['__url_done']);
        }

        return $scrubbed;
    }

    public function scrubText(string $text): string
    {
        // The scheme survives; the value does not.
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]{3,}/i', 'Bearer ' . self::FILTERED, $text) ?? $text;

        return preg_replace_callback(
            $this->textPattern,
            static fn (array $m) => $m[1] . $m[2] . $m[3] . self::FILTERED . $m[3],
            $text
        ) ?? $text;
    }

    /**
     * Rebuilt by hand rather than through http_build_query, which would percent-encode the square
     * brackets of the marker and turn "[Filtered]" into "%5BFiltered%5D" in every cockpit.
     */
    public function scrubUrl(string $url): string
    {
        $split = explode('?', $url, 2);
        if (count($split) === 1 || $split[1] === '') {
            return $url;
        }

        $scrubbedPairs = array_map(function (string $pair): string {
            $kv = explode('=', $pair, 2);
            if (count($kv) === 1) {
                return $pair;
            }

            return $this->keyIsDenied(urldecode($kv[0])) ? $kv[0] . '=' . self::FILTERED : $pair;
        }, explode('&', $split[1]));

        return $split[0] . '?' . implode('&', $scrubbedPairs);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function walk(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->keyIsDenied($key)) {
                $result[$key] = self::FILTERED;
                continue;
            }
            if (is_array($item)) {
                $result[$key] = $item === [] ? [] : $this->walk($item);
                continue;
            }
            if (is_string($item)) {
                $result[$key] = ($key === 'url' && ($value['__url_done'] ?? false) === true)
                    ? $item
                    : $this->scrubText($item);
                continue;
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function keyIsDenied(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $key) ?? $key);
        foreach ($this->normalizedTerms as $term) {
            if (str_contains($normalized, $term)) {
                return true;
            }
        }

        return false;
    }
}
