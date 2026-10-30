<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\App;
use App\Core\Clock;
use App\Services\Attachments;
use App\Services\PermanentJobFailure;
use App\Services\Templates;
use App\Services\TicketService;

/**
 * Envío de correos al cliente (trabajos de la cola). Mantiene el hilo:
 * responde desde el buzón por el que entró la incidencia, con In-Reply-To al
 * último correo del cliente y References con el historial.
 */
final class MailOutbound
{
    public function __construct(private readonly App $app)
    {
    }

    public function sendReply(int $ticketMessageId): void
    {
        $db = $this->app->db();
        $m = $db->one('SELECT * FROM ticket_messages WHERE id = ?', [$ticketMessageId]);
        if ($m === null) {
            throw new PermanentJobFailure('El mensaje ya no existe.');
        }
        if (in_array($m['delivery_status'], ['sent', 'delivered', 'read'], true)) {
            return;
        }
        $ticket = $db->one('SELECT * FROM tickets WHERE id = ?', [(int)$m['ticket_id']]);
        $tickets = $this->app->make(TicketService::class);
        $to = $tickets->customerEmail($ticket);
        if ($to === null) {
            $db->run("UPDATE ticket_messages SET delivery_status = 'failed' WHERE id = ?", [$ticketMessageId]);
            throw new PermanentJobFailure('La incidencia no tiene correo de cliente.');
        }
        $vars = $tickets->templateVars((int)$ticket['id']) + [
            'message' => (string)$m['body_text'],
            'agent.name' => (string)($m['sender_label'] ?? ''),
        ];
        $rendered = $this->app->make(Templates::class)->render('email', 'ticket_reply', $vars);
        $attachments = [];
        $svc = $this->app->make(Attachments::class);
        foreach ($db->all("SELECT * FROM ticket_attachments WHERE message_id = ? AND status = 'stored' AND deleted_at IS NULL", [$ticketMessageId]) as $a) {
            $attachments[] = ['path' => $svc->path((string)$a['storage_key']), 'name' => (string)$a['original_name'], 'mime' => (string)$a['mime_type']];
        }
        $this->deliver($ticket, $to, $rendered['subject'], $rendered['body'], $ticketMessageId, $attachments, null);
    }

    public function sendCustomerNotice(int $ticketId, string $template, ?int $replyToEmailId = null): void
    {
        $db = $this->app->db();
        $ticket = $db->one('SELECT * FROM tickets WHERE id = ?', [$ticketId]);
        if ($ticket === null) {
            throw new PermanentJobFailure('La incidencia no existe.');
        }
        $tickets = $this->app->make(TicketService::class);
        $to = $tickets->customerEmail($ticket);
        if ($to === null) {
            return;
        }
        $rendered = $this->app->make(Templates::class)->render('email', $template, $tickets->templateVars($ticketId));
        $now = Clock::nowDb();
        $tmId = $db->insert('ticket_messages', [
            'ticket_id' => $ticketId,
            'direction' => 'outbound',
            'channel_code' => 'email',
            'author_user_id' => null,
            'sender_label' => 'Aviso automático',
            'body_text' => $rendered['body'],
            'is_automatic' => 1,
            'delivery_status' => 'queued',
            'created_at' => $now,
        ]);
        try {
            $this->deliver($ticket, $to, $rendered['subject'], $rendered['body'], $tmId, [], $replyToEmailId, true);
        } catch (\Throwable $e) {
            // El aviso queda registrado como fallido; el reintento crea uno nuevo
            $db->run("UPDATE ticket_messages SET delivery_status = 'failed' WHERE id = ?", [$tmId]);
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $ticket
     * @param list<array{path:string,name:string,mime:string}> $attachments
     */
    private function deliver(array $ticket, string $to, string $subject, string $body, int $ticketMessageId, array $attachments, ?int $replyToEmailId, bool $auto = false): void
    {
        $db = $this->app->db();
        $accounts = $this->app->make(EmailAccounts::class);
        $tid = (int)$ticket['id'];

        $reply = $replyToEmailId !== null
            ? $db->one('SELECT * FROM email_messages WHERE id = ?', [$replyToEmailId])
            : $db->one("SELECT * FROM email_messages WHERE ticket_id = ? AND direction = 'inbound' ORDER BY id DESC LIMIT 1", [$tid]);
        $account = null;
        if ($reply !== null) {
            $account = $db->one('SELECT * FROM email_accounts WHERE id = ? AND active = 1', [(int)$reply['account_id']]);
        }
        $account ??= $accounts->default();
        if ($account === null) {
            throw new \RuntimeException('No hay ningún buzón activo para enviar.');
        }
        $refs = $db->column('SELECT message_id FROM email_messages WHERE ticket_id = ? ORDER BY id', [$tid]);
        $messageId = Mailer::newMessageId(substr((string)strrchr((string)$account['address'], '@'), 1));

        // Se registra antes de enviar: si el envío funciona pero el proceso muere,
        // la respuesta del cliente seguirá encontrando la incidencia.
        $emailId = $db->insert('email_messages', [
            'account_id' => (int)$account['id'],
            'direction' => 'outbound',
            'ticket_id' => $tid,
            'ticket_message_id' => $ticketMessageId,
            'message_id' => $messageId,
            'message_id_hash' => hash('sha256', strtolower($messageId)),
            'in_reply_to' => $reply['message_id'] ?? null,
            'references_header' => $refs !== [] ? implode(' ', array_slice($refs, -20)) : null,
            'from_address' => (string)$account['address'],
            'to_addresses' => json_encode([$to]),
            'subject' => mb_substr($subject, 0, 998),
            'received_at' => Clock::nowDb(),
            'processing_status' => 'pending',
            'created_at' => Clock::nowDb(),
        ]);
        try {
            $this->app->make(Mailer::class)->send($account, $to, $subject, $body, $reply['message_id'] ?? null, $refs, $attachments, $auto, $messageId);
        } catch (\Throwable $e) {
            $db->run("UPDATE email_messages SET processing_status = 'failed', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 1000), $emailId]);
            $db->run("UPDATE ticket_messages SET delivery_status = 'failed' WHERE id = ?", [$ticketMessageId]);
            throw $e;
        }
        $db->run("UPDATE email_messages SET processing_status = 'processed', sent_at = ? WHERE id = ?", [Clock::nowDb(), $emailId]);
        $db->run("UPDATE ticket_messages SET delivery_status = 'sent' WHERE id = ?", [$ticketMessageId]);
    }
}
