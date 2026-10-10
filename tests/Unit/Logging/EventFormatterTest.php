<?php

namespace Ascario\Sereno\Tests\Unit\Logging;

use Ascario\Sereno\Logging\EventFormatter;
use Ascario\Sereno\Tests\TestCase;
use DateTimeImmutable;
use DateTimeZone;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;

class EventFormatterTest extends TestCase
{
    public function test_convierte_un_registro_simple(): void
    {
        $event = $this->format($this->record('Pago recibido', Level::Warning, ['order' => 15]));

        $this->assertSame('warning', $event['level']);
        $this->assertSame('Pago recibido', $event['message']);
        $this->assertSame(['order' => 15], $event['context']);
        $this->assertArrayNotHasKey('fingerprint', $event);
        $this->assertSame('2026-10-07T15:30:45.123Z', $event['occurred_at']);
    }

    public function test_mapea_todos_los_niveles_a_psr3(): void
    {
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

        foreach (Level::cases() as $level) {
            $this->assertContains($this->format($this->record('x', $level))['level'], $levels);
        }
    }

    public function test_recorta_el_mensaje_por_caracteres_y_no_por_bytes(): void
    {
        $event = $this->format($this->record(str_repeat('😀', 9000)));

        $this->assertSame(8192, mb_strlen($event['message']));
        $this->assertTrue(mb_check_encoding($event['message'], 'UTF-8'));
    }

    public function test_mensaje_vacio_o_con_byte_nulo_sigue_siendo_valido(): void
    {
        $this->assertSame('-', $this->format($this->record(''))['message']);
        $this->assertSame('ab', $this->format($this->record("a\0b"))['message']);
        $this->assertSame('-', $this->format($this->record("  \n"))['message']);
    }

    public function test_fingerprint_de_solo_espacios_se_ignora_y_aplica_el_de_la_excepcion(): void
    {
        $exception = new RuntimeException('boom');

        $event = $this->format($this->record('x', context: ['fingerprint' => "  \n", 'exception' => $exception]));
        $this->assertSame(RuntimeException::class.'|'.$exception->getFile().':'.$exception->getLine(), $event['fingerprint']);

        $event = $this->format($this->record('x', context: ['fingerprint' => '   ']));
        $this->assertArrayNotHasKey('fingerprint', $event);

        $event = $this->format($this->record('x', context: ['fingerprint' => ' abc ', 'exception' => $exception]));
        $this->assertSame('abc', $event['fingerprint']);
    }

    public function test_espacios_unicode_cuentan_como_vacio_en_mensaje_y_fingerprint(): void
    {
        $blank = "\u{00A0}\u{200B}\u{FEFF}\u{2003}";

        $this->assertSame('-', $this->format($this->record($blank))['message']);

        $event = $this->format($this->record('x', context: ['fingerprint' => $blank]));
        $this->assertArrayNotHasKey('fingerprint', $event);

        $event = $this->format($this->record('x', context: ['fingerprint' => $blank.'abc'.$blank]));
        $this->assertSame('abc', $event['fingerprint']);
    }

    public function test_caracteres_invisibles_de_laravel_solos_cuentan_como_vacio(): void
    {
        foreach (["\u{2060}", "\u{3000}", "\u{2800}", "\u{00AD}"] as $invisible) {
            $this->assertSame('-', $this->format($this->record($invisible))['message']);

            $event = $this->format($this->record('x', context: ['fingerprint' => $invisible]));
            $this->assertArrayNotHasKey('fingerprint', $event);
        }
    }

    public function test_json_serializable_que_devuelve_objeto_no_cae_en_resource(): void
    {
        $serializable = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return new \stdClass;
            }
        };

        $event = $this->format($this->record('x', context: ['obj' => $serializable]));

        $this->assertSame('[object stdClass]', $event['context']['obj']);
    }

    public function test_json_serializable_que_devuelve_array_se_normaliza_con_limites(): void
    {
        $items = [];
        for ($i = 0; $i < 60; $i++) {
            $items['k'.$i] = $i;
        }
        $items['long'] = str_repeat('a', 3000);

        $serializable = new class($items) implements \JsonSerializable
        {
            public function __construct(private array $items) {}

            public function jsonSerialize(): mixed
            {
                return $this->items;
            }
        };

        $event = $this->format($this->record('x', context: ['obj' => $serializable]));

        $this->assertCount(50, $event['context']['obj']);
        $this->assertSame(0, $event['context']['obj']['k0']);
        $this->assertArrayNotHasKey('long', $event['context']['obj']);
        $this->assertTrue($event['context']['_truncated']);

        $short = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['a' => 1, 'b' => ["x\0y"]];
            }
        };

        $event = $this->format($this->record('x', context: ['obj' => $short]));

        $this->assertSame(['a' => 1, 'b' => ['xy']], $event['context']['obj']);
        $this->assertArrayNotHasKey('_truncated', $event['context']);
    }

    public function test_contexto_enorme_se_recorta_a_32_kb_con_marca(): void
    {
        $context = [];
        for ($i = 0; $i < 200; $i++) {
            $context['key_'.$i] = str_repeat('é😀/', 600);
        }

        $event = $this->format($this->record('big', context: $context));

        $this->assertLessThanOrEqual(32768, strlen(json_encode($event['context'])));
        $this->assertTrue($event['context']['_truncated']);
    }

    public function test_contexto_anidado_profundo_no_pasa_de_8_niveles(): void
    {
        $nested = 'fondo';
        for ($i = 0; $i < 30; $i++) {
            $nested = ['n' => $nested];
        }

        $event = $this->format($this->record('deep', context: ['data' => $nested]));

        $this->assertLessThanOrEqual(8, $this->depth($event['context']));
        $this->assertTrue($event['context']['_truncated']);
    }

    public function test_quita_bytes_nulos_de_valores_y_claves_del_contexto(): void
    {
        $event = $this->format($this->record('nul', context: ["k\0ey" => "va\0lue", 'list' => ["x\0"]]));

        $this->assertSame(['key' => 'value', 'list' => ['x']], $event['context']);
        $this->assertStringNotContainsString('\u0000', json_encode($event));
    }

    public function test_excepcion_se_normaliza_y_genera_fingerprint_explicito(): void
    {
        $exception = new RuntimeException("falló\0 todo");

        $event = $this->format($this->record('Error', Level::Error, ['exception' => $exception, 'order' => 7]));

        $this->assertSame(RuntimeException::class, $event['context']['exception']['class']);
        $this->assertSame('falló todo', $event['context']['exception']['message']);
        $this->assertSame($exception->getFile().':'.$exception->getLine(), $event['context']['exception']['location']);
        $this->assertLessThanOrEqual(2000, mb_strlen($event['context']['exception']['trace']));
        $this->assertSame(RuntimeException::class.'|'.$exception->getFile().':'.$exception->getLine(), $event['fingerprint']);
        $this->assertSame(7, $event['context']['order']);
        $this->assertLessThanOrEqual(255, mb_strlen($event['fingerprint']));
    }

    public function test_excepcion_con_mensaje_de_100_kb_conserva_clase_y_ubicacion(): void
    {
        foreach ([str_repeat('x', 100 * 1024), str_repeat("\u{1F600}", 25 * 1024)] as $message) {
            $exception = new RuntimeException($message);

            $event = $this->format($this->record('Error', Level::Error, ['exception' => $exception]));

            $this->assertIsArray($event['context']['exception']);
            $this->assertSame(RuntimeException::class, $event['context']['exception']['class']);
            $this->assertSame($exception->getFile().':'.$exception->getLine(), $event['context']['exception']['location']);
            $this->assertLessThanOrEqual(2048, mb_strlen($event['context']['exception']['message']));
            $this->assertTrue($event['context']['_truncated']);
            $this->assertLessThanOrEqual(EventFormatter::MAX_CONTEXT_BYTES, strlen(json_encode($event['context'])));
        }
    }

    public function test_fingerprint_del_contexto_gana_y_se_limita_a_255(): void
    {
        $event = $this->format($this->record('x', context: ['fingerprint' => 'sicm-timeout']));
        $this->assertSame('sicm-timeout', $event['fingerprint']);

        $event = $this->format($this->record('x', context: ['fingerprint' => str_repeat('ñ', 400)]));
        $this->assertLessThanOrEqual(255, mb_strlen($event['fingerprint']));
    }

    public function test_fingerprint_explicito_de_mas_de_255_caracteres_se_reemplaza_por_sha1(): void
    {
        $long = str_repeat('ñ', 300);

        $event = $this->format($this->record('x', context: ['fingerprint' => $long]));

        $this->assertSame(sha1($long), $event['fingerprint']);
        $this->assertSame(40, strlen($event['fingerprint']));

        $exact = str_repeat('a', 255);
        $this->assertSame($exact, $this->format($this->record('x', context: ['fingerprint' => $exact]))['fingerprint']);
    }

    public function test_valores_no_serializables_quedan_como_texto(): void
    {
        $handle = fopen('php://memory', 'r');
        $event = $this->format($this->record('x', context: [
            'resource' => $handle,
            'object' => new \stdClass,
            'nan' => NAN,
            'date' => new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC')),
            'bad' => "\xB1\x31",
        ]));
        fclose($handle);

        $this->assertSame('[resource]', $event['context']['resource']);
        $this->assertSame('[object stdClass]', $event['context']['object']);
        $this->assertSame('NAN', $event['context']['nan']);
        $this->assertSame('2026-01-02T03:04:05+00:00', $event['context']['date']);
        $this->assertNotFalse(json_encode($event, JSON_THROW_ON_ERROR));
    }

    private function format(LogRecord $record): array
    {
        return (new EventFormatter)->format($record);
    }

    private function record(string $message, Level $level = Level::Info, array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable('2026-10-07 11:30:45.123456', new DateTimeZone('America/Caracas')),
            channel: 'test',
            level: $level,
            message: $message,
            context: $context,
        );
    }

    private function depth(mixed $value, int $level = 0): int
    {
        if (! is_array($value)) {
            return $level;
        }

        $deepest = $level + 1;
        foreach ($value as $item) {
            $deepest = max($deepest, $this->depth($item, $level + 1));
        }

        return $deepest;
    }
}
