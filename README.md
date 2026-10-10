# sereno-laravel

Sends Laravel logs to a Sereno project channel. It adds a Monolog handler and a ready-to-use `sereno` log channel
that posts events to the Sereno ingest endpoint (`POST {SERENO_LOG_URL}/api/ingest/events`) with a project API key.

Requirements: PHP 8.3+ and Laravel 13.

## Installation

The package is not on Packagist. Install it from its Gitea repository with a `vcs` repository entry:

```bash
composer config repositories.sereno vcs https://gitea.ascario.dev/ascario/sereno-laravel.git
composer require ascario/sereno-laravel:^0.1
```

This adds the following to your `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://gitea.ascario.dev/ascario/sereno-laravel.git" }
]
```

The service provider is registered by Laravel package auto-discovery.

### Private repository access

If the repository is private, Composer needs credentials for the Gitea host. Composer has no dedicated Gitea token
setting (`gitea-token` does not exist; `forgejo-token` is for Forgejo). Use `http-basic` with your Gitea username
and a personal access token (Gitea: Settings, Applications, with the `read:repository` scope) as the password:

```bash
composer config --global http-basic.gitea.ascario.dev YOUR_USERNAME YOUR_TOKEN
```

This writes to the global `auth.json` (`~/.composer/auth.json` or `~/.config/composer/auth.json`). In CI, keep the
same structure in a project `auth.json` that is not committed, or set it through the `COMPOSER_AUTH` environment
variable:

```json
{
    "http-basic": {
        "gitea.ascario.dev": { "username": "YOUR_USERNAME", "password": "YOUR_TOKEN" }
    }
}
```

Never commit `auth.json` or the token.

## Configuration

Create a project in Sereno, open its Integrations tab and create a source. The dialog shows the API key once, with
the values below. Add them to `.env`:

| Variable | Default | Description |
|---|---|---|
| `SERENO_LOG_URL` | none | Base URL of the Sereno API, without a path (for example `https://api.example.com`). |
| `SERENO_LOG_KEY` | none | The source API key, sent as a Bearer token. |
| `SERENO_LOG_TIMEOUT` | `2` | Connect and total timeout in seconds per request. Capped at `10`; a missing, non numeric or non positive value falls back to `2`. |
| `SERENO_LOG_LEVEL` | `debug` | Minimum level sent (any Monolog/PSR level name). |
| `SERENO_LOG_FALLBACK_CHANNEL` | `daily` | Log channel that receives a warning when delivery fails. An empty value falls back to `daily`. |

Without `SERENO_LOG_URL` or `SERENO_LOG_KEY` the channel is inert: nothing is buffered and no HTTP request is made.

To customize the configuration, publish it:

```bash
php artisan vendor:publish --tag=sereno-config
```

This copies `config/sereno.php` to your application. If you run `config:cache`, remember to rebuild it after
changing the variables.

## Usage

The package registers a `sereno` channel in `logging.channels` unless your application already defines one. It does
not touch your default channel: add `sereno` to the `stack` yourself in `config/logging.php`:

```php
'stack' => [
    'driver' => 'stack',
    'channels' => ['daily', 'sereno'],
    'ignore_exceptions' => false,
],
```

Or send to it directly:

```php
Log::channel('sereno')->error('Payment failed', ['order_id' => 42]);
```

To define the channel yourself (for example with another level), use the factory:

```php
'sereno' => [
    'driver' => 'custom',
    'via' => Ascario\Sereno\Logging\CreateSerenoLogger::class,
    'level' => 'warning',
],
```

The channel `level` overrides `SERENO_LOG_LEVEL`.

### Events

Each record becomes an event with `level`, `message`, `occurred_at` (UTC), optional `context` and optional
`fingerprint`. The fingerprint groups repeated events: pass `['fingerprint' => '...']` in the context, or log an
exception under the `exception` key (the default for `Log::error($e->getMessage(), ['exception' => $e])`), which
uses the exception class, file and line. Limits keep the payload valid for the API: messages are cut at 8192
characters, context at 32 KB, 8 levels of depth and 50 items per array, and long strings are shortened. When
anything is cut, the context carries `_truncated: true`.

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
