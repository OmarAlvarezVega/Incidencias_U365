<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Crypto;
use App\Security\PasswordPolicy;
use RuntimeException;

/**
 * Instalación inicial, compartida por el instalador web y bin/install.php.
 *
 * Orden pensado para no dejar nada a medias que impida reintentar:
 *  1. comprobaciones del servidor;
 *  2. conexión a la base de datos, que debe estar VACÍA (no se presupone);
 *  3. clave de cifrado (se reutiliza si ya existe y es válida, nunca se pisa);
 *  4. migraciones y superadministrador;
 *  5. config/config.php (0600) y storage/installed.lock, al final.
 */
final class Installer
{
    public const MIN_PHP = '8.2.0';
    public const EXTENSIONS = ['pdo_mysql', 'mbstring', 'sodium', 'fileinfo', 'zip', 'openssl', 'json', 'ctype', 'zlib'];

    public function __construct(private readonly string $root)
    {
    }

    public function lockFile(): string
    {
        return $this->root . '/storage/installed.lock';
    }

    public function tokenFile(): string
    {
        return $this->root . '/storage/install.token';
    }

    public function configFile(): string
    {
        return $this->root . '/config/config.php';
    }

    public function isLocked(): bool
    {
        return is_file($this->lockFile()) || is_file($this->configFile());
    }

    /** @return list<array{label:string,ok:bool,detail:string,blocking:bool}> */
    public function checks(): array
    {
        $out = [];
        $out[] = ['label' => 'PHP ' . self::MIN_PHP . ' o superior', 'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='), 'detail' => 'Versión actual: ' . PHP_VERSION, 'blocking' => true];
        foreach (self::EXTENSIONS as $ext) {
            $out[] = ['label' => "Extensión $ext", 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'cargada' : 'no disponible', 'blocking' => true];
        }
        $out[] = ['label' => 'Extensión curl', 'ok' => extension_loaded('curl'), 'detail' => extension_loaded('curl') ? 'cargada' : 'no disponible: WhatsApp no podrá subir archivos', 'blocking' => false];
        $out[] = ['label' => 'Extensión intl', 'ok' => extension_loaded('intl'), 'detail' => extension_loaded('intl') ? 'cargada' : 'opcional', 'blocking' => false];
        foreach (['storage' => $this->root . '/storage', 'config' => $this->root . '/config'] as $name => $dir) {
            $ok = is_dir($dir) && is_writable($dir);
            $out[] = ['label' => "Carpeta $name/ escribible", 'ok' => $ok, 'detail' => $ok ? 'sí' : 'no: revisa permisos por FTP/SFTP', 'blocking' => true];
        }
        $out[] = ['label' => 'Dependencias instaladas (vendor/)', 'ok' => is_file($this->root . '/vendor/autoload.php'), 'detail' => '', 'blocking' => true];
        return $out;
    }

    public function checksPass(): bool
    {
        foreach ($this->checks() as $c) {
            if ($c['blocking'] && !$c['ok']) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{host:string,port:int,name:string,user:string,pass:string} $db
     * @param array{first_name:string,last_name:string,email:string,password:string,password_confirmation:string} $admin
     * @param callable(string):void|null $log
     */
    public function install(string $appUrl, array $db, array $admin, ?callable $log = null): void
    {
        $log ??= static function (string $m): void {
        };
        if ($this->isLocked()) {
            throw new RuntimeException('La aplicación ya está instalada. El instalador está bloqueado.');
        }
        if (!$this->checksPass()) {
            throw new RuntimeException('El servidor no cumple los requisitos. Revisa la lista de comprobaciones.');
        }
        if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $appUrl) && !preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $appUrl)) {
            throw ValidationException::single('app_url', 'La URL debe empezar por https:// y no llevar ruta ni barra final.');
        }

        // Datos del administrador validados ANTES de tocar la base de datos, para
        // no dejarla a medio instalar por una contraseña corta
        foreach (['first_name' => 'El nombre', 'last_name' => 'Los apellidos'] as $k => $label) {
            if (trim($admin[$k]) === '' || mb_strlen($admin[$k]) > 80) {
                throw ValidationException::single($k, "$label es obligatorio (máximo 80 caracteres).");
            }
        }
        if (filter_var($admin['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::single('email', 'El correo no es válido.');
        }
        (new PasswordPolicy(12))->assertValid($admin['password'], $admin['password_confirmation'], [$admin['email'], $admin['first_name'], $admin['last_name']]);

        $log('Conectando con la base de datos...');
        try {
            $conn = Database::connect($db);
        } catch (\PDOException $e) {
            throw ValidationException::single('db_host', 'No se puede conectar a la base de datos. Revisa servidor, nombre, usuario y contraseña (código ' . $e->getCode() . ').');
        }
        $version = (string)$conn->value('SELECT VERSION()');
        $tables = (int)$conn->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
        if ($tables > 0) {
            throw ValidationException::single('db_name', "La base de datos indicada ya contiene $tables tablas. El instalador solo trabaja sobre una base de datos vacía y no borra nada.");
        }
        $log("Base de datos vacía ($version).");

        $keyFile = $this->root . '/config/app.key';
        if (is_file($keyFile)) {
            // Restos de un intento anterior: se reutiliza si es válida, nunca se sobrescribe
            (new Crypto($keyFile))->encrypt('comprobación');
            $log('Se reutiliza la clave de cifrado existente.');
        } else {
            Crypto::generateKeyFile($keyFile);
            $log('Clave de cifrado creada en config/app.key.');
        }

        $config = [
            'env' => str_starts_with($appUrl, 'https://') ? 'production' : 'development',
            'app_url' => rtrim($appUrl, '/'),
            'db' => ['host' => $db['host'], 'port' => (int)$db['port'], 'name' => $db['name'], 'user' => $db['user'], 'pass' => $db['pass']],
            'key_file' => $keyFile,
            'paths' => ['storage' => $this->root . '/storage'],
            'cron_key_sha256' => '',
        ];
        $app = App::boot($this->root, $config);
        $app->bind(Database::class, $conn);

        $log('Creando tablas y catálogos...');
        (new Migrator($conn, $this->root . '/database/migrations'))->migrate($log);

        $log('Creando el superadministrador...');
        $roleId = (int)$conn->value("SELECT id FROM roles WHERE code = 'superadmin'");
        $app->make(StaffService::class)->create([
            'first_name' => $admin['first_name'],
            'last_name' => $admin['last_name'],
            'email' => $admin['email'],
            'password' => $admin['password'],
            'password_confirmation' => $admin['password_confirmation'],
            'role_id' => $roleId,
        ], null, false);

        foreach (['logs', 'attachments', 'mail-raw', 'backups', 'tmp', 'cache'] as $dir) {
            $p = $this->root . '/storage/' . $dir;
            if (!is_dir($p)) {
                mkdir($p, 0750, true);
            }
        }

        $this->writeConfig($config);
        file_put_contents($this->lockFile(), 'Instalado el ' . gmdate('Y-m-d H:i:s') . " UTC. Borra config/config.php y este fichero solo para reinstalar en una base de datos nueva.\n", LOCK_EX);
        if (is_file($this->tokenFile())) {
            @unlink($this->tokenFile());
        }
        $app->audit()->log('app.installed', null, null, ['version_bd' => $version], 'system', null);
        $log('Instalación completada.');
    }

    /** @param array<string,mixed> $config */
    private function writeConfig(array $config): void
    {
        $file = $this->configFile();
        if (is_file($file)) {
            throw new RuntimeException('config/config.php ya existe; no se sobrescribe.');
        }
        $php = "<?php\n\n// Generado por el instalador el " . gmdate('Y-m-d H:i:s') . " UTC.\n// Contiene credenciales: permisos 0600, nunca al repositorio.\n\nreturn " . var_export($config, true) . ";\n";
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir config/config.php.');
        }
        @chmod($tmp, 0600);
        rename($tmp, $file);
    }
}
