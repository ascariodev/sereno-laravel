<?php

namespace Ascario\Sereno\Tests\Unit\Logging;

use Ascario\Sereno\Logging\EventFormatter;
use Ascario\Sereno\Logging\SerenoLogHandler;
use Ascario\Sereno\Tests\TestCase;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use RuntimeException;

class SerenoLogHandlerTest extends TestCase
{
    private const URL = 'http://sereno.test';

    private const KEY = 'wsk_prueba';

    private TestHandler $fallbackRecords;

    /** @var list<Closure> */
    private array $shutdownCallbacks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fallbackRecords = new TestHandler;
        Http::preventStrayRequests();
    }

    public function test_envia_el_lote_al_cerrar_con_el_token_bearer(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 2], 202)]);
        [$logger, $handler] = $this->logger();

        $logger->error('Fallo SICM', ['order' => 7]);
        $logger->info('Tasa BCV actualizada');

        Http::assertNothingSent();

        $handler->close();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $events = $request['events'];

            return $request->url() === self::URL.'/api/ingest/events'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
                && count($events) === 2
                && $events[0]['level'] === 'error'
                && $events[0]['message'] === 'Fallo SICM'
                && $events[0]['context'] === ['order' => 7]
                && $events[1]['level'] === 'info';
        });
        $this->assertFalse($this->fallbackRecords->hasRecords(Level::Warning));
    }

    public function test_corta_en_lotes_de_100(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 100], 202)]);
        [$logger, $handler] = $this->logger();

        for ($i = 1; $i <= 99; $i++) {
            $logger->warning("evento {$i}");
        }

        Http::assertNothingSent();

        $logger->warning('evento 100');
        Http::assertSentCount(1);

        for ($i = 101; $i <= 130; $i++) {
            $logger->warning("evento {$i}");
        }

        $handler->close();

        $sizes = Http::recorded()->map(fn (array $pair) => count($pair[0]['events']))->all();
        $this->assertSame([100, 30], $sizes);
    }

    public function test_cerrar_o_reiniciar_varias_veces_no_reenvia(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1], 202)]);
        [$logger, $handler] = $this->logger();

        $logger->error('una vez');
        $handler->reset();
        $handler->close();
        $handler->close();
        $this->app->terminate();

        Http::assertSentCount(1);
    }

    public function test_envia_al_terminar_la_aplicacion_y_en_cada_vuelta_del_worker(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1], 202)]);
        [$logger] = $this->logger();

        $logger->error('desde una petición o un comando');
        $this->app->terminate();
        Http::assertSentCount(1);

        $logger->error('desde un job');
        event(new Looping('redis', 'default'));
        Http::assertSentCount(2);
    }

    public function test_envia_en_el_apagado_tras_un_error_fatal_sin_terminate(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1], 202)]);
        [$logger, $handler] = $this->logger();

        $this->assertCount(1, $this->shutdownCallbacks);

        $logger->critical('Allowed memory size exhausted');
        Http::assertNothingSent();

        $this->shutdownCallbacks[0]();
        Http::assertSentCount(1);
        $this->assertSame('Allowed memory size exhausted', Http::recorded()->first()[0]['events'][0]['message']);

        $this->shutdownCallbacks[0]();
        $handler->close();
        $this->app->terminate();
        event(new Looping('redis', 'default'));
        Http::assertSentCount(1);
    }

    public function test_envia_al_destruir_el_handler(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1], 202)]);
        [$logger, $handler] = $this->logger();

        $logger->error('pendiente al destruir');
        unset($logger, $handler);

        Http::assertSentCount(1);

        $this->shutdownCallbacks[0]();
        $this->app->terminate();
        Http::assertSentCount(1);
    }

    public function test_un_formatter_que_falla_no_lanza_y_escribe_en_el_respaldo(): void
    {
        Http::fake();
        $formatter = new class extends EventFormatter
        {
            public function format(LogRecord $record): array
            {
                throw new RuntimeException('formato roto');
            }
        };
        $handler = new SerenoLogHandler(
            self::URL,
            self::KEY,
            formatter: $formatter,
            fallback: new Logger('daily', [$this->fallbackRecords]),
            shutdownRegistrar: $this->captureShutdown(...),
        );
        $logger = new Logger('critical_alerts', [$handler]);

        $logger->error('no se puede formatear');
        $handler->close();
        $this->shutdownCallbacks[0]();

        Http::assertNothingSent();
        $this->assertTrue($this->fallbackRecords->hasWarningThatContains('Sereno log event could not be formatted'));
        $this->assertSame('formato roto', $this->fallbackRecords->getRecords()[0]->context['error']);
    }

    public function test_error_http_no_lanza_y_escribe_en_el_respaldo(): void
    {
        Http::fake(['*' => Http::response(['message' => 'invalid'], 422)]);
        [$logger, $handler] = $this->logger();

        $logger->error('rechazado');
        $handler->close();

        Http::assertSentCount(1);
        $this->assertTrue($this->fallbackRecords->hasWarningThatContains('Sereno log delivery failed'));
        $this->assertSame(422, $this->fallbackRecords->getRecords()[0]->context['status']);
    }

    public function test_timeout_no_lanza_y_escribe_en_el_respaldo(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);
        [$logger, $handler] = $this->logger();

        $logger->critical('sin conexión');
        $handler->close();

        $this->assertTrue($this->fallbackRecords->hasWarningThatContains('Sereno log delivery failed'));
        $this->assertCount(1, $this->fallbackRecords->getRecords());
    }

    public function test_usa_timeout_corto_sin_reintentos(): void
    {
        $options = null;
        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response(status: 500);
        });
        [$logger, $handler] = $this->logger();

        $logger->error('x');
        $handler->close();

        Http::assertSentCount(1);
        $this->assertSame(SerenoLogHandler::TIMEOUT_SECONDS, $options['timeout']);
        $this->assertSame(SerenoLogHandler::TIMEOUT_SECONDS, $options['connect_timeout']);
    }

    public function test_usa_el_timeout_recibido_y_cae_a_2_si_es_invalido(): void
    {
        $casos = [[5, 5], ['7', 7], [1.5, 1.5], [0, 2], [-3, 2], ['abc', 2], ['', 2], [null, 2], [999, 10], ['1e999', 10]];

        foreach ($casos as [$recibido, $esperado]) {
            $options = null;
            Http::fake(function (Request $request, array $requestOptions) use (&$options) {
                $options = $requestOptions;

                return Http::response(['accepted' => 1], 202);
            });
            $handler = new SerenoLogHandler(
                self::URL,
                self::KEY,
                fallback: new Logger('daily', [$this->fallbackRecords]),
                shutdownRegistrar: $this->captureShutdown(...),
                timeout: $recibido,
            );

            (new Logger('critical_alerts', [$handler]))->error('x');
            $handler->close();

            $this->assertSame($esperado, $options['timeout']);
            $this->assertSame($esperado, $options['connect_timeout']);
        }
    }

    public function test_un_respaldo_que_pasa_por_el_mismo_handler_no_genera_recursion(): void
    {
        Http::fake(['*' => Http::response(status: 503)]);

        $fallback = new Logger('daily', [$this->fallbackRecords]);
        $recursive = new SerenoLogHandler(self::URL, self::KEY, fallback: $fallback);
        $fallback->pushHandler($recursive);
        $logger = new Logger('critical_alerts', [$recursive]);

        $logger->error('falla y se reporta');
        $recursive->close();
        $recursive->close();

        Http::assertSentCount(1);
        $this->assertCount(1, $this->fallbackRecords->getRecords());
    }

    public function test_lo_que_se_loguea_durante_el_envio_no_vuelve_al_handler(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1], 202)]);
        [$logger, $handler] = $this->logger();
        Event::listen(ResponseReceived::class, fn () => $logger->error('log desde un listener del cliente HTTP'));

        $logger->error('original');
        $handler->close();
        $handler->close();

        Http::assertSentCount(1);
        $this->assertCount(1, Http::recorded()->first()[0]['events']);
    }

    public function test_sin_url_o_key_no_envia_nada(): void
    {
        Http::fake();

        foreach ([[null, self::KEY], [self::URL, null], ['', self::KEY], [self::URL, '  ']] as [$url, $key]) {
            $handler = new SerenoLogHandler($url, $key, fallback: new Logger('daily', [$this->fallbackRecords]));
            $logger = new Logger('critical_alerts', [$handler]);

            $logger->error('no debe salir');
            $handler->close();
            $this->assertFalse($handler->isHandling($this->record()));
        }

        $this->app->terminate();
        Http::assertNothingSent();
        $this->assertFalse($this->fallbackRecords->hasRecords(Level::Warning));
    }

    public function test_respeta_el_nivel_minimo(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1], 202)]);
        $handler = new SerenoLogHandler(self::URL, self::KEY, Level::Error, fallback: new Logger('daily', [$this->fallbackRecords]));
        $logger = new Logger('critical_alerts', [$handler]);

        $logger->info('debajo del mínimo');
        $handler->close();
        Http::assertNothingSent();

        $logger->error('encima del mínimo');
        $handler->close();
        Http::assertSentCount(1);
    }

    public function test_el_respaldo_usa_el_canal_daily_por_defecto(): void
    {
        Http::fake(['*' => Http::response(status: 500)]);
        Log::shouldReceive('channel')->once()->with('daily')->andReturn(new Logger('daily', [$this->fallbackRecords]));
        $handler = new SerenoLogHandler(self::URL, self::KEY, shutdownRegistrar: $this->captureShutdown(...));

        (new Logger('critical_alerts', [$handler]))->error('x');
        $handler->close();

        $this->assertTrue($this->fallbackRecords->hasWarningThatContains('Sereno log delivery failed'));
    }

    public function test_el_respaldo_usa_el_canal_configurado(): void
    {
        Http::fake(['*' => Http::response(status: 500)]);
        Log::shouldReceive('channel')->once()->with('stderr')->andReturn(new Logger('stderr', [$this->fallbackRecords]));
        $handler = new SerenoLogHandler(
            self::URL,
            self::KEY,
            shutdownRegistrar: $this->captureShutdown(...),
            fallbackChannel: 'stderr',
        );

        (new Logger('critical_alerts', [$handler]))->error('x');
        $handler->close();

        $this->assertTrue($this->fallbackRecords->hasWarningThatContains('Sereno log delivery failed'));
    }

    /**
     * @return array{Logger, SerenoLogHandler}
     */
    private function logger(): array
    {
        $handler = new SerenoLogHandler(
            self::URL,
            self::KEY,
            fallback: new Logger('daily', [$this->fallbackRecords]),
            shutdownRegistrar: $this->captureShutdown(...),
        );

        return [new Logger('critical_alerts', [$handler]), $handler];
    }

    private function captureShutdown(Closure $callback): void
    {
        $this->shutdownCallbacks[] = $callback;
    }

    private function record(): LogRecord
    {
        return new LogRecord(new DateTimeImmutable, 'test', Level::Error, 'x');
    }
}
