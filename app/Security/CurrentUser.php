<?php

declare(strict_types=1);

namespace App\Security;

/** Usuario autenticado de la petición en curso, con sus permisos efectivos. */
final class CurrentUser
{
    /**
     * @param array<string,true> $permissions
     * @param list<int> $ledTeamIds equipos que coordina
     * @param list<int> $teamIds equipos a los que pertenece
     */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly ?string $username,
        public readonly string $name,
        public readonly string $roleCode,
        public readonly string $roleName,
        public readonly string $userType,
        public readonly ?int $employeeId,
        public readonly bool $mustChangePassword,
        private readonly array $permissions,
        public readonly array $ledTeamIds,
        public readonly array $teamIds,
    ) {
    }

    public function can(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }

    public function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $p) {
            if (isset($this->permissions[$p])) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    public function permissionCodes(): array
    {
        return array_keys($this->permissions);
    }

    public function isStaff(): bool
    {
        return $this->userType === 'staff';
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];
        $i = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $i .= mb_strtoupper(mb_substr($p, 0, 1));
        }
        return $i !== '' ? $i : '?';
    }
}
