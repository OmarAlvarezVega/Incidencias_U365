<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Services\WhatsApp\WhatsAppService;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnv;

/** Webhook oficial de WhatsApp Cloud API: almacenamiento, duplicados y creación de incidencia. Sin llamadas a Meta. */
final class WhatsAppTest extends TestCase
{
    private const PNID = '100000000000001';

    public static function setUpBeforeClass(): void
    {
        $db = TestEnv::db();
        if (!$db->value('SELECT 1 FROM whatsapp_numbers WHERE phone_number_id = ?', [self::PNID])) {
            $now = Clock::nowDb();
            $db->insert('whatsapp_numbers', ['phone_number_id' => self::PNID, 'waba_id' => '200000000000002', 'display_phone' => '+34 600 000 000', 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private static function payload(string $wamid, string $from, string $text): string
    {
        return json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '200000000000002', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '34600000000', 'phone_number_id' => self::PNID],
            'contacts' => [['profile' => ['name' => 'Cliente Prueba'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => $wamid, 'timestamp' => (string)time(), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]], JSON_THROW_ON_ERROR);
    }

    public function testEventoDuplicadoNoSeGuardaDosVeces(): void
    {
        $wa = TestEnv::app()->make(WhatsAppService::class);
        $body = self::payload('wamid.DUP1', '34611111111', 'Hola');
        $this->assertTrue($wa->store($body));
        $this->assertFalse($wa->store($body));
    }

    public function testNumeroDesconocidoCreaIncidenciaPendienteDeIdentificar(): void
    {
        $wa = TestEnv::app()->make(WhatsAppService::class);
        $db = TestEnv::db();
        $body = self::payload('wamid.NEW1', '34622222222', 'No me funciona el correo');
        $this->assertTrue($wa->store($body));
        $eventId = (int)$db->value('SELECT id FROM whatsapp_webhook_events WHERE payload_sha256 = ?', [hash('sha256', $body)]);
        $wa->processEvent($eventId);

        $this->assertSame('processed', $db->value('SELECT processing_status FROM whatsapp_webhook_events WHERE id = ?', [$eventId]));
        $t = $db->one("SELECT t.* FROM whatsapp_messages m JOIN tickets t ON t.id = m.ticket_id WHERE m.wamid = 'wamid.NEW1'");
        $this->assertNotNull($t, 'El mensaje queda unido a una incidencia');
        $this->assertSame('whatsapp', $t['channel_code']);
        $this->assertSame('pending', $t['identification_status']);
        $this->assertNull($t['client_id']);

        // Reprocesar el mismo evento no duplica nada
        $wa->processEvent($eventId);
        $this->assertSame(1, (int)$db->value("SELECT COUNT(*) FROM whatsapp_messages WHERE wamid = 'wamid.NEW1'"));
    }

    public function testFirmaDelWebhook(): void
    {
        $wa = TestEnv::app()->make(WhatsAppService::class);
        $this->assertFalse($wa->validSignature('{}', null));
        $this->assertFalse($wa->validSignature('{}', 'sha256=' . str_repeat('0', 64)));
    }
}
