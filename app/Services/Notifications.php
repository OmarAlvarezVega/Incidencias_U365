<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Clock;

/**
 * Avisos internos a empleados: en la aplicación y, si el usuario lo tiene
 * activado, por correo (enviado por la cola). dedup_key impide avisar dos
 * veces del mismo hecho.
 */
final class Notifications
{
    /** Eventos, descripción y si el correo está activado por defecto. */
    public const EVENTS = [
        'ticket.assigned' => ['Se me asigna una incidencia', true],
        'ticket.collaborator' => ['Me añaden como colaborador', true],
        'ticket.customer_reply' => ['El cliente responde en una incidencia mía', true],
        'ticket.note' => ['Nota interna en una incidencia mía', false],
        'ticket.status' => ['Cambia el estado de una incidencia mía', false],
        'ticket.unassigned_new' => ['Entra una incidencia sin asignar (responsables)', false],
        'ticket.review' => ['Mensaje pendiente de revisión (responsables)', false],
        'sla.warning' => ['Una incidencia mía está cerca de incumplir el SLA', true],
        'sla.breached' => ['Una incidencia mía ha incumplido el SLA', true],
        'mail.error' => ['Errores de correo o WhatsApp (administración)', true],
    ];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param list<int> $userIds
     * @return int avisos creados
     */
    public function notify(array $userIds, string $event, ?int $ticketId, string $title, ?string $body, string $dedupKey, ?int $exceptUserId = null): int
    {
        $db = $this->app->db();
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn ($id) => $id > 0 && $id !== $exceptUserId)));
        if ($userIds === []) {
            return 0;
        }
        $active = array_map('intval', $db->column(
            "SELECT id FROM users WHERE id IN (" . implode(',', $userIds) . ") AND status = 'active' AND deleted_at IS NULL AND user_type = 'staff'"
        ));
        $prefs = [];
        foreach ($db->all('SELECT user_id, channel, enabled FROM notification_preferences WHERE event_code = ? AND user_id IN (' . implode(',', $userIds) . ')', [$event]) as $p) {
            $prefs[(int)$p['user_id']][$p['channel']] = (bool)$p['enabled'];
        }
        $emailDefault = self::EVENTS[$event][1] ?? false;
        $emailGlobal = $this->app->settings()->bool('notifications.email_enabled', true);
        $now = Clock::nowDb();
        $created = 0;
        foreach ($active as $uid) {
            if (($prefs[$uid]['in_app'] ?? true) === false) {
                continue;
            }
            $inserted = $db->run(
                'INSERT IGNORE INTO notifications (user_id, event_code, ticket_id, title, body, dedup_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$uid, $event, $ticketId, mb_substr($title, 0, 200), $body !== null ? mb_substr($body, 0, 1000) : null, mb_substr($dedupKey, 0, 191), $now]
            )->rowCount();
            $nid = $db->lastInsertId();
            if ($inserted === 0 || $nid === 0) {
                continue;
            }
            $created++;
            if ($emailGlobal && ($prefs[$uid]['email'] ?? $emailDefault)) {
                $db->run('INSERT IGNORE INTO notification_deliveries (notification_id, channel, status, created_at) VALUES (?, \'email\', \'pending\', ?)', [$nid, $now]);
                $this->app->make(Jobs::class)->push('notification.email', ['notification_id' => $nid], 'notif:' . $nid);
            }
        }
        return $created;
    }

    /** Responsable y colaboradores activos de una incidencia. @return list<int> */
    public function ticketWorkers(int $ticketId): array
    {
        $db = $this->app->db();
        $ids = $db->column("SELECT user_id FROM ticket_participants WHERE ticket_id = ? AND removed_at IS NULL", [$ticketId]);
        $owner = $db->value('SELECT owner_user_id FROM tickets WHERE id = ?', [$ticketId]);
        if ($owner !== null) {
            $ids[] = $owner;
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Responsables de equipo (o superadministradores si no hay equipo). @return list<int> */
    public function supervisors(?int $teamId): array
    {
        $db = $this->app->db();
        $ids = [];
        if ($teamId !== null) {
            $ids = $db->column('SELECT user_id FROM team_members WHERE team_id = ? AND is_lead = 1', [$teamId]);
        }
        if ($ids === []) {
            $ids = $db->column("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code IN ('superadmin','coordinator') AND u.status = 'active' AND u.deleted_at IS NULL");
        }
        return array_values(array_map('intval', $ids));
    }

    /** Envía el correo de un aviso (trabajo de la cola). */
    public function deliverEmail(int $notificationId): void
    {
        $db = $this->app->db();
        $n = $db->one(
            'SELECT n.*, u.email FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.id = ?',
            [$notificationId]
        );
        if ($n === null) {
            return;
        }
        $delivery = $db->one("SELECT * FROM notification_deliveries WHERE notification_id = ? AND channel = 'email'", [$notificationId]);
        if ($delivery === null || $delivery['status'] === 'sent') {
            return;
        }
        $account = $this->app->make(Mail\EmailAccounts::class)->default();
        if ($account === null) {
            $db->run("UPDATE notification_deliveries SET status = 'skipped', last_error = 'Sin buzón de envío configurado' WHERE id = ?", [(int)$delivery['id']]);
            return;
        }
        $vars = ['message' => $n['title'] . ($n['body'] ? "\n\n" . $n['body'] : '')];
        if ($n['ticket_id'] !== null) {
            $vars += $this->app->make(TicketService::class)->templateVars((int)$n['ticket_id']);
            $vars['ticket.url'] = $this->app->url('/incidencias/' . (int)$n['ticket_id']);
        }
        $msg = $this->app->make(Templates::class)->render('email', 'staff_notification', $vars);
        try {
            $this->app->make(Mail\Mailer::class)->send($account, (string)$n['email'], $msg['subject'], $msg['body'], autoSubmitted: true);
            $db->run("UPDATE notification_deliveries SET status = 'sent', sent_at = ?, attempts = attempts + 1, last_error = NULL WHERE id = ?", [Clock::nowDb(), (int)$delivery['id']]);
        } catch (\Throwable $e) {
            $db->run("UPDATE notification_deliveries SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 1000), (int)$delivery['id']]);
            throw $e;
        }
    }
}
