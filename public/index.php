<?php

declare(strict_types=1);

/**
 * Controlador frontal único. Todo el código vive fuera de public/.
 */

$root = dirname(__DIR__);

// Registro de errores desde la primera línea: en hosting compartido log_errors
// suele venir desactivado y un fallo antes de arrancar daría una página en blanco
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir($root . '/storage/logs') || @mkdir($root . '/storage/logs', 0750, true)) {
    ini_set('error_log', $root . '/storage/logs/php-errors.log');
}
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Error interno. El detalle está en storage/logs/php-errors.log.';
    }
});

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Faltan las dependencias (vendor/). Consulta el manual de despliegue.';
    exit;
}

require $root . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Request;
use App\Http\InstallKernel;
use App\Http\Kernel;
use App\Http\Routes;

$request = Request::fromGlobals();

try {
    $app = App::boot($root);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'NOT_INSTALLED') {
        // Sin config/config.php solo responde el instalador, y solo si existe storage/install.token
        (new InstallKernel($root))->handle($request)->send($request->isHttps());
        exit;
    }
    http_response_code(500);
    error_log('Arranque: ' . $e->getMessage());
    echo 'Error de configuración.';
    exit;
}

$kernel = new Kernel($app, Routes::build());
$response = $kernel->handle($request);
$response->send($request->isHttps());
