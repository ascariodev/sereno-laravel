<?php

namespace Sereno\Laravel\Tests\Feature;

use Sereno\Laravel\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('logging.channels.sereno', ['driver' => 'single', 'path' => '/tmp/x.log']);
    }

    public function test_no_pisa_un_canal_definido_por_la_app(): void
    {
        $this->assertSame('single', config('logging.channels.sereno.driver'));
        $this->assertNull(config('logging.channels.sereno.via'));
    }

    public function test_publica_la_config(): void
    {
        $target = config_path('sereno.php');
        @unlink($target);

        try {
            $this->artisan('vendor:publish', ['--tag' => 'sereno-config'])->assertSuccessful();

            $this->assertFileExists($target);
        } finally {
            @unlink($target);
        }
    }
}
