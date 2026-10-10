<?php

namespace Ascario\Sereno\Tests\Feature;

use Ascario\Sereno\Logging\CreateSerenoLogger;
use Ascario\Sereno\Logging\SerenoLogHandler;
use Ascario\Sereno\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SerenoIntegrationTest extends TestCase
{
    private const URL = 'http://sereno.test';

    private const KEY = 'srk_prueba';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_registra_el_canal_y_la_config(): void
    {
        $this->assertSame('custom', config('logging.channels.sereno.driver'));
        $this->assertSame(CreateSerenoLogger::class, config('logging.channels.sereno.via'));
        $this->assertSame('daily', config('sereno.fallback_channel'));
        $this->assertSame(2, config('sereno.timeout'));
    }

    public function test_no_pisa_un_canal_definido_por_la_app(): void
    {
        $this->app['config']->set('logging.channels.sereno', ['driver' => 'single', 'path' => '/tmp/x.log']);
        (new \Ascario\Sereno\SerenoServiceProvider($this->app))->register();

        $this->assertSame('single', config('logging.channels.sereno.driver'));
    }

    public function test_el_canal_envia_con_la_config(): void
    {
        $options = null;
        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response(['accepted' => 1], 202);
        });
        config(['sereno.url' => self::URL, 'sereno.key' => self::KEY, 'sereno.timeout' => '6', 'logging.channels.sereno.level' => 'info']);
        Log::forgetChannel('sereno');

        Log::channel('sereno')->debug('Ruido');
        Log::channel('sereno')->error('Fallo', ['order' => 7]);
        $this->flush();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL.'/api/ingest/events'
            && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
            && count($request['events']) === 1
            && $request['events'][0]['message'] === 'Fallo');
        $this->assertSame(6, $options['timeout']);
    }

    public function test_sin_url_o_key_no_hace_http(): void
    {
        Http::fake();
        config(['sereno.url' => self::URL, 'sereno.key' => null]);
        Log::forgetChannel('sereno');

        Log::channel('sereno')->error('Fallo');
        $this->flush();

        Http::assertNothingSent();
    }

    public function test_fallback_channel_vacio_se_normaliza_a_daily(): void
    {
        config(['sereno.url' => self::URL, 'sereno.key' => self::KEY, 'sereno.fallback_channel' => '  ']);

        $logger = (new CreateSerenoLogger)(['level' => 'debug']);
        $handler = $logger->getHandlers()[0];

        $this->assertInstanceOf(SerenoLogHandler::class, $handler);
        $property = new \ReflectionProperty($handler, 'fallbackChannel');
        $this->assertSame('daily', $property->getValue($handler));
    }

    private function flush(): void
    {
        foreach (Log::channel('sereno')->getLogger()->getHandlers() as $handler) {
            $handler->close();
        }
    }
}
