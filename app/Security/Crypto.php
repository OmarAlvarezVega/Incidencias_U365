<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * Cifrado simétrico autenticado (libsodium secretbox) para credenciales de
 * integraciones guardadas en la base de datos. La clave vive en
 * config/app.key, fuera de la raíz web y fuera de las copias automáticas.
 *
 * Protege frente a una fuga de la base de datos o de una copia, no frente a
 * alguien con acceso completo al servidor.
 */
final class Crypto
{
    private const PREFIX = "u365v1";

    private ?string $key = null;

    public function __construct(private readonly string $keyFile)
    {
    }

    public static function generateKeyFile(string $path): void
    {
        if (file_exists($path)) {
            throw new RuntimeException('La clave de cifrado ya existe; no se sobrescribe.');
        }
        $key = sodium_crypto_secretbox_keygen();
        if (file_put_contents($path, base64_encode($key) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir la clave de cifrado.');
        }
        @chmod($path, 0600);
        sodium_memzero($key);
    }

    private function key(): string
    {
        if ($this->key === null) {
            $raw = @file_get_contents($this->keyFile);
            $key = $raw === false ? false : base64_decode(trim($raw), true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException('Clave de cifrado ausente o no válida (config/app.key).');
            }
            $this->key = $key;
        }
        return $this->key;
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . $nonce . sodium_crypto_secretbox($plain, $nonce, $this->key());
    }

    public function decrypt(?string $blob): ?string
    {
        if ($blob === null || $blob === '') {
            return null;
        }
        if (!str_starts_with($blob, self::PREFIX)) {
            throw new RuntimeException('Formato de dato cifrado desconocido.');
        }
        $body = substr($blob, strlen(self::PREFIX));
        $nonce = substr($body, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($body, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
        if ($plain === false) {
            throw new RuntimeException('No se pudo descifrar: clave distinta o dato alterado.');
        }
        return $plain;
    }

    /** Representación segura para mostrar en la interfaz. */
    public static function mask(?string $plain): string
    {
        if ($plain === null || $plain === '') {
            return '';
        }
        $len = mb_strlen($plain);
        return '••••••••' . ($len > 8 ? mb_substr($plain, -2) : '');
    }

    /** Hash para comparar tokens sin guardarlos (restablecimiento, cron). */
    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
