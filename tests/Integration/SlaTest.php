<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Sla;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnv;

/** Calendario sembrado: L-V 09:00-14:00 y 15:00-18:00, Europe/Madrid. */
final class SlaTest extends TestCase
{
    private Sla $sla;
    private int $cal;

    protected function setUp(): void
    {
        $this->sla = TestEnv::app()->make(Sla::class);
        $this->cal = (int)TestEnv::db()->value('SELECT MIN(id) FROM business_calendars');
        TestEnv::db()->run('DELETE FROM calendar_holidays WHERE calendar_id = ?', [$this->cal]);
        $this->sla->forgetCalendars();
    }

    private static function local(string $s): DateTimeImmutable
    {
        return (new DateTimeImmutable($s, new DateTimeZone('Europe/Madrid')))->setTimezone(new DateTimeZone('UTC'));
    }

    private static function fmt(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d H:i');
    }

    public function testSaltaLaNocheYLaPausaDeComida(): void
    {
        // Lunes 7/12/2026 17:30 + 2 h laborables = martes 10:30
        $this->assertSame('2026-12-08 10:30', self::fmt($this->sla->addMinutes(self::local('2026-12-07 17:30'), 120, 'business', $this->cal)));
        // 13:30 + 1 h = 15:30 (la pausa de 14 a 15 no cuenta)
        $this->assertSame('2026-12-07 15:30', self::fmt($this->sla->addMinutes(self::local('2026-12-07 13:30'), 60, 'business', $this->cal)));
    }

    public function testFestivoNoCuenta(): void
    {
        TestEnv::db()->run('INSERT INTO calendar_holidays (calendar_id, holiday_date, name) VALUES (?, ?, ?)', [$this->cal, '2026-12-08', 'Inmaculada']);
        $this->sla->forgetCalendars();
        $this->assertSame('2026-12-09 10:30', self::fmt($this->sla->addMinutes(self::local('2026-12-07 17:30'), 120, 'business', $this->cal)));
    }

    public function testFinDeSemanaYCambioDeHora(): void
    {
        // Viernes 23/10/2026 17:00 (CEST) + 2 h: 1 h el viernes, el domingo 25 cambia la hora, lunes 10:00 (CET)
        $due = $this->sla->addMinutes(self::local('2026-10-23 17:00'), 120, 'business', $this->cal);
        $this->assertSame('2026-10-26 10:00', self::fmt($due));
        $this->assertSame('2026-10-26 09:00', $due->format('Y-m-d H:i'), 'En UTC, lunes 09:00 (UTC+1)');
        $this->assertSame(120, $this->sla->minutesBetween(self::local('2026-10-23 17:00'), $due, 'business', $this->cal));
    }

    public function testModoNaturalIgnoraElCalendario(): void
    {
        $this->assertSame('2026-12-08 01:30', self::fmt($this->sla->addMinutes(self::local('2026-12-07 17:30'), 480, 'calendar', $this->cal)));
    }

    public function testFueraDeHorarioEmpiezaAlAbrir(): void
    {
        // Sábado: empieza a contar el lunes 09:00
        $this->assertSame('2026-12-14 09:15', self::fmt($this->sla->addMinutes(self::local('2026-12-12 11:00'), 15, 'business', $this->cal)));
    }
}
