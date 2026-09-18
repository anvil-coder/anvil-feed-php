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

    /** null when the denylist is empty: no key rule at all, rather than one that matches everywhere. */
    private readonly ?string $textPattern;
    /** @var list<string> */
    private readonly array $normalizedTerms;

    /** @param list<string> $denylist */
    public function __construct(array $denylist)
    {
        // A denylist entry that is not a string is ignored rather than cast: "(string) []" is a PHP
        // warning, and Laravel's error handler turns a warning into a thrown ErrorException — inside the
        // constructor of the object whose whole job is to keep this SDK out of the host's way (FR-2 b).
        $terms = array_values(array_filter(array_map(
            static fn (string $term) => strtolower(trim($term)),
            array_filter($denylist, static fn ($term) => is_string($term))
        ), static fn (string $term) => $term !== ''));

        $this->normalizedTerms = array_map(
            static fn (string $term) => preg_replace('/[^a-z0-9]/', '', $term) ?? $term,
            $terms
        );

        if ($terms === []) {
            // An EMPTY denylist is a configuration, not a mistake (FR-5 a): the operator asked for no
            // key-based redaction and gets none. What it must NOT become is an empty alternation —
            // `/\b()\b(\s*[:=]\s*)…/` matches at every colon and every equals sign in every text this
            // SDK touches, so `user=alice&mode=fast` came back as `user=[Filtered]&mode=[Filtered]`: every
            // readable value destroyed by the one setting whose whole point was to keep them. The Bearer
            // pass, the URL rules and the user.ip removal are absolutes and keep running. This is the Java
            // core's behaviour (AnvilFeedConfig/Scrubber, task-6 review finding 7), and C9/C17 require the
            // two languages to answer the same here.
            $this->textPattern = null;

            return;
        }

        // "api_key" also matches "api-key", "apiKey" and "API KEY"; "credit_card" likewise.
        $alternatives = implode('|', array_map(
            static fn (string $term) => str_replace('_', '[-_ ]?', preg_quote($term, '/')),
            $terms
        ));
        // The quote group comes BEFORE the two lookaheads, and that order is the whole of the fix. With the
        // guard in front, a second pass over `token="[Filtered]"` read the lookahead against the QUOTE —
        // which is not `[Filtered]`, so it passed — the value class then stopped at the `]` it excludes, and
        // the leftover `]"` survived in the tail: `token="[Filtered]"]"`, two more characters on every
        // further pass. Behind the quote the guard sees the value a reader sees, so an already-filtered
        // value (quoted or not) matches nothing and comes back byte-identical, and `Authorization: "Bearer
        // [Filtered]"` keeps the scheme the line above deliberately kept. The value class stays the server's
        // own (IncidentRedaction.KV_SECRET): excluding `)}]` is what keeps `(token=abc)` from losing its
        // bracket, and it is also the reason the guard cannot be left where it was.
        $this->textPattern = '/\b(' . $alternatives . ')\b(\s*[:=]\s*)(["\']?)'
            . '(?!' . preg_quote(self::FILTERED, '/') . ')(?!Bearer\b)([^\s"\',;&)}\]]+)\3?/i';
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

        if ($this->textPattern === null) {
            return $text;   // an honoured empty denylist: no key rule, the absolutes above still ran
        }

        return preg_replace_callback(
            $this->textPattern,
            static fn (array $m) => $m[1] . $m[2] . $m[3] . self::FILTERED . $m[3],
            $text
        ) ?? $text;
    }

    /**
     * FR-5 e. Three things happen here and the order matters:
     *
     *   1. The fragment is split off first. It is not part of the query, and folding it into the last
     *      parameter's value meant "?token=abc#section" scrubbed to "?token=[Filtered]" — the fragment
     *      silently eaten. A reader of the cockpit assumes the scrubbed URL is the sent URL minus its
     *      secrets, and it has to be exactly that.
     *   2. Userinfo is removed: "https://admin:hunter2@host/x" carries a credential with no key at all,
     *      so neither the parameter walk here nor IncidentRedaction's KV_SECRET on the server would ever
     *      see it. The shape survives so a reader knows a credential was in play.
     *   3. Parameters are scrubbed one by one, split on "&" AND ";" — the legacy separator some PHP
     *      stacks still emit (arg_separator.input) — with the separator put back where it was.
     *
     * Rebuilt by hand rather than through http_build_query, which would percent-encode the square
     * brackets of the marker and turn "[Filtered]" into "%5BFiltered%5D" in every cockpit.
     */
    public function scrubUrl(string $url): string
    {
        $withoutFragment = explode('#', $url, 2);
        $fragment = count($withoutFragment) === 2 ? '#' . $withoutFragment[1] : '';

        $split = explode('?', $withoutFragment[0], 2);
        $head = $this->scrubUserInfo($split[0]);
        if (count($split) === 1 || $split[1] === '') {
            return $head . (count($split) === 2 ? '?' : '') . $fragment;
        }

        // preg_split with DELIM_CAPTURE keeps the separators, so "a=1;b=2&c=3" comes back unchanged
        // apart from the values that had to go.
        $parts = preg_split('/([&;])/', $split[1], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$split[1]];
        $rebuilt = '';
        foreach ($parts as $index => $part) {
            $rebuilt .= $index % 2 === 1 ? $part : $this->scrubQueryPair($part);
        }

        return $head . '?' . $rebuilt . $fragment;
    }

    private function scrubQueryPair(string $pair): string
    {
        $kv = explode('=', $pair, 2);
        if (count($kv) === 1) {
            return $pair;
        }

        return $this->keyIsDenied(urldecode($kv[0])) ? $kv[0] . '=' . self::FILTERED : $pair;
    }

    /** "scheme://user:pass@host/path" keeps its shape and loses the credential. */
    private function scrubUserInfo(string $head): string
    {
        return preg_replace(
            '~^([A-Za-z][A-Za-z0-9+.-]*://)([^/@]+)@~',
            '$1' . self::FILTERED . '@',
            $head
        ) ?? $head;
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
