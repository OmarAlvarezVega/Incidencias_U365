<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\ValidationException;

/** Política de contraseñas configurable y hash con password_hash(). */
final class PasswordPolicy
{
    /** Lista local reducida de contraseñas muy comunes (sin llamadas externas). */
    private const COMMON = [
        '123456789012', 'password1234', 'contraseña123', 'contrasena123', 'qwertyuiop12', 'administrador',
        'administrator', 'univers365', 'univers3652024', 'univers3652025', 'univers3652026', 'incidencias1',
        'soporte12345', 'bienvenido123', 'iloveyou1234', '000000000000', '111111111111', 'abcdefghijkl',
        'passw0rd1234', 'p@ssw0rd1234', 'madrid123456', 'barcelona123', 'españa123456', 'qwerty123456',
    ];

    public function __construct(private readonly int $minLength = 12)
    {
    }

    /** @return list<string> problemas encontrados (vacío = válida) */
    public function problems(string $password, array $userData = []): array
    {
        $p = [];
        if (mb_strlen($password) < $this->minLength) {
            $p[] = "Debe tener al menos {$this->minLength} caracteres.";
        }
        if (mb_strlen($password) > 200) {
            $p[] = 'No puede superar 200 caracteres.';
        }
        $classes = (int)preg_match('/[a-zà-ÿñ]/u', $password) + (int)preg_match('/[A-ZÀ-ÝÑ]/u', $password)
            + (int)preg_match('/\d/', $password) + (int)preg_match('/[^\p{L}\d]/u', $password);
        if ($classes < 3) {
            $p[] = 'Combina al menos tres tipos: minúsculas, mayúsculas, números y símbolos.';
        }
        $lower = mb_strtolower($password);
        if (in_array($lower, self::COMMON, true) || preg_match('/^(.)\1+$/u', $password)) {
            $p[] = 'Es una contraseña demasiado común.';
        }
        foreach ($userData as $piece) {
            $piece = mb_strtolower(trim((string)$piece));
            if (mb_strlen($piece) >= 4 && str_contains($lower, $piece)) {
                $p[] = 'No debe contener tu nombre, usuario o correo.';
                break;
            }
        }
        return $p;
    }

    public function assertValid(string $password, string $confirmation, array $userData = [], string $field = 'password'): void
    {
        $problems = $this->problems($password, $userData);
        if ($password !== $confirmation) {
            $problems[] = 'Las contraseñas no coinciden.';
        }
        if ($problems) {
            throw new ValidationException([$field => $problems], $problems[0]);
        }
    }

    public static function hash(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function needsRehash(string $hash): bool
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID)
            : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}
