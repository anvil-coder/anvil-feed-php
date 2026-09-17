<?php

declare(strict_types=1);

namespace AnvilFeed;

/**
 * The static face of the SDK, for the call sites that have no container. Every method is a no-op until
 * init() has run, so a library that calls AnvilFeed::captureException() in an application that never
 * configured the SDK does nothing at all rather than failing.
 */
final class AnvilFeed
{
    private static ?Client $client = null;

    public static function init(Client $client): void
    {
        self::$client = $client;
    }

    public static function client(): ?Client
    {
        return self::$client;
    }

    /** For tests and for a host that tears the SDK down; the facade is process-wide state. */
    public static function reset(): void
    {
        self::$client = null;
    }

    /** @param array<string, mixed> $context */
    public static function captureException(\Throwable $error, array $context = []): ?string
    {
        return self::$client?->captureException($error, $context);
    }

    /** @param array<string, mixed> $context */
    public static function captureMessage(string $message, string $level = 'warning', array $context = []): ?string
    {
        return self::$client?->captureMessage($message, $level, $context);
    }

    public static function captureTestEvent(): ?string
    {
        return self::$client?->captureTestEvent();
    }

    public static function flush(?float $deadline = null): void
    {
        self::$client?->flush($deadline);
    }
}
