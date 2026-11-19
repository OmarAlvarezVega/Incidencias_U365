<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Services\Settings;

/**
 * Autenticación por sesión.
 *
 * - Mensaje y tiempo de respuesta iguales exista o no el usuario.
 * - Límite de intentos por usuario y por IP, con bloqueo temporal.
 * - Regeneración del id de sesión al autenticar.
 * - Caducidad por inactividad y absoluta.
 * - Revocación inmediata: al desactivar o bloquear a un usuario se incrementa
 *   users.session_version y todas sus sesiones dejan de valer.
 * - Sesión recordada (opcional, por dispositivo): una cookie con
 *   «selector.validador» abre una sesión nueva cuando la anterior ha caducado.
 *   En la BD solo está el hash del validador; caduca a los
 *   security.remember_days días y se revoca con la sesión (cerrar sesión,
 *   cambio de contraseña, desactivación).
 */
final class Auth
{
    public const GENERIC_ERROR = 'Usuario o contraseña incorrectos, o acceso bloqueado temporalmente.';
    private const ABSOLUTE_HOURS = 12;
    private const REMEMBER_COOKIE = 'u365r';

    private ?CurrentUser $user = null;
    private bool $resolved = false;
    private ?Request $request = null;

    public function __construct(
        private readonly Database $db,
        private readonly Session $session,
        private readonly Permissions $permissions,
        private readonly AuditLogger $audit,
        private readonly Settings $settings,
    ) {
    }

    /**
     * Comprueba credenciales. Devuelve el id de usuario o null. No inicia sesión.
     */
    public function attempt(string $identifier, string $password, Request $request): ?int
    {
        $identifier = mb_strtolower(trim($identifier));
        $idHash = hash('sha256', $identifier);
        $ip = $request->ip();
        $ipBin = @inet_pton($ip) ?: inet_pton('0.0.0.0');
        $now = Clock::now();
        $window = $now->modify('-15 minutes')->format(Clock::DB_FORMAT);
        $maxPerUser = max(3, $this->settings->int('security.login_max_attempts', 5));

        $failuresUser = (int)$this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier_hash = ? AND succeeded = 0 AND attempted_at >= ?',
            [$idHash, $window]
        );
        $failuresIp = (int)$this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = 0 AND attempted_at >= ?',
            [$ipBin, $window]
        );

        $user = $this->db->one(
            "SELECT id, password_hash, status, locked_until, deleted_at, user_type
               FROM users WHERE (email_normalized = ? OR username = ?) AND user_type = 'staff' LIMIT 1",
            [$identifier, $identifier]
        );

        // Se verifica siempre una contraseña para igualar el tiempo de respuesta
        $hash = $user['password_hash'] ?? (defined('PASSWORD_ARGON2ID')
            ? '$argon2id$v=19$m=65536,t=4,p=1$MTlXV3VNSC9UYTdMeFN0Ug$8SCZGGg4zIjUt9zl8AolQLaB+Z/mRoYyS2bCx2UFyhg'
            : '$2y$12$Q7zHe/A1EiVnejIuGSGP9OP453tGGZMfbIpP.8ydD5a8rxgU1cj8e');
        $passwordOk = password_verify($password, (string)$hash);

        $blocked = $failuresUser >= $maxPerUser
            || $failuresIp >= 30
            || ($user !== null && $user['locked_until'] !== null && Clock::fromDb($user['locked_until']) > $now);
        $valid = $user !== null && $passwordOk && !$blocked && $user['status'] === 'active' && $user['deleted_at'] === null;

        $this->db->insert('login_attempts', [
            'identifier_hash' => $idHash,
            'ip' => $ipBin,
            'succeeded' => $valid ? 1 : 0,
            'attempted_at' => $now->format(Clock::DB_FORMAT),
        ]);

        if (!$valid) {
            if ($user !== null && !$blocked && $failuresUser + 1 >= $maxPerUser) {
                $this->db->run('UPDATE users SET locked_until = ?, failed_login_count = failed_login_count + 1 WHERE id = ?', [
                    $now->modify('+15 minutes')->format(Clock::DB_FORMAT), (int)$user['id'],
                ]);
                $this->audit->log('auth.locked', 'user', (int)$user['id'], ['motivo' => 'demasiados intentos fallidos'], 'anonymous', null);
            }
            $this->audit->log('auth.login_failed', $user ? 'user' : null, $user['id'] ?? null, [
                'bloqueado' => $blocked,
                'motivo' => $user === null ? 'usuario desconocido' : ($blocked ? 'bloqueo temporal' : ($user['status'] !== 'active' ? 'usuario ' . $user['status'] : 'contraseña incorrecta')),
            ], 'anonymous', null);
            return null;
        }

        if (PasswordPolicy::needsRehash((string)$user['password_hash'])) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [PasswordPolicy::hash($password), (int)$user['id']]);
        }
        return (int)$user['id'];
    }

    /** La petición en curso: hace falta para leer la cookie de sesión recordada. */
    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function login(int $userId, Request $request): void
    {
        $this->session->regenerate();
        $now = Clock::nowDb();
        $version = (int)$this->db->value('SELECT session_version FROM users WHERE id = ?', [$userId]);
        $this->session->set('uid', $userId);
        $this->session->set('sv', $version);
        $this->session->set('login_at', time());
        $this->session->set('seen_at', time());
        $this->session->set('_csrf', bin2hex(random_bytes(32)));
        $sidHash = hash('sha256', $this->session->id());
        $this->session->set('sid_hash', $sidHash);
        $this->db->run(
            'INSERT INTO user_sessions (id, user_id, session_version, ip, user_agent, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$sidHash, $userId, $version, @inet_pton($request->ip()) ?: null, $request->userAgent(), $now, $now]
        );
        $this->db->run('UPDATE users SET last_login_at = ?, failed_login_count = 0, locked_until = NULL WHERE id = ?', [$now, $userId]);
        $this->audit->log('auth.login', 'user', $userId, [], 'user', $userId);
        $this->resolved = false;
        $this->user = null;
    }

    public function logout(): void
    {
        $uid = $this->session->get('uid');
        $sid = $this->session->get('sid_hash');
        if (is_string($sid)) {
            $this->db->run('UPDATE user_sessions SET revoked_at = ? WHERE id = ?', [Clock::nowDb(), $sid]);
        }
        if (is_int($uid)) {
            $this->audit->log('auth.logout', 'user', $uid, [], 'user', $uid);
        }
        $this->forgetDevice();
        $this->session->destroy();
        $this->user = null;
        $this->resolved = true;
    }

    /** Usuario de la sesión, validado contra la BD en cada petición. */
    public function user(): ?CurrentUser
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;
        $uid = $this->session->get('uid');
        if (!is_int($uid)) {
            return $this->resumeRemembered();
        }
        $idle = max(5, $this->settings->int('security.session_idle_minutes', 60)) * 60;
        $seen = (int)$this->session->get('seen_at', 0);
        $loginAt = (int)$this->session->get('login_at', 0);
        if (time() - $seen > $idle || time() - $loginAt > self::ABSOLUTE_HOURS * 3600) {
            $this->session->remove('uid');
            $this->session->remove('sv');
            $resumed = $this->resumeRemembered();
            if ($resumed === null) {
                $this->expire('caducada');
            }
            return $resumed;
        }
        $row = $this->db->one(
            "SELECT u.id, u.email, u.username, u.user_type, u.status, u.session_version, u.must_change_password, u.deleted_at,
                    r.code AS role_code, r.name AS role_name, e.id AS employee_id,
                    COALESCE(CONCAT(e.first_name, ' ', e.last_name), u.email) AS display_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
          LEFT JOIN employees e ON e.user_id = u.id
              WHERE u.id = ?",
            [$uid]
        );
        if ($row === null || $row['status'] !== 'active' || $row['deleted_at'] !== null
            || (int)$row['session_version'] !== (int)$this->session->get('sv', -1)
            || $row['user_type'] !== 'staff') {
            $this->expire('revocada');
            return null;
        }
        $sid = $this->session->get('sid_hash');
        if (is_string($sid)) {
            $revoked = $this->db->value('SELECT revoked_at FROM user_sessions WHERE id = ?', [$sid]);
            if ($revoked !== null) {
                $this->expire('revocada');
                return null;
            }
            if (time() - $seen > 60) {
                $this->db->run('UPDATE user_sessions SET last_seen_at = ? WHERE id = ?', [Clock::nowDb(), $sid]);
            }
        }
        $this->session->set('seen_at', time());
        $teams = $this->db->all('SELECT team_id, is_lead FROM team_members WHERE user_id = ?', [$uid]);
        $this->user = new CurrentUser(
            id: (int)$row['id'],
            email: (string)$row['email'],
            username: $row['username'],
            name: trim((string)$row['display_name']),
            roleCode: (string)$row['role_code'],
            roleName: (string)$row['role_name'],
            userType: (string)$row['user_type'],
            employeeId: $row['employee_id'] !== null ? (int)$row['employee_id'] : null,
            mustChangePassword: (bool)$row['must_change_password'],
            permissions: $this->permissions->forUser((int)$row['id']),
            ledTeamIds: array_values(array_map('intval', array_column(array_filter($teams, static fn ($t) => (int)$t['is_lead'] === 1), 'team_id'))),
            teamIds: array_values(array_map('intval', array_column($teams, 'team_id'))),
        );
        return $this->user;
    }

    private function expire(string $why): void
    {
        $this->session->remove('uid');
        $this->session->remove('sv');
        $this->session->flash('warning', $why === 'caducada'
            ? 'Tu sesión ha caducado por inactividad. Vuelve a iniciar sesión.'
            : 'Tu sesión ya no es válida. Vuelve a iniciar sesión.');
    }

    /** Invalida todas las sesiones de un usuario (desactivación, bloqueo, cambio de contraseña). */
    public function revokeAll(int $userId): void
    {
        $this->db->run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        $this->db->run('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [Clock::nowDb(), $userId]);
        $this->revokeRemembered($userId, null);
        $this->permissions->forget($userId);
    }

    /** Días que dura una sesión recordada; 0 si la opción está desactivada. */
    public function rememberDays(): int
    {
        return max(0, min(365, $this->settings->int('security.remember_days', 180)));
    }

    /**
     * Recuerda este dispositivo para el usuario recién autenticado: crea el
     * token y envía la cookie. No hace nada si la opción está desactivada.
     */
    public function rememberDevice(int $userId, Request $request): void
    {
        $days = $this->rememberDays();
        if ($days === 0) {
            return;
        }
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $now = Clock::now();
        $expires = $now->modify("+$days days");
        try {
            $this->db->insert('remember_tokens', [
                'selector' => $selector,
                'validator_hash' => hash('sha256', $validator),
                'user_id' => $userId,
                'session_version' => (int)$this->db->value('SELECT session_version FROM users WHERE id = ?', [$userId]),
                'ip' => @inet_pton($request->ip()) ?: null,
                'user_agent' => $request->userAgent(),
                'created_at' => $now->format(Clock::DB_FORMAT),
                'last_used_at' => $now->format(Clock::DB_FORMAT),
                'expires_at' => $expires->format(Clock::DB_FORMAT),
            ]);
        } catch (\PDOException) {
            return; // Migración 0004 sin aplicar: se entra igual, sin recordar
        }
        $this->session->set('remember_selector', $selector);
        $this->sendCookie($request->isHttps(), "$selector.$validator", $expires->getTimestamp());
        $this->audit->log('auth.device_remembered', 'user', $userId, ['dias' => $days], 'user', $userId);
    }

    /** Tras cambiar la propia contraseña, este dispositivo sigue recordado y los demás no. */
    public function keepThisDevice(int $userId): void
    {
        $selector = $this->session->get('remember_selector');
        $this->revokeRemembered($userId, is_string($selector) ? $selector : null);
        if (is_string($selector)) {
            $this->safeRun(
                'UPDATE remember_tokens SET session_version = (SELECT session_version FROM users WHERE id = ?) WHERE selector = ? AND user_id = ?',
                [$userId, $selector, $userId]
            );
        }
    }

    /** Olvida todos los dispositivos del usuario salvo, si se indica, uno. */
    public function revokeRemembered(int $userId, ?string $exceptSelector): void
    {
        $this->safeRun(
            'UPDATE remember_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND selector <> ?',
            [Clock::nowDb(), $userId, (string)$exceptSelector]
        );
    }

    private function resumeRemembered(): ?CurrentUser
    {
        if ($this->request === null) {
            return null;
        }
        $raw = $this->request->cookies[$this->cookieName($this->request->isHttps())] ?? null;
        if (!is_string($raw) || !preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/', $raw, $m)) {
            return null;
        }
        try {
            $row = $this->db->one(
                'SELECT t.id, t.user_id, t.validator_hash, t.session_version, t.expires_at, t.revoked_at,
                        u.status, u.deleted_at, u.user_type, u.session_version AS current_version
                   FROM remember_tokens t JOIN users u ON u.id = t.user_id WHERE t.selector = ?',
                [$m[1]]
            );
        } catch (\PDOException) {
            return null;
        }
        $days = $this->rememberDays();
        $valid = $row !== null && $days > 0
            && hash_equals((string)$row['validator_hash'], hash('sha256', $m[2]))
            && $row['revoked_at'] === null
            && Clock::fromDb((string)$row['expires_at']) > Clock::now()
            && Clock::fromDb((string)$row['expires_at']) <= Clock::now()->modify("+$days days")
            && (int)$row['session_version'] === (int)$row['current_version']
            && $row['status'] === 'active' && $row['deleted_at'] === null && $row['user_type'] === 'staff';
        if (!$valid) {
            $this->sendCookie($this->request->isHttps(), '', 1);
            return null;
        }
        $userId = (int)$row['user_id'];
        $this->db->run('UPDATE remember_tokens SET last_used_at = ? WHERE id = ?', [Clock::nowDb(), (int)$row['id']]);
        $this->login($userId, $this->request);
        $this->session->set('remember_selector', $m[1]);
        $this->audit->log('auth.login_remembered', 'user', $userId, [], 'user', $userId);
        return $this->user();
    }

    private function forgetDevice(): void
    {
        $selector = $this->session->get('remember_selector');
        if (is_string($selector)) {
            $this->safeRun('UPDATE remember_tokens SET revoked_at = ? WHERE selector = ? AND revoked_at IS NULL', [Clock::nowDb(), $selector]);
        }
        if ($this->request !== null) {
            $this->sendCookie($this->request->isHttps(), '', 1);
        }
    }

    private function cookieName(bool $secure): string
    {
        // El prefijo __Host- obliga al navegador a exigir Secure, Path=/ y sin Domain
        return $secure ? '__Host-' . self::REMEMBER_COOKIE : self::REMEMBER_COOKIE;
    }

    private function sendCookie(bool $secure, string $value, int $expires): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }
        setcookie($this->cookieName($secure), $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** @param list<mixed> $params */
    private function safeRun(string $sql, array $params): void
    {
        try {
            $this->db->run($sql, $params);
        } catch (\PDOException) {
            // Sin la tabla (migración 0004 pendiente) no hay nada que revocar
        }
    }

    /** Mantiene viva la sesión actual tras cambiar la propia contraseña. */
    public function refreshVersion(int $userId): void
    {
        $this->session->set('sv', (int)$this->db->value('SELECT session_version FROM users WHERE id = ?', [$userId]));
        $this->session->regenerate();
        $sidHash = hash('sha256', $this->session->id());
        $now = Clock::nowDb();
        $this->session->set('sid_hash', $sidHash);
        $this->db->run(
            'INSERT INTO user_sessions (id, user_id, session_version, ip, user_agent, created_at, last_seen_at) VALUES (?, ?, ?, NULL, NULL, ?, ?)',
            [$sidHash, $userId, (int)$this->session->get('sv'), $now, $now]
        );
        $this->resolved = false;
    }
}
