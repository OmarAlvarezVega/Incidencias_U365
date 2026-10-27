<?php

declare(strict_types=1);

/**
 * Controlador frontal único. Todo el código vive fuera de public/.
 */

$root = dirname(__DIR__);

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
