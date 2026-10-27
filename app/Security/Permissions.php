<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Database;

/**
 * Permisos efectivos de un usuario: los de su rol, más las concesiones
 * individuales, menos las denegaciones individuales (deny siempre gana).
 */
final class Permissions
{
    /** @var array<int,array<string,true>> */
    private array $cache = [];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,true> */
    public function forUser(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }
        $codes = $this->db->column(
            'SELECT p.code FROM users u
               JOIN role_permissions rp ON rp.role_id = u.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE u.id = ?',
            [$userId]
        );
        $set = array_fill_keys($codes, true);
        foreach ($this->db->all(
            'SELECT p.code, up.effect FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = ?',
            [$userId]
        ) as $o) {
            if ($o['effect'] === 'allow') {
                $set[$o['code']] = true;
            }
        }
        foreach ($this->db->all(
            "SELECT p.code FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = ? AND up.effect = 'deny'",
            [$userId]
        ) as $o) {
            unset($set[$o['code']]);
        }
        return $this->cache[$userId] = $set;
    }

    public function forget(?int $userId = null): void
    {
        if ($userId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$userId]);
        }
    }

    /** @return list<array{id:int,code:string,module:string,description:string}> */
    public function all(): array
    {
        return $this->db->all('SELECT id, code, module, description FROM permissions ORDER BY module, code');
    }
}
