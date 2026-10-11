<?php

namespace Sereno\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Sereno\Laravel\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    private const KEY = 'wsk_AbC123xyz';

    private string $dir;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['accepted' => 1], 202)]);

        $this->dir = sys_get_temp_dir().'/sereno-install-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->envPath = $this->dir.'/.env';
        $this->app->useEnvironmentPath($this->dir);
        $this->app->loadEnvironmentFrom('.env');
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function writeEnv(string $contents): void
    {
        file_put_contents($this->envPath, $contents);
    }

    private function readEnv(): string
    {
        return (string) file_get_contents($this->envPath);
    }

    private function install(array $options = []): PendingCommand
    {
        return $this->artisan('sereno:install', ['key' => self::KEY] + $options);
    }

    public function test_alta_agrega_key_y_stack(): void
    {
        $this->writeEnv("APP_NAME=Test\n");

        $this->install()->assertSuccessful();

        $this->assertSame("APP_NAME=Test\nSERENO_KEY=wsk_AbC123xyz\nLOG_STACK=single,sereno\n", $this->readEnv());
    }

    public function test_agrega_salto_de_linea_si_el_archivo_no_termina_en_uno(): void
    {
        $this->writeEnv('APP_NAME=Test');

        $this->install()->assertSuccessful();

        $this->assertStringStartsWith("APP_NAME=Test\nSERENO_KEY=", $this->readEnv());
    }

    public function test_reemplaza_key_y_url_existentes(): void
    {
        $this->writeEnv("SERENO_KEY=wsk_old\nSERENO_URL=https://old.test\nLOG_STACK=single\n");

        $this->install(['--url' => 'https://sereno.test/api/log/events'])->assertSuccessful();

        $contents = $this->readEnv();
        $this->assertSame(1, substr_count($contents, 'SERENO_KEY='));
        $this->assertSame(1, substr_count($contents, 'SERENO_URL='));
        $this->assertStringContainsString("SERENO_KEY=wsk_AbC123xyz\n", $contents);
        $this->assertStringContainsString("SERENO_URL=https://sereno.test/api/log/events\n", $contents);
        $this->assertStringNotContainsString('wsk_old', $contents);
    }

    public function test_sin_url_no_toca_sereno_url(): void
    {
        $this->writeEnv("SERENO_URL=https://keep.test\n");

        $this->install()->assertSuccessful();

        $this->assertStringContainsString("SERENO_URL=https://keep.test\n", $this->readEnv());
    }

    public function test_log_stack_vacio(): void
    {
        $this->writeEnv("LOG_STACK=\n");

        $this->install()->assertSuccessful();

        $this->assertStringContainsString("LOG_STACK=sereno\n", $this->readEnv());
    }

    public function test_log_stack_con_otros_canales(): void
    {
        $this->writeEnv("LOG_STACK=single, slack\n");

        $this->install()->assertSuccessful();

        $this->assertStringContainsString("LOG_STACK=single,slack,sereno\n", $this->readEnv());
    }

    public function test_log_stack_ya_con_sereno_no_se_duplica(): void
    {
        $this->writeEnv("LOG_STACK=single,sereno\n");

        $this->install()->assertSuccessful();
        $this->install()->assertSuccessful();

        $this->assertSame("LOG_STACK=single,sereno\nSERENO_KEY=wsk_AbC123xyz\n", $this->readEnv());
    }

    public function test_avisa_si_log_channel_no_es_stack(): void
    {
        $this->writeEnv("LOG_CHANNEL=daily\n");

        $this->install()->expectsOutputToContain('LOG_CHANNEL is "daily"')->assertSuccessful();
    }

    public function test_no_avisa_con_log_channel_stack(): void
    {
        $this->writeEnv("LOG_CHANNEL=stack\n");

        $this->install()->doesntExpectOutputToContain('LOG_CHANNEL')->assertSuccessful();
    }

    public function test_clave_invalida_no_escribe(): void
    {
        $this->writeEnv("APP_NAME=Test\n");

        $this->artisan('sereno:install', ['key' => 'abc_123'])->assertFailed();
        $this->artisan('sereno:install', ['key' => 'wsk_'])->assertFailed();

        $this->assertSame("APP_NAME=Test\n", $this->readEnv());
    }

    public function test_url_invalida_no_escribe(): void
    {
        $this->writeEnv("APP_NAME=Test\n");

        $this->install(['--url' => 'not a url'])->assertFailed();

        $this->assertSame("APP_NAME=Test\n", $this->readEnv());
    }

    public function test_crlf_alta_reemplazo_y_stack_sin_duplicados(): void
    {
        $this->writeEnv("APP_NAME=Test\r\nLOG_CHANNEL=daily\r\nLOG_STACK=single,slack\r\nSERENO_KEY=wsk_old\r\n");

        $this->install(['--url' => 'https://sereno.test/x'])->expectsOutputToContain('LOG_CHANNEL is "daily"')->assertSuccessful();
        $this->install()->assertSuccessful();

        $this->assertSame(
            "APP_NAME=Test\r\nLOG_CHANNEL=daily\r\nLOG_STACK=single,slack,sereno\r\nSERENO_KEY=wsk_AbC123xyz\r\nSERENO_URL=https://sereno.test/x\r\n",
            $this->readEnv(),
        );
    }

    public function test_crlf_sin_linea_final_conserva_crlf(): void
    {
        $this->writeEnv("APP_NAME=Test\r\nLOG_STACK=single");

        $this->install()->assertSuccessful();

        $this->assertSame("APP_NAME=Test\r\nLOG_STACK=single,sereno\r\nSERENO_KEY=wsk_AbC123xyz\r\n", $this->readEnv());
    }

    public function test_no_confunde_variables_con_prefijo_ni_comentarios(): void
    {
        $this->writeEnv("SERENO_KEY_OLD=keep\n# SERENO_KEY=wsk_commented\n");

        $this->install()->assertSuccessful();

        $this->assertSame(
            "SERENO_KEY_OLD=keep\n# SERENO_KEY=wsk_commented\nSERENO_KEY=wsk_AbC123xyz\nLOG_STACK=single,sereno\n",
            $this->readEnv(),
        );
    }

    public function test_log_stack_entre_comillas(): void
    {
        $this->writeEnv("LOG_STACK=\"single,slack\"\n");

        $this->install()->assertSuccessful();

        $this->assertSame("LOG_STACK=single,slack,sereno\nSERENO_KEY=wsk_AbC123xyz\n", $this->readEnv());
    }

    public function test_url_con_caracteres_de_interpolacion_se_rechaza(): void
    {
        $this->writeEnv("APP_NAME=Test\n");

        $this->install(['--url' => 'https://sereno.test/${APP_KEY}'])->assertFailed();
        $this->install(['--url' => 'https://sereno.test/a"b'])->assertFailed();

        $this->assertSame("APP_NAME=Test\n", $this->readEnv());
    }

    public function test_sin_env_falla_con_mensaje(): void
    {
        $this->install()->expectsOutputToContain('Cannot write the environment file')->assertFailed();

        $this->assertFileDoesNotExist($this->envPath);
    }
}
