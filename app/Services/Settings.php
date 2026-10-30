<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Security\Crypto;

/**
 * Ajustes de application_settings. Los valores normales se guardan como JSON;
 * los secretos, cifrados en value_secret y nunca se devuelven a la interfaz.
 */
final class Settings
{
    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db, private readonly Crypto $crypto)
    {
    }

    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }
        $this->cache = [];
        foreach ($this->db->all('SELECT setting_key, value_json FROM application_settings WHERE value_json IS NOT NULL') as $r) {
            $this->cache[$r['setting_key']] = json_decode((string)$r['value_json'], true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return array_key_exists($key, $this->cache) && $this->cache[$key] !== null ? $this->cache[$key] : $default;
    }

    public function int(string $key, int $default): int
    {
        $v = $this->get($key, $default);
        return is_numeric($v) ? (int)$v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key, $default);
        return is_bool($v) ? $v : (bool)$v;
    }

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        $this->db->run(
            'INSERT INTO application_settings (setting_key, value_json, value_secret, updated_by, updated_at)
             VALUES (:k, :v, NULL, :u, :t)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), value_secret = NULL, updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            ['k' => $key, 'v' => json_encode($value, JSON_UNESCAPED_UNICODE), 'u' => $userId, 't' => Clock::nowDb()]
        );
        $this->load();
        $this->cache[$key] = $value;
    }

    public function setSecret(string $key, string $plain, ?int $userId = null): void
    {
        $this->db->run(
            'INSERT INTO application_settings (setting_key, value_json, value_secret, updated_by, updated_at)
             VALUES (:k, NULL, :v, :u, :t)
             ON DUPLICATE KEY UPDATE value_json = NULL, value_secret = VALUES(value_secret), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            ['k' => $key, 'v' => $this->crypto->encrypt($plain), 'u' => $userId, 't' => Clock::nowDb()]
        );
    }

    public function secret(string $key): ?string
    {
        $blob = $this->db->value('SELECT value_secret FROM application_settings WHERE setting_key = ?', [$key]);
        return $blob === null ? null : $this->crypto->decrypt((string)$blob);
    }

    public function hasSecret(string $key): bool
    {
        return (bool)$this->db->value('SELECT 1 FROM application_settings WHERE setting_key = ? AND value_secret IS NOT NULL', [$key]);
    }

    public function forget(): void
    {
        $this->cache = null;
    }
}
