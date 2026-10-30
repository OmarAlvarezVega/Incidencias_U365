<?php

declare(strict_types=1);

/**
 * Restauración de una copia. NUNCA sobre la base de datos en uso: solo sobre
 * una base de datos NUEVA y VACÍA creada antes en el panel de IONOS. Después
 * se cambia config/config.php para apuntar a ella (ver docs/recuperacion.md).
 *
 *   php bin/restore.php --copia=u365-bd-20261027-030000.sql.gz \
 *       --host=dbXXXX.hosting-data.io --bd=dbsYYYY --usuario=dbuYYYY
 *   (la contraseña se pide por teclado o se lee de U365_RESTORE_DB_PASS)
 *
 *   php bin/restore.php --archivos=u365-archivos-20261027-030000.zip --destino=/ruta/vacia
 *   extrae los adjuntos en una carpeta vacía para revisarlos antes de moverlos.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\Backup;

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

function confirmOrExit(string $what): void
{
    echo "\nVas a $what.\nEscribe RESTAURAR (en mayúsculas) para continuar: ";
    $answer = trim((string)fgets(STDIN));
    if ($answer !== 'RESTAURAR') {
        echo "Cancelado. No se ha cambiado nada.\n";
        exit(1);
    }
}

try {
    $app = App::boot(dirname(__DIR__));
    $svc = $app->make(Backup::class);

    if (isset($opts['archivos'])) {
        $dest = rtrim((string)($opts['destino'] ?? ''), '/');
        if ($dest === '' || !is_dir($dest) || count(scandir($dest) ?: []) > 2) {
            throw new RuntimeException('--destino debe ser una carpeta existente y VACÍA.');
        }
        $zipPath = $svc->pathFor($opts['archivos']);
        if (($p = $svc->verify($opts['archivos'])) !== null) {
            throw new RuntimeException("La copia no es válida: $p");
        }
        confirmOrExit("extraer {$opts['archivos']} en $dest");
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('No se puede abrir el ZIP.');
        }
        // Protección contra rutas con ../ dentro del ZIP
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if (str_contains($n, '..') || str_starts_with($n, '/')) {
                throw new RuntimeException("Ruta no permitida dentro del ZIP: $n");
            }
        }
        $zip->extractTo($dest);
        $zip->close();
        echo "Adjuntos extraídos en $dest. Revísalos y muévelos a storage/ cuando la aplicación esté parada.\n";
        exit(0);
    }

    foreach (['copia', 'host', 'bd', 'usuario'] as $k) {
        if (empty($opts[$k])) {
            throw new RuntimeException("Falta --$k=. Ejecuta sin argumentos para ver la ayuda en la cabecera del fichero.");
        }
    }
    $pass = getenv('U365_RESTORE_DB_PASS');
    if ($pass === false) {
        echo 'Contraseña de la base de datos de destino: ';
        if (DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN)) {
            @shell_exec('stty -echo');
        }
        $pass = rtrim((string)fgets(STDIN), "\r\n");
        if (DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN)) {
            @shell_exec('stty echo');
        }
        echo "\n";
    }
    confirmOrExit("restaurar {$opts['copia']} en la base de datos «{$opts['bd']}» de {$opts['host']} (debe estar vacía)");
    @set_time_limit(0);
    $n = $svc->restoreInto($opts['copia'], ['host' => $opts['host'], 'port' => (int)($opts['puerto'] ?? 3306), 'name' => $opts['bd'], 'user' => $opts['usuario'], 'pass' => (string)$pass]);
    $app->audit()->log('backup.restored', 'backup', null, ['fichero' => $opts['copia'], 'destino' => $opts['bd']], 'system', null);
    echo "Restauración terminada ($n sentencias). Comprueba los datos y, si son correctos, cambia 'db' en config/config.php.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
