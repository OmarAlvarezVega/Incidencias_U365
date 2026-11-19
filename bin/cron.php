<?php

declare(strict_types=1);

/**
 * Tarea programada. En IONOS, cada 5 minutos:
 *   /usr/bin/php8.3-cli /ruta/a/la/aplicacion/bin/cron.php
 * (la ruta de PHP exacta la indica el diagnóstico o el panel de IONOS).
 *
 *   php bin/cron.php                 ejecuta todo lo que toque
 *   php bin/cron.php --task=sla.check   solo una tarea
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\Scheduler;

try {
    $app = App::boot(dirname(__DIR__));
    $only = null;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--task=')) {
            $task = substr($arg, 7);
            if (!isset(Scheduler::TASKS[$task])) {
                fwrite(STDERR, "Tarea desconocida: $task\n");
                exit(2);
            }
            $only = [$task];
        }
    }
    $results = $app->make(Scheduler::class)->run('cron', $only, 50.0);
    $failed = false;
    foreach ($results as $task => $r) {
        echo $task, ': ', $r, PHP_EOL;
        $failed = $failed || str_starts_with((string)$r, 'error');
    }
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
