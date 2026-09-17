<?php

declare(strict_types=1);

namespace AnvilFeed;

/**
 * One event, ready to send. The payload is already scrubbed, already bounded and already in canonical key
 * order: nothing between here and the socket may change it, because the order is part of Z1.
 */
final class Event
{
    /** Chapter 03 section 3, top to bottom. Both SDKs emit exactly this order. */
    public const KEY_ORDER = [
        'schema_version', 'event_id', 'timestamp', 'title', 'level', 'environment', 'service',
        'language', 'sdk', 'exception', 'release', 'request', 'user', 'breadcrumbs', 'tags',
        'enrichment', 'agent_context',
    ];

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly string $id, public readonly array $payload)
    {
    }

    public function canonicalJson(): string
    {
        return json_encode(
            $this->payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function inCanonicalOrder(array $payload): array
    {
        $ordered = [];
        foreach (self::KEY_ORDER as $key) {
            if (array_key_exists($key, $payload)) {
                $ordered[$key] = $payload[$key];
            }
        }
        foreach ($payload as $key => $value) {   // anything a future minor adds keeps its place at the end
            if (!array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }
}
