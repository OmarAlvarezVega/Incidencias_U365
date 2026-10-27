<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Core\App;
use App\Core\Clock;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\Attachments;
use App\Services\ClientService;
use App\Services\Jobs;
use App\Services\Notifications;
use App\Services\PermanentJobFailure;
use App\Services\Templates;
use App\Services\TicketService;

/**
 * WhatsApp por la API oficial Cloud de Meta.
 *
 * Webhook: se verifica la firma X-Hub-Signature-256 con el secreto de la
 * aplicación, se guarda el evento (deduplicado por hash) y se responde 200 al
 * momento; el contenido se procesa en la cola.
 *
 * Asignación de mensajes entrantes a incidencias:
 *  - Conversación con incidencia activa y abierta → se añade a ella.
 *  - Contacto con una sola incidencia abierta → se añade a esa.
 *  - Varias abiertas → según whatsapp.ambiguity_policy: preguntar al cliente
 *    con una lista numerada (ask_customer) o añadir a la más reciente.
 *  - Ninguna → nueva incidencia. Número desconocido → incidencia pendiente de
 *    identificar y se pide al cliente que se identifique.
 */
final class WhatsAppService
{
    public function __construct(private readonly App $app)
    {
    }

    public function enabled(): bool
    {
        return $this->app->settings()->bool('whatsapp.enabled', false) && $this->number() !== null;
    }

    /** @return array<string,mixed>|null */
    public function number(): ?array
    {
        return $this->app->db()->one('SELECT * FROM whatsapp_numbers WHERE active = 1 ORDER BY id LIMIT 1');
    }

    /** @param array<string,mixed>|null $number */
    public function api(?array $number = null): CloudApi
    {
        $number ??= $this->number();
        if ($number === null) {
            throw new \RuntimeException('No hay ningún número de WhatsApp activo.');
        }
        $token = $this->app->crypto()->decrypt($number['access_token_enc']);
        if ($token === null) {
            throw new \RuntimeException('Falta el token de acceso de WhatsApp.');
        }
        return new CloudApi((string)$number['phone_number_id'], $token, (string)$this->app->settings()->get('whatsapp.api_version', 'v21.0'));
    }

    // ------------------------------------------------------------------
    // Webhook
    // ------------------------------------------------------------------

    /** Verificación GET de Meta. Devuelve el challenge o null. */
    public function verifyChallenge(string $mode, string $token, string $challenge): ?string
    {
        $expected = $this->app->settings()->secret('whatsapp.verify_token');
        if ($mode !== 'subscribe' || $expected === null || $expected === '' || !hash_equals($expected, $token)) {
            return null;
        }
        return preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $challenge) ? $challenge : null;
    }

    public function validSignature(string $rawBody, ?string $header): bool
    {
        $secret = $this->app->settings()->secret('whatsapp.app_secret');
        if ($secret === null || $secret === '' || $header === null || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $rawBody, $secret), substr($header, 7));
    }

    /** Guarda el evento y lo encola. Devuelve false si era un duplicado. */
    public function store(string $rawBody): bool
    {
        $db = $this->app->db();
        $hash = hash('sha256', $rawBody);
        $n = $db->run(
            'INSERT IGNORE INTO whatsapp_webhook_events (received_at, signature_valid, payload_sha256, payload, processing_status) VALUES (?, 1, ?, ?, \'pending\')',
            [Clock::now()->format('Y-m-d H:i:s.v'), $hash, $rawBody]
        )->rowCount();
        if ($n === 0) {
            return false;
        }
        $id = $db->lastInsertId();
        $this->app->make(Jobs::class)->push('whatsapp.process_event', ['event_id' => $id], "wa-event:$id", 0, 5, 'default');
        return true;
    }

    public function processEvent(int $eventId): void
    {
        $db = $this->app->db();
        $ev = $db->one('SELECT * FROM whatsapp_webhook_events WHERE id = ?', [$eventId]);
        if ($ev === null || $ev['processing_status'] === 'processed') {
            return;
        }
        $db->run('UPDATE whatsapp_webhook_events SET attempts = attempts + 1 WHERE id = ?', [$eventId]);
        try {
            $payload = json_decode((string)$ev['payload'], true, 64, JSON_THROW_ON_ERROR);
            if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
                $db->run("UPDATE whatsapp_webhook_events SET processing_status = 'ignored', processed_at = ? WHERE id = ?", [Clock::nowDb(), $eventId]);
                return;
            }
            foreach ((array)($payload['entry'] ?? []) as $entry) {
                foreach ((array)($entry['changes'] ?? []) as $change) {
                    $value = (array)($change['value'] ?? []);
                    $pnid = (string)($value['metadata']['phone_number_id'] ?? '');
                    $number = $db->one('SELECT * FROM whatsapp_numbers WHERE phone_number_id = ?', [$pnid]);
                    if ($number === null) {
                        continue;
                    }
                    $profiles = [];
                    foreach ((array)($value['contacts'] ?? []) as $c) {
                        $profiles[(string)($c['wa_id'] ?? '')] = (string)($c['profile']['name'] ?? '');
                    }
                    foreach ((array)($value['statuses'] ?? []) as $st) {
                        $this->applyStatus((array)$st);
                    }
                    foreach ((array)($value['messages'] ?? []) as $m) {
                        $this->inbound($number, (array)$m, $profiles[(string)($m['from'] ?? '')] ?? '');
                    }
                }
            }
            $db->run("UPDATE whatsapp_webhook_events SET processing_status = 'processed', processed_at = ?, error = NULL WHERE id = ?", [Clock::nowDb(), $eventId]);
        } catch (\Throwable $e) {
            $db->run("UPDATE whatsapp_webhook_events SET processing_status = 'failed', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 1000), $eventId]);
            throw $e;
        }
    }

    /** @param array<string,mixed> $st */
    private function applyStatus(array $st): void
    {
        $db = $this->app->db();
        $wamid = (string)($st['id'] ?? '');
        $status = (string)($st['status'] ?? '');
        if ($wamid === '' || !in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }
        $order = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        $row = $db->one('SELECT id, status, ticket_message_id, ticket_id FROM whatsapp_messages WHERE wamid = ?', [$wamid]);
        if ($row === null || ($order[$status] <= ($order[$row['status']] ?? 0) && $status !== 'failed')) {
            return;
        }
        $err = $st['errors'][0] ?? null;
        $db->run(
            'UPDATE whatsapp_messages SET status = ?, status_updated_at = ?, error_code = ?, error_message = ? WHERE id = ?',
            [$status, Clock::nowDb(), $err ? mb_substr((string)($err['code'] ?? ''), 0, 20) : null, $err ? mb_substr((string)($err['title'] ?? $err['message'] ?? ''), 0, 500) : null, (int)$row['id']]
        );
        if ($row['ticket_message_id'] !== null) {
            $db->run('UPDATE ticket_messages SET delivery_status = ? WHERE id = ?', [$status, (int)$row['ticket_message_id']]);
        }
        if ($status === 'failed' && $row['ticket_id'] !== null) {
            $n = $this->app->make(Notifications::class);
            $n->notify($n->ticketWorkers((int)$row['ticket_id']), 'ticket.customer_reply', (int)$row['ticket_id'], 'WhatsApp no entregado', $err ? (string)($err['title'] ?? '') : null, 'wa.failed.' . $row['id']);
        }
    }

    /** @param array<string,mixed> $number @param array<string,mixed> $m */
    private function inbound(array $number, array $m, string $profileName): void
    {
        $db = $this->app->db();
        $wamid = (string)($m['id'] ?? '');
        $from = preg_replace('/\D/', '', (string)($m['from'] ?? '')) ?? '';
        if ($wamid === '' || $from === '' || $db->value('SELECT 1 FROM whatsapp_messages WHERE wamid = ?', [$wamid])) {
            return;
        }
        $type = (string)($m['type'] ?? 'unknown');
        $text = match ($type) {
            'text' => (string)($m['text']['body'] ?? ''),
            'image', 'video', 'document', 'audio', 'sticker' => (string)($m[$type]['caption'] ?? ''),
            'button' => (string)($m['button']['text'] ?? ''),
            'interactive' => (string)($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''),
            'location' => 'Ubicación: ' . ($m['location']['latitude'] ?? '') . ', ' . ($m['location']['longitude'] ?? '') . ' ' . ($m['location']['name'] ?? ''),
            default => '',
        };
        $mediaId = in_array($type, ['image', 'video', 'document', 'audio', 'sticker'], true) ? (string)($m[$type]['id'] ?? '') : null;
        $ts = isset($m['timestamp']) && ctype_digit((string)$m['timestamp']) ? gmdate(Clock::DB_FORMAT, (int)$m['timestamp']) : null;
        $now = Clock::nowDb();

        $conv = $db->one('SELECT * FROM whatsapp_conversations WHERE number_id = ? AND wa_id = ?', [(int)$number['id'], $from]);
        $sender = $this->app->make(ClientService::class)->resolveSender('phone', $from);
        if ($conv === null) {
            $convId = $db->insert('whatsapp_conversations', [
                'number_id' => (int)$number['id'], 'wa_id' => $from, 'profile_name' => mb_substr($profileName, 0, 120) ?: null,
                'contact_id' => $sender['contact_id'], 'state' => 'idle', 'last_inbound_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $conv = $db->one('SELECT * FROM whatsapp_conversations WHERE id = ?', [$convId]);
        } else {
            $db->run('UPDATE whatsapp_conversations SET last_inbound_at = ?, updated_at = ?, contact_id = COALESCE(?, contact_id), profile_name = COALESCE(?, profile_name) WHERE id = ?',
                [$now, $now, $sender['contact_id'], $profileName !== '' ? mb_substr($profileName, 0, 120) : null, (int)$conv['id']]);
            $conv = $db->one('SELECT * FROM whatsapp_conversations WHERE id = ?', [(int)$conv['id']]);
        }
        $waMsgId = $db->insert('whatsapp_messages', [
            'conversation_id' => (int)$conv['id'], 'wamid' => $wamid, 'direction' => 'inbound', 'msg_type' => mb_substr($type, 0, 20),
            'body_text' => $text !== '' ? $text : null, 'media_id' => $mediaId, 'status' => 'received', 'meta_timestamp' => $ts, 'created_at' => $now,
        ]);

        $tickets = $this->app->make(TicketService::class);
        $label = trim(($profileName !== '' ? $profileName . ' ' : '') . '+' . $from);
        $contactId = $sender['contact_id'];

        // ¿Responde a la pregunta de qué incidencia?
        if ($conv['state'] === 'awaiting_ticket_choice' && $type === 'text') {
            $choice = strtoupper(trim($text));
            $open = $this->openTickets($contactId, $from);
            if ($choice === 'NUEVA' || $choice === 'NUEVO') {
                $db->run('DELETE FROM whatsapp_messages WHERE id = ?', [$waMsgId]);
                $first = (string)$db->value("SELECT body_text FROM whatsapp_messages WHERE conversation_id = ? AND direction = 'inbound' AND ticket_id IS NULL ORDER BY id LIMIT 1", [(int)$conv['id']]);
                $tid = $this->newTicket($from, $sender, $first, $label);
                $this->attachPending((int)$conv['id'], $tid, $contactId, $label);
                $this->setConversation((int)$conv['id'], $tid, 'idle');
                return;
            }
            if (ctype_digit($choice) && isset($open[(int)$choice - 1])) {
                $tid = (int)$open[(int)$choice - 1]['id'];
                $db->run('DELETE FROM whatsapp_messages WHERE id = ?', [$waMsgId]); // la elección no es contenido
                $this->attachPending((int)$conv['id'], $tid, $contactId, $label);
                $this->setConversation((int)$conv['id'], $tid, 'idle');
                return;
            }
        }

        $targetId = null;
        if ($conv['active_ticket_id'] !== null) {
            $t = $db->one('SELECT t.id, s.phase FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.id = ? AND t.deleted_at IS NULL', [(int)$conv['active_ticket_id']]);
            if ($t !== null && !in_array($t['phase'], ['closed', 'cancelled'], true)) {
                $targetId = (int)$t['id'];
            }
        }
        if ($targetId === null) {
            $open = $this->openTickets($contactId, $from);
            if (count($open) === 1) {
                $targetId = (int)$open[0]['id'];
            } elseif (count($open) > 1) {
                if ($this->app->settings()->get('whatsapp.ambiguity_policy', 'ask_customer') === 'ask_customer') {
                    $this->setConversation((int)$conv['id'], null, 'awaiting_ticket_choice');
                    $list = [];
                    foreach (array_slice($open, 0, 9) as $i => $o) {
                        $list[] = ($i + 1) . ') ' . $o['ticket_number'] . ' · ' . mb_substr((string)$o['subject'], 0, 60);
                    }
                    $msg = $this->app->make(Templates::class)->render('whatsapp', 'choose_ticket', ['tickets.list' => implode("\n", $list)]);
                    $this->sendSystem($number, (int)$conv['id'], $from, $msg['body']);
                    return; // el mensaje queda pendiente hasta que elija
                }
                $targetId = (int)$open[0]['id'];
            }
        }
        if ($targetId === null) {
            $targetId = $this->newTicket($from, $sender, $text, $label);
            if ($sender['client_id'] === null && $conv['state'] !== 'awaiting_identification') {
                $msg = $this->app->make(Templates::class)->render('whatsapp', 'ask_identification', []);
                $this->sendSystem($number, (int)$conv['id'], $from, $msg['body'], $targetId);
                $this->setConversation((int)$conv['id'], $targetId, 'awaiting_identification');
            } else {
                $this->setConversation((int)$conv['id'], $targetId, 'idle');
                $ack = $this->app->make(Templates::class)->render('whatsapp', 'ticket_created_ack', $tickets->templateVars($targetId));
                $this->sendSystem($number, (int)$conv['id'], $from, $ack['body'], $targetId);
            }
        } else {
            $this->setConversation((int)$conv['id'], $targetId, $conv['state'] === 'awaiting_identification' ? 'awaiting_identification' : 'idle');
        }
        $this->attachPending((int)$conv['id'], $targetId, $contactId, $label);
    }

    /** Une a la incidencia los mensajes de la conversación que aún no tienen incidencia. */
    private function attachPending(int $convId, int $ticketId, ?int $contactId, string $label): void
    {
        $db = $this->app->db();
        $tickets = $this->app->make(TicketService::class);
        $pending = $db->all("SELECT * FROM whatsapp_messages WHERE conversation_id = ? AND direction = 'inbound' AND ticket_id IS NULL ORDER BY id", [$convId]);
        foreach ($pending as $p) {
            $ticket = $db->one('SELECT * FROM tickets WHERE id = ?', [$ticketId]);
            $body = (string)($p['body_text'] ?? '');
            if ($body === '' && $p['media_id'] !== null) {
                $body = '(' . self::typeLabel((string)$p['msg_type']) . ')';
            }
            $tmId = $tickets->addInbound($ticket, 'whatsapp', $contactId, $label, $body, null);
            $db->run('UPDATE whatsapp_messages SET ticket_id = ?, ticket_message_id = ? WHERE id = ?', [$ticketId, $tmId, (int)$p['id']]);
            if ($p['media_id'] !== null && $p['media_id'] !== '') {
                $this->app->make(Jobs::class)->push('whatsapp.download_media', ['whatsapp_message_id' => (int)$p['id']], 'wa-media:' . $p['id']);
            }
        }
    }

    public function downloadMedia(int $waMessageId): void
    {
        $db = $this->app->db();
        $w = $db->one('SELECT * FROM whatsapp_messages WHERE id = ?', [$waMessageId]);
        if ($w === null || $w['attachment_id'] !== null || $w['media_id'] === null || $w['ticket_id'] === null) {
            return;
        }
        $att = $this->app->make(Attachments::class);
        $file = $this->api()->downloadMedia((string)$w['media_id'], $att->maxBytes());
        $ext = self::extensionFor($file['mime']);
        $name = 'whatsapp-' . substr((string)$w['wamid'], -10) . '.' . $ext;
        $id = $att->storeContent((int)$w['ticket_id'], $file['content'], preg_replace('/[^A-Za-z0-9.\-_]/', '', $name) ?: 'whatsapp.bin', $w['ticket_message_id'] !== null ? (int)$w['ticket_message_id'] : null, 'public');
        $db->run('UPDATE whatsapp_messages SET attachment_id = ? WHERE id = ?', [$id, $waMessageId]);
    }

    /** @param array{contact_id:?int,client_id:?int,method:string} $sender */
    private function newTicket(string $from, array $sender, string $text, string $label): int
    {
        $subject = trim(mb_substr((string)preg_replace('/\s+/', ' ', $text), 0, 80));
        return $this->app->make(TicketService::class)->create([
            'subject' => $subject !== '' ? 'WhatsApp: ' . $subject : 'WhatsApp de ' . $label,
            'client_id' => $sender['client_id'],
            'contact_id' => $sender['contact_id'],
            'requester_phone' => '+' . $from,
        ], null, 'whatsapp');
    }

    /** @return list<array<string,mixed>> */
    private function openTickets(?int $contactId, string $waId): array
    {
        $where = $contactId !== null ? 't.contact_id = ?' : 't.requester_phone = ?';
        return $this->app->db()->all(
            "SELECT t.id, t.ticket_number, t.subject FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id
              WHERE $where AND s.phase IN ('new','open','pending','resolved') AND t.deleted_at IS NULL
              ORDER BY t.last_activity_at DESC, t.id DESC LIMIT 9",
            [$contactId ?? '+' . $waId]
        );
    }

    private function setConversation(int $convId, ?int $ticketId, string $state): void
    {
        $this->app->db()->run('UPDATE whatsapp_conversations SET active_ticket_id = ?, state = ?, updated_at = ? WHERE id = ?', [$ticketId, $state, Clock::nowDb(), $convId]);
    }

    /** Mensaje automático del sistema (dentro de la ventana, porque responde a uno entrante). */
    private function sendSystem(array $number, int $convId, string $to, string $body, ?int $ticketId = null): void
    {
        $db = $this->app->db();
        $now = Clock::nowDb();
        $tmId = null;
        if ($ticketId !== null) {
            $tmId = $db->insert('ticket_messages', [
                'ticket_id' => $ticketId, 'direction' => 'outbound', 'channel_code' => 'whatsapp', 'sender_label' => 'Respuesta automática',
                'body_text' => $body, 'is_automatic' => 1, 'delivery_status' => 'queued', 'created_at' => $now,
            ]);
        }
        try {
            $wamid = $this->api($number)->sendText($to, $body);
            $db->insert('whatsapp_messages', [
                'conversation_id' => $convId, 'wamid' => $wamid, 'direction' => 'outbound', 'ticket_id' => $ticketId, 'ticket_message_id' => $tmId,
                'msg_type' => 'text', 'body_text' => $body, 'status' => 'sent', 'created_at' => $now,
            ]);
            if ($tmId !== null) {
                $db->run("UPDATE ticket_messages SET delivery_status = 'sent' WHERE id = ?", [$tmId]);
            }
        } catch (\Throwable $e) {
            if ($tmId !== null) {
                $db->run("UPDATE ticket_messages SET delivery_status = 'failed' WHERE id = ?", [$tmId]);
            }
            $this->app->logger()->warning('WhatsApp automático no enviado: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Respuestas de los técnicos
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $ticket @return array<string,mixed>|null */
    public function conversationFor(array $ticket): ?array
    {
        $db = $this->app->db();
        $conv = $db->one('SELECT c.* FROM whatsapp_conversations c JOIN whatsapp_messages m ON m.conversation_id = c.id WHERE m.ticket_id = ? ORDER BY m.id DESC LIMIT 1', [(int)$ticket['id']]);
        if ($conv !== null) {
            return $conv;
        }
        $phones = [];
        if ($ticket['requester_phone']) {
            $phones[] = Validator::normalizePhone((string)$ticket['requester_phone']);
        }
        if ($ticket['contact_id'] !== null) {
            foreach ($db->column("SELECT value_normalized FROM contact_identities WHERE contact_id = ? AND kind = 'phone'", [(int)$ticket['contact_id']]) as $p) {
                $phones[] = (string)$p;
            }
        }
        if ($phones === []) {
            return null;
        }
        return $db->one('SELECT * FROM whatsapp_conversations WHERE wa_id IN (' . implode(',', array_fill(0, count($phones), '?')) . ') ORDER BY last_inbound_at DESC LIMIT 1', $phones);
    }

    /** @param array<string,mixed> $conv */
    public static function windowOpen(?array $conv): bool
    {
        $last = $conv !== null ? Clock::fromDb($conv['last_inbound_at']) : null;
        return $last !== null && Clock::now()->getTimestamp() - $last->getTimestamp() < 24 * 3600 - 60;
    }

    /** @param array<string,mixed> $ticket */
    public function assertCanReply(array $ticket): void
    {
        if (!$this->enabled()) {
            throw ValidationException::single('channel', 'WhatsApp no está activado.');
        }
        $conv = $this->conversationFor($ticket);
        if ($conv === null) {
            throw ValidationException::single('channel', 'El cliente no ha escrito nunca por WhatsApp desde un número asociado a esta incidencia.');
        }
        if (!self::windowOpen($conv) && $this->app->settings()->get('whatsapp.reopen_template') === null) {
            throw ValidationException::single('channel', 'Han pasado más de 24 h desde el último mensaje del cliente. WhatsApp solo permite escribirle con una plantilla aprobada por Meta (configúrala en Administración → WhatsApp) o responde por correo.');
        }
    }

    /** Trabajo de la cola: envía la respuesta de un técnico. */
    public function sendReply(int $ticketMessageId): void
    {
        $db = $this->app->db();
        $m = $db->one('SELECT * FROM ticket_messages WHERE id = ?', [$ticketMessageId]);
        if ($m === null || in_array($m['delivery_status'], ['sent', 'delivered', 'read'], true)) {
            return;
        }
        $ticket = $db->one('SELECT * FROM tickets WHERE id = ?', [(int)$m['ticket_id']]);
        $conv = $this->conversationFor($ticket);
        if ($conv === null) {
            $db->run("UPDATE ticket_messages SET delivery_status = 'failed' WHERE id = ?", [$ticketMessageId]);
            throw new PermanentJobFailure('Sin conversación de WhatsApp.');
        }
        $number = $db->one('SELECT * FROM whatsapp_numbers WHERE id = ?', [(int)$conv['number_id']]);
        $api = $this->api($number);
        $vars = $this->app->make(TicketService::class)->templateVars((int)$ticket['id']) + ['message' => (string)$m['body_text'], 'agent.name' => (string)$m['sender_label']];
        $now = Clock::nowDb();
        try {
            if (self::windowOpen($conv)) {
                $body = $this->app->make(Templates::class)->render('whatsapp', 'ticket_reply', $vars)['body'];
                $att = $db->one("SELECT * FROM ticket_attachments WHERE message_id = ? AND status = 'stored' AND deleted_at IS NULL LIMIT 1", [$ticketMessageId]);
                if ($att !== null) {
                    $svc = $this->app->make(Attachments::class);
                    $mediaId = $api->uploadMedia($svc->path((string)$att['storage_key']), (string)$att['mime_type'], (string)$att['original_name']);
                    $type = str_starts_with((string)$att['mime_type'], 'image/') ? 'image' : 'document';
                    $wamid = $api->sendMedia((string)$conv['wa_id'], $type, $mediaId, (string)$att['original_name'], $body);
                    $msgType = $type;
                } else {
                    $wamid = $api->sendText((string)$conv['wa_id'], $body);
                    $msgType = 'text';
                }
                $tplName = null;
            } else {
                $tpl = (array)$this->app->settings()->get('whatsapp.reopen_template', []);
                if (empty($tpl['name'])) {
                    throw new PermanentJobFailure('Fuera de la ventana de 24 h y sin plantilla configurada.');
                }
                $wamid = $api->sendTemplate((string)$conv['wa_id'], (string)$tpl['name'], (string)($tpl['lang'] ?? 'es'), !empty($tpl['with_text']) ? [(string)$m['body_text']] : []);
                $msgType = 'template';
                $tplName = (string)$tpl['name'];
            }
        } catch (\Throwable $e) {
            $db->run("UPDATE ticket_messages SET delivery_status = 'failed' WHERE id = ?", [$ticketMessageId]);
            throw $e;
        }
        $db->insert('whatsapp_messages', [
            'conversation_id' => (int)$conv['id'], 'wamid' => $wamid, 'direction' => 'outbound', 'ticket_id' => (int)$ticket['id'],
            'ticket_message_id' => $ticketMessageId, 'msg_type' => $msgType, 'body_text' => (string)$m['body_text'], 'template_name' => $tplName,
            'status' => 'sent', 'sent_by_user_id' => $m['author_user_id'], 'created_at' => $now,
        ]);
        $db->run("UPDATE ticket_messages SET delivery_status = 'sent' WHERE id = ?", [$ticketMessageId]);
        $db->run('UPDATE whatsapp_conversations SET active_ticket_id = ?, updated_at = ? WHERE id = ?', [(int)$ticket['id'], $now, (int)$conv['id']]);
    }

    private static function typeLabel(string $type): string
    {
        return match ($type) {
            'image' => 'imagen', 'video' => 'vídeo', 'audio' => 'nota de voz', 'document' => 'documento', 'sticker' => 'sticker', default => $type,
        };
    }

    private static function extensionFor(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
            'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'video/mp4' => 'mp4', 'text/plain' => 'txt',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/msword' => 'doc', 'application/vnd.ms-excel' => 'xls', 'application/zip' => 'zip',
            default => 'bin',
        };
    }
}
