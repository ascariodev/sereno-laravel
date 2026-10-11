<?php

namespace Sereno\Laravel\Tests\Feature;

use Sereno\Laravel\SerenoServiceProvider;
use Sereno\Laravel\Tests\TestCase;

class ConfigEnvTest extends TestCase
{
    private const VARIABLES = [
        'SERENO_URL', 'SERENO_KEY', 'SERENO_TIMEOUT', 'SERENO_LEVEL', 'SERENO_FALLBACK_CHANNEL',
        'SERENO_LOG_URL', 'SERENO_LOG_KEY', 'SERENO_LOG_TIMEOUT', 'SERENO_LOG_LEVEL', 'SERENO_LOG_FALLBACK_CHANNEL',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearVariables();
    }

    protected function tearDown(): void
    {
        $this->clearVariables();
        parent::tearDown();
    }

    private function clearVariables(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    private function setVariables(array $values): void
    {
        foreach ($values as $name => $value) {
            putenv("{$name}={$value}");
        }
    }

    private function loadConfig(): array
    {
        return require __DIR__.'/../../config/sereno.php';
    }

    public function test_sin_variables_usa_los_valores_por_defecto(): void
    {
        $config = $this->loadConfig();

        $this->assertSame(SerenoServiceProvider::DEFAULT_URL, $config['url']);
        $this->assertNull($config['key']);
        $this->assertSame(2, $config['timeout']);
        $this->assertSame('debug', $config['level']);
        $this->assertSame('daily', $config['fallback_channel']);
    }

    public function test_las_variables_nuevas_configuran_el_canal(): void
    {
        $this->setVariables([
            'SERENO_URL' => 'https://sereno.test',
            'SERENO_KEY' => 'wsk_new',
            'SERENO_TIMEOUT' => '5',
            'SERENO_LEVEL' => 'error',
            'SERENO_FALLBACK_CHANNEL' => 'single',
        ]);

        $this->assertSame([
            'url' => 'https://sereno.test',
            'key' => 'wsk_new',
            'timeout' => '5',
            'level' => 'error',
            'fallback_channel' => 'single',
        ], $this->loadConfig());
    }

    public function test_las_variables_viejas_siguen_valiendo(): void
    {
        $this->setVariables([
            'SERENO_LOG_URL' => 'https://old.test',
            'SERENO_LOG_KEY' => 'wsk_old',
            'SERENO_LOG_TIMEOUT' => '7',
            'SERENO_LOG_LEVEL' => 'warning',
            'SERENO_LOG_FALLBACK_CHANNEL' => 'stderr',
        ]);

        $this->assertSame([
            'url' => 'https://old.test',
            'key' => 'wsk_old',
            'timeout' => '7',
            'level' => 'warning',
            'fallback_channel' => 'stderr',
        ], $this->loadConfig());
    }

    public function test_las_nuevas_tienen_prioridad_sobre_las_viejas(): void
    {
        $this->setVariables([
            'SERENO_URL' => 'https://new.test',
            'SERENO_LOG_URL' => 'https://old.test',
            'SERENO_KEY' => 'wsk_new',
            'SERENO_LOG_KEY' => 'wsk_old',
            'SERENO_TIMEOUT' => '5',
            'SERENO_LOG_TIMEOUT' => '7',
            'SERENO_LEVEL' => 'error',
            'SERENO_LOG_LEVEL' => 'warning',
            'SERENO_FALLBACK_CHANNEL' => 'single',
            'SERENO_LOG_FALLBACK_CHANNEL' => 'stderr',
        ]);

        $this->assertSame([
            'url' => 'https://new.test',
            'key' => 'wsk_new',
            'timeout' => '5',
            'level' => 'error',
            'fallback_channel' => 'single',
        ], $this->loadConfig());
    }
}
