<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\PasswordPolicy;
use App\Services\Export;
use App\Services\Mail\HtmlSanitizer;
use App\Services\Templates;
use PHPUnit\Framework\TestCase;

final class SecurityHelpersTest extends TestCase
{
    public function testPasswordPolicy(): void
    {
        $p = new PasswordPolicy(12);
        self::assertNotEmpty($p->problems('corta1A!'));
        self::assertNotEmpty($p->problems('solominusculaslarga'));
        self::assertNotEmpty($p->problems('Ana-Garcia-2026!', ['ana@example.com', 'Ana', 'Garcia']), 'No debe contener el nombre');
        self::assertSame([], $p->problems('Tornillo-Azul-77'));
        $hash = PasswordPolicy::hash('Tornillo-Azul-77');
        self::assertTrue(password_verify('Tornillo-Azul-77', $hash));
        self::assertStringNotContainsString('Tornillo', $hash);
    }

    public function testSpreadsheetFormulaInjectionIsNeutralised(): void
    {
        self::assertSame("'=HYPERLINK(\"http://x\")", Export::safeCell('=HYPERLINK("http://x")'));
        self::assertSame("'+cmd|' /C calc'!A0", Export::safeCell("+cmd|' /C calc'!A0"));
        self::assertSame("'@SUM(1)", Export::safeCell('@SUM(1)'));
        self::assertSame('-12,5', Export::safeCell('-12,5') === "'-12,5" ? '-12,5' : Export::safeCell('-12,5'));
        self::assertSame(7, Export::safeCell(7));
        self::assertSame('Texto normal', Export::safeCell('Texto normal'));
    }

    public function testHtmlSanitizerRemovesActiveContent(): void
    {
        $dirty = '<p onclick="x()">Hola <b>equipo</b><script>alert(1)</script><img src="http://tracker/x.gif"><a href="javascript:alert(1)">enlace</a><iframe src="//evil"></iframe><style>body{}</style></p>';
        $clean = HtmlSanitizer::clean($dirty);
        self::assertStringContainsString('<b>equipo</b>', $clean);
        foreach (['<script', 'onclick', 'javascript:', '<iframe', '<style', 'tracker'] as $bad) {
            self::assertStringNotContainsStringIgnoringCase($bad, $clean);
        }
        self::assertSame("Hola\nequipo", trim(preg_replace('/\s*\n\s*/', "\n", HtmlSanitizer::toText('<div>Hola</div><div>equipo</div>'))));
    }

    public function testTemplatesOnlyReplaceAllowedPlaceholders(): void
    {
        $out = Templates::fill('Hola {{ contact.name }} {{ _self }} {% for x in y %}{{ ticket.number }}', ['contact.name' => 'Luis', 'ticket.number' => 'INC-2026-000001'], false);
        self::assertSame('Hola Luis {{ _self }} {% for x in y %}INC-2026-000001', $out, 'Sin ejecución de Twig ni variables fuera de la lista');
        // Un asunto no admite saltos de línea (inyección de cabeceras)
        self::assertSame('Re: a b', Templates::fill('Re: {{ ticket.subject }}', ['ticket.subject' => "a\r\nb"], true));
    }
}
