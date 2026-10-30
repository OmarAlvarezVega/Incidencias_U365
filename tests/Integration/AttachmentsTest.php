<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Attachments;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnv;

final class AttachmentsTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\ntrailer\n<</Root 1 0 R>>\n%%EOF\n";

    private function file(string $content): string
    {
        $p = tempnam(TestEnv::dir(), 'adj');
        file_put_contents($p, $content);
        return $p;
    }

    private function rejects(string $content, string $name, string $why): void
    {
        try {
            TestEnv::app()->make(Attachments::class)->inspect($this->file($content), $name);
            $this->fail("Debería rechazar $name ($why)");
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testAdmitePdfReal(): void
    {
        $this->assertSame('application/pdf', TestEnv::app()->make(Attachments::class)->inspect($this->file(self::PDF), 'Factura.PDF'));
    }

    public function testRechazaFicherosEjecutablesYDisfrazados(): void
    {
        $this->rejects('<?php echo 1;', 'shell.php', 'extensión php');
        $this->rejects(self::PDF, 'factura.php.pdf', 'doble extensión');
        $this->rejects('<?php system($_GET["c"]);', 'notas.txt', 'texto con código PHP');
        $this->rejects('<html><script>alert(1)</script></html>', 'pagina.txt', 'HTML disfrazado de texto');
        $this->rejects('hola', 'foto.png', 'el contenido no es una imagen');
        $this->rejects('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'logo.svg', 'SVG no permitido');
        $this->rejects('', 'vacio.pdf', 'vacío');
        $this->rejects('MZ' . str_repeat("\0", 200), 'programa.exe', 'ejecutable');
    }

    public function testRespetaElTamanoMaximo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TestEnv::app()->make(Attachments::class)->inspect($this->file(self::PDF . str_repeat('x', 2048)), 'grande.pdf', 1024);
    }

    public function testNombreSeguro(): void
    {
        $safe = Attachments::safeName("../../etc/passwd\0.txt");
        $this->assertStringNotContainsString('/', $safe);
        $this->assertStringNotContainsString("\0", $safe);
    }
}
