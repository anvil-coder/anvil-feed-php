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
    public const MESSAGE_TYPE = 'AnvilFeed.Message';
    public const TEST_TYPE = 'AnvilFeed.TestEvent';
    public const TEST_MESSAGE = 'This event was produced by captureTestEvent() to verify the integration. '
        . 'It is safe to ignore or close.';

    /** schema 1.0: title 512, exception.message 2000, everything short-text 1000. */
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
        private readonly string $sdkVersion = '0.1.0',
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

    /** @param array<string, mixed> $context */
    public function fromMessage(string $message, string $level = 'warning', array $context = []): Event
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->config->maxFrames + 4);
        $frames = $this->framesOf(
            array_slice($backtrace, 1),
            $backtrace[0]['file'] ?? 'unknown',
            $backtrace[0]['line'] ?? 0,
            null
        );

        return $this->build(
            title: $this->clip($message, self::MAX_TITLE_CHARS),
            level: $level,
            exception: $this->exception(self::MESSAGE_TYPE, $message, $frames),
            context: $context,
        );
    }

    public function testEvent(): Event
    {
        return $this->build(
            title: 'Anvil Feed test event',
            level: 'error',
            exception: $this->exception(self::TEST_TYPE, self::TEST_MESSAGE, [[
                'file' => __FILE__,
                'line' => __LINE__,
                'function' => 'testEvent',
                'in_app' => false,
            ]]),
            context: [],
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
            'level' => in_array($level, ['error', 'warning', 'fatal'], true) ? $level : 'error',
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
            // schema 1.0 requires http(s) here. A URL of any other scheme is dropped rather than sent: the
            // documented intent is that the event survives without it, and a file:// path is somebody's
            // local filesystem, which the ingest never asked for.
            $url = $context['request']['url'] ?? null;
            $isWebUrl = is_string($url) && preg_match('~^https?://~i', $url) === 1;
            $payload['request'] = array_filter([
                'method' => $context['request']['method'] ?? null,
                'url' => $isWebUrl ? $this->clip($url, self::MAX_SHORT_TEXT_CHARS) : null,
                'status_code' => isset($context['request']['status_code'])
                    ? (int) $context['request']['status_code'] : null,
            ], static fn ($v) => $v !== null);
        }
        if (isset($context['user_id']) && is_string($context['user_id']) && $context['user_id'] !== '') {
            $payload['user'] = ['id' => $context['user_id']];
        }
        if (!empty($context['breadcrumbs']) && is_array($context['breadcrumbs'])) {
            $crumbs = array_map(
                fn ($crumb) => $this->clip(
                    is_string($crumb) ? $crumb : (string) json_encode($crumb),
                    self::MAX_SHORT_TEXT_CHARS
                ),
                array_values($context['breadcrumbs'])
            );
            // The TAIL: it explains the fault. The ingest keeps the last MAX_BREADCRUMBS and so do we.
            $payload['breadcrumbs'] = array_slice($crumbs, -self::MAX_BREADCRUMBS);
        }
        if (!empty($context['tags']) && is_array($context['tags'])) {
            $tags = [];
            foreach ($context['tags'] as $key => $value) {
                if (count($tags) >= self::MAX_TAGS) {
                    break;
                }
                $tags[(string) $key] = $this->clip(
                    is_scalar($value) ? (string) $value : (string) json_encode($value),
                    self::MAX_SHORT_TEXT_CHARS
                );
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

        return new Event($id, Event::inCanonicalOrder($this->scrubber->scrub($payload)));
    }

    /**
     * @param list<array<string, mixed>> $frames
     * @return array<string, mixed>
     */
    private function exception(string $type, string $message, array $frames): array
    {
        $exception = ['type' => $type];
        if ($message !== '') {
            $exception['message'] = $this->clip($message, self::MAX_MESSAGE_CHARS);
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
        $frame['in_app'] = $file !== '' && !str_contains($file, '/vendor/');
        $context = $this->contextCode($file, $line);
        if ($context !== null) {
            $frame['context_code'] = $context;
        }

        return $frame;
    }

    /**
     * The lines around the failure, bounded exactly as the ingest bounds them — and then bounded once more
     * as a whole, because context_lines * max_context_line_chars is 8000 at the defaults while both the
     * ingest and schema 1.0 stop at 1000 short-text characters.
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

        return mb_substr(implode("\n", array_map(
            fn (string $l) => mb_substr($l, 0, $this->config->maxContextLineChars),
            $slice
        )), 0, self::MAX_SHORT_TEXT_CHARS);
    }

    private function title(string $type, string $message): string
    {
        return $this->clip($message === '' ? $type : $type . ': ' . $message, self::MAX_TITLE_CHARS);
    }

    private function clip(string $value, int $max): string
    {
        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max - 12) . ' [truncated]';
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
