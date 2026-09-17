# anvil-feed-php

The Anvil Feed SDK for PHP. It turns an uncaught exception into one event of the Anvil Feed Protocol
(schema `1.0`) and posts it to the ingest described in `docs/anvil-feed-ingest.md`.

Zero framework dependencies: four PSR interfaces and nothing else. The Laravel wiring is a separate
package.

## The parts

| Class | What it does |
|---|---|
| `AnvilFeed\Config` | Every option, every default, every repair. `fromArray()` / `fromEnvironment()`. |
| `AnvilFeed\EventFactory` | A `Throwable` in, a scrubbed and bounded `AnvilFeed\Event` out. |
| `AnvilFeed\Scrubber` | FR-5. Nothing on the denylist reaches the wire. |
| `AnvilFeed\Queue` | A bounded FIFO. The oldest goes when it is full, and every drop is a log line. |
| `AnvilFeed\Sender` | The retry rules, read off the server's own status table. |
| `AnvilFeed\Transport\PsrTransport` | The host application's own PSR-18 client. |
| `AnvilFeed\Transport\DirectTransport` | The bundled fallback for a tree with no HTTP client at all. |

```php
$config  = AnvilFeed\Config::fromEnvironment();
$logger  = new AnvilFeed\Logger();                  // ONE per process — see below
$queue   = new AnvilFeed\Queue($config->maxQueueSize, $logger);
$sender  = new AnvilFeed\Sender($config, $queue, new AnvilFeed\Transport\DirectTransport($config), $logger);

$queue->push($factory->fromThrowable($error));
$sender->flush();                                   // bounded by request_timeout_ms
```

One `Logger`, one `Sender`, one `Queue` per process. The `Sender` holds the pause, the backoff and the
"stopped" state; a second one would start every rate limit again from zero.

## What this package promises, and the one requirement it cannot reach alone

Nothing here ever throws into the host application, nothing here follows a redirect, no request runs past
`request_timeout_ms`, and a `flush()` is bounded by its deadline **including** the request in flight.

**FR-3 (a) — "0 events lost across a five-minute outage below `max_queue_size`" — is not reachable in the
per-request PHP model, and this package does not pretend otherwise.**

The queue is in memory. In PHP-FPM or mod_php the process *is* the request: when the ingest answers `429`
or a connection fails, the sender parks the events and sets a deadline, and then the worker finishes the
response and the memory goes with it. Buffering therefore survives only as long as the process does —
useful within one request, and gone at the end of it. A CLI worker or a long-running server (Swoole,
RoadRunner, FrankenPHP) keeps the queue for its whole lifetime, which is where the pause and the backoff
earn their keep; a web request does not.

What closes the gap is a **durable** buffer behind the same `Transport` interface, and that is the
Laravel package's job (the queue-backed transport, Task 5): the event is handed to the framework's queue
instead of to a socket, a worker drains it, and a five-minute outage costs a retry rather than an event.
Until that transport is wired, treat the numbers here as "no event is lost *while the process lives*".

Two other things a host has to configure, because this package cannot do it for them:

- **A PSR-18 client passed to `PsrTransport` must have its own timeout at or below
  `request_timeout_ms`.** PSR-18 has no timeout knob, so the budget cannot be enforced from here.
- **…and it must not follow redirects** (Guzzle: `['allow_redirects' => false]`). Following one presents
  the `anv_srv_` token to a host nobody configured. `DirectTransport` never follows one on either path.

## Tests

```
composer install
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite conformance
```

The conformance suite reads `schema/v1.0/event.schema.json` and `conformance/` from the repository root:
it holds this SDK against the shared fixtures rather than against a transcription of them. The transport
tests start a local `php -S` and fail — never skip — if they cannot.
