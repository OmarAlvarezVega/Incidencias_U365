<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredAndTypes(): void
    {
        $out = (new Validator(['n' => ' 42 ', 'e' => 'Ana@Example.com', 'b' => 'on', 'd' => '27/10/2026', 'list' => ['3', '3', '5']]))
            ->validate(['n' => 'required|int|min:1|max:100', 'e' => 'email', 'b' => 'bool', 'd' => 'day', 'list' => 'intlist', 'missing' => 'string']);
        self::assertSame(42, $out['n']);
        self::assertSame('Ana@Example.com', $out['e']);
        self::assertTrue($out['b']);
        self::assertSame('2026-10-27', $out['d']);
        self::assertSame([3, 5], $out['list']);
        self::assertNull($out['missing']);
    }

    public function testErrorsInSpanishPerField(): void
    {
        try {
            (new Validator(['n' => 'abc', 'c' => 'red'], ['n' => 'El número']))->validate(['n' => 'int', 'c' => 'color', 'r' => 'required']);
            self::fail('Debía fallar');
        } catch (ValidationException $e) {
            self::assertSame('El número debe ser un número entero.', $e->errors['n'][0]);
            self::assertArrayHasKey('c', $e->errors);
            self::assertArrayHasKey('r', $e->errors);
        }
    }

    public function testArraysAreRejectedForScalarFields(): void
    {
        $this->expectException(ValidationException::class);
        (new Validator(['s' => ['inyección']]))->validate(['s' => 'string']);
    }

    public function testControlCharactersAreStripped(): void
    {
        $out = (new Validator(['s' => "ho\x00la\x07"]))->validate(['s' => 'string']);
        self::assertSame('hola', $out['s']);
    }

    public function testDateRuleConvertsMadridToUtc(): void
    {
        // 27/10/2026 10:00 en Madrid (horario de invierno, UTC+1) = 09:00 UTC
        $out = (new Validator(['d' => '2026-10-27T10:00']))->validate(['d' => 'date']);
        self::assertSame('2026-10-27 09:00:00', $out['d']);
        // 15/07/2026 10:00 en Madrid (verano, UTC+2) = 08:00 UTC
        $out = (new Validator(['d' => '15/07/2026 10:00']))->validate(['d' => 'date']);
        self::assertSame('2026-07-15 08:00:00', $out['d']);
    }

    public function testNormalizers(): void
    {
        self::assertSame('ana@example.com', Validator::normalizeEmail('  Ana@EXAMPLE.com '));
        self::assertSame('34600111222', Validator::normalizePhone('+34 600 11 12 22'));
        self::assertSame('B12345678', Validator::normalizeTaxId('b-1234 5678'));
    }
}
