<?php

declare(strict_types=1);

/**
 * Aplica las migraciones pendientes.
 *   php bin/migrate.php            aplica
 *   php bin/migrate.php --status   solo muestra el estado
 * Haz una copia de seguridad antes de migrar producción (php bin/backup.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\Migrator;

$app = App::boot(dirname(__DIR__));
$migrator = new Migrator($app->db(), dirname(__DIR__) . '/database/migrations');

try {
    if (in_array('--status', $argv, true)) {
        $applied = $migrator->applied();
        foreach ($migrator->available() as $m) {
            printf("%s  %-40s %s\n", $m['version'], $m['description'], isset($applied[$m['version']]) ? 'aplicada' : 'PENDIENTE');
        }
        exit(0);
    }
    $n = $migrator->migrate(static fn (string $line) => print($line . PHP_EOL));
    echo "Migraciones aplicadas: $n\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
