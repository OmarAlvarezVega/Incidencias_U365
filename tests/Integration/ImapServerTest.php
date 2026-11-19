<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Services\Mail\MailProcessor;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnv;

/**
 * Lectura real por IMAP contra un servidor de pruebas (p. ej. Dovecot local).
 * Se omite si no se indican:
 *   TEST_IMAP_HOST, TEST_IMAP_PORT (SSL), TEST_IMAP_STARTTLS_PORT, TEST_IMAP_USER,
 *   TEST_IMAP_PASS y TEST_IMAP_MAILDIR (carpeta Maildir del buzón, para dejar correos).
 * El certificado del servidor se valida: ejecuta PHP con -d openssl.cafile=... si es autofirmado.
 */
final class ImapServerTest extends TestCase
{
    private static function env(string $k): string
    {
        return (string)(getenv($k) ?: '');
    }

    protected function setUp(): void
    {
        foreach (['TEST_IMAP_HOST', 'TEST_IMAP_PORT', 'TEST_IMAP_USER', 'TEST_IMAP_PASS', 'TEST_IMAP_MAILDIR'] as $k) {
            if (self::env($k) === '') {
                $this->markTestSkipped("Sin servidor IMAP de pruebas ($k)");
            }
        }
    }

    private function deliver(string $fixture, string $messageId): void
    {
        $raw = (string)file_get_contents(dirname(__DIR__) . '/fixtures/mail/' . $fixture);
        $raw = preg_replace('/^Message-ID: .*$/m', "Message-ID: <$messageId>", $raw);
        $dir = rtrim(self::env('TEST_IMAP_MAILDIR'), '/');
        foreach (['new', 'cur', 'tmp'] as $d) {
            if (!is_dir("$dir/$d")) {
                mkdir("$dir/$d", 0700, true);
            }
        }
        file_put_contents("$dir/new/" . microtime(true) . '.' . bin2hex(random_bytes(4)) . '.pruebas', str_replace("\n", "\r\n", (string)$raw));
    }

    private function account(string $encryption, int $port, string $password): array
    {
        $db = TestEnv::db();
        $now = Clock::nowDb();
        // Dirección única por prueba: cada cuenta empieza sin estado de lectura previo
        $address = "imap-$encryption-" . bin2hex(random_bytes(4)) . '@pruebas.test';
        $id = $db->insert('email_accounts', [
            'name' => "IMAP $encryption", 'address' => $address, 'auth_type' => 'password',
            'imap_host' => self::env('TEST_IMAP_HOST'), 'imap_port' => $port, 'imap_encryption' => $encryption,
            'imap_username' => self::env('TEST_IMAP_USER'), 'imap_secret_enc' => TestEnv::app()->crypto()->encrypt($password),
            'processed_folder' => 'Procesados', 'smtp_host' => 'smtp.invalid', 'smtp_username' => self::env('TEST_IMAP_USER'),
            'unknown_sender_policy' => 'review', 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $db->one('SELECT * FROM email_accounts WHERE id = ?', [$id]);
    }

    private function poll(int $accId): int
    {
        $acc = TestEnv::db()->one('SELECT * FROM email_accounts WHERE id = ?', [$accId]);
        return TestEnv::app()->make(MailProcessor::class)->poll($acc, microtime(true) + 30);
    }

    public function testPrimeraConexionIgnoraLoExistenteYDespuesProcesaYMueve(): void
    {
        TestEnv::domainClient();
        $this->deliver('desconocido.eml', 'antiguo-' . bin2hex(random_bytes(4)) . '@pruebas.test');
        $acc = $this->account('ssl', (int)self::env('TEST_IMAP_PORT'), self::env('TEST_IMAP_PASS'));

        $this->assertSame(0, $this->poll((int)$acc['id']), 'La primera conexión no importa lo que ya había');
        $after = TestEnv::db()->one('SELECT * FROM email_accounts WHERE id = ?', [(int)$acc['id']]);
        $this->assertNull($after['last_error']);
        $this->assertNotNull($after['last_uid']);

        $mid = 'imap-' . bin2hex(random_bytes(4)) . '@cliente-pruebas.es';
        $this->deliver('conocido_nuevo.eml', $mid);
        $this->assertSame(1, $this->poll((int)$acc['id']));
        $e = TestEnv::db()->one('SELECT * FROM email_messages WHERE message_id = ?', [$mid]);
        $this->assertNotNull($e);
        $this->assertSame('processed', $e['processing_status']);
        $this->assertNotNull($e['imap_uid']);
        $this->assertNotNull($e['ticket_id']);
        $this->assertSame(1, (int)TestEnv::db()->value("SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id = ? AND status = 'stored'", [(int)$e['ticket_id']]), 'El PDF viaja entero por IMAP');

        // Movido a la carpeta de procesados
        $moved = glob(rtrim(self::env('TEST_IMAP_MAILDIR'), '/') . '/.Procesados/{cur,new}/*', GLOB_BRACE) ?: [];
        $this->assertNotEmpty($moved);
        // Nada más que leer
        $this->assertSame(0, $this->poll((int)$acc['id']));
    }

    public function testStarttls(): void
    {
        $port = (int)self::env('TEST_IMAP_STARTTLS_PORT');
        if ($port === 0) {
            $this->markTestSkipped('Sin puerto STARTTLS de pruebas');
        }
        $acc = $this->account('starttls', $port, self::env('TEST_IMAP_PASS'));
        $this->poll((int)$acc['id']);
        $this->assertNull(TestEnv::db()->value('SELECT last_error FROM email_accounts WHERE id = ?', [(int)$acc['id']]));
    }

    public function testContrasenaIncorrectaSeRegistraSinFiltrarla(): void
    {
        $bad = 'contrasena-mala-' . bin2hex(random_bytes(4));
        $acc = $this->account('ssl', (int)self::env('TEST_IMAP_PORT'), $bad);
        $this->assertSame(0, $this->poll((int)$acc['id']));
        $err = (string)TestEnv::db()->value('SELECT last_error FROM email_accounts WHERE id = ?', [(int)$acc['id']]);
        $this->assertNotSame('', $err);
        $this->assertStringNotContainsString($bad, $err);
        $this->assertStringNotContainsString($bad, (string)TestEnv::db()->value('SELECT GROUP_CONCAT(message) FROM email_processing_log WHERE account_id = ?', [(int)$acc['id']]));
    }
}
