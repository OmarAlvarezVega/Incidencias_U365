<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sesión PHP nativa con ajustes seguros. Los ficheros de sesión se guardan en
 * storage/sessions (fuera de la raíz web y separados del /tmp compartido del
 * alojamiento).
 */
final class Session
{
    private bool $started = false;

    public function __construct(
        private readonly string $savePath,
        private readonly int $idleMinutes = 60,
    ) {
    }

    public function start(bool $secure): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }
        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0700, true);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string)max(1800, $this->idleMinutes * 60));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_save_path($this->savePath);
        // El prefijo __Host- obliga al navegador a exigir Secure, Path=/ y sin Domain
        session_name($secure ? '__Host-u365' : 'u365');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        if ($this->started) {
            session_regenerate_id(true);
        }
    }

    public function id(): string
    {
        return $this->started ? (string)session_id() : '';
    }

    public function destroy(): void
    {
        if (!$this->started) {
            return;
        }
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
        $this->started = false;
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public function takeFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    /** Guarda datos y errores de un formulario para mostrarlos tras redirigir. */
    public function flashForm(array $old, array $errors): void
    {
        unset($old['_token'], $old['password'], $old['password_confirmation'], $old['current_password']);
        $_SESSION['_old'] = $old;
        $_SESSION['_errors'] = $errors;
    }

    /** @return array{old:array<string,mixed>,errors:array<string,list<string>>} */
    public function takeForm(): array
    {
        $r = ['old' => $_SESSION['_old'] ?? [], 'errors' => $_SESSION['_errors'] ?? []];
        unset($_SESSION['_old'], $_SESSION['_errors']);
        return $r;
    }

    public function close(): void
    {
        if ($this->started) {
            session_write_close();
        }
    }
}
