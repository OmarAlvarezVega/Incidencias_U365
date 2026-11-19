<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Services\TicketService;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpServer;
use Tests\Support\TestEnv;

/** Pruebas de seguridad contra la aplicación real servida por HTTP. */
final class SecurityTest extends TestCase
{
    public function testCabecerasDeSeguridad(): void
    {
        $r = HttpServer::request('GET', '/login');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString("script-src 'self'", $r['headers']['content-security-policy'] ?? '');
        $this->assertStringContainsString("frame-ancestors 'none'", $r['headers']['content-security-policy'] ?? '');
        $this->assertSame('DENY', $r['headers']['x-frame-options'] ?? '');
        $this->assertSame('nosniff', $r['headers']['x-content-type-options'] ?? '');
        $this->assertStringContainsString('noindex', $r['body']);
        $this->assertStringContainsString('HttpOnly', $r['headers']['set-cookie'] ?? '');
        $this->assertStringContainsStringIgnoringCase('SameSite', $r['headers']['set-cookie'] ?? '');
    }

    public function testSinSesionRedirigeAlLogin(): void
    {
        foreach (['/', '/incidencias', '/admin/ajustes', '/clientes'] as $path) {
            $r = HttpServer::request('GET', $path);
            $this->assertContains($r['status'], [302, 303], $path);
            $this->assertStringContainsString('/login', $r['headers']['location'] ?? '', $path);
        }
    }

    public function testPostSinTokenCsrfSeRechaza(): void
    {
        $jar = HttpServer::login(TestEnv::ADMIN_EMAIL);
        $r = HttpServer::request('POST', '/incidencias', ['subject' => 'Sin token', 'priority_id' => 1, 'sin_cliente' => '1'], [], $jar);
        $this->assertContains($r['status'], [302, 303, 403, 419]);
        $this->assertSame(0, (int)TestEnv::db()->value("SELECT COUNT(*) FROM tickets WHERE subject = 'Sin token'"));

        $r = HttpServer::request('POST', '/incidencias', ['subject' => 'Token falso', '_token' => str_repeat('a', 64), 'priority_id' => 1, 'sin_cliente' => '1'], [], $jar);
        $this->assertSame(0, (int)TestEnv::db()->value("SELECT COUNT(*) FROM tickets WHERE subject = 'Token falso'"));

        // Control positivo: con el token de la sesión sí se crea
        $token = HttpServer::csrf('/incidencias/nueva', $jar);
        $prio = (int)TestEnv::db()->value("SELECT id FROM ticket_priorities WHERE code = 'normal'");
        HttpServer::request('POST', '/incidencias', ['subject' => 'Con token', '_token' => $token, 'priority_id' => $prio, 'sin_cliente' => '1'], [], $jar);
        $this->assertSame(1, (int)TestEnv::db()->value("SELECT COUNT(*) FROM tickets WHERE subject = 'Con token'"));
    }

    public function testUnTecnicoRecibe404EnIncidenciasAjenas(): void
    {
        $ana = TestEnv::user('technician', 'ana.http@pruebas.test');
        TestEnv::user('technician', 'luis.http@pruebas.test');
        $id = TestEnv::app()->make(TicketService::class)->create(['subject' => 'Solo para Ana', 'owner_user_id' => $ana], TestEnv::actor(TestEnv::adminId()));

        $luis = HttpServer::login('luis.http@pruebas.test');
        $this->assertSame(404, HttpServer::request('GET', "/incidencias/$id", null, [], $luis)['status']);
        $token = HttpServer::csrf('/', $luis);
        $r = HttpServer::request('POST', "/incidencias/$id/nota", ['_token' => $token, 'body' => 'intrusión', 'visibility' => 'internal'], [], $luis);
        $this->assertSame(404, $r['status']);
        $this->assertSame(0, (int)TestEnv::db()->value("SELECT COUNT(*) FROM ticket_activities WHERE ticket_id = ? AND body LIKE '%intrusión%'", [$id]));

        $anaJar0 = HttpServer::login('ana.http@pruebas.test');
        $tokenAna = HttpServer::csrf("/incidencias/$id", $anaJar0);
        HttpServer::request('POST', "/incidencias/$id/nota", ['_token' => $tokenAna, 'body' => 'nota legítima', 'visibility' => 'internal'], [], $anaJar0);
        $this->assertSame(1, (int)TestEnv::db()->value("SELECT COUNT(*) FROM ticket_activities WHERE ticket_id = ? AND body LIKE '%nota legítima%'", [$id]), 'Control positivo: la responsable sí puede');

        $anaJar = HttpServer::login('ana.http@pruebas.test');
        $this->assertSame(200, HttpServer::request('GET', "/incidencias/$id", null, [], $anaJar)['status']);
    }

    public function testElTextoDelUsuarioSeEscapa(): void
    {
        $payload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
        $id = TestEnv::app()->make(TicketService::class)->create(['subject' => $payload, 'description' => $payload], TestEnv::actor(TestEnv::adminId()));
        $jar = HttpServer::login(TestEnv::ADMIN_EMAIL);
        foreach (['/incidencias', "/incidencias/$id"] as $path) {
            $r = HttpServer::request('GET', $path, null, [], $jar);
            $this->assertSame(200, $r['status'], $path);
            $this->assertStringNotContainsString('<script>alert("xss")', $r['body'], $path);
            $this->assertStringNotContainsString('<img src=x onerror', $r['body'], $path);
            $this->assertStringContainsString('&lt;script&gt;', $r['body'], $path);
        }
    }

    public function testOrdenYFiltrosNoPermitenInyeccionSql(): void
    {
        $jar = HttpServer::login(TestEnv::ADMIN_EMAIL);
        foreach ([
            '/incidencias?orden=' . rawurlencode('id; DROP TABLE users--') . '&dir=' . rawurlencode("asc'"),
            '/incidencias?q=' . rawurlencode("' OR 1=1 -- ") . '&estado=' . rawurlencode('1) OR (1=1'),
            '/clientes?q=' . rawurlencode("%' UNION SELECT password_hash FROM users -- "),
        ] as $path) {
            $r = HttpServer::request('GET', $path, null, [], $jar);
            $this->assertContains($r['status'], [200, 302, 303], $path);
            $this->assertStringNotContainsString('SQLSTATE', $r['body'], $path);
            $this->assertStringNotContainsString('$argon2', $r['body'], $path);
        }
        $this->assertGreaterThan(0, (int)TestEnv::db()->value('SELECT COUNT(*) FROM users'));
    }

    public function testBloqueoTrasIntentosFallidos(): void
    {
        TestEnv::user('technician', 'fuerza.bruta@pruebas.test');
        for ($i = 0; $i < 6; $i++) {
            $jar = tempnam(TestEnv::dir(), 'c');
            $token = HttpServer::csrf('/login', $jar);
            HttpServer::request('POST', '/login', ['_token' => $token, 'identifier' => 'fuerza.bruta@pruebas.test', 'password' => 'incorrecta-' . $i], [], $jar);
        }
        $this->assertNotNull(TestEnv::db()->value('SELECT locked_until FROM users WHERE email_normalized = ?', ['fuerza.bruta@pruebas.test']));
        try {
            HttpServer::login('fuerza.bruta@pruebas.test');
            $this->fail('Con la cuenta bloqueada no debe entrar ni con la contraseña correcta');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No se pudo iniciar sesión', $e->getMessage());
        }
    }

    public function testSubidaDePhpRechazada(): void
    {
        $id = TestEnv::app()->make(TicketService::class)->create(['subject' => 'Adjuntos'], TestEnv::actor(TestEnv::adminId()));
        $jar = HttpServer::login(TestEnv::ADMIN_EMAIL);
        $token = HttpServer::csrf("/incidencias/$id", $jar);
        $tmp = tempnam(TestEnv::dir(), 'up');
        file_put_contents($tmp, '<?php echo "pwned"; ?>');
        foreach (['shell.php', 'factura.php.pdf', 'nota.txt'] as $name) {
            HttpServer::request('POST', "/incidencias/$id/adjuntos", [
                '_token' => $token, 'visibility' => 'internal', 'files[]' => new \CURLFile($tmp, 'application/octet-stream', $name),
            ], [], $jar);
        }
        $this->assertSame(0, (int)TestEnv::db()->value("SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id = ? AND status = 'stored'", [$id]));

        // Control positivo: el mismo formulario sí guarda un PDF legítimo
        $pdf = tempnam(TestEnv::dir(), 'up');
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\ntrailer\n<</Root 1 0 R>>\n%%EOF\n");
        HttpServer::request('POST', "/incidencias/$id/adjuntos", ['_token' => $token, 'visibility' => 'internal', 'files[]' => new \CURLFile($pdf, 'application/pdf', 'bueno.pdf')], [], $jar);
        $this->assertSame(1, (int)TestEnv::db()->value("SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id = ? AND status = 'stored'", [$id]));
        // Y nada ejecutable dentro de public/
        $this->assertSame([], glob(dirname(__DIR__, 2) . '/public/**/*.php') ?: []);
    }

    public function testWebhookExigeFirmaYDescartaDuplicados(): void
    {
        $settings = TestEnv::app()->settings();
        $settings->setSecret('whatsapp.app_secret', 'secreto-de-pruebas');
        $settings->set('whatsapp.enabled', true);
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'x', 'changes' => []]], 'n' => random_int(1, PHP_INT_MAX)]);

        $this->assertSame(401, HttpServer::request('POST', '/webhooks/whatsapp', $body, ['Content-Type' => 'application/json'])['status']);
        $this->assertSame(401, HttpServer::request('POST', '/webhooks/whatsapp', $body, ['Content-Type' => 'application/json', 'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'otro')])['status']);

        $sig = 'sha256=' . hash_hmac('sha256', $body, 'secreto-de-pruebas');
        $this->assertSame(200, HttpServer::request('POST', '/webhooks/whatsapp', $body, ['Content-Type' => 'application/json', 'X-Hub-Signature-256' => $sig])['status']);
        $this->assertSame(200, HttpServer::request('POST', '/webhooks/whatsapp', $body, ['Content-Type' => 'application/json', 'X-Hub-Signature-256' => $sig])['status']);
        $this->assertSame(1, (int)TestEnv::db()->value('SELECT COUNT(*) FROM whatsapp_webhook_events WHERE payload_sha256 = ?', [hash('sha256', $body)]));

        // Verificación GET con token incorrecto
        $this->assertSame(403, HttpServer::request('GET', '/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=malo&hub.challenge=123')['status']);
        $settings->set('whatsapp.enabled', false);
    }

    public function testInstaladorNoAccesibleTrasInstalar(): void
    {
        // Con config/config.php presente el instalador ni siquiera se enruta
        $this->assertSame(404, HttpServer::request('GET', '/instalar')['status']);
    }

    public function testCronSinClaveSeRechaza(): void
    {
        $r = HttpServer::request('POST', '/cron/run', ['task' => 'jobs.process']);
        $this->assertContains($r['status'], [401, 403, 404]);
    }

    /** Inicia sesión marcando «mantener la sesión iniciada». */
    private static function loginRemembered(string $email): string
    {
        $jar = tempnam(TestEnv::dir(), 'cookies');
        $token = HttpServer::csrf('/login', $jar);
        $r = HttpServer::request('POST', '/login', ['_token' => $token, 'identifier' => $email, 'password' => TestEnv::PASSWORD, 'remember' => '1'], [], $jar);
        if (!in_array($r['status'], [302, 303], true)) {
            throw new \RuntimeException("Login fallido (HTTP {$r['status']})");
        }
        return $jar;
    }

    /** Simula cerrar el navegador: se pierde la cookie de sesión, se conserva la recordada. */
    private static function dropSessionCookie(string $jar): void
    {
        $lines = file($jar) ?: [];
        $keep = array_filter($lines, static fn ($l) => (explode("\t", rtrim($l, "\n"))[5] ?? '') !== 'u365');
        file_put_contents($jar, implode('', $keep));
    }

    private static function rememberCookie(string $jar): ?string
    {
        foreach (file($jar) ?: [] as $l) {
            $f = explode("\t", rtrim($l, "\n"));
            if (($f[5] ?? '') === 'u365r') {
                return $f[6] ?? null;
            }
        }
        return null;
    }

    private static function isLoggedIn(string $jar): bool
    {
        $r = HttpServer::request('GET', '/', null, [], $jar);
        return $r['status'] === 200;
    }

    public function testSesionRecordadaSobreviveAlCierreDelNavegador(): void
    {
        TestEnv::user('technician', 'recordar@pruebas.test');
        $jar = self::loginRemembered('recordar@pruebas.test');
        $cookie = self::rememberCookie($jar);
        $this->assertNotNull($cookie);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{24}\.[a-f0-9]{64}$/', (string)$cookie);
        // En la base de datos solo está el hash del validador
        [$selector, $validator] = explode('.', (string)$cookie);
        $hash = TestEnv::db()->value('SELECT validator_hash FROM remember_tokens WHERE selector = ?', [$selector]);
        $this->assertSame(hash('sha256', $validator), $hash);
        $this->assertSame(0, (int)TestEnv::db()->value('SELECT COUNT(*) FROM remember_tokens WHERE validator_hash = ?', [$validator]));

        self::dropSessionCookie($jar);
        $this->assertTrue(self::isLoggedIn($jar), 'Con la cookie recordada debe seguir dentro');

        // Sin marcar la casilla, cerrar el navegador sí cierra la sesión
        $plain = HttpServer::login('recordar@pruebas.test');
        $this->assertNull(self::rememberCookie($plain));
        self::dropSessionCookie($plain);
        $this->assertFalse(self::isLoggedIn($plain));
    }

    public function testSesionRecordadaManipuladaSeRechaza(): void
    {
        TestEnv::user('technician', 'manipular@pruebas.test');
        $jar = self::loginRemembered('manipular@pruebas.test');
        self::dropSessionCookie($jar);
        $cookie = (string)self::rememberCookie($jar);
        $bad = substr($cookie, 0, -1) . ($cookie[-1] === 'a' ? 'b' : 'a');
        file_put_contents($jar, str_replace($cookie, $bad, (string)file_get_contents($jar)));
        $this->assertFalse(self::isLoggedIn($jar));
    }

    public function testCerrarSesionRevocaElDispositivo(): void
    {
        TestEnv::user('technician', 'salir@pruebas.test');
        $jar = self::loginRemembered('salir@pruebas.test');
        $stolen = (string)file_get_contents($jar);
        $token = HttpServer::csrf('/', $jar);
        HttpServer::request('POST', '/logout', ['_token' => $token], [], $jar);
        // Quien copió la cookie antes de salir ya no puede entrar
        file_put_contents($jar, $stolen);
        self::dropSessionCookie($jar);
        $this->assertFalse(self::isLoggedIn($jar));
    }

    public function testCambioDeVersionDeSesionYAjusteCeroDesactivan(): void
    {
        $uid = TestEnv::user('technician', 'revocar@pruebas.test');
        $jar = self::loginRemembered('revocar@pruebas.test');
        self::dropSessionCookie($jar);
        // Desactivación, bloqueo o cambio de contraseña suben session_version
        TestEnv::db()->run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$uid]);
        $this->assertFalse(self::isLoggedIn($jar));

        $jar2 = self::loginRemembered('revocar@pruebas.test');
        self::dropSessionCookie($jar2);
        TestEnv::app()->settings()->set('security.remember_days', 0);
        try {
            $this->assertFalse(self::isLoggedIn($jar2), 'Con 0 días la función queda desactivada');
        } finally {
            TestEnv::app()->settings()->set('security.remember_days', 180);
        }
    }

    public function testVersionVisibleYActualizacionSoloSuperadmin(): void
    {
        $r = HttpServer::request('GET', '/login');
        $this->assertStringContainsString('versión', $r['body']);
        $admin = HttpServer::login(TestEnv::ADMIN_EMAIL);
        $r = HttpServer::request('GET', '/admin/actualizacion', null, [], $admin);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('al día', $r['body']);

        TestEnv::user('technician', 'tecnico.update@pruebas.test');
        $tech = HttpServer::login('tecnico.update@pruebas.test');
        $r = HttpServer::request('GET', '/admin/actualizacion', null, [], $tech);
        $this->assertContains($r['status'], [403, 404]);
        $token = HttpServer::csrf('/', $tech);
        $r = HttpServer::request('POST', '/admin/actualizacion', ['_token' => $token, 'confirm' => '1'], [], $tech);
        $this->assertContains($r['status'], [403, 404]);
    }
}
