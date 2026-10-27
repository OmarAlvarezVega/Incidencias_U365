<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\App;
use App\Core\Clock;
use App\Core\Validator;
use App\Services\Attachments;
use App\Services\ClientService;
use App\Services\Jobs;
use App\Services\Notifications;
use App\Services\TicketService;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\IdHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Lectura de buzones y conversión de correos en incidencias.
 *
 * Orden de decisión para cada correo:
 *  1. Duplicado (mismo Message-ID ya procesado) → se ignora.
 *  2. Bucle (lo envió uno de nuestros buzones) → se ignora.
 *  3. Rebote (DSN) → se marca la respuesta original como fallida.
 *  4. Respuesta a una incidencia: cabeceras In-Reply-To/References o, si no,
 *     etiqueta [INC-AAAA-NNNNNN] del asunto (solo si el remitente es del mismo
 *     cliente o el de la incidencia, para que nadie se cuele en otra).
 *  5. Respuesta automática (fuera de oficina) → se guarda en la incidencia sin
 *     cambiar su estado, o se ignora si no corresponde a ninguna.
 *  6. Nuevo: remitente conocido → incidencia; desconocido → cola de revisión
 *     o incidencia pendiente de identificar, según la política del buzón.
 *
 * El .eml original se conserva en storage/mail-raw para poder reprocesarlo.
 */
final class MailProcessor
{
    private string $runId = '';

    public function __construct(private readonly App $app)
    {
    }

    /** Procesa todos los buzones activos que toca revisar. @return int correos procesados */
    public function pollAll(float $budgetSeconds = 40.0, bool $force = false): int
    {
        $deadline = microtime(true) + $budgetSeconds;
        $total = 0;
        foreach ($this->app->make(EmailAccounts::class)->active() as $acc) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $last = Clock::fromDb($acc['last_polled_at']);
            if (!$force && $last !== null && Clock::now()->getTimestamp() - $last->getTimestamp() < max(1, (int)$acc['poll_interval_minutes']) * 60 - 20) {
                continue;
            }
            $total += $this->poll($acc, $deadline);
        }
        return $total;
    }

    /** @param array<string,mixed> $acc */
    public function poll(array $acc, float $deadline): int
    {
        $db = $this->app->db();
        $this->runId = \App\Services\Jobs::ulid();
        $accId = (int)$acc['id'];
        $count = 0;
        $imap = null;
        try {
            $imap = $this->app->make(EmailAccounts::class)->imap($acc);
            $info = $imap->select((string)$acc['imap_folder']);
            $lastUid = (int)$acc['last_uid'];
            if ($acc['last_uidvalidity'] !== null && (int)$acc['last_uidvalidity'] !== $info['uidvalidity']) {
                $this->log($accId, 'warning', 'UIDVALIDITY ha cambiado: se revisa la carpeta completa (los duplicados se descartan por Message-ID).');
                $lastUid = 0;
            }
            if ($acc['last_uid'] === null && $acc['last_uidvalidity'] === null) {
                // Primera conexión: el correo que ya había en el buzón no se convierte en incidencias
                $existing = $imap->uidsAfter(0);
                $max = $existing === [] ? 0 : max($existing);
                $db->run('UPDATE email_accounts SET last_uid = ?, last_uidvalidity = ?, last_polled_at = ?, last_error = NULL WHERE id = ?', [$max, $info['uidvalidity'], Clock::nowDb(), $accId]);
                $this->log($accId, 'info', 'Buzón inicializado: se ignoran ' . count($existing) . ' correos que ya estaban en la carpeta. Se procesarán los que lleguen desde ahora.');
                return 0;
            }
            $uids = array_slice($imap->uidsAfter($lastUid), 0, 50);
            if ($acc['processed_folder']) {
                $imap->ensureFolder((string)$acc['processed_folder']);
            }
            $maxBytes = max(1, (int)$acc['max_attachment_mb']) * 1048576 * 2 + 1048576;
            foreach ($uids as $uid) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $size = $imap->size($uid);
                if ($size > $maxBytes) {
                    $this->log($accId, 'warning', "Correo UID $uid de " . round($size / 1048576, 1) . ' MB supera el límite; se deja en el buzón sin procesar.');
                } else {
                    $raw = $imap->fetchRaw($uid);
                    if ($raw !== null) {
                        $this->ingest($acc, $raw, $info['uidvalidity'], $uid);
                        $count++;
                    }
                    if ($acc['processed_folder']) {
                        $imap->move($uid, (string)$acc['processed_folder']);
                    } else {
                        $imap->markSeen($uid);
                    }
                }
                $db->run('UPDATE email_accounts SET last_uid = ?, last_uidvalidity = ? WHERE id = ?', [$uid, $info['uidvalidity'], $accId]);
            }
            $db->run('UPDATE email_accounts SET last_polled_at = ?, last_error = NULL, last_uidvalidity = ? WHERE id = ?', [Clock::nowDb(), $info['uidvalidity'], $accId]);
            if ($count > 0) {
                $this->log($accId, 'info', "Procesados $count correos.");
            }
        } catch (\Throwable $e) {
            $msg = mb_substr(\App\Core\Logger::redact($e->getMessage()), 0, 900);
            $db->run('UPDATE email_accounts SET last_polled_at = ?, last_error = ? WHERE id = ?', [Clock::nowDb(), $msg, $accId]);
            $this->log($accId, 'error', $msg);
            $prevError = (string)($acc['last_error'] ?? '');
            if ($prevError === '') {
                $n = $this->app->make(Notifications::class);
                $admins = array_map('intval', $db->column("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'superadmin' AND u.status = 'active'"));
                $n->notify($admins, 'mail.error', null, 'Error en el buzón ' . $acc['address'], $msg, 'mail.error.' . $accId . '.' . date('YmdH'));
            }
        } finally {
            $imap?->logout();
        }
        return $count;
    }

    /**
     * Procesa un correo completo. Público para pruebas y reprocesado.
     * @param array<string,mixed> $acc
     * @return int id de email_messages
     */
    public function ingest(array $acc, string $raw, ?int $uidValidity = null, ?int $uid = null): int
    {
        $db = $this->app->db();
        $accId = (int)$acc['id'];
        $msg = (new MailMimeParser())->parse($raw, false);

        $messageId = self::cleanId((string)($msg->getHeaderValue('Message-ID') ?? ''));
        if ($messageId === '') {
            $messageId = 'sin-id.' . hash('sha256', $raw) . '@local';
        }
        $hash = hash('sha256', strtolower($messageId));
        $existing = $db->one("SELECT id FROM email_messages WHERE account_id = ? AND direction = 'inbound' AND message_id_hash = ?", [$accId, $hash]);
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        $from = $msg->getHeader('From');
        $fromEmail = $from instanceof AddressHeader ? Validator::normalizeEmail((string)$from->getEmail()) : '';
        $fromName = $from instanceof AddressHeader ? trim((string)$from->getPersonName()) : '';
        $to = [];
        foreach (['To', 'Cc'] as $h) {
            $hdr = $msg->getHeader($h);
            if ($hdr instanceof AddressHeader) {
                foreach ($hdr->getAddresses() as $a) {
                    $to[] = strtolower((string)$a->getEmail());
                }
            }
        }
        $subject = trim((string)($msg->getHeaderValue('Subject') ?? ''));
        $inReplyTo = self::ids($msg, 'In-Reply-To');
        $refs = self::ids($msg, 'References');
        $dateHdr = $msg->getHeaderValue('Date');
        $sentAt = null;
        if ($dateHdr) {
            $ts = strtotime($dateHdr);
            if ($ts !== false) {
                $sentAt = gmdate(Clock::DB_FORMAT, $ts);
            }
        }

        $isBounce = self::isBounce($msg, $fromEmail);
        $isAuto = !$isBounce && self::isAutoReply($msg, $fromEmail);

        // Original en disco (fuera de la web)
        $rawKey = bin2hex(random_bytes(32));
        $rawPath = $this->rawPath($rawKey);
        if (!is_dir(dirname($rawPath))) {
            @mkdir(dirname($rawPath), 0750, true);
        }
        file_put_contents($rawPath, gzencode($raw, 6));
        @chmod($rawPath, 0640);

        $emailId = $db->insert('email_messages', [
            'account_id' => $accId,
            'direction' => 'inbound',
            'message_id' => mb_substr($messageId, 0, 998),
            'message_id_hash' => $hash,
            'in_reply_to' => $inReplyTo[0] ?? null,
            'references_header' => $refs !== [] ? mb_substr(implode(' ', $refs), 0, 60000) : null,
            'imap_uidvalidity' => $uidValidity,
            'imap_uid' => $uid,
            'from_address' => mb_substr($fromEmail !== '' ? $fromEmail : '(desconocido)', 0, 254),
            'to_addresses' => json_encode(array_values(array_unique($to))),
            'subject' => mb_substr($subject, 0, 998),
            'sent_at' => $sentAt,
            'received_at' => Clock::nowDb(),
            'raw_storage_key' => $rawKey,
            'raw_sha256' => hash('sha256', $raw),
            'is_auto_submitted' => $isAuto ? 1 : 0,
            'is_bounce' => $isBounce ? 1 : 0,
            'processing_status' => 'pending',
            'created_at' => Clock::nowDb(),
        ]);

        try {
            $this->route($acc, $emailId, $msg, $fromEmail, $fromName, $subject, $inReplyTo, $refs, $isBounce, $isAuto);
        } catch (\Throwable $e) {
            $db->run("UPDATE email_messages SET processing_status = 'failed', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 1000), $emailId]);
            $this->log($accId, 'error', 'Error procesando correo de ' . $fromEmail . ': ' . $e->getMessage(), $emailId);
            $this->app->logger()->error('Correo ' . $emailId . ': ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
        }
        return $emailId;
    }

    /**
     * @param list<string> $inReplyTo
     * @param list<string> $refs
     */
    private function route(array $acc, int $emailId, IMessage $msg, string $fromEmail, string $fromName, string $subject, array $inReplyTo, array $refs, bool $isBounce, bool $isAuto): void
    {
        $db = $this->app->db();
        $accId = (int)$acc['id'];
        $tickets = $this->app->make(TicketService::class);

        // Bucle: correo enviado por uno de nuestros buzones
        $ours = array_map('strtolower', $db->column('SELECT address FROM email_accounts'));
        if (in_array($fromEmail, $ours, true)) {
            $this->finish($emailId, 'ignored', 'none', null, null, 'Enviado por un buzón propio (bucle evitado).');
            return;
        }

        if ($isBounce) {
            $failed = $this->matchByHeaders(array_merge($inReplyTo, $refs), 'outbound');
            $orig = $failed ?? $this->bouncedOriginal($msg);
            if ($orig !== null) {
                $db->run("UPDATE ticket_messages SET delivery_status = 'failed' WHERE id = ?", [(int)$orig['ticket_message_id']]);
                $tickets->event((int)$orig['ticket_id'], null, 'delivery_failed', null, ['detalle' => 'El servidor del destinatario rechazó el correo']);
                $n = $this->app->make(Notifications::class);
                $num = (string)$db->value('SELECT ticket_number FROM tickets WHERE id = ?', [(int)$orig['ticket_id']]);
                $n->notify($n->ticketWorkers((int)$orig['ticket_id']), 'ticket.customer_reply', (int)$orig['ticket_id'], "No se pudo entregar la respuesta de $num", 'Revisa el correo del cliente.', "bounce.$emailId");
            }
            $this->finish($emailId, 'ignored', $orig ? 'headers' : 'none', $orig ? (int)$orig['ticket_id'] : null, null, 'Rebote de correo.');
            return;
        }

        [$text, $html] = $this->bodies($msg);
        $sender = $fromEmail !== '' ? $this->app->make(ClientService::class)->resolveSender('email', $fromEmail) : ['contact_id' => null, 'client_id' => null, 'method' => 'none'];
        $label = $fromName !== '' ? "$fromName <$fromEmail>" : $fromEmail;

        // ¿Respuesta a una incidencia?
        $ticket = null;
        $method = null;
        $hit = $this->matchByHeaders(array_merge($inReplyTo, $refs), null);
        if ($hit !== null) {
            $ticket = $db->one('SELECT * FROM tickets WHERE id = ? AND deleted_at IS NULL', [(int)$hit['ticket_id']]);
            $method = 'headers';
        }
        if ($ticket === null && preg_match('/\[([A-Z0-9]{2,10}-\d{4}-\d{6})\]/', $subject, $m)) {
            $cand = $db->one('SELECT * FROM tickets WHERE ticket_number = ? AND deleted_at IS NULL', [$m[1]]);
            if ($cand !== null && $this->senderMayUse($cand, $sender, $fromEmail)) {
                $ticket = $cand;
                $method = 'subject_tag';
            }
        }

        if ($ticket !== null) {
            $phase = $tickets->status((int)$ticket['status_id'])['phase'];
            if (in_array($phase, ['closed', 'cancelled'], true) && !$isAuto) {
                // Incidencia cerrada: se abre otra relacionada
                $newId = $this->createTicket($acc, $emailId, $msg, $subject, $text, $html, $label, $fromEmail, $sender, $ticket);
                $db->run('INSERT IGNORE INTO ticket_relations (ticket_id, related_ticket_id, relation_type, created_by, created_at) VALUES (?, ?, \'related\', NULL, ?)', [$newId, (int)$ticket['id'], Clock::nowDb()]);
                $tickets->event($newId, null, 'relation_added', null, ['tipo' => 'related', 'incidencia' => $ticket['ticket_number'], 'motivo' => 'respuesta a incidencia cerrada']);
                return;
            }
            $tmId = $tickets->addInbound($ticket, 'email', $sender['contact_id'], $label, $text, $html, $isAuto);
            $this->storeAttachments((int)$ticket['id'], $tmId, $msg, (int)$acc['max_attachment_mb']);
            $this->finish($emailId, 'processed', $method, (int)$ticket['id'], $tmId, null);
            return;
        }

        if ($isAuto) {
            $this->finish($emailId, 'ignored', 'none', null, null, 'Respuesta automática sin incidencia asociada.');
            return;
        }

        if ($sender['client_id'] === null && $acc['unknown_sender_policy'] === 'review') {
            $this->finish($emailId, 'review', 'review', null, null, null);
            $n = $this->app->make(Notifications::class);
            $reviewers = array_map('intval', $db->column(
                "SELECT DISTINCT u.id FROM users u JOIN role_permissions rp ON rp.role_id = u.role_id JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.code = 'tickets.review_queue' AND u.status = 'active' AND u.deleted_at IS NULL"
            ));
            $n->notify($reviewers, 'ticket.review', null, 'Correo pendiente de revisión', "De $label: $subject", "review.email.$emailId");
            return;
        }
        $this->createTicket($acc, $emailId, $msg, $subject, $text, $html, $label, $fromEmail, $sender, null);
    }

    /**
     * @param array{contact_id:?int,client_id:?int,method:string} $sender
     * @param array<string,mixed>|null $previous incidencia cerrada a la que respondía
     */
    private function createTicket(array $acc, int $emailId, IMessage $msg, string $subject, string $text, ?string $html, string $label, string $fromEmail, array $sender, ?array $previous): int
    {
        $tickets = $this->app->make(TicketService::class);
        $cleanSubject = trim((string)preg_replace('/^((re|rv|fw|fwd|aw|sv)\s*:\s*)+/i', '', (string)preg_replace('/\[[A-Z0-9]{2,10}-\d{4}-\d{6}\]\s*/', '', $subject)));
        $ticketId = $tickets->create([
            'subject' => $cleanSubject !== '' ? $cleanSubject : '(sin asunto)',
            'description' => null,
            'client_id' => $sender['client_id'],
            'contact_id' => $sender['contact_id'],
            'requester_email' => $fromEmail !== '' ? $fromEmail : null,
        ], null, 'email');
        $ticket = $this->app->db()->one('SELECT * FROM tickets WHERE id = ?', [$ticketId]);
        $tmId = $tickets->addInbound($ticket, 'email', $sender['contact_id'], $label, $text, $html, false);
        $this->storeAttachments($ticketId, $tmId, $msg, (int)$acc['max_attachment_mb']);
        $this->finish($emailId, 'processed', 'new_ticket', $ticketId, $tmId, null);
        if ($sender['client_id'] !== null && $this->app->settings()->bool('tickets.ack_email', true)) {
            $this->app->make(Jobs::class)->push('email.customer_notice', ['ticket_id' => $ticketId, 'template' => 'ticket_created_ack', 'reply_to_email_id' => $emailId], "ack:$ticketId");
        }
        return $ticketId;
    }

    /**
     * Desde la cola de revisión: crear incidencia o añadir a una existente.
     * @return int id de la incidencia
     */
    public function resolveReview(int $emailId, string $action, ?int $clientId, ?int $contactId, ?int $ticketId, \App\Security\CurrentUser $actor, bool $rememberSender): int
    {
        $db = $this->app->db();
        $e = $db->one("SELECT * FROM email_messages WHERE id = ? AND processing_status = 'review'", [$emailId]);
        if ($e === null) {
            throw new \App\Core\ValidationException([], 'Este correo ya se ha tramitado.');
        }
        $raw = $this->loadRaw((string)$e['raw_storage_key']);
        $msg = (new MailMimeParser())->parse($raw, false);
        [$text, $html] = $this->bodies($msg);
        $acc = $db->one('SELECT * FROM email_accounts WHERE id = ?', [(int)$e['account_id']]);
        $from = $msg->getHeader('From');
        $fromName = $from instanceof AddressHeader ? trim((string)$from->getPersonName()) : '';
        $label = $fromName !== '' ? "$fromName <{$e['from_address']}>" : (string)$e['from_address'];
        $tickets = $this->app->make(TicketService::class);

        if ($action === 'attach') {
            $ticket = $this->app->make(\App\Services\TicketAccess::class)->find($actor, (int)$ticketId);
            $tmId = $tickets->addInbound($ticket, 'email', $contactId, $label, $text, $html, false);
            $this->storeAttachments((int)$ticket['id'], $tmId, $msg, (int)$acc['max_attachment_mb']);
            $this->finish($emailId, 'processed', 'review', (int)$ticket['id'], $tmId, null);
            return (int)$ticket['id'];
        }
        $id = $tickets->create([
            'subject' => trim((string)$e['subject']) !== '' ? (string)$e['subject'] : '(sin asunto)',
            'client_id' => $clientId,
            'contact_id' => $contactId,
            'requester_email' => (string)$e['from_address'],
        ], $actor, 'email');
        $ticket = $db->one('SELECT * FROM tickets WHERE id = ?', [$id]);
        $tmId = $tickets->addInbound($ticket, 'email', $contactId, $label, $text, $html, false);
        $this->storeAttachments($id, $tmId, $msg, (int)$acc['max_attachment_mb']);
        $this->finish($emailId, 'processed', 'review', $id, $tmId, null);
        if ($rememberSender && $contactId !== null) {
            $this->app->make(ClientService::class)->addIdentity($contactId, 'email', (string)$e['from_address'], false);
        }
        return $id;
    }

    public function ignoreReview(int $emailId, \App\Security\CurrentUser $actor): void
    {
        $this->app->db()->run("UPDATE email_messages SET processing_status = 'ignored', error = ? WHERE id = ? AND processing_status = 'review'", ['Descartado por ' . $actor->name, $emailId]);
        $this->app->audit()->log('email.review_ignored', 'email_message', $emailId);
    }

    /** Vista previa del texto de un correo en revisión. */
    public function previewText(int $emailId): string
    {
        $e = $this->app->db()->one('SELECT raw_storage_key FROM email_messages WHERE id = ?', [$emailId]);
        if ($e === null || $e['raw_storage_key'] === null) {
            return '';
        }
        try {
            $msg = (new MailMimeParser())->parse($this->loadRaw((string)$e['raw_storage_key']), false);
            return $this->bodies($msg)[0];
        } catch (\Throwable) {
            return '(no se pudo leer el correo original)';
        }
    }

    // ------------------------------------------------------------------

    /** @return array{0:string,1:?string} texto (sin citas largas) y HTML saneado */
    private function bodies(IMessage $msg): array
    {
        $text = (string)($msg->getTextContent() ?? '');
        $htmlRaw = $msg->getHtmlContent();
        $html = $htmlRaw !== null ? HtmlSanitizer::clean($htmlRaw) : null;
        if (trim($text) === '' && $htmlRaw !== null) {
            $text = HtmlSanitizer::toText($htmlRaw);
        }
        $text = str_replace("\r\n", "\n", $text);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        return [mb_substr(trim($text), 0, 200000), $html !== '' ? $html : null];
    }

    private function storeAttachments(int $ticketId, int $ticketMessageId, IMessage $msg, int $maxMb): void
    {
        $svc = $this->app->make(Attachments::class);
        $n = 0;
        foreach ($msg->getAllAttachmentParts() as $part) {
            if (++$n > 20) {
                break;
            }
            $name = (string)($part->getFilename() ?? '');
            $disp = strtolower((string)$part->getContentDisposition(''));
            // Imágenes incrustadas en la firma (logos) sin nombre: se omiten
            if ($name === '' && ($part->getContentId() !== null || $disp === 'inline')) {
                continue;
            }
            if ($name === '') {
                $name = 'adjunto-' . $n . '.' . (str_contains((string)$part->getContentType(), 'rfc822') ? 'eml' : 'bin');
            }
            $stream = $part->getBinaryContentStream();
            if ($stream === null) {
                continue;
            }
            $content = (string)$stream->getContents();
            $svc->storeContent($ticketId, $content, $name, $ticketMessageId, 'public', max(1, $maxMb) * 1048576);
        }
    }

    /** @param list<string> $ids @return array{ticket_id:int,ticket_message_id:?int}|null */
    private function matchByHeaders(array $ids, ?string $direction): ?array
    {
        if ($ids === []) {
            return null;
        }
        $hashes = array_map(static fn ($i) => hash('sha256', strtolower($i)), array_slice(array_reverse($ids), 0, 30));
        $sql = 'SELECT ticket_id, ticket_message_id FROM email_messages WHERE message_id_hash IN (' . implode(',', array_fill(0, count($hashes), '?')) . ') AND ticket_id IS NOT NULL';
        $params = $hashes;
        if ($direction !== null) {
            $sql .= ' AND direction = ?';
            $params[] = $direction;
        }
        $row = $this->app->db()->one($sql . ' ORDER BY id DESC LIMIT 1', $params);
        return $row === null ? null : ['ticket_id' => (int)$row['ticket_id'], 'ticket_message_id' => $row['ticket_message_id'] !== null ? (int)$row['ticket_message_id'] : null];
    }

    /** En un DSN el mensaje original suele ir adjunto (message/rfc822 o text/rfc822-headers). */
    private function bouncedOriginal(IMessage $msg): ?array
    {
        foreach ($msg->getAllAttachmentParts() as $part) {
            $ct = strtolower((string)$part->getContentType());
            if (str_contains($ct, 'rfc822')) {
                $content = (string)$part->getContent();
                if (preg_match('/^Message-ID:\s*(<[^>]+>)/mi', $content, $m)) {
                    return $this->matchByHeaders([self::cleanId($m[1])], 'outbound');
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $ticket @param array{contact_id:?int,client_id:?int,method:string} $sender */
    private function senderMayUse(array $ticket, array $sender, string $fromEmail): bool
    {
        if ($ticket['client_id'] !== null && $sender['client_id'] !== null) {
            return (int)$ticket['client_id'] === $sender['client_id'];
        }
        return $ticket['requester_email'] !== null && strtolower((string)$ticket['requester_email']) === $fromEmail;
    }

    private function finish(int $emailId, string $status, ?string $method, ?int $ticketId, ?int $ticketMessageId, ?string $note): void
    {
        $this->app->db()->run(
            'UPDATE email_messages SET processing_status = ?, match_method = ?, ticket_id = ?, ticket_message_id = ?, error = ? WHERE id = ?',
            [$status, $method, $ticketId, $ticketMessageId, $note, $emailId]
        );
    }

    private function log(int $accountId, string $level, string $message, ?int $emailId = null): void
    {
        $this->app->db()->insert('email_processing_log', [
            'account_id' => $accountId,
            'run_id' => $this->runId !== '' ? $this->runId : \App\Services\Jobs::ulid(),
            'level' => $level,
            'message' => mb_substr($message, 0, 1000),
            'email_message_id' => $emailId,
            'created_at' => Clock::nowDb(),
        ]);
    }

    public function rawPath(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
            throw new \InvalidArgumentException('Clave no válida.');
        }
        return $this->app->storagePath('mail-raw/' . substr($key, 0, 2) . '/' . $key . '.eml.gz');
    }

    public function loadRaw(string $key): string
    {
        $data = @file_get_contents($this->rawPath($key));
        if ($data === false) {
            throw new \RuntimeException('No se encuentra el correo original.');
        }
        return (string)gzdecode($data);
    }

    public static function cleanId(string $id): string
    {
        $id = trim($id);
        if (preg_match('/<([^>]+)>/', $id, $m)) {
            $id = $m[1];
        }
        return mb_substr(trim($id, "<> \t\r\n"), 0, 900);
    }

    /** @return list<string> */
    private static function ids(IMessage $msg, string $header): array
    {
        $h = $msg->getHeader($header);
        if ($h instanceof IdHeader) {
            return array_values(array_filter(array_map(static fn ($i) => self::cleanId((string)$i), $h->getIds())));
        }
        $v = (string)($msg->getHeaderValue($header) ?? '');
        preg_match_all('/<([^>]+)>/', $v, $m);
        return array_values(array_filter(array_map([self::class, 'cleanId'], $m[1])));
    }

    private static function isBounce(IMessage $msg, string $from): bool
    {
        if (preg_match('/^(mailer-daemon|postmaster)@/i', $from)) {
            return true;
        }
        $ct = strtolower((string)$msg->getHeaderValue('Content-Type'));
        return str_contains($ct, 'multipart/report') && str_contains(strtolower((string)$msg->getHeaderParameter('Content-Type', 'report-type')), 'delivery-status');
    }

    private static function isAutoReply(IMessage $msg, string $from): bool
    {
        $auto = strtolower(trim((string)$msg->getHeaderValue('Auto-Submitted')));
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }
        $prec = strtolower(trim((string)$msg->getHeaderValue('Precedence')));
        if (in_array($prec, ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return true;
        }
        // X-Auto-Response-Suppress no se usa: Outlook la pone también en correos normales
        foreach (['X-Autoreply', 'X-Autorespond'] as $h) {
            if (trim((string)$msg->getHeaderValue($h)) !== '') {
                return true;
            }
        }
        if (preg_match('/^(no-?reply|noreply|do-not-reply)@/i', $from)) {
            return true;
        }
        $subject = strtolower((string)$msg->getHeaderValue('Subject'));
        return (bool)preg_match('/^(respuesta automática|automatic reply|auto: |fuera de la oficina|out of office)/u', $subject);
    }
}
