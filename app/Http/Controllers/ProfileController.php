<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Clock;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Security\PasswordPolicy;
use App\Services\Notifications;

final class ProfileController extends Controller
{
    public function show(): Response
    {
        $u = $this->user();
        $db = $this->db();
        $prefs = [];
        foreach ($db->all('SELECT event_code, channel, enabled FROM notification_preferences WHERE user_id = ?', [$u->id]) as $p) {
            $prefs[$p['event_code']][$p['channel']] = (bool)$p['enabled'];
        }
        $events = [];
        foreach (Notifications::EVENTS as $code => [$label, $emailDefault]) {
            $events[] = [
                'code' => $code,
                'label' => $label,
                'in_app' => $prefs[$code]['in_app'] ?? true,
                'email' => $prefs[$code]['email'] ?? $emailDefault,
            ];
        }
        $sessions = $db->all(
            'SELECT id, ip, user_agent, created_at, last_seen_at FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL AND last_seen_at > ? ORDER BY last_seen_at DESC LIMIT 20',
            [$u->id, Clock::now()->modify('-12 hours')->format(Clock::DB_FORMAT)]
        );
        foreach ($sessions as &$s) {
            $s['ip'] = $s['ip'] !== null ? (string)@inet_ntop((string)$s['ip']) : '';
            $s['current'] = $s['id'] === $this->app->session()->get('sid_hash');
        }
        unset($s);
        $profile = $db->one(
            'SELECT u.email, u.username, u.last_login_at, u.password_changed_at, e.first_name, e.last_name, e.corporate_phone
               FROM users u LEFT JOIN employees e ON e.user_id = u.id WHERE u.id = ?',
            [$u->id]
        );
        $teams = $db->all('SELECT t.name, m.is_lead FROM team_members m JOIN teams t ON t.id = m.team_id WHERE m.user_id = ? ORDER BY t.name', [$u->id]);
        return $this->render('profile/show.twig', compact('profile', 'events', 'sessions', 'teams'));
    }

    public function showPassword(): Response
    {
        return $this->render('profile/password.twig', ['forced' => $this->user()->mustChangePassword]);
    }

    public function password(): Response
    {
        $u = $this->user();
        $current = (string)($this->request->post['current_password'] ?? '');
        $new = (string)($this->request->post['password'] ?? '');
        $confirm = (string)($this->request->post['password_confirmation'] ?? '');
        $row = $this->db()->one('SELECT password_hash FROM users WHERE id = ?', [$u->id]);
        if (!$this->app->rateLimiter()->hit('pwchange:' . $u->id, 10, 900)) {
            throw new ValidationException([], 'Demasiados intentos. Espera unos minutos.');
        }
        if ($row === null || !password_verify($current, (string)$row['password_hash'])) {
            throw ValidationException::single('current_password', 'La contraseña actual no es correcta.');
        }
        if (password_verify($new, (string)$row['password_hash'])) {
            throw ValidationException::single('password', 'La nueva contraseña debe ser distinta de la actual.');
        }
        (new PasswordPolicy($this->app->settings()->int('security.password_min_length', 12)))
            ->assertValid($new, $confirm, array_values(array_filter([$u->email, $u->username, $u->name])));
        $this->db()->run(
            'UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = ?, session_version = session_version + 1 WHERE id = ?',
            [PasswordPolicy::hash($new), Clock::nowDb(), $u->id]
        );
        // Cierra las demás sesiones y mantiene esta
        $this->db()->run('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [Clock::nowDb(), $u->id]);
        $this->app->auth()->refreshVersion($u->id);
        $this->audit()->log('user.password_changed', 'user', $u->id);
        return $this->redirect('/perfil', 'Contraseña cambiada. Se han cerrado tus otras sesiones.');
    }

    public function notifications(): Response
    {
        $u = $this->user();
        $in = (array)($this->request->post['pref'] ?? []);
        $now = Clock::nowDb();
        foreach (array_keys(Notifications::EVENTS) as $code) {
            foreach (['in_app', 'email'] as $ch) {
                $enabled = isset($in[$code][$ch]) ? 1 : 0;
                $this->db()->run(
                    'INSERT INTO notification_preferences (user_id, event_code, channel, enabled, updated_at) VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = VALUES(updated_at)',
                    [$u->id, $code, $ch, $enabled, $now]
                );
            }
        }
        return $this->redirect('/perfil', 'Preferencias de avisos guardadas.');
    }

    public function closeSessions(): Response
    {
        $u = $this->user();
        $this->db()->run(
            'UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND id <> ?',
            [Clock::nowDb(), $u->id, (string)$this->app->session()->get('sid_hash')]
        );
        $this->audit()->log('user.sessions_closed', 'user', $u->id);
        return $this->redirect('/perfil', 'Se han cerrado tus otras sesiones.');
    }
}
