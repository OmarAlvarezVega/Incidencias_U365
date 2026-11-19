<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\ValidationException;
use App\Services\Installer;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnv;

final class InstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        TestEnv::app();
        $this->root = TestEnv::dir() . '/raiz-instalador-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage', 0700, true);
        mkdir($this->root . '/config', 0700, true);
        symlink(dirname(__DIR__, 2) . '/vendor', $this->root . '/vendor');
    }

    private const ADMIN = ['first_name' => 'Ana', 'last_name' => 'Pruebas', 'email' => 'ana.instalador@pruebas.test', 'password' => 'Instalador-Seguro-2026!', 'password_confirmation' => 'Instalador-Seguro-2026!'];

    public function testNoTocaUnaBaseDeDatosConTablas(): void
    {
        $before = (int)TestEnv::db()->value('SELECT COUNT(*) FROM users');
        try {
            (new Installer($this->root))->install('http://127.0.0.1:8765', TestEnv::dbConfig(), self::ADMIN);
            $this->fail('Debería negarse');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('db_name', $e->errors);
        }
        $this->assertSame($before, (int)TestEnv::db()->value('SELECT COUNT(*) FROM users'));
        $this->assertFileDoesNotExist($this->root . '/config/config.php');
        $this->assertFileDoesNotExist($this->root . '/storage/installed.lock');
    }

    public function testValidaElAdministradorAntesDeConectar(): void
    {
        $this->expectException(ValidationException::class);
        $bad = ['password' => 'corta', 'password_confirmation' => 'corta'] + self::ADMIN;
        // Con credenciales de BD imposibles: si validara después, el error sería de conexión
        (new Installer($this->root))->install('http://127.0.0.1:8765', ['host' => '127.0.0.1', 'port' => 1, 'name' => 'x', 'user' => 'x', 'pass' => 'x'], $bad);
    }

    public function testBloqueadoSiYaHayConfiguracion(): void
    {
        file_put_contents($this->root . '/storage/installed.lock', 'x');
        $inst = new Installer($this->root);
        $this->assertTrue($inst->isLocked());
        $this->expectException(\RuntimeException::class);
        $inst->install('http://127.0.0.1:8765', TestEnv::dbConfig(), self::ADMIN);
    }

    public function testExigeHttpsFueraDeLocal(): void
    {
        $this->expectException(ValidationException::class);
        (new Installer($this->root))->install('http://incidencias.univers365.com', TestEnv::dbConfig(), self::ADMIN);
    }
}
