# Sereno for Laravel

Sends Laravel logs to a Sereno project channel. It adds a Monolog handler and a ready-to-use `sereno` log channel
that posts events to the Sereno ingest endpoint (`POST {SERENO_URL}/api/ingest/events`) with a source API key.

Source: https://github.com/ascariodev/sereno-laravel

## Requirements

- PHP 8.3 or newer
- Laravel 13 (`illuminate/*` `^13.0`)

## Installation

Create a project in Sereno, open its Integrations page and choose "Create log source". The dialog shows the source
key (`wsk_...`) only once; if you lose it, rotate the key. Then, in your Laravel application:

```bash
composer require ascariodev/sereno-laravel
php artisan sereno:install wsk_your_key --url=https://your-sereno-api.example.com
```

The service provider is registered by Laravel package auto-discovery.

`--url` is the base URL of the Sereno API, without a path. The command accepts it missing, but pass it for now:
the package has no default URL yet, and without a URL the channel sends nothing (the test reports the missing URL).

### What `sereno:install` does

It edits only your `.env` file (never PHP files):

- Writes or replaces `SERENO_KEY`, and `SERENO_URL` when `--url` is given.
- Adds `sereno` to `LOG_STACK` without duplicating it. If `LOG_STACK` is missing it is treated as `single`, so the
  result is `single,sereno`.
- Then sends a test event to Sereno and reports the result (see below).

Options:

| Option | Description |
|---|---|
| `--url=` | Base URL of the Sereno API. Must be a valid URL without `$`, quotes, backslashes or backticks. |
| `--no-test` | Skip the test event. |

The command fails (exit code 1) when the key does not match `wsk_` followed by letters and digits, when `--url` is
not a valid URL, or when there is no writable `.env` file (create it with `cp .env.example .env`). Nothing is
written in those cases.

**If `.env` defines `LOG_CHANNEL` and it is not `stack`**, the installer warns you (no warning when the variable is absent): the `LOG_STACK` change only takes effect through
the `stack` channel. Either set `LOG_CHANNEL=stack`, or add `sereno` to the channel you use in `config/logging.php`,
or send to it directly:

```php
Log::channel('sereno')->error('Payment failed', ['order_id' => 42]);
```

Laravel's default `stack` channel reads `LOG_STACK` from the environment. If your `config/logging.php` has a
hardcoded `channels` list for `stack`, add `'sereno'` to it yourself.

If you run `config:cache`, rebuild it after the install.

### Testing the connection

`sereno:install` ends by sending a test event (level `info`, message "Sereno connection test") to
`{url}/api/ingest/events` with a 5 second timeout, using the key and URL it just wrote (the URL is `--url`, then
`SERENO_URL` from `.env`, then the configured one). The outcome is one of:

- Connected: Sereno answered `202`.
- Key rejected: `401`, the key is invalid or has been revoked.
- Missing URL or key: nothing is sent.
- Delivery error: network failure, timeout or any other HTTP status.

`sereno:install` exits with code 0 even when the test fails, because the `.env` file is already written. Read the
message, fix the cause and run the test again whenever you want:

```bash
php artisan sereno:test
```

`sereno:test` uses the current configuration (`SERENO_KEY` and `SERENO_URL`) and exits with code 0 when Sereno
accepts the event and 1 in any other case.

## Configuration

| Variable | Fallback | Default | Description |
|---|---|---|---|
| `SERENO_URL` | `SERENO_LOG_URL` | none | Base URL of the Sereno API, without a path (for example `https://api.example.com`). |
| `SERENO_KEY` | `SERENO_LOG_KEY` | none | The source API key, sent as a Bearer token. |
| `SERENO_TIMEOUT` | `SERENO_LOG_TIMEOUT` | `2` | Connect and total timeout in seconds per request. Capped at `10`; a missing, non numeric or non positive value falls back to `2`. |
| `SERENO_LEVEL` | `SERENO_LOG_LEVEL` | `debug` | Minimum level sent (any Monolog/PSR level name). |
| `SERENO_FALLBACK_CHANNEL` | `SERENO_LOG_FALLBACK_CHANNEL` | `daily` | Log channel that receives a warning when delivery fails. An empty value falls back to `daily`. |

The `SERENO_LOG_*` names from 0.1 keep working as a fallback during 0.x, but when both are set the `SERENO_*` value
wins. Note that a variable that exists but is empty (`SERENO_KEY=`) overrides its fallback.

Without a URL or a key the channel is inert: nothing is buffered and no HTTP request is made.

To customize the configuration, publish it:

```bash
php artisan vendor:publish --tag=sereno-config
```

This copies `config/sereno.php` to your application.

## Usage

The package registers a `sereno` channel in `logging.channels` unless your application already defines one. It does
not touch your default channel: `sereno:install` adds it to `LOG_STACK`, or add it to your own stack manually:

```php
'stack' => [
    'driver' => 'stack',
    'channels' => ['daily', 'sereno'],
    'ignore_exceptions' => false,
],
```

To define the channel yourself (for example with another level), use the factory:

```php
'sereno' => [
    'driver' => 'custom',
    'via' => Sereno\Laravel\Logging\CreateSerenoLogger::class,
    'level' => 'warning',
],
```

The channel `level` overrides `SERENO_LEVEL`.

### Events

Each record becomes an event with `id`, `level`, `message`, `occurred_at` (UTC), optional `context` and optional
`fingerprint`. The fingerprint groups repeated events: pass `['fingerprint' => '...']` in the context, or log an
exception under the `exception` key (the default for `Log::error($e->getMessage(), ['exception' => $e])`), which
uses the exception class, file and line. Limits keep the payload valid for the API: messages are cut at 8192
characters, context at 32 KB, 8 levels of depth and 50 items per array, and long strings are shortened. When
anything is cut, the context carries `_truncated: true`.

The `id` is a UUID generated once when the record is formatted, so it stays the same if the same batch is sent
again. In the API the `id` is optional (1 to 64 characters, unique per log source): an event whose `id` was already
received from the same source is accepted but discarded, so resending a batch never duplicates events or inflates
the group counters. Events sent without an `id` get one from the server and are not protected against resends.

## Delivery behavior

- **Buffered and batched.** Records are buffered in memory and sent in batches of at most 100 events per request.
  Reaching 100 buffered events flushes immediately.
- **No retries.** Each batch is sent once. Logging never throws and never blocks the request beyond the timeout.
- **Flush points.** The buffer is flushed when the application terminates (after the response in PHP-FPM), on every
  queue worker `Looping` event (workers never terminate between jobs), when the handler is closed or reset, and in a
  PHP shutdown function (covers fatal errors that skip normal termination). Events still buffered when the process
  is killed are lost.
- **Fallback channel.** If a request fails (network error, timeout or a non 2xx response) or an event cannot be
  formatted, a warning with the reason is written to the fallback channel (`daily` by default) and the events of
  that batch are dropped. While Sereno is sending or reporting a failure, records that reach a Sereno handler are
  ignored by it, so a failure can never loop.

## License

MIT. See [LICENSE](LICENSE).
