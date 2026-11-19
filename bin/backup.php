<?php

declare(strict_types=1);

/**
 * Copia de seguridad manual por línea de comandos.
 *   php bin/backup.php                 base de datos y adjuntos
 *   php bin/backup.php --solo-bd       solo base de datos
 *   php bin/backup.php --verificar=u365-bd-20261027-030000.sql.gz
 *   php bin/backup.php --listar
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\Backup;

try {
    $app = App::boot(dirname(__DIR__));
    $svc = $app->make(Backup::class);
    foreach ($argv as $arg) {
        if ($arg === '--listar') {
            foreach ($svc->list() as $f) {
                printf("%-40s %10.1f MB  %s\n", $f['name'], $f['size'] / 1048576, date('d/m/Y H:i', $f['mtime']));
            }
            exit(0);
        }
        if (str_starts_with($arg, '--verificar=')) {
            $name = substr($arg, 12);
            $problem = $svc->verify($name);
            echo $problem === null ? "$name: correcta\n" : "$name: NO VÁLIDA: $problem\n";
            exit($problem === null ? 0 : 1);
        }
    }
    @set_time_limit(0);
    $id = $svc->run(in_array('--solo-bd', $argv, true) ? 'database' : 'full');
    $row = $app->db()->one('SELECT file_name, size_bytes FROM backup_runs WHERE id = ?', [$id]);
    printf("Copia creada: %s (%.1f MB) en %s\n", $row['file_name'], (int)$row['size_bytes'] / 1048576, $svc->dir());
    echo "Recuerda descargarla y guardarla fuera del servidor.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
