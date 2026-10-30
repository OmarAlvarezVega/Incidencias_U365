<?php

declare(strict_types=1);

/**
 * Instalación por línea de comandos (alternativa al instalador web).
 * Pide los datos por teclado; la base de datos debe existir y estar vacía.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\ValidationException;
use App\Services\Installer;

$installer = new Installer(dirname(__DIR__));
if ($installer->isLocked()) {
    fwrite(STDERR, "La aplicación ya está instalada (existe config/config.php o storage/installed.lock).\n");
    exit(1);
}
foreach ($installer->checks() as $c) {
    printf("[%s] %s %s\n", $c['ok'] ? 'OK' : ($c['blocking'] ? 'FALLA' : 'AVISO'), $c['label'], $c['detail']);
}
if (!$installer->checksPass()) {
    exit(1);
}

$ask = static function (string $label, bool $secret = false, string $default = ''): string {
    echo $label . ($default !== '' ? " [$default]" : '') . ': ';
    if ($secret && DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN)) {
        @shell_exec('stty -echo');
    }
    $v = rtrim((string)fgets(STDIN), "\r\n");
    if ($secret && DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN)) {
        @shell_exec('stty echo');
        echo "\n";
    }
    return $v === '' ? $default : $v;
};

try {
    $installer->install(
        $ask('URL pública', false, 'https://incidencias.univers365.com'),
        ['host' => $ask('Servidor MariaDB'), 'port' => (int)$ask('Puerto', false, '3306'), 'name' => $ask('Base de datos'), 'user' => $ask('Usuario'), 'pass' => $ask('Contraseña', true)],
        ['first_name' => $ask('Nombre del superadministrador'), 'last_name' => $ask('Apellidos'), 'email' => $ask('Correo'), 'password' => $ask('Contraseña', true), 'password_confirmation' => $ask('Repite la contraseña', true)],
        static fn (string $l) => print($l . PHP_EOL)
    );
} catch (ValidationException $e) {
    fwrite(STDERR, 'ERROR: ' . implode(' ', array_map(static fn ($m) => $m[0] ?? '', $e->errors)) . ' ' . $e->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
