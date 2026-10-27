<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Hora de la aplicación. En base de datos todo se guarda en UTC ('Y-m-d H:i:s');
 * la conversión a Europe/Madrid solo ocurre al mostrar o al interpretar lo que
 * escribe un usuario.
 */
final class Clock
{
    public const DB_FORMAT = 'Y-m-d H:i:s';
    public const LOCAL_TZ = 'Europe/Madrid';

    private static ?DateTimeImmutable $frozen = null;

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', self::utc());
    }

    /** Solo para pruebas. */
    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$frozen = $at?->setTimezone(self::utc());
    }

    public static function nowDb(): string
    {
        return self::now()->format(self::DB_FORMAT);
    }

    public static function utc(): DateTimeZone
    {
        static $tz = null;
        return $tz ??= new DateTimeZone('UTC');
    }

    public static function local(): DateTimeZone
    {
        static $tz = null;
        return $tz ??= new DateTimeZone(self::LOCAL_TZ);
    }

    public static function fromDb(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        return new DateTimeImmutable(substr($value, 0, 19), self::utc());
    }

    public static function toDb(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(self::utc())->format(self::DB_FORMAT);
    }

    /** Formatea una fecha UTC de BD como DD/MM/AAAA HH:MM en Europe/Madrid. */
    public static function format(?string $dbValue, bool $withTime = true): string
    {
        $dt = self::fromDb($dbValue);
        if ($dt === null) {
            return '';
        }
        return $dt->setTimezone(self::local())->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    }

    /**
     * Interpreta una fecha escrita por el usuario (DD/MM/AAAA [HH:MM] o
     * AAAA-MM-DD[THH:MM] de un input date/datetime-local) en hora de Madrid.
     */
    public static function parseLocal(?string $input, bool $endOfDay = false): ?DateTimeImmutable
    {
        $input = trim((string)$input);
        if ($input === '') {
            return null;
        }
        $formats = ['d/m/Y H:i', 'd/m/Y', 'Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d'];
        foreach ($formats as $f) {
            $dt = DateTimeImmutable::createFromFormat('!' . $f, $input, self::local());
            $errors = DateTimeImmutable::getLastErrors();
            if ($dt !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                if (!str_contains($f, 'H') && $endOfDay) {
                    $dt = $dt->setTime(23, 59, 59);
                }
                return $dt->setTimezone(self::utc());
            }
        }
        return null;
    }

    /** Fecha local (Y-m-d) de un instante UTC. */
    public static function localDate(?DateTimeImmutable $dt = null): string
    {
        return ($dt ?? self::now())->setTimezone(self::local())->format('Y-m-d');
    }

    /** "hace 3 h", "hace 2 d" para listados. */
    public static function ago(?string $dbValue): string
    {
        $dt = self::fromDb($dbValue);
        if ($dt === null) {
            return '';
        }
        $s = self::now()->getTimestamp() - $dt->getTimestamp();
        if ($s < 0) {
            $s = -$s;
            $prefix = 'en ';
        } else {
            $prefix = 'hace ';
        }
        return $prefix . match (true) {
            $s < 60 => 'menos de 1 min',
            $s < 3600 => intdiv($s, 60) . ' min',
            $s < 86400 => intdiv($s, 3600) . ' h',
            default => intdiv($s, 86400) . ' d',
        };
    }

    public static function minutesToHuman(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h > 0 ? sprintf('%d h %02d min', $h, $m) : sprintf('%d min', $m);
    }
}
