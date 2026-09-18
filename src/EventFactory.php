<?php

declare(strict_types=1);

namespace AnvilFeed;

/**
 * Turns a Throwable, a message or a verification request into an event the ingest accepts.
 *
 * The one thing worth knowing before reading it: the ingest requires exception.type. There is no such
 * thing as a message-only event — a captureMessage() without an exception is answered schema_violation and
 * is lost. So fromMessage() and testEvent() synthesise one, with the caller's own stack, and the type says
 * plainly which of the two it was.
 *
 * Every bound below is the ingest's own bound (IncidentDelivery.MAX_*), and where schema 1.0 is stricter
 * than the ingest it is the schema that wins: the ingest truncates a too-long value, the schema refuses the
 * event, and a serialiser that can produce a body the normative schema rejects is a defect either way.
 */
final class EventFactory
{
    /**
     * The single source of sdk.version. It is what tells support which SDK a customer is running, so it
     * must not exist twice: Task 11's release check pins THIS constant against the released tag, and the
     * constructor default below is the only other place it is spelled.
     */
    public const SDK_VERSION = '0.1.0';

    public const MESSAGE_TYPE = 'AnvilFeed.Message';
    public const TEST_TYPE = 'AnvilFeed.TestEvent';
    public const TEST_MESSAGE = 'This event was produced by captureTestEvent() to verify the integration. '
        . 'It is safe to ignore or close.';

    /** schema 1.0: title 512, exception.message 2000, everything short-text 1000. */
    /** The range schema 1.0's `integer` readers have; PHP's 64-bit int does not give it for free. */
    private const INT32_MIN = -2147483648;
    private const INT32_MAX = 2147483647;

    private const MAX_TITLE_CHARS = 512;
    private const MAX_MESSAGE_CHARS = 2000;
    private const MAX_SHORT_TEXT_CHARS = 1000;
    private const MAX_BREADCRUMBS = 30;
    /**
     * 29, not the ingest's MAX_TAGS of 30: AnvilFeedEventMapper.tags() writes the server's own
     * user.ip_hash pseudonym into the first slot before any sender tag, so a sender that fills thirty
     * loses one of them — silently, in the cockpit, weeks later.
     */
    private const MAX_TAGS = 29;

    public function __construct(
        private readonly Config $config,
        private readonly Scrubber $scrubber,
        private readonly GitContext $git,
        private readonly string $sdkName = 'anvil-feed-php',
        private readonly string $sdkVersion = self::SDK_VERSION,
        private readonly string $language = 'php',
    ) {
    }

    /** @param array<string, mixed> $context */
    public function fromThrowable(\Throwable $error, string $level = 'error', array $context = []): Event
    {
        $type = $error::class;
        // The throw site is the first frame; getTrace() starts one level above it.
        $frames = $this->framesOf($error->getTrace(), $error->getFile(), $error->getLine(), null);

        return $this->build(
            title: $this->title($type, $error->getMessage()),
            level: $level,
            exception: $this->exception($type, $error->getMessage(), $frames),
            context: $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>>|null $callerTrace the backtrace taken at the PUBLIC entry point
     */
    public function fromMessage(
        string $message,
        string $level = 'warning',
        array $context = [],
        ?array $callerTrace = null,
    ): Event {
        return $this->build(
            title: $message,
            level: $level,
            exception: $this->exception(self::MESSAGE_TYPE, $message, $this->callerFrames($callerTrace)),
            context: $context,
        );
    }

    /** @param list<array<string, mixed>>|null $callerTrace */
    public function testEvent(?array $callerTrace = null): Event
    {
        return $this->build(
            title: 'Anvil Feed test event',
            level: 'error',
            exception: $this->exception(self::TEST_TYPE, self::TEST_MESSAGE, $this->callerFrames($callerTrace)),
            context: [],
        );
    }

    /**
     * The stack of whoever called us, with this SDK's own frames removed from the front.
     *
     * A synthesised exception whose stack starts inside anvil-feed is worse than useless twice over. The
     * operator opens an incident and reads three of OUR files before their own; and — the expensive half —
     * `isInApp()` decides the in-app promotion the fingerprint partitions on, so wherever this package is
     * NOT installed under a `vendor/` directory (a Composer `path` repository, a monorepo checkout, the
     * in-repo adapter) those three constant frames would win the promotion and EVERY captureMessage() in
     * the application would collapse into one fingerprint — the exact failure the promotion exists to
     * prevent, turned inward. So the frames are dropped by path, and `isInApp()` refuses this directory
     * as well, which makes the two defences independent.
     *
     * @param list<array<string, mixed>>|null $callerTrace
     * @return list<array<string, mixed>>
     */
    private function callerFrames(?array $callerTrace): array
    {
        $trace = $callerTrace ?? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->config->maxFrames + 8);
        $trace = array_values($trace);

        $first = null;
        foreach ($trace as $index => $entry) {
            $file = is_string($entry['file'] ?? null) ? $entry['file'] : '';
            if ($file !== '' && !self::isOurOwn($file)) {
                $first = $index;
                break;
            }
        }
        if ($first === null) {
            // Nothing outside this SDK called us — there is no caller stack to report, and inventing one
            // out of our own files is the defect above. The event then groups by its title, which is the
            // documented shape for an event without frames.
            return [];
        }

        return $this->framesOf(
            array_slice($trace, $first + 1),
            (string) $trace[$first]['file'],
            (int) ($trace[$first]['line'] ?? 0),
            null
        );
    }

    /**
     * @param array<string, mixed> $exception
     * @param array<string, mixed> $context
     */
    private function build(string $title, string $level, array $exception, array $context): Event
    {
        $id = self::uuid4();
        $payload = [
            'schema_version' => '1.0',
            'event_id' => $id,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            // title is required and must not be blank. A caught exception with no message and an empty
            // log line are both real calls, and neither may cost the event.
            'title' => trim($title) === '' ? $exception['type'] : $title,
            'level' => self::coerceLevel($level),
            'environment' => $this->config->environment,
            'service' => $this->config->service,
            'language' => $this->language,
            'sdk' => ['name' => $this->sdkName, 'version' => $this->sdkVersion, 'runtime' => 'server'],
            'exception' => $exception,
        ];

        $release = $this->config->release ?? $this->git->release;
        if ($release !== null) {
            $payload['release'] = $release;
        }
        if (isset($context['request']) && is_array($context['request']) && $context['request'] !== []) {
            // The URL goes in as it came. It is scrubbed (userinfo and parameters) by Scrubber::scrubUrl
            // and only THEN checked for an http(s) scheme, in dropUnsendable() — FR-5 e before the shape
            // rule, so a credential can never survive on the grounds that the URL was going to be dropped.
            $url = $context['request']['url'] ?? null;
            $payload['request'] = array_filter([
                'method' => $context['request']['method'] ?? null,
                'url' => is_string($url) ? $url : null,
                'status_code' => self::asInteger($context['request']['status_code'] ?? null),
            ], static fn ($v) => $v !== null);
        }
        if (isset($context['user_id']) && is_string($context['user_id']) && $context['user_id'] !== '') {
            $payload['user'] = ['id' => $context['user_id']];
        }
        if (!empty($context['breadcrumbs']) && is_array($context['breadcrumbs'])) {
            $crumbs = array_map(
                static fn ($crumb) => is_string($crumb) ? $crumb : (string) json_encode($crumb),
                array_values($context['breadcrumbs'])
            );
            // NOT cut here. boundCounts() is the one place every count cap is applied — to this payload
            // and to a beforeSend hook's output alike — so that the two can never drift apart.
            $payload['breadcrumbs'] = $crumbs;
        }
        if (!empty($context['tags']) && is_array($context['tags'])) {
            $tags = [];
            foreach ($context['tags'] as $key => $value) {
                $tags[(string) $key] = is_scalar($value)
                    ? (string) $value
                    : (string) json_encode($value);
            }
            $payload['tags'] = $tags;
        }
        if ($this->git->repo !== null || $this->git->branch !== null) {
            $payload['agent_context'] = array_filter([
                'suggested_ticket_type' => 'bug',
                'repo' => $this->git->repo,
                'branch' => $this->git->branch,
            ], static fn ($v) => $v !== null);
        }

        // The order is the whole point of these lines and it is the SERVER's order
        // (IncidentRedaction.redact: redact, then truncate to the cap):
        //   1. scrub   — the only step that may GROW a value, [Filtered] being ten characters
        //   2. bound   — every cap, of characters and of counts, applied to what scrubbing produced
        //   3. drop    — the members the contract refuses, and then the maps they leave empty
        return new Event($id, Event::inCanonicalOrder($this->finalise($payload)));
    }

    /**
     * Scrub, bound, prune — the three steps in the server's own order, as ONE callable step.
     *
     * It is public because a `beforeSend` hook's output has to go through exactly the same pipeline as the
     * payload this factory built: FR-5 says the SDK redacts before sending, and the most obvious use of
     * the hook is enrichment ($payload['tags']['user_email'] = …), which would otherwise reach the wire
     * having never met the denylist. `Scrubber::scrub` is idempotent, so a second pass over an already
     * scrubbed payload costs one walk and cannot double-filter.
     *
     * "Bound" is both kinds of bound: the per-value character caps AND the counts. A hook that adds a
     * thirtieth tag to a full event used to pass straight through here, and `tags` is `maxProperties: 29`
     * in schema 1.0 — so the ingest answered `schema_violation` and the event was lost for a field the
     * sender never noticed.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function finalise(array $payload): array
    {
        $payload = $this->dropUnsendable(
            $this->boundCounts($this->boundTexts($this->scrubber->scrub($payload)))
        );
        // The level enum is re-applied HERE and not only in build(): finalise() is also the second pass a
        // beforeSend result takes, and a hook that writes `level: "info"` used to reach the wire — the
        // ingest stores it, so nothing complains (sdk-forbidden/level-info.json, task-4 fix round).
        $payload['level'] = self::coerceLevel($payload['level'] ?? null);

        return $payload;
    }

    /** The wire's level enum; anything else is an error, which is what an unknown severity means to a repair. */
    public static function coerceLevel(mixed $level): string
    {
        return in_array($level, ['error', 'warning', 'fatal'], true) ? $level : 'error';
    }

    /**
     * Every bound schema 1.0 states, applied AFTER scrubbing. Clipping first and redacting second is how a
     * capped value climbs back over its cap: the defaults capture a 1000-character context window, and one
     * "$password = 'x';" in it becomes "$password = '[Filtered]';" — fourteen characters longer, and the
     * event is refused. That needs no unusual input; an ordinary Laravel config file does it.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function boundTexts(array $payload): array
    {
        if (isset($payload['title']) && is_string($payload['title'])) {
            $payload['title'] = $this->clip($payload['title'], self::MAX_TITLE_CHARS);
        }
        if (isset($payload['service']) && is_string($payload['service'])) {
            $payload['service'] = $this->clip($payload['service'], Config::MAX_SERVICE_CHARS);
        }
        if (isset($payload['exception']['message']) && is_string($payload['exception']['message'])) {
            $payload['exception']['message'] =
                $this->clip($payload['exception']['message'], self::MAX_MESSAGE_CHARS);
        }
        if (isset($payload['exception']['stacktrace']) && is_array($payload['exception']['stacktrace'])) {
            foreach ($payload['exception']['stacktrace'] as $index => $frame) {
                if (isset($frame['context_code']) && is_string($frame['context_code'])) {
                    $payload['exception']['stacktrace'][$index]['context_code'] =
                        $this->clip($frame['context_code'], self::MAX_SHORT_TEXT_CHARS);
                }
            }
        }
        if (isset($payload['request']['url']) && is_string($payload['request']['url'])) {
            $payload['request']['url'] = $this->clip($payload['request']['url'], self::MAX_SHORT_TEXT_CHARS);
        }
        foreach (['breadcrumbs', 'tags'] as $block) {
            if (!isset($payload[$block]) || !is_array($payload[$block])) {
                continue;
            }
            foreach ($payload[$block] as $key => $value) {
                if (is_string($value)) {
                    $payload[$block][$key] = $this->clip($value, self::MAX_SHORT_TEXT_CHARS);
                }
            }
        }

        return $payload;
    }

    /**
     * Every bound that is a COUNT, in the direction the server keeps: tags the first 29 by insertion order,
     * breadcrumbs the last 30, frames the first `max_frames` of a most-recent-first stack.
     *
     * This is the only place they are applied. `build()` used to cut the tags and the breadcrumbs itself and
     * `finalise()` re-applied nothing, so the second pipeline pass — the one a `beforeSend` result takes, and
     * the one whose contract says it is the same pipeline — let a thirtieth tag, a thirty-first breadcrumb
     * and a fifty-first frame reach the wire, where schema 1.0 refuses all three and the event is lost.
     * Having one helper rather than two copies is what keeps the two passes from drifting apart again.
     *
     * `framesOf()` still stops collecting at `max_frames`, and that is not a second copy of this rule: it is
     * there so that the frames beyond the cap never cost a source-file read for a `context_code` this method
     * would throw away. The cap that decides what travels is this one.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function boundCounts(array $payload): array
    {
        if (isset($payload['tags']) && is_array($payload['tags']) && count($payload['tags']) > self::MAX_TAGS) {
            // The FRONT: AnvilFeedEventMapper.tags() keeps the first thirty by insertion order and spends
            // the first slot on the server's own pseudonym, so the sender's own first 29 are what survives.
            $payload['tags'] = array_slice($payload['tags'], 0, self::MAX_TAGS, true);
        }
        if (isset($payload['breadcrumbs']) && is_array($payload['breadcrumbs'])) {
            // The TAIL: it is the tail that explains the fault, and it is the tail the ingest keeps
            // (IncidentDelivery.redactedBreadcrumbs). array_values because schema 1.0 types this as a JSON
            // array: a map left behind by a hook, or the gap a slice leaves, would serialise as an object.
            $payload['breadcrumbs'] = array_values(
                array_slice($payload['breadcrumbs'], -self::MAX_BREADCRUMBS)
            );
        }
        if (isset($payload['exception']['stacktrace']) && is_array($payload['exception']['stacktrace'])) {
            // The HEAD: the stack is most-recent-first, frame 0 is the crash site, and the ingest keeps the
            // head too (IncidentDelivery.redactedFrames). Dropping from the front would keep the 50 least
            // interesting frames and fingerprint the fault on somebody else's code.
            $payload['exception']['stacktrace'] = array_values(
                array_slice($payload['exception']['stacktrace'], 0, $this->config->maxFrames)
            );
        }

        return $payload;
    }

    /**
     * What the contract refuses, and what is left when everything inside a block was refused.
     *
     * The second half is the part that is easy to miss: PHP's empty array is JSON's `[]`, and every one of
     * these blocks is typed `object` in schema 1.0. A request context carrying only a file:// URL, or tags
     * carrying only the ingest's own user.ip_hash, leaves an empty array behind and the whole event is
     * refused for a field the sender never needed to send.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function dropUnsendable(array $payload): array
    {
        // schema 1.0 requires http(s). A file:// path is somebody's local filesystem and the ingest never
        // asked for it; the documented intent is that the event survives without the URL.
        if (isset($payload['request']['url'])
            && (!is_string($payload['request']['url'])
                || preg_match('~^https?://~i', $payload['request']['url']) !== 1)) {
            unset($payload['request']['url']);
        }

        foreach (['request', 'user', 'tags', 'agent_context', 'enrichment', 'sdk'] as $block) {
            if (isset($payload[$block]) && $payload[$block] === []) {
                unset($payload[$block]);
            }
        }

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $frames
     * @return array<string, mixed>
     */
    private function exception(string $type, string $message, array $frames): array
    {
        $exception = ['type' => $type];
        if ($message !== '') {
            $exception['message'] = $message;   // bounded after scrubbing, in boundTexts()
        }
        if ($frames !== []) {
            $exception['stacktrace'] = $frames;
        }

        return $exception;
    }

    /**
     * @param list<array<string, mixed>> $trace
     * @return list<array<string, mixed>>
     */
    private function framesOf(array $trace, string $file, int $line, ?string $function): array
    {
        $frames = [];
        $frames[] = $this->frame($file, $line, $function ?? '{main}');
        foreach ($trace as $entry) {
            if (count($frames) >= $this->config->maxFrames) {
                break;
            }
            $frames[] = $this->frame(
                (string) ($entry['file'] ?? ''),
                (int) ($entry['line'] ?? 0),
                (string) ($entry['function'] ?? '')
            );
        }

        return array_values(array_filter($frames, static fn (array $f) => ($f['file'] ?? '') !== ''));
    }

    /** @return array<string, mixed> */
    private function frame(string $file, int $line, string $function): array
    {
        $frame = ['file' => $file, 'line' => max(0, $line), 'function' => $function];
        $frame['in_app'] = self::isInApp($file);
        // in_app frames only: a vendor frame's source lines cost a file read per frame in the request path
        // (measured 12.4 ms for ten captures, task-5 review) and tell a repair agent nothing it can change.
        $context = $frame['in_app'] ? $this->contextCode($file, $line) : null;
        if ($context !== null) {
            $frame['context_code'] = $context;
        }

        return $frame;
    }

    /**
     * The lines around the failure, each cut to the max_context_line_chars knob. The whole-value bound is
     * NOT applied here: scrubbing grows text — "$password = 'x';" becomes "$password = '[Filtered]';" —
     * so a window capped before redaction climbs back over the cap afterwards. boundTexts() does it in the
     * server's order instead: redact, then truncate (IncidentRedaction.redact).
     */
    private function contextCode(string $file, int $line): ?string
    {
        if ($this->config->contextLines === 0 || $line < 1 || !is_file($file) || !is_readable($file)
            || filesize($file) > 2_097_152) {
            return null;
        }
        $around = intdiv($this->config->contextLines, 2);
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }
        $slice = array_slice($lines, max(0, $line - 1 - $around), $this->config->contextLines);

        return implode("\n", array_map(
            fn (string $l) => mb_substr($l, 0, $this->config->maxContextLineChars),
            $slice
        ));
    }

    /**
     * schema 1.0 types `request.status_code` as an INTEGER, and a value that is not one is not a status
     * code. `(int)` read `"12abc"` as 12 and `"abc"` as 0 — a status nobody ever sent, shown in a cockpit
     * as fact, and `0` is exactly the shape a reader takes for "the request never completed". A filter
     * that read the status off a header, or a value that took a JSON or properties hop, legitimately hands
     * over the string `"500"`, so a string of digits is accepted; anything else is DROPPED. One member of
     * an optional block is a cheaper loss than a guessed one — and shipping it as a string would cost the
     * whole event a `schema_violation`. This is the Java core's `asInteger()` (task-6 review finding 2),
     * rule for rule, so that C9/C17 hold here too.
     *
     * Two rules, and every branch below shares both.
     *
     * INTEGRALITY. A float is only a status code when it IS a whole number. `200.0` is the 200 a JSON or
     * properties hop turned into a float — every `json_decode` of `{"status_code": 200.0}` hands one over
     * — and dropping it lost a status the caller did state. `200.5` is not a status at all, and a cast
     * would have shown it in a cockpit as 200; `NAN` casts to 0, which reads as "the request never
     * completed", and `INF` casts to PHP_INT_MIN.
     *
     * RANGE. A whole number outside a SIGNED 32-BIT int is not one either, and it is DROPPED rather than
     * wrapped or saturated. PHP's int is 64-bit and its float branch was bounded at PHP_INT_MIN/MAX, so
     * `3000000000`, `"3000000000"` and `3.0e9` all went out on the wire as a status schema 1.0's readers
     * cannot hold. Negatives are still kept where they fit (`-1`), because the rule is the int32 range and
     * not a non-negativity rule — the Java core's `INTEGER` pattern takes an optional minus too.
     *
     * So: an integer, a digit string, or a whole finite float, inside {@link withinAnInt32}, or nothing.
     * This is Java `EventFactory.asInteger` with its shared `withinAnInt` bound, rule for rule
     * (task-9 parity list); C9/C17.
     *
     * @return int|null the status as the schema's integer, or null when it is not one
     */
    private static function asInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::withinAnInt32($value);
        }
        if (is_float($value)) {
            // The integrality check and the range check are both load-bearing: 200.5 must not become 200,
            // NAN and INF must not become 0 and PHP_INT_MIN, and a float outside the 32-bit range must not
            // be cast at all. Compared as floats, because 3.0e9 has no int this branch could compare with.
            return is_finite($value) && floor($value) === $value
                && $value >= (float) self::INT32_MIN && $value <= (float) self::INT32_MAX
                    ? (int) $value : null;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            // A digit string longer than PHP's int saturates to PHP_INT_MIN/MAX on cast rather than
            // wrapping, and both ends are outside the 32-bit range, so the bound catches those too.
            return self::withinAnInt32((int) trim($value));
        }

        return null;
    }

    /**
     * The ONE 32-bit bound every branch of {@link asInteger} shares — the range schema 1.0's `integer`
     * readers actually have, which PHP's 64-bit int does not give for free.
     *
     * Out of range is DROPPED, never wrapped and never saturated: `status_code` is one member of an
     * optional block, and losing it costs less than showing 705032704 in a cockpit as a status the caller
     * never sent. This is Java's `withinAnInt`, and the answer its string branch has always given.
     */
    private static function withinAnInt32(int $value): ?int
    {
        return $value >= self::INT32_MIN && $value <= self::INT32_MAX ? $value : null;
    }

    private function title(string $type, string $message): string
    {
        return $message === '' ? $type : $type . ': ' . $message;
    }

    private function clip(string $value, int $max): string
    {
        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max - 12) . ' [truncated]';
    }

    /**
     * A path rule, not a substring. `str_contains($file, '/vendor/')` marks every vendor frame in_app on
     * Windows, where __FILE__ uses backslashes — and in_app is what IncidentFingerprint.topFrames
     * partitions on, so the same fault would fingerprint differently on a developer machine than in
     * production and open a second incident for it. Public because it is a rule worth testing directly and
     * the Laravel adapter needs the same answer.
     */
    public static function isInApp(string $file): bool
    {
        if ($file === '' || self::isOurOwn($file)) {
            return false;   // this SDK is never the customer's own code, wherever it is installed
        }

        return preg_match('~[\\\\/]vendor[\\\\/]~', $file) !== 1;
    }

    /**
     * A file inside this package. `__DIR__` rather than a `vendor/` substring, because the one install
     * layout where the substring rule fails — the SDK NOT under vendor/ — is the layout every developer
     * of this repository and of the Laravel adapter runs.
     */
    private static function isOurOwn(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        $ours = str_replace('\\', '/', __DIR__) . '/';

        return str_starts_with($normalized, $ours);
    }

    /** A canonical UUID v4. The ingest refuses anything else, however reasonable it looks. */
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
