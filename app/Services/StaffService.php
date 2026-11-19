<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Clock;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Security\CurrentUser;
use App\Security\PasswordPolicy;

/**
 * Empleados y sus cuentas de acceso (una ficha de empleado por usuario).
 */
final class StaffService
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string,mixed> $d first_name, last_name, email, username, role_id, corporate_phone, hired_on, max_open_tickets, team_ids, password
     */
    public function create(array $d, ?int $actorId, bool $mustChangePassword = true): int
    {
        $db = $this->app->db();
        $email = Validator::normalizeEmail((string)$d['email']);
        if ($db->value('SELECT 1 FROM users WHERE email_normalized = ?', [$email])) {
            throw ValidationException::single('email', 'Ya existe un usuario con ese correo.');
        }
        $username = isset($d['username']) && $d['username'] !== null && $d['username'] !== '' ? mb_strtolower((string)$d['username']) : null;
        if ($username !== null && $db->value('SELECT 1 FROM users WHERE username = ?', [$username])) {
            throw ValidationException::single('username', 'Ese nombre de usuario ya está en uso.');
        }
        $role = $db->one("SELECT id, code FROM roles WHERE id = ? AND user_type = 'staff'", [(int)$d['role_id']])
            ?? throw ValidationException::single('role_id', 'Rol no válido.');
        $policy = new PasswordPolicy($this->app->settings()->int('security.password_min_length', 12));
        $policy->assertValid((string)$d['password'], (string)($d['password_confirmation'] ?? $d['password']), array_values(array_filter([$email, $username, (string)$d['first_name'], (string)$d['last_name']])));
        $now = Clock::nowDb();
        return $db->transaction(function () use ($db, $d, $email, $username, $role, $now, $actorId, $mustChangePassword) {
            $uid = $db->insert('users', [
                'user_type' => 'staff',
                'username' => $username,
                'email' => $d['email'],
                'email_normalized' => $email,
                'password_hash' => PasswordPolicy::hash((string)$d['password']),
                'role_id' => (int)$role['id'],
                'status' => 'active',
                'must_change_password' => $mustChangePassword ? 1 : 0,
                'password_changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $actorId,
            ]);
            $db->insert('employees', [
                'user_id' => $uid,
                'first_name' => $d['first_name'],
                'last_name' => $d['last_name'],
                'corporate_phone' => $d['corporate_phone'] ?? null,
                'hired_on' => $d['hired_on'] ?? null,
                'max_open_tickets' => $d['max_open_tickets'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $actorId,
            ]);
            $this->syncTeams($uid, (array)($d['team_ids'] ?? []), (array)($d['lead_team_ids'] ?? []));
            $this->app->audit()->log('user.created', 'user', $uid, ['correo' => $email, 'rol' => $role['code']], $actorId ? 'user' : 'system', $actorId);
            return $uid;
        });
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $d */
    public function update(array $user, array $d, CurrentUser $actor): void
    {
        $db = $this->app->db();
        $uid = (int)$user['id'];
        $email = Validator::normalizeEmail((string)$d['email']);
        if ($db->value('SELECT 1 FROM users WHERE email_normalized = ? AND id <> ?', [$email, $uid])) {
            throw ValidationException::single('email', 'Ya existe otro usuario con ese correo.');
        }
        $username = isset($d['username']) && $d['username'] !== null && $d['username'] !== '' ? mb_strtolower((string)$d['username']) : null;
        if ($username !== null && $db->value('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, $uid])) {
            throw ValidationException::single('username', 'Ese nombre de usuario ya está en uso.');
        }
        $set = ['email' => $d['email'], 'email_normalized' => $email, 'username' => $username, 'updated_at' => Clock::nowDb()];
        $roleChanged = false;
        if (isset($d['role_id']) && (int)$d['role_id'] !== (int)$user['role_id']) {
            if (!$actor->can('permissions.manage')) {
                throw ValidationException::single('role_id', 'No tienes permiso para cambiar roles.');
            }
            if ($uid === $actor->id) {
                throw ValidationException::single('role_id', 'No puedes cambiar tu propio rol.');
            }
            $role = $db->one("SELECT id, code FROM roles WHERE id = ? AND user_type = 'staff'", [(int)$d['role_id']])
                ?? throw ValidationException::single('role_id', 'Rol no válido.');
            $this->assertNotLastSuperadmin($uid, $role['code'] !== 'superadmin');
            $set['role_id'] = (int)$role['id'];
            $roleChanged = true;
        }
        $db->transaction(function () use ($db, $uid, $set, $d, $roleChanged, $user) {
            $db->update('users', $set, 'id = ?', [$uid]);
            $db->update('employees', [
                'first_name' => $d['first_name'],
                'last_name' => $d['last_name'],
                'corporate_phone' => $d['corporate_phone'] ?? null,
                'hired_on' => $d['hired_on'] ?? null,
                'left_on' => $d['left_on'] ?? null,
                'max_open_tickets' => $d['max_open_tickets'] ?? null,
                'updated_at' => Clock::nowDb(),
            ], 'user_id = ?', [$uid]);
            if (array_key_exists('team_ids', $d)) {
                $this->syncTeams($uid, (array)$d['team_ids'], (array)($d['lead_team_ids'] ?? []));
            }
            $this->app->audit()->log('user.updated', 'user', $uid, ['rol_cambiado' => $roleChanged, 'correo' => $set['email_normalized'] !== $user['email_normalized'] ? $set['email_normalized'] : null]);
        });
        if ($roleChanged) {
            $this->app->auth()->revokeAll($uid);
        }
        $this->app->permissions()->forget($uid);
    }

    /** @param list<int> $teamIds @param list<int> $leadTeamIds */
    public function syncTeams(int $userId, array $teamIds, array $leadTeamIds): void
    {
        $db = $this->app->db();
        $teamIds = array_values(array_unique(array_map('intval', $teamIds)));
        $leadTeamIds = array_values(array_intersect(array_map('intval', $leadTeamIds), $teamIds));
        $valid = $teamIds === [] ? [] : array_map('intval', $db->column('SELECT id FROM teams WHERE id IN (' . implode(',', $teamIds) . ')'));
        $db->run('DELETE FROM team_members WHERE user_id = ?' . ($valid !== [] ? ' AND team_id NOT IN (' . implode(',', $valid) . ')' : ''), [$userId]);
        foreach ($valid as $tid) {
            $db->run(
                'INSERT INTO team_members (team_id, user_id, is_lead, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE is_lead = VALUES(is_lead)',
                [$tid, $userId, in_array($tid, $leadTeamIds, true) ? 1 : 0, Clock::nowDb()]
            );
        }
    }

    /** active | blocked | disabled. Bloquear o desactivar cierra sus sesiones al momento. */
    public function setStatus(int $userId, string $status, CurrentUser $actor): void
    {
        if (!in_array($status, ['active', 'blocked', 'disabled'], true)) {
            throw ValidationException::single('status', 'Estado no válido.');
        }
        if ($userId === $actor->id && $status !== 'active') {
            throw new ValidationException([], 'No puedes bloquear ni desactivar tu propia cuenta.');
        }
        if ($status !== 'active') {
            $this->assertNotLastSuperadmin($userId, true);
        }
        $this->app->db()->run('UPDATE users SET status = ?, locked_until = NULL, failed_login_count = 0, updated_at = ? WHERE id = ?', [$status, Clock::nowDb(), $userId]);
        if ($status !== 'active') {
            $this->app->auth()->revokeAll($userId);
            $this->app->make(TimeService::class);
            foreach ($this->app->db()->all('SELECT * FROM ticket_time_entries WHERE running_user_id = ?', [$userId]) as $e) {
                $this->app->db()->run('UPDATE ticket_time_entries SET ended_at = ?, minutes = GREATEST(1, LEAST(1440, CEIL(TIMESTAMPDIFF(SECOND, started_at, ?) / 60))), updated_by = ? WHERE id = ?',
                    [Clock::nowDb(), Clock::nowDb(), $actor->id, (int)$e['id']]);
                $this->app->make(TimeService::class)->recompute((int)$e['ticket_id']);
            }
        }
        $this->app->audit()->log('user.status', 'user', $userId, ['estado' => $status]);
    }

    /** Contraseña temporal puesta por un administrador: obliga a cambiarla. */
    public function adminSetPassword(int $userId, string $password, string $confirmation): void
    {
        $u = $this->app->db()->one('SELECT u.email, u.username, e.first_name, e.last_name FROM users u LEFT JOIN employees e ON e.user_id = u.id WHERE u.id = ?', [$userId]);
        (new PasswordPolicy($this->app->settings()->int('security.password_min_length', 12)))
            ->assertValid($password, $confirmation, array_values(array_filter((array)$u)));
        $this->app->db()->run(
            'UPDATE users SET password_hash = ?, must_change_password = 1, password_changed_at = ?, locked_until = NULL, failed_login_count = 0, updated_at = ? WHERE id = ?',
            [PasswordPolicy::hash($password), Clock::nowDb(), Clock::nowDb(), $userId]
        );
        $this->app->auth()->revokeAll($userId);
        $this->app->audit()->log('user.password_reset_by_admin', 'user', $userId);
    }

    /**
     * Reasigna en bloque las incidencias abiertas de un empleado (bajas, vacaciones).
     * @return int incidencias reasignadas
     */
    public function reassignOpenTickets(int $fromUserId, ?int $toUserId, CurrentUser $actor, bool $includeCollaborations): int
    {
        $db = $this->app->db();
        $svc = $this->app->make(TicketService::class);
        $ids = $db->column(
            "SELECT t.id FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id
              WHERE t.owner_user_id = ? AND s.phase IN ('new','open','pending') AND t.deleted_at IS NULL",
            [$fromUserId]
        );
        $n = 0;
        foreach ($ids as $id) {
            $t = $db->one('SELECT * FROM tickets WHERE id = ?', [(int)$id]);
            $collabs = array_map('intval', $db->column("SELECT user_id FROM ticket_participants WHERE ticket_id = ? AND role = 'collaborator' AND removed_at IS NULL", [(int)$id]));
            $svc->assign($t, $toUserId, array_values(array_diff($collabs, [$toUserId ?? 0])), $actor);
            $n++;
        }
        if ($includeCollaborations) {
            $now = Clock::nowDb();
            foreach ($db->column(
                "SELECT p.ticket_id FROM ticket_participants p JOIN tickets t ON t.id = p.ticket_id JOIN ticket_statuses s ON s.id = t.status_id
                  WHERE p.user_id = ? AND p.removed_at IS NULL AND s.phase IN ('new','open','pending')",
                [$fromUserId]
            ) as $tid) {
                $db->run('UPDATE ticket_participants SET removed_at = ? WHERE ticket_id = ? AND user_id = ?', [$now, (int)$tid, $fromUserId]);
                $svc->event((int)$tid, $actor->id, 'participants_changed', ['quitados' => [$fromUserId]], null);
            }
        }
        $this->app->audit()->log('user.mass_reassign', 'user', $fromUserId, ['a' => $toUserId, 'incidencias' => $n]);
        return $n;
    }

    /** Sustituye las excepciones de permisos de un usuario. @param array<string,string> $overrides code => allow|deny */
    public function setOverrides(int $userId, array $overrides, CurrentUser $actor): void
    {
        $db = $this->app->db();
        if ($userId === $actor->id) {
            throw new ValidationException([], 'No puedes modificar tus propios permisos.');
        }
        $ids = array_column($db->all('SELECT id, code FROM permissions'), 'id', 'code');
        $db->transaction(function () use ($db, $userId, $overrides, $ids, $actor) {
            $db->run('DELETE FROM user_permissions WHERE user_id = ?', [$userId]);
            foreach ($overrides as $code => $effect) {
                if (!isset($ids[$code]) || !in_array($effect, ['allow', 'deny'], true)) {
                    continue;
                }
                $db->insert('user_permissions', ['user_id' => $userId, 'permission_id' => (int)$ids[$code], 'effect' => $effect, 'granted_by' => $actor->id, 'created_at' => Clock::nowDb()]);
            }
        });
        $this->app->permissions()->forget($userId);
        $this->app->audit()->log('user.permissions', 'user', $userId, ['excepciones' => $overrides]);
    }

    private function assertNotLastSuperadmin(int $userId, bool $losing): void
    {
        if (!$losing) {
            return;
        }
        $db = $this->app->db();
        $isSuper = $db->value("SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.code = 'superadmin'", [$userId]);
        if (!$isSuper) {
            return;
        }
        $others = (int)$db->value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'superadmin' AND u.status = 'active' AND u.deleted_at IS NULL AND u.id <> ?", [$userId]);
        if ($others === 0) {
            throw new ValidationException([], 'Debe quedar al menos un superadministrador activo.');
        }
    }
}
