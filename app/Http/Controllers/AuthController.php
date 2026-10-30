<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Clock;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Http\Controller;
use App\Security\Auth;
use App\Security\Crypto;
use App\Security\PasswordPolicy;
use App\Security\RateLimiter;
use App\Services\Mail\EmailAccounts;
use App\Services\Mail\Mailer;
use App\Services\Templates;

final class AuthController extends Controller
{
    public function showLogin(): Response
    {
        return $this->render('auth/login.twig');
    }

    public function login(): Response
    {
        $identifier = trim($this->request->str('identifier'));
        $password = (string)($this->request->post['password'] ?? '');
        $session = $this->app->session();
        if ($identifier === '' || $password === '') {
            $session->flashForm(['identifier' => $identifier], []);
            $this->flash('danger', 'Introduce usuario y contraseña.');
            return $this->redirect('/login');
        }
        $uid = $this->app->auth()->attempt($identifier, $password, $this->request);
        if ($uid === null) {
            $session->flashForm(['identifier' => $identifier], []);
            $this->flash('danger', Auth::GENERIC_ERROR);
            return $this->redirect('/login');
        }
        $intended = $session->get('intended');
        $this->app->auth()->login($uid, $this->request);
        $session->remove('intended');
        $to = is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//') ? $intended : '/';
        return $this->redirect($to);
    }

    public function logout(): Response
    {
        $this->app->auth()->logout();
        $this->app->session()->start($this->request->isHttps());
        $this->flash('success', 'Has cerrado la sesión.');
        return $this->redirect('/login');
    }

    public function showForgot(): Response
    {
        return $this->render('auth/forgot.twig');
    }

    /**
     * Respuesta idéntica exista o no la cuenta (no se revela qué correos existen).
     */
    public function forgot(): Response
    {
        $email = Validator::normalizeEmail($this->request->str('email'));
        $generic = 'Si el correo corresponde a una cuenta activa, recibirás un enlace para restablecer la contraseña en unos minutos.';
        $limiter = $this->app->rateLimiter();
        if (!$limiter->hit(RateLimiter::key('reset-ip', $this->request->ip()), 10, 3600)
            || !$limiter->hit(RateLimiter::key('reset-mail', $email), 3, 3600)) {
            return $this->redirect('/login', $generic);
        }
        $db = $this->db();
        $user = $email !== '' ? $db->one("SELECT id, email FROM users WHERE email_normalized = ? AND status = 'active' AND deleted_at IS NULL AND user_type = 'staff'", [$email]) : null;
        if ($user !== null) {
            $account = $this->app->make(EmailAccounts::class)->default();
            if ($account === null) {
                $this->app->logger()->warning('Recuperación de contraseña solicitada sin buzón de envío configurado.');
            } else {
                $token = Crypto::randomToken(32);
                $minutes = $this->app->settings()->int('security.reset_minutes', 30);
                $db->run('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [Clock::nowDb(), (int)$user['id']]);
                $db->insert('password_resets', [
                    'user_id' => (int)$user['id'],
                    'token_hash' => Crypto::tokenHash($token),
                    'expires_at' => Clock::now()->modify("+$minutes minutes")->format(Clock::DB_FORMAT),
                    'requested_ip' => @inet_pton($this->request->ip()) ?: null,
                    'created_at' => Clock::nowDb(),
                ]);
                $msg = $this->app->make(Templates::class)->render('email', 'password_reset', [
                    'reset.url' => $this->app->url('/restablecer/' . $token),
                    'reset.minutes' => (string)$minutes,
                ]);
                try {
                    // Envío directo: el token no debe pasar por la cola ni guardarse en claro
                    $this->app->make(Mailer::class)->send($account, (string)$user['email'], $msg['subject'], $msg['body'], autoSubmitted: true);
                } catch (\Throwable $e) {
                    $this->app->logger()->error('No se pudo enviar el correo de recuperación: ' . $e->getMessage());
                }
                $this->audit()->log('auth.reset_requested', 'user', (int)$user['id'], [], 'anonymous', null);
            }
        }
        return $this->redirect('/login', $generic);
    }

    public function showReset(): Response
    {
        if ($this->validResetRow($this->request->param('token')) === null) {
            $this->flash('danger', 'El enlace no es válido o ha caducado. Solicita otro.');
            return $this->redirect('/recuperar');
        }
        return $this->render('auth/reset.twig', ['token' => $this->request->param('token')]);
    }

    public function reset(): Response
    {
        $token = $this->request->param('token');
        $row = $this->validResetRow($token);
        if ($row === null) {
            $this->flash('danger', 'El enlace no es válido o ha caducado. Solicita otro.');
            return $this->redirect('/recuperar');
        }
        $password = (string)($this->request->post['password'] ?? '');
        $confirm = (string)($this->request->post['password_confirmation'] ?? '');
        $u = $this->db()->one('SELECT u.email, u.username, e.first_name, e.last_name FROM users u LEFT JOIN employees e ON e.user_id = u.id WHERE u.id = ?', [(int)$row['user_id']]);
        (new PasswordPolicy($this->app->settings()->int('security.password_min_length', 12)))
            ->assertValid($password, $confirm, array_values(array_filter((array)$u)));
        $db = $this->db();
        $db->transaction(function () use ($db, $row, $password) {
            $n = $db->run('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL', [Clock::nowDb(), (int)$row['id']])->rowCount();
            if ($n === 0) {
                throw new ValidationException([], 'El enlace ya se ha utilizado.');
            }
            $db->run(
                'UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = ?, locked_until = NULL, failed_login_count = 0 WHERE id = ?',
                [PasswordPolicy::hash($password), Clock::nowDb(), (int)$row['user_id']]
            );
        });
        $this->app->auth()->revokeAll((int)$row['user_id']);
        $this->audit()->log('auth.password_reset', 'user', (int)$row['user_id'], [], 'anonymous', null);
        return $this->redirect('/login', 'Contraseña actualizada. Ya puedes iniciar sesión.');
    }

    /** @return array<string,mixed>|null */
    private function validResetRow(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        return $this->db()->one(
            "SELECT pr.* FROM password_resets pr JOIN users u ON u.id = pr.user_id
              WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > ? AND u.status = 'active' AND u.deleted_at IS NULL",
            [Crypto::tokenHash($token), Clock::nowDb()]
        );
    }
}
