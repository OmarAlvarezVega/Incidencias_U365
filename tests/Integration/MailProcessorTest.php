<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Services\ClientService;
use App\Services\Mail\MailProcessor;
use App\Services\TicketService;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnv;

/** Entrada de correo con ficheros .eml reales (sin servidor IMAP). */
final class MailProcessorTest extends TestCase
{
    private static array $acc;
    private static int $clientId;

    public static function setUpBeforeClass(): void
    {
        $db = TestEnv::db();
        $now = Clock::nowDb();
        $id = $db->value('SELECT id FROM email_accounts WHERE address = ?', ['soporte@pruebas.test']);
        if ($id === null) {
            $id = $db->insert('email_accounts', [
                'name' => 'Pruebas', 'address' => 'soporte@pruebas.test', 'imap_host' => 'imap.invalid', 'imap_username' => 'soporte@pruebas.test',
                'smtp_host' => 'smtp.invalid', 'smtp_username' => 'soporte@pruebas.test', 'unknown_sender_policy' => 'review',
                'active' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        self::$acc = $db->one('SELECT * FROM email_accounts WHERE id = ?', [$id]);
        $clients = TestEnv::app()->make(ClientService::class);
        $cid = $db->value("SELECT id FROM clients WHERE legal_name = 'Cliente Correo Pruebas SL'");
        if ($cid === null) {
            $cid = $clients->createClient(['client_type' => 'company', 'legal_name' => 'Cliente Correo Pruebas SL'], TestEnv::adminId());
            $clients->addDomain((int)$cid, 'cliente-pruebas.es');
        }
        self::$clientId = (int)$cid;
    }

    private function ingest(string $fixture, array $vars = []): array
    {
        $raw = (string)file_get_contents(dirname(__DIR__) . '/fixtures/mail/' . $fixture);
        $raw = strtr(str_replace("\n", "\r\n", $raw), $vars);
        $id = TestEnv::app()->make(MailProcessor::class)->ingest(self::$acc, $raw);
        return TestEnv::db()->one('SELECT * FROM email_messages WHERE id = ?', [$id]);
    }

    public function testRemitenteDeDominioClienteCreaIncidenciaYSaneaHtml(): void
    {
        $e = $this->ingest('conocido_nuevo.eml');
        $this->assertSame('processed', $e['processing_status']);
        $this->assertSame('new_ticket', $e['match_method']);
        $t = TestEnv::db()->one('SELECT * FROM tickets WHERE id = ?', [(int)$e['ticket_id']]);
        $this->assertSame(self::$clientId, (int)$t['client_id']);
        $this->assertSame('email', $t['channel_code']);

        $html = (string)TestEnv::db()->value('SELECT body_html_safe FROM ticket_messages WHERE id = ?', [(int)$e['ticket_message_id']]);
        $this->assertStringContainsString('no va el wifi', $html);
        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $html);

        $att = [];
        foreach (TestEnv::db()->all('SELECT original_name, status, storage_key FROM ticket_attachments WHERE ticket_id = ?', [(int)$t['id']]) as $r) {
            $att[$r['original_name']] = $r;
        }
        $this->assertSame('stored', $att['informe.pdf']['status']);
        // El .php queda anotado como rechazado (para que el técnico sepa que existía) pero sin fichero en disco
        $this->assertSame('rejected', $att['virus.php']['status']);
        $this->assertFileDoesNotExist(TestEnv::app()->make(\App\Services\Attachments::class)->path($att['virus.php']['storage_key']));
    }

    public function testElMismoCorreoDosVecesNoDuplica(): void
    {
        $a = $this->ingest('conocido_nuevo.eml');
        $b = $this->ingest('conocido_nuevo.eml');
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, (int)TestEnv::db()->value("SELECT COUNT(*) FROM email_messages WHERE message_id = 'nuevo-001@cliente-pruebas.es'"));
    }

    public function testRespuestaConEtiquetaEnElAsuntoSeHilaEnLaIncidencia(): void
    {
        $first = $this->ingest('conocido_nuevo.eml');
        $number = (string)TestEnv::db()->value('SELECT ticket_number FROM tickets WHERE id = ?', [(int)$first['ticket_id']]);
        $e = $this->ingest('respuesta_etiqueta.eml', ['{{NUMERO}}' => $number]);
        $this->assertSame('processed', $e['processing_status']);
        $this->assertSame((int)$first['ticket_id'], (int)$e['ticket_id']);
        $this->assertContains($e['match_method'], ['headers', 'subject_tag']);
    }

    public function testUnTerceroNoPuedeColarseConLaEtiqueta(): void
    {
        $first = $this->ingest('conocido_nuevo.eml');
        $number = (string)TestEnv::db()->value('SELECT ticket_number FROM tickets WHERE id = ?', [(int)$first['ticket_id']]);
        $e = $this->ingest('respuesta_ajena.eml', ['{{NUMERO}}' => $number]);
        $this->assertSame('review', $e['processing_status']);
        $this->assertNull($e['ticket_id']);
    }

    public function testRemitenteDesconocidoVaARevision(): void
    {
        $e = $this->ingest('desconocido.eml');
        $this->assertSame('review', $e['processing_status']);
        $this->assertNull($e['ticket_id']);
    }

    public function testRespuestaAutomaticaSinIncidenciaSeIgnora(): void
    {
        $e = $this->ingest('autorespuesta.eml');
        $this->assertSame('ignored', $e['processing_status']);
        $this->assertSame(1, (int)$e['is_auto_submitted']);
    }

    public function testCorreoDeUnBuzonPropioSeIgnoraParaEvitarBucles(): void
    {
        $e = $this->ingest('bucle.eml');
        $this->assertSame('ignored', $e['processing_status']);
        $this->assertNull($e['ticket_id']);
    }

    public function testRespuestaAIncidenciaCerradaAbreOtraRelacionada(): void
    {
        $svc = TestEnv::app()->make(TicketService::class);
        $admin = TestEnv::actor(TestEnv::adminId());
        $old = $svc->create(['subject' => 'Impresora', 'client_id' => self::$clientId, 'requester_email' => 'marta@cliente-pruebas.es'], $admin);
        $closed = (int)TestEnv::db()->value("SELECT id FROM ticket_statuses WHERE code = 'closed'");
        $svc->changeStatus(TestEnv::db()->one('SELECT * FROM tickets WHERE id = ?', [$old]), $closed, $admin, false, true);
        $number = (string)TestEnv::db()->value('SELECT ticket_number FROM tickets WHERE id = ?', [$old]);

        $e = $this->ingest('respuesta_cerrada.eml', ['{{NUMERO}}' => $number]);
        $this->assertSame('processed', $e['processing_status']);
        $this->assertNotSame($old, (int)$e['ticket_id']);
        $this->assertSame(1, (int)TestEnv::db()->value('SELECT COUNT(*) FROM ticket_relations WHERE ticket_id = ? AND related_ticket_id = ?', [(int)$e['ticket_id'], $old]));
    }
}
