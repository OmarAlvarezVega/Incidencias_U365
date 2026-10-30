<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Clock;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Security\CurrentUser;

/**
 * Reglas de negocio de las incidencias. Los controladores, el correo y
 * WhatsApp pasan por aquí; ninguna otra clase escribe en tickets.
 * Cada cambio deja un evento en el historial (ticket_events) y, si procede,
 * avisos a las personas implicadas.
 */
final class TicketService
{
    /** @var array<int,array<string,mixed>>|null */
    private ?array $statuses = null;

    public function __construct(private readonly App $app)
    {
    }

    // ------------------------------------------------------------------
    // Catálogos
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function statuses(): array
    {
        if ($this->statuses === null) {
            $this->statuses = [];
            foreach ($this->app->db()->all('SELECT * FROM ticket_statuses ORDER BY sort_order') as $s) {
                $this->statuses[(int)$s['id']] = $s;
            }
        }
        return $this->statuses;
    }

    /** @return array<string,mixed> */
    public function status(int $id): array
    {
        return $this->statuses()[$id] ?? throw new \RuntimeException("Estado $id inexistente.");
    }

    /** @return array<string,mixed>|null */
    public function statusByCode(string $code): ?array
    {
        foreach ($this->statuses() as $s) {
            if ($s['code'] === $code && (int)$s['active'] === 1) {
                return $s;
            }
        }
        return null;
    }

    /** Estados a los que se puede pasar desde $fromId con los permisos del usuario. @return list<array<string,mixed>> */
    public function allowedTransitions(int $fromId, ?CurrentUser $user): array
    {
        $rows = $this->app->db()->all(
            'SELECT s.*, tr.permission_code FROM ticket_status_transitions tr JOIN ticket_statuses s ON s.id = tr.to_status_id
              WHERE tr.from_status_id = ? AND s.active = 1 ORDER BY s.sort_order',
            [$fromId]
        );
        return array_values(array_filter($rows, static fn ($r) => $r['permission_code'] === null || ($user !== null && $user->can((string)$r['permission_code']))));
    }

    // ------------------------------------------------------------------
    // Alta
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $d
     */
    public function create(array $d, ?CurrentUser $actor, string $channel = 'manual'): int
    {
        $db = $this->app->db();
        $now = Clock::nowDb();
        $actorId = $actor?->id;

        $subject = trim((string)($d['subject'] ?? ''));
        if ($subject === '') {
            throw ValidationException::single('subject', 'El asunto es obligatorio.');
        }
        $clientId = isset($d['client_id']) && $d['client_id'] !== null ? (int)$d['client_id'] : null;
        $contactId = isset($d['contact_id']) && $d['contact_id'] !== null ? (int)$d['contact_id'] : null;
        $ident = $clientId === null ? 'pending' : 'identified';
        $this->assertClientContact($clientId, $contactId);

        $priorityId = isset($d['priority_id']) ? (int)$d['priority_id'] : (int)$db->value(
            'SELECT id FROM ticket_priorities WHERE code = ? AND active = 1',
            [(string)$this->app->settings()->get('tickets.default_priority', 'normal')]
        );
        if ($priorityId === 0 || !$db->value('SELECT 1 FROM ticket_priorities WHERE id = ? AND active = 1', [$priorityId])) {
            throw ValidationException::single('priority_id', 'Prioridad no válida.');
        }
        $categoryId = isset($d['category_id']) && $d['category_id'] !== null ? (int)$d['category_id'] : null;
        if ($categoryId !== null && !$db->value('SELECT 1 FROM ticket_categories WHERE id = ? AND active = 1', [$categoryId])) {
            throw ValidationException::single('category_id', 'Categoría no válida.');
        }
        $teamId = isset($d['team_id']) && $d['team_id'] !== null ? (int)$d['team_id'] : null;
        if ($teamId !== null && !$db->value('SELECT 1 FROM teams WHERE id = ? AND active = 1', [$teamId])) {
            throw ValidationException::single('team_id', 'Equipo no válido.');
        }
        $ownerId = isset($d['owner_user_id']) && $d['owner_user_id'] !== null ? (int)$d['owner_user_id'] : null;
        $collaborators = array_values(array_diff(array_map('intval', (array)($d['collaborators'] ?? [])), [$ownerId ?? 0]));
        $this->assertStaff(array_merge($ownerId !== null ? [$ownerId] : [], $collaborators));
        if (!$db->value('SELECT 1 FROM ticket_channels WHERE code = ?', [$channel])) {
            throw new \InvalidArgumentException('Canal no válido.');
        }

        $initial = $db->one('SELECT * FROM ticket_statuses WHERE is_initial = 1 AND active = 1 ORDER BY sort_order LIMIT 1')
            ?? throw new \RuntimeException('No hay un estado inicial activo.');
        $status = $initial;
        if ($ownerId !== null && ($assigned = $this->statusByCode('assigned')) !== null) {
            $status = $assigned;
        }

        $ticketId = $db->transaction(function () use ($db, $now, $actorId, $subject, $d, $clientId, $contactId, $ident, $priorityId, $categoryId, $teamId, $ownerId, $collaborators, $channel, $status) {
            $year = (int)Clock::now()->setTimezone(Clock::local())->format('Y');
            $db->run(
                'INSERT INTO ticket_sequences (year, last_value) VALUES (?, LAST_INSERT_ID(1))
                 ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)',
                [$year]
            );
            $seq = (int)$db->value('SELECT LAST_INSERT_ID()');
            $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$this->app->settings()->get('tickets.number_prefix', 'INC'))) ?: 'INC';
            $number = sprintf('%s-%d-%06d', $prefix, $year, $seq);

            $id = $db->insert('tickets', [
                'ticket_number' => $number,
                'seq_year' => $year,
                'seq_value' => $seq,
                'subject' => mb_substr($subject, 0, 255),
                'description' => $d['description'] ?? null,
                'client_id' => $clientId,
                'contact_id' => $contactId,
                'identification_status' => $ident,
                'requester_email' => isset($d['requester_email']) ? mb_substr((string)$d['requester_email'], 0, 254) : null,
                'requester_phone' => isset($d['requester_phone']) ? mb_substr((string)$d['requester_phone'], 0, 30) : null,
                'category_id' => $categoryId,
                'priority_id' => $priorityId,
                'status_id' => (int)$status['id'],
                'channel_code' => $channel,
                'team_id' => $teamId,
                'owner_user_id' => $ownerId,
                'assignment_mode' => $collaborators !== [] ? 'shared' : 'individual',
                'due_at' => $d['due_at'] ?? null,
                'last_activity_at' => $now,
                'last_customer_message_at' => $channel !== 'manual' ? $now : null,
                'internal_summary' => $d['internal_summary'] ?? null,
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($collaborators as $uid) {
                $db->run('INSERT INTO ticket_participants (ticket_id, user_id, role, added_by, added_at) VALUES (?, ?, \'collaborator\', ?, ?)', [$id, $uid, $actorId, $now]);
            }
            foreach (array_unique(array_map('intval', (array)($d['tags'] ?? []))) as $tagId) {
                $db->run('INSERT IGNORE INTO ticket_tag_relations (ticket_id, tag_id) SELECT ?, id FROM ticket_tags WHERE id = ?', [$id, $tagId]);
            }
            $db->insert('ticket_status_history', ['ticket_id' => $id, 'from_status_id' => null, 'to_status_id' => (int)$status['id'], 'changed_by' => $actorId, 'changed_at' => $now]);
            $this->event($id, $actorId, 'created', null, ['numero' => $number, 'canal' => $channel, 'estado' => $status['name']]);
            return $id;
        });

        $this->app->make(Sla::class)->attach($ticketId);
        $this->app->audit()->log('ticket.created', 'ticket', $ticketId, ['canal' => $channel], $actor ? 'user' : 'integration', $actorId);

        $t = $db->one('SELECT ticket_number, subject, team_id FROM tickets WHERE id = ?', [$ticketId]);
        $n = $this->app->make(Notifications::class);
        if ($ownerId !== null) {
            $n->notify([$ownerId], 'ticket.assigned', $ticketId, 'Te han asignado ' . $t['ticket_number'], (string)$t['subject'], "assigned.$ticketId.$ownerId", $actorId);
        } else {
            $n->notify($n->supervisors($teamId), 'ticket.unassigned_new', $ticketId, 'Nueva incidencia sin asignar: ' . $t['ticket_number'], (string)$t['subject'], "new.$ticketId", $actorId);
        }
        if ($collaborators !== []) {
            $n->notify($collaborators, 'ticket.collaborator', $ticketId, 'Colaboras en ' . $t['ticket_number'], (string)$t['subject'], "collab.$ticketId", $actorId);
        }
        if (!empty($d['send_ack'])) {
            $this->queueCustomerNotice($ticketId, 'ticket_created_ack');
        }
        return $ticketId;
    }

    // ------------------------------------------------------------------
    // Cambios
    // ------------------------------------------------------------------

    /**
     * Edición de datos generales con bloqueo optimista.
     * @param array<string,mixed> $ticket
     * @param array<string,mixed> $changes subject, description, category_id, due_at, internal_summary, team_id
     */
    public function update(array $ticket, array $changes, CurrentUser $actor, int $lockVersion): void
    {
        $db = $this->app->db();
        $allowed = ['subject', 'description', 'category_id', 'due_at', 'internal_summary', 'team_id'];
        $set = [];
        $diffOld = [];
        $diffNew = [];
        foreach ($allowed as $f) {
            if (!array_key_exists($f, $changes)) {
                continue;
            }
            $new = $changes[$f];
            if ($f === 'subject' && trim((string)$new) === '') {
                throw ValidationException::single('subject', 'El asunto es obligatorio.');
            }
            if ($f === 'category_id' && $new !== null && !$db->value('SELECT 1 FROM ticket_categories WHERE id = ?', [(int)$new])) {
                throw ValidationException::single('category_id', 'Categoría no válida.');
            }
            if ($f === 'team_id' && $new !== null && !$db->value('SELECT 1 FROM teams WHERE id = ? AND active = 1', [(int)$new])) {
                throw ValidationException::single('team_id', 'Equipo no válido.');
            }
            if ((string)($ticket[$f] ?? '') !== (string)($new ?? '')) {
                $set[$f] = $new;
                $diffOld[$f] = in_array($f, ['description', 'internal_summary'], true) ? '(texto anterior)' : $ticket[$f];
                $diffNew[$f] = in_array($f, ['description', 'internal_summary'], true) ? '(texto modificado)' : $new;
            }
        }
        if ($set === []) {
            return;
        }
        $set['updated_at'] = Clock::nowDb();
        $cols = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($set)));
        $n = $db->run(
            "UPDATE tickets SET $cols, lock_version = lock_version + 1 WHERE id = ? AND lock_version = ?",
            array_merge(array_values($set), [(int)$ticket['id'], $lockVersion])
        )->rowCount();
        if ($n === 0) {
            throw new ValidationException([], 'Otra persona ha modificado esta incidencia mientras la editabas. Recarga la página y vuelve a aplicar tus cambios.');
        }
        $this->event((int)$ticket['id'], $actor->id, 'updated', $diffOld, $diffNew);
    }

    /** @param array<string,mixed> $ticket */
    public function changeStatus(array $ticket, int $toStatusId, ?CurrentUser $actor, bool $notifyCustomer = false, bool $system = false): void
    {
        $db = $this->app->db();
        $fromId = (int)$ticket['status_id'];
        if ($fromId === $toStatusId) {
            return;
        }
        $to = $this->status($toStatusId);
        $from = $this->status($fromId);
        if (!$system) {
            $tr = $db->one('SELECT permission_code FROM ticket_status_transitions WHERE from_status_id = ? AND to_status_id = ?', [$fromId, $toStatusId]);
            if ($tr === null || (int)$to['active'] !== 1) {
                throw new ValidationException([], "No se puede pasar de «{$from['name']}» a «{$to['name']}».");
            }
            if ($tr['permission_code'] !== null && ($actor === null || !$actor->can((string)$tr['permission_code']))) {
                throw HttpException::forbidden('No tienes permiso para este cambio de estado.');
            }
        }
        if (!$system && $to['code'] === 'in_progress' && $ticket['owner_user_id'] === null) {
            throw new ValidationException([], 'Asigna un responsable antes de pasar la incidencia a «En curso».');
        }
        $now = Clock::nowDb();
        $set = ['status_id' => $toStatusId, 'updated_at' => $now, 'last_activity_at' => $now];
        $isReopen = in_array($from['phase'], ['resolved', 'closed', 'cancelled'], true) && in_array($to['phase'], ['new', 'open', 'pending'], true);
        if ($isReopen) {
            $set['resolved_at'] = null;
            $set['closed_at'] = null;
        }
        if ($to['phase'] === 'resolved') {
            $set['resolved_at'] = $now;
        }
        if ($to['phase'] === 'closed' || $to['phase'] === 'cancelled') {
            $set['closed_at'] = $now;
            if ($ticket['resolved_at'] === null && $to['phase'] === 'closed') {
                $set['resolved_at'] = $now;
            }
        }
        $cols = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($set)));
        $db->transaction(function () use ($db, $cols, $set, $ticket, $fromId, $toStatusId, $actor, $now, $isReopen, $from, $to) {
            $n = $db->run(
                "UPDATE tickets SET $cols" . ($isReopen ? ', reopened_count = reopened_count + 1' : '') . ', lock_version = lock_version + 1 WHERE id = ? AND status_id = ?',
                array_merge(array_values($set), [(int)$ticket['id'], $fromId])
            )->rowCount();
            if ($n === 0) {
                throw new ValidationException([], 'El estado de la incidencia acaba de cambiar. Recarga la página.');
            }
            $db->insert('ticket_status_history', ['ticket_id' => (int)$ticket['id'], 'from_status_id' => $fromId, 'to_status_id' => $toStatusId, 'changed_by' => $actor?->id, 'changed_at' => $now]);
            $this->event((int)$ticket['id'], $actor?->id, $isReopen ? 'reopened' : 'status_changed', ['estado' => $from['name']], ['estado' => $to['name']]);
        });

        if (in_array($to['phase'], ['resolved', 'closed', 'cancelled'], true)) {
            $this->app->make(TimeService::class)->stopAllForTicket((int)$ticket['id'], $actor?->id);
        }
        $this->app->make(Sla::class)->onStatusChange((int)$ticket['id'], $fromId, $toStatusId);
        $n = $this->app->make(Notifications::class);
        $n->notify($n->ticketWorkers((int)$ticket['id']), 'ticket.status', (int)$ticket['id'], "{$ticket['ticket_number']}: {$to['name']}", (string)$ticket['subject'], "status.{$ticket['id']}.$toStatusId." . substr($now, 0, 16), $actor?->id);
        if ($notifyCustomer && $to['phase'] === 'resolved') {
            $this->queueCustomerNotice((int)$ticket['id'], 'ticket_resolved');
        }
    }

    /** @param array<string,mixed> $ticket */
    public function changePriority(array $ticket, int $priorityId, CurrentUser $actor): void
    {
        $db = $this->app->db();
        if ((int)$ticket['priority_id'] === $priorityId) {
            return;
        }
        $p = $db->one('SELECT id, name FROM ticket_priorities WHERE id = ? AND active = 1', [$priorityId])
            ?? throw ValidationException::single('priority_id', 'Prioridad no válida.');
        $old = $db->value('SELECT name FROM ticket_priorities WHERE id = ?', [(int)$ticket['priority_id']]);
        $db->run('UPDATE tickets SET priority_id = ?, updated_at = ?, lock_version = lock_version + 1 WHERE id = ?', [$priorityId, Clock::nowDb(), (int)$ticket['id']]);
        $this->event((int)$ticket['id'], $actor->id, 'priority_changed', ['prioridad' => $old], ['prioridad' => $p['name']]);
        $this->app->make(Sla::class)->attach((int)$ticket['id']);
    }

    /**
     * Responsable único + colaboradores. Modo compartido = hay colaboradores.
     * @param array<string,mixed> $ticket
     * @param list<int> $collaborators
     */
    public function assign(array $ticket, ?int $ownerId, array $collaborators, CurrentUser $actor, ?int $teamId = null, bool $changeTeam = false): void
    {
        $db = $this->app->db();
        $tid = (int)$ticket['id'];
        $currentOwner = $ticket['owner_user_id'] !== null ? (int)$ticket['owner_user_id'] : null;
        if ($currentOwner !== null && $currentOwner !== $ownerId && !$actor->can('tickets.reassign') && $currentOwner !== $actor->id) {
            throw HttpException::forbidden('No tienes permiso para cambiar el responsable de una incidencia ya asignada.');
        }
        $collaborators = array_values(array_unique(array_diff(array_map('intval', $collaborators), [$ownerId ?? 0])));
        $this->assertStaff(array_merge($ownerId !== null ? [$ownerId] : [], $collaborators));
        if ($changeTeam && $teamId !== null && !$db->value('SELECT 1 FROM teams WHERE id = ? AND active = 1', [$teamId])) {
            throw ValidationException::single('team_id', 'Equipo no válido.');
        }
        $now = Clock::nowDb();
        $before = array_map('intval', $db->column("SELECT user_id FROM ticket_participants WHERE ticket_id = ? AND role = 'collaborator' AND removed_at IS NULL", [$tid]));
        $added = array_values(array_diff($collaborators, $before));
        $removed = array_values(array_diff($before, $collaborators));

        $db->transaction(function () use ($db, $tid, $ownerId, $collaborators, $added, $removed, $actor, $now, $currentOwner, $ticket, $teamId, $changeTeam) {
            $set = ['owner_user_id' => $ownerId, 'assignment_mode' => $collaborators !== [] ? 'shared' : 'individual', 'updated_at' => $now, 'last_activity_at' => $now];
            if ($changeTeam) {
                $set['team_id'] = $teamId;
            }
            $cols = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($set)));
            $db->run("UPDATE tickets SET $cols, lock_version = lock_version + 1 WHERE id = ?", array_merge(array_values($set), [$tid]));
            foreach ($added as $uid) {
                $db->run(
                    "INSERT INTO ticket_participants (ticket_id, user_id, role, added_by, added_at) VALUES (?, ?, 'collaborator', ?, ?)
                     ON DUPLICATE KEY UPDATE role = 'collaborator', removed_at = NULL, added_by = VALUES(added_by), added_at = VALUES(added_at)",
                    [$tid, $uid, $actor->id, $now]
                );
            }
            if ($removed !== []) {
                $db->run('UPDATE ticket_participants SET removed_at = ? WHERE ticket_id = ? AND user_id IN (' . implode(',', $removed) . ')', [$now, $tid]);
            }
            // El nuevo responsable deja de figurar como colaborador
            if ($ownerId !== null) {
                $db->run('UPDATE ticket_participants SET removed_at = ? WHERE ticket_id = ? AND user_id = ? AND removed_at IS NULL', [$now, $tid, $ownerId]);
            }
            if ($currentOwner !== $ownerId) {
                $names = $this->userNames(array_filter([$currentOwner, $ownerId]));
                $this->event($tid, $actor->id, 'owner_changed', ['responsable' => $currentOwner ? $names[$currentOwner] ?? null : null], ['responsable' => $ownerId ? $names[$ownerId] ?? null : null]);
            }
            if ($added !== [] || $removed !== []) {
                $names = $this->userNames(array_merge($added, $removed));
                $this->event($tid, $actor->id, 'participants_changed',
                    $removed !== [] ? ['quitados' => array_values(array_map(static fn ($u) => $names[$u] ?? $u, $removed))] : null,
                    $added !== [] ? ['añadidos' => array_values(array_map(static fn ($u) => $names[$u] ?? $u, $added))] : null);
            }
            if ($changeTeam && (string)$ticket['team_id'] !== (string)$teamId) {
                $this->event($tid, $actor->id, 'team_changed', ['equipo' => $ticket['team_id']], ['equipo' => $teamId]);
            }
        });

        // Estado automático: con responsable deja de estar "nueva"
        $status = $this->status((int)$ticket['status_id']);
        if ($ownerId !== null && $status['phase'] === 'new' && ($assigned = $this->statusByCode('assigned')) !== null) {
            $fresh = $db->one('SELECT * FROM tickets WHERE id = ?', [$tid]);
            $this->changeStatus($fresh, (int)$assigned['id'], $actor, false, true);
        } elseif ($ownerId === null && $status['phase'] === 'new' && ($pa = $this->statusByCode('pending_assign')) !== null && (int)$status['id'] !== (int)$pa['id']) {
            $fresh = $db->one('SELECT * FROM tickets WHERE id = ?', [$tid]);
            $this->changeStatus($fresh, (int)$pa['id'], $actor, false, true);
        }

        $n = $this->app->make(Notifications::class);
        if ($ownerId !== null && $ownerId !== $currentOwner) {
            $n->notify([$ownerId], 'ticket.assigned', $tid, 'Te han asignado ' . $ticket['ticket_number'], (string)$ticket['subject'], "assigned.$tid.$ownerId." . substr($now, 0, 16), $actor->id);
        }
        if ($added !== []) {
            $n->notify($added, 'ticket.collaborator', $tid, 'Colaboras en ' . $ticket['ticket_number'], (string)$ticket['subject'], "collab.$tid." . substr($now, 0, 16), $actor->id);
        }
        $this->app->audit()->log('ticket.assigned', 'ticket', $tid, ['responsable' => $ownerId, 'colaboradores' => $collaborators]);
    }

    /**
     * Asociar a cliente y contacto una incidencia pendiente de identificar (o corregirlo).
     * @param array<string,mixed> $ticket
     */
    public function identify(array $ticket, int $clientId, ?int $contactId, CurrentUser $actor, bool $rememberSender = false): void
    {
        $db = $this->app->db();
        $this->assertClientContact($clientId, $contactId);
        $db->run(
            "UPDATE tickets SET client_id = ?, contact_id = ?, identification_status = 'identified', updated_at = ?, lock_version = lock_version + 1 WHERE id = ?",
            [$clientId, $contactId, Clock::nowDb(), (int)$ticket['id']]
        );
        $client = $db->value('SELECT legal_name FROM clients WHERE id = ?', [$clientId]);
        $this->event((int)$ticket['id'], $actor->id, 'identified', ['cliente' => $ticket['client_id']], ['cliente' => $client]);
        if ($rememberSender && $contactId !== null) {
            $svc = $this->app->make(ClientService::class);
            if ($ticket['requester_email']) {
                $svc->addIdentity($contactId, 'email', (string)$ticket['requester_email'], false);
            }
            if ($ticket['requester_phone']) {
                $svc->addIdentity($contactId, 'phone', (string)$ticket['requester_phone'], false);
            }
        }
        $this->app->make(Sla::class)->attach((int)$ticket['id']);
    }

    /**
     * Respuesta al cliente por correo o WhatsApp. Se registra al momento y se
     * envía por la cola, con reintentos.
     * @param array<string,mixed> $ticket
     * @param list<array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     */
    public function reply(array $ticket, CurrentUser $actor, string $body, string $channel, array $files = []): int
    {
        $db = $this->app->db();
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::single('body', 'Escribe la respuesta.');
        }
        if ($channel === 'email') {
            if ($this->customerEmail($ticket) === null) {
                throw ValidationException::single('channel', 'Esta incidencia no tiene un correo de cliente al que responder.');
            }
            if ($this->app->make(Mail\EmailAccounts::class)->default() === null) {
                throw ValidationException::single('channel', 'No hay ningún buzón de correo configurado para enviar.');
            }
        } elseif ($channel === 'whatsapp') {
            $this->app->make(WhatsApp\WhatsAppService::class)->assertCanReply($ticket);
            if (count($files) > 1) {
                throw ValidationException::single('files', 'Por WhatsApp solo se puede enviar un archivo por mensaje.');
            }
        } else {
            throw ValidationException::single('channel', 'Canal de respuesta no válido.');
        }
        $now = Clock::nowDb();
        $msgId = $db->transaction(function () use ($db, $ticket, $actor, $body, $channel, $now) {
            $id = $db->insert('ticket_messages', [
                'ticket_id' => (int)$ticket['id'],
                'direction' => 'outbound',
                'channel_code' => $channel,
                'author_user_id' => $actor->id,
                'sender_label' => $actor->name,
                'body_text' => $body,
                'body_html_safe' => null,
                'is_automatic' => 0,
                'delivery_status' => 'queued',
                'created_at' => $now,
            ]);
            $db->run(
                'UPDATE tickets SET first_response_at = COALESCE(first_response_at, ?), last_activity_at = ?, updated_at = ? WHERE id = ?',
                [$now, $now, $now, (int)$ticket['id']]
            );
            $this->event((int)$ticket['id'], $actor->id, 'reply_sent', null, ['canal' => $channel]);
            return $id;
        });
        if ($files !== []) {
            $this->app->make(Attachments::class)->storeUploads((int)$ticket['id'], $files, $actor->id, 'public', $msgId);
        }
        $this->app->make(Jobs::class)->push($channel === 'email' ? 'email.send_reply' : 'whatsapp.send_reply', ['ticket_message_id' => $msgId], "reply:$msgId", 0, 6);

        // Primera respuesta: si estaba sin empezar y tiene responsable, pasa a "En curso"
        $status = $this->status((int)$ticket['status_id']);
        if (in_array($status['code'], ['new', 'assigned'], true) && $ticket['owner_user_id'] !== null && ($ip = $this->statusByCode('in_progress')) !== null) {
            $this->changeStatus($db->one('SELECT * FROM tickets WHERE id = ?', [(int)$ticket['id']]), (int)$ip['id'], $actor, false, true);
        }
        return $msgId;
    }

    /**
     * Nota interna o actuación técnica.
     * @param array<string,mixed> $ticket
     * @param list<array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     */
    public function addActivity(array $ticket, CurrentUser $actor, string $kind, string $visibility, string $body, array $files = []): int
    {
        $db = $this->app->db();
        if (!in_array($kind, ['internal_note', 'technical_action'], true)) {
            throw ValidationException::single('kind', 'Tipo no válido.');
        }
        if ($kind === 'internal_note') {
            $visibility = 'internal';
        }
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::single('body', 'Escribe el texto.');
        }
        $now = Clock::nowDb();
        $id = $db->insert('ticket_activities', [
            'ticket_id' => (int)$ticket['id'],
            'user_id' => $actor->id,
            'kind' => $kind,
            'visibility' => $visibility === 'public' ? 'public' : 'internal',
            'body' => $body,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->run('UPDATE tickets SET last_activity_at = ?, updated_at = ? WHERE id = ?', [$now, $now, (int)$ticket['id']]);
        if ($files !== []) {
            $this->app->make(Attachments::class)->storeUploads((int)$ticket['id'], $files, $actor->id, $kind === 'internal_note' ? 'internal' : $visibility, null, $id);
        }
        $n = $this->app->make(Notifications::class);
        $n->notify($n->ticketWorkers((int)$ticket['id']), 'ticket.note', (int)$ticket['id'],
            ($kind === 'internal_note' ? 'Nota interna en ' : 'Actuación en ') . $ticket['ticket_number'],
            mb_substr($body, 0, 200), "note.$id", $actor->id);
        return $id;
    }

    /**
     * Mensaje entrante de un cliente (correo o WhatsApp) en una incidencia existente.
     * Reabre si estaba resuelta y reanuda si esperaba al cliente.
     * @param array<string,mixed> $ticket
     */
    public function addInbound(array $ticket, string $channel, ?int $contactId, string $senderLabel, string $text, ?string $htmlSafe, bool $automatic = false): int
    {
        $db = $this->app->db();
        $now = Clock::nowDb();
        $msgId = $db->insert('ticket_messages', [
            'ticket_id' => (int)$ticket['id'],
            'direction' => 'inbound',
            'channel_code' => $channel,
            'author_contact_id' => $contactId,
            'sender_label' => mb_substr($senderLabel, 0, 254),
            'body_text' => $text !== '' ? $text : '(mensaje sin texto)',
            'body_html_safe' => $htmlSafe,
            'is_automatic' => $automatic ? 1 : 0,
            'delivery_status' => 'received',
            'created_at' => $now,
        ]);
        $db->run('UPDATE tickets SET last_customer_message_at = ?, last_activity_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $now, (int)$ticket['id']]);
        $this->event((int)$ticket['id'], null, 'customer_message', null, ['canal' => $channel, 'de' => $senderLabel]);

        if (!$automatic) {
            $status = $this->status((int)$ticket['status_id']);
            $target = null;
            if ($status['phase'] === 'resolved' || $status['code'] === 'pending_customer') {
                $target = $ticket['owner_user_id'] !== null ? $this->statusByCode('in_progress') : $this->statusByCode('pending_assign');
            }
            if ($target !== null) {
                $this->changeStatus($db->one('SELECT * FROM tickets WHERE id = ?', [(int)$ticket['id']]), (int)$target['id'], null, false, true);
            }
            $n = $this->app->make(Notifications::class);
            $workers = $n->ticketWorkers((int)$ticket['id']);
            $n->notify($workers !== [] ? $workers : $n->supervisors($ticket['team_id'] !== null ? (int)$ticket['team_id'] : null),
                'ticket.customer_reply', (int)$ticket['id'], 'El cliente ha respondido en ' . $ticket['ticket_number'], mb_substr($text, 0, 200), "inbound.$msgId");
        }
        return $msgId;
    }

    /** @param array<string,mixed> $ticket @param list<int> $tagIds */
    public function setTags(array $ticket, array $tagIds, CurrentUser $actor): void
    {
        $db = $this->app->db();
        $tid = (int)$ticket['id'];
        $before = array_map('intval', $db->column('SELECT tag_id FROM ticket_tag_relations WHERE ticket_id = ?', [$tid]));
        $tagIds = array_map('intval', $tagIds === [] ? [] : $db->column('SELECT id FROM ticket_tags WHERE id IN (' . implode(',', array_map('intval', $tagIds)) . ')'));
        $add = array_diff($tagIds, $before);
        $del = array_diff($before, $tagIds);
        foreach ($add as $id) {
            $db->run('INSERT IGNORE INTO ticket_tag_relations (ticket_id, tag_id) VALUES (?, ?)', [$tid, $id]);
        }
        if ($del !== []) {
            $db->run('DELETE FROM ticket_tag_relations WHERE ticket_id = ? AND tag_id IN (' . implode(',', $del) . ')', [$tid]);
        }
        if ($add !== [] || $del !== []) {
            $names = $db->all('SELECT id, name FROM ticket_tags WHERE id IN (' . implode(',', array_merge([0], $add, $del)) . ')');
            $map = array_column($names, 'name', 'id');
            $this->event($tid, $actor->id, 'tags_changed',
                $del ? ['quitadas' => array_values(array_map(static fn ($i) => $map[$i] ?? $i, $del))] : null,
                $add ? ['añadidas' => array_values(array_map(static fn ($i) => $map[$i] ?? $i, $add))] : null);
        }
    }

    /** @param array<string,mixed> $ticket */
    public function addRelation(array $ticket, int $relatedId, string $type, CurrentUser $actor): void
    {
        $db = $this->app->db();
        if (!in_array($type, ['related', 'duplicate_of', 'parent_of', 'blocks'], true)) {
            throw ValidationException::single('relation_type', 'Tipo de relación no válido.');
        }
        if ($relatedId === (int)$ticket['id']) {
            throw ValidationException::single('related', 'Una incidencia no puede relacionarse consigo misma.');
        }
        if ($this->app->make(TicketAccess::class)->visible($actor, $relatedId) === null) {
            throw ValidationException::single('related', 'No existe esa incidencia o no tienes acceso a ella.');
        }
        $db->run('INSERT IGNORE INTO ticket_relations (ticket_id, related_ticket_id, relation_type, created_by, created_at) VALUES (?, ?, ?, ?, ?)',
            [(int)$ticket['id'], $relatedId, $type, $actor->id, Clock::nowDb()]);
        $num = $db->value('SELECT ticket_number FROM tickets WHERE id = ?', [$relatedId]);
        $this->event((int)$ticket['id'], $actor->id, 'relation_added', null, ['tipo' => $type, 'incidencia' => $num]);
    }

    /** @param array<string,mixed> $ticket */
    public function removeRelation(array $ticket, int $relationId, CurrentUser $actor): void
    {
        $db = $this->app->db();
        $r = $db->one('SELECT r.*, t.ticket_number FROM ticket_relations r JOIN tickets t ON t.id = r.related_ticket_id WHERE r.id = ? AND r.ticket_id = ?', [$relationId, (int)$ticket['id']]);
        if ($r === null) {
            throw HttpException::notFound();
        }
        $db->run('DELETE FROM ticket_relations WHERE id = ?', [$relationId]);
        $this->event((int)$ticket['id'], $actor->id, 'relation_removed', ['tipo' => $r['relation_type'], 'incidencia' => $r['ticket_number']], null);
    }

    /** Eliminación lógica: deja de verse, pero no se borra el historial. @param array<string,mixed> $ticket */
    public function softDelete(array $ticket, CurrentUser $actor, string $reason): void
    {
        $now = Clock::nowDb();
        $this->app->make(TimeService::class)->stopAllForTicket((int)$ticket['id'], $actor->id);
        $this->app->db()->run('UPDATE tickets SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL', [$now, $now, (int)$ticket['id']]);
        $this->event((int)$ticket['id'], $actor->id, 'deleted', null, ['motivo' => $reason]);
        $this->app->audit()->log('ticket.deleted', 'ticket', (int)$ticket['id'], ['numero' => $ticket['ticket_number'], 'motivo' => $reason]);
    }

    public function event(int $ticketId, ?int $actorId, string $type, mixed $old, mixed $new): void
    {
        $this->app->db()->insert('ticket_events', [
            'ticket_id' => $ticketId,
            'actor_user_id' => $actorId,
            'event_type' => $type,
            'old_value' => $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
            'new_value' => $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
            'created_at' => Clock::now()->format('Y-m-d H:i:s.v'),
        ]);
    }

    // ------------------------------------------------------------------
    // Cliente: correo de contacto, avisos y variables de plantilla
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $ticket */
    public function customerEmail(array $ticket): ?string
    {
        if ($ticket['contact_id'] !== null) {
            $e = $this->app->db()->value(
                "SELECT value FROM contact_identities WHERE contact_id = ? AND kind = 'email' ORDER BY is_primary DESC, id LIMIT 1",
                [(int)$ticket['contact_id']]
            );
            if ($e !== null) {
                return (string)$e;
            }
        }
        if ($ticket['requester_email'] !== null && $ticket['requester_email'] !== '') {
            return (string)$ticket['requester_email'];
        }
        if ($ticket['client_id'] !== null) {
            $e = $this->app->db()->value('SELECT email FROM clients WHERE id = ?', [(int)$ticket['client_id']]);
            return $e !== null && $e !== '' ? (string)$e : null;
        }
        return null;
    }

    public function queueCustomerNotice(int $ticketId, string $templateCode): void
    {
        $t = $this->app->db()->one('SELECT * FROM tickets WHERE id = ?', [$ticketId]);
        if ($t === null || $this->customerEmail($t) === null || $this->app->make(Mail\EmailAccounts::class)->default() === null) {
            return;
        }
        $this->app->make(Jobs::class)->push('email.customer_notice', ['ticket_id' => $ticketId, 'template' => $templateCode], "notice:$templateCode:$ticketId:" . date('YmdHi'));
    }

    /** @return array<string,string> */
    public function templateVars(int $ticketId): array
    {
        $r = $this->app->db()->one(
            "SELECT t.ticket_number, t.subject, s.name AS status, p.name AS priority,
                    COALESCE(c.trade_name, c.legal_name) AS client_name,
                    TRIM(CONCAT(COALESCE(cc.first_name,''), ' ', COALESCE(cc.last_name,''))) AS contact_name
               FROM tickets t
               JOIN ticket_statuses s ON s.id = t.status_id
               JOIN ticket_priorities p ON p.id = t.priority_id
          LEFT JOIN clients c ON c.id = t.client_id
          LEFT JOIN client_contacts cc ON cc.id = t.contact_id
              WHERE t.id = ?",
            [$ticketId]
        );
        if ($r === null) {
            return [];
        }
        return [
            'ticket.number' => (string)$r['ticket_number'],
            'ticket.subject' => (string)$r['subject'],
            'ticket.status' => (string)$r['status'],
            'ticket.priority' => (string)$r['priority'],
            'client.name' => (string)($r['client_name'] ?? 'Sin identificar'),
            'contact.name' => trim((string)$r['contact_name']) !== '' ? trim((string)$r['contact_name']) : 'cliente',
        ];
    }

    // ------------------------------------------------------------------
    // Validaciones internas
    // ------------------------------------------------------------------

    private function assertClientContact(?int $clientId, ?int $contactId): void
    {
        $db = $this->app->db();
        if ($clientId !== null && !$db->value("SELECT 1 FROM clients WHERE id = ? AND deleted_at IS NULL", [$clientId])) {
            throw ValidationException::single('client_id', 'Cliente no válido.');
        }
        if ($contactId !== null) {
            if ($clientId === null) {
                throw ValidationException::single('contact_id', 'Indica primero el cliente.');
            }
            if (!$db->value('SELECT 1 FROM client_contacts WHERE id = ? AND client_id = ? AND deleted_at IS NULL', [$contactId, $clientId])) {
                throw ValidationException::single('contact_id', 'El contacto no pertenece a ese cliente.');
            }
        }
    }

    /** @param list<int> $userIds */
    private function assertStaff(array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return;
        }
        $ok = (int)$this->app->db()->value(
            "SELECT COUNT(*) FROM users WHERE id IN (" . implode(',', $userIds) . ") AND user_type = 'staff' AND status = 'active' AND deleted_at IS NULL"
        );
        if ($ok !== count($userIds)) {
            throw ValidationException::single('owner_user_id', 'Alguno de los empleados elegidos no está activo.');
        }
    }

    /** @param list<int|null> $ids @return array<int,string> */
    public function userNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->app->db()->all(
            "SELECT u.id, COALESCE(CONCAT(e.first_name, ' ', e.last_name), u.email) AS name FROM users u LEFT JOIN employees e ON e.user_id = u.id WHERE u.id IN (" . implode(',', $ids) . ')'
        );
        return array_column($rows, 'name', 'id');
    }
}
