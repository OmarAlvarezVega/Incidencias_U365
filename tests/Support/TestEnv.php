<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\App;
use App\Core\Database;
use App\Security\Crypto;
use App\Services\Migrator;
use App\Services\StaffService;
use RuntimeException;

/**
 * Entorno de pruebas compartido: una base de datos de PRUEBAS recién
 * migrada, una carpeta temporal para storage y la clave, y una App
 * arrancada con esa configuración. Nunca toca config/config.php.
 */
final class TestEnv
{
    private static ?App $app = null;
    private static ?string $dir = null;
    /** @var array<string,mixed>|null */
    private static ?array $config = null;
    public const ADMIN_EMAIL = 'admin@pruebas.test';
    public const PASSWORD = 'Prueba-Segura-2026!';

    /** @return array{host:string,port:int,name:string,user:string,pass:string} */
    public static function dbConfig(): array
    {
        $name = (string)(getenv('TEST_DB_NAME') ?: 'u365_test');
        if (!str_contains($name, 'test')) {
            throw new RuntimeException('Por seguridad, el nombre de la base de datos de pruebas debe contener "test".');
        }
        return [
            'host' => (string)(getenv('TEST_DB_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('TEST_DB_PORT') ?: 3306),
            'name' => $name,
            'user' => (string)(getenv('TEST_DB_USER') ?: 't'),
            'pass' => getenv('TEST_DB_PASS') !== false ? (string)getenv('TEST_DB_PASS') : 't',
        ];
    }

    public static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = sys_get_temp_dir() . '/u365-pruebas-' . getmypid();
            foreach (['', '/storage', '/config'] as $d) {
                if (!is_dir(self::$dir . $d)) {
                    mkdir(self::$dir . $d, 0700, true);
                }
            }
        }
        return self::$dir;
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        if (self::$config === null) {
            $key = self::dir() . '/config/app.key';
            if (!is_file($key)) {
                Crypto::generateKeyFile($key);
            }
            self::$config = [
                'env' => 'development',
                'app_url' => 'http://127.0.0.1:8765',
                'db' => self::dbConfig(),
                'key_file' => $key,
                'paths' => ['storage' => self::dir() . '/storage'],
                'cron_key_sha256' => '',
            ];
        }
        return self::$config;
    }

    public static function app(): App
    {
        if (self::$app === null) {
            self::resetDatabase();
            self::$app = App::boot(dirname(__DIR__, 2), self::config());
            $staff = self::$app->make(StaffService::class);
            $staff->create(['first_name' => 'Admin', 'last_name' => 'Pruebas', 'email' => self::ADMIN_EMAIL, 'password' => self::PASSWORD, 'role_id' => self::roleId('superadmin')], null, false);
        }
        return self::$app;
    }

    public static function db(): Database
    {
        return self::app()->db();
    }

    public static function roleId(string $code): int
    {
        return (int)self::$app?->db()->value('SELECT id FROM roles WHERE code = ?', [$code]);
    }

    /** Crea un empleado activo con el rol indicado. Devuelve su id de usuario. */
    public static function user(string $role, string $email, array $teamIds = [], array $leadTeamIds = []): int
    {
        $app = self::app();
        $id = $app->db()->value('SELECT id FROM users WHERE email_normalized = ?', [strtolower($email)]);
        if ($id !== null) {
            return (int)$id;
        }
        return $app->make(StaffService::class)->create([
            'first_name' => ucfirst(strtok($email, '@')), 'last_name' => 'Pruebas', 'email' => $email,
            'password' => self::PASSWORD, 'role_id' => self::roleId($role), 'team_ids' => $teamIds, 'lead_team_ids' => $leadTeamIds,
        ], null, false);
    }

    /** Usuario autenticado equivalente al que construye Auth para una petición. */
    public static function actor(int $userId): \App\Security\CurrentUser
    {
        $db = self::db();
        $row = $db->one(
            "SELECT u.id, u.email, u.username, u.user_type, u.must_change_password, r.code AS role_code, r.name AS role_name, e.id AS employee_id,
                    COALESCE(CONCAT(e.first_name, ' ', e.last_name), u.email) AS display_name
               FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN employees e ON e.user_id = u.id WHERE u.id = ?",
            [$userId]
        ) ?? throw new RuntimeException("No existe el usuario $userId");
        $teams = $db->all('SELECT team_id, is_lead FROM team_members WHERE user_id = ?', [$userId]);
        self::app()->permissions()->forget($userId);
        return new \App\Security\CurrentUser(
            id: (int)$row['id'], email: (string)$row['email'], username: $row['username'], name: (string)$row['display_name'],
            roleCode: (string)$row['role_code'], roleName: (string)$row['role_name'], userType: (string)$row['user_type'],
            employeeId: $row['employee_id'] !== null ? (int)$row['employee_id'] : null, mustChangePassword: (bool)$row['must_change_password'],
            permissions: self::app()->permissions()->forUser($userId),
            ledTeamIds: array_values(array_map('intval', array_column(array_filter($teams, static fn ($t) => (int)$t['is_lead'] === 1), 'team_id'))),
            teamIds: array_values(array_map('intval', array_column($teams, 'team_id'))),
        );
    }

    public static function adminId(): int
    {
        return (int)self::db()->value('SELECT id FROM users WHERE email_normalized = ?', [self::ADMIN_EMAIL]);
    }

    private static function resetDatabase(): void
    {
        $db = Database::connect(self::dbConfig());
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->column('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()') as $t) {
            $db->pdo()->exec('DROP TABLE `' . str_replace('`', '', (string)$t) . '`');
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        (new Migrator($db, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
    }
}
