<?php

namespace Sereno\Laravel\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sereno\Laravel\Tests\TestCase;

class ConnectionTestTest extends TestCase
{
    private const KEY = 'wsk_AbC123xyz';

    private const URL = 'https://sereno.test/';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/sereno-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir.'/.env', "APP_NAME=Test\n");
        $this->app->useEnvironmentPath($this->dir);
        $this->app->loadEnvironmentFrom('.env');
        config(['sereno.key' => self::KEY, 'sereno.url' => self::URL]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/.env');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function fakeStatus(int $status): void
    {
        Http::fake(['*' => Http::response(['message' => 'x'], $status)]);
    }

    private function install(array $options = [])
    {
        return $this->artisan('sereno:install', ['key' => self::KEY, '--url' => self::URL] + $options);
    }

    public function test_test_conectado(): void
    {
        $this->fakeStatus(202);

        $this->artisan('sereno:test')->expectsOutputToContain('Connected')->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $event = $request['events'][0] ?? [];

            return $request->url() === 'https://sereno.test/api/ingest/events'
                && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
                && count($request['events']) === 1
                && $event['level'] === 'info'
                && $event['message'] === 'Sereno connection test'
                && preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\z/', $event['occurred_at']) === 1;
        });
    }

    public function test_test_key_invalida(): void
    {
        $this->fakeStatus(401);

        $this->artisan('sereno:test')->expectsOutputToContain('401')->assertFailed();
    }

    public function test_test_error_de_red(): void
    {
        Http::fake(fn () => throw new ConnectionException('boom'));

        $this->artisan('sereno:test')->expectsOutputToContain('boom')->assertFailed();
    }

    public function test_test_otro_estado_http(): void
    {
        $this->fakeStatus(500);

        $this->artisan('sereno:test')->expectsOutputToContain('HTTP 500')->assertFailed();
    }

    public function test_test_redireccion_falla_con_detalle_claro(): void
    {
        Http::fake(['*' => Http::response('', 301, ['Location' => 'https://sereno.test/api/ingest/events'])]);

        $this->artisan('sereno:test')->expectsOutputToContain('HTTP 301, check the URL scheme')->assertFailed();
    }

    public function test_test_sin_url_no_envia(): void
    {
        config(['sereno.url' => null]);
        Http::fake();

        $this->artisan('sereno:test')->expectsOutputToContain('URL is missing')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_install_conectado(): void
    {
        $this->fakeStatus(202);

        $this->install()->expectsOutputToContain('Connected')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_install_key_invalida_conserva_la_configuracion(): void
    {
        $this->fakeStatus(401);

        $this->install()->expectsOutputToContain('401')->assertSuccessful();

        $this->assertStringContainsString('SERENO_KEY='.self::KEY, (string) file_get_contents($this->dir.'/.env'));
    }

    public function test_install_error_de_red(): void
    {
        Http::fake(fn () => throw new ConnectionException('boom'));

        $this->install()->expectsOutputToContain('boom')->assertSuccessful();
    }

    public function test_install_sin_url_no_envia(): void
    {
        config(['sereno.url' => null]);
        Http::fake();

        $this->artisan('sereno:install', ['key' => self::KEY])->expectsOutputToContain('URL is missing')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_install_usa_la_url_del_env_si_no_llega_option(): void
    {
        config(['sereno.url' => null]);
        file_put_contents($this->dir.'/.env', "SERENO_URL=https://other.test\n");
        $this->fakeStatus(202);

        $this->artisan('sereno:install', ['key' => self::KEY])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://other.test/api/ingest/events');
    }

    public function test_install_no_test_omite_el_envio(): void
    {
        Http::fake();

        $this->install(['--no-test' => true])->assertSuccessful();

        Http::assertNothingSent();
    }
}
