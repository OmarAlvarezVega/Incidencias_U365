<?php
/**
 * UNIVERS365 Incident Management - Diagnóstico del alojamiento (Fase 1)
 *
 * Qué hace: comprueba versión y extensiones de PHP, límites, funciones
 * disponibles, permisos de escritura, HTTPS, conectividad saliente hacia los
 * servidores de correo y de Meta, y (opcional) la base de datos MariaDB.
 *
 * Qué NO hace: no modifica la base de datos (solo usa tablas TEMPORARY que
 * desaparecen al desconectar), no envía correos, no guarda ninguna contraseña
 * y no se comunica con ningún servidor de UNIVERS365 ni de terceros salvo las
 * pruebas de conexión TLS listadas en $NETWORK_TARGETS.
 *
 * USO EN LA WEB (IONOS):
 *   1. Cambia DIAG_TOKEN por una cadena aleatoria de 24+ caracteres.
 *   2. Sube el fichero a la carpeta del subdominio por SFTP.
 *   3. Abre https://incidencias.univers365.com/diagnostico.php?t=TU_TOKEN
 *   4. Copia el informe de texto y BORRA el fichero (hay un botón para ello).
 *   El script deja de funcionar solo 24 h después de subirlo.
 *
 * USO POR CONSOLA (SSH), recomendado además del web porque el PHP de consola
 * puede ser otra versión:
 *   php diagnostico.php
 *   Con base de datos (la contraseña no queda en el historial):
 *   read -s DIAG_DB_PASS; export DIAG_DB_PASS
 *   DIAG_DB_HOST=... DIAG_DB_NAME=... DIAG_DB_USER=... php diagnostico.php
 *
 * Compatible con PHP 7.0+ a propósito: si el alojamiento tiene un PHP antiguo,
 * el diagnóstico tiene que poder decirlo.
 */

const DIAG_TOKEN = 'CAMBIA-ESTE-TOKEN';
const DIAG_MAX_AGE_HOURS = 24;
const DIAG_NET_TIMEOUT = 4;

$NETWORK_TARGETS = array(
    // host, puerto, modo (tls = TLS implícito, plain = TCP y banner), para qué
    array('imap.ionos.es',            993, 'tls',   'IMAP de IONOS Mail'),
    array('smtp.ionos.es',            465, 'tls',   'SMTP de IONOS Mail (TLS implícito)'),
    array('smtp.ionos.es',            587, 'plain', 'SMTP de IONOS Mail (STARTTLS)'),
    array('outlook.office365.com',    993, 'tls',   'IMAP de Microsoft 365'),
    array('smtp.office365.com',       587, 'plain', 'SMTP de Microsoft 365 (STARTTLS)'),
    array('login.microsoftonline.com',443, 'tls',   'OAuth2 de Microsoft (si el buzón está en 365)'),
    array('graph.microsoft.com',      443, 'tls',   'Microsoft Graph'),
    array('imap.gmail.com',           993, 'tls',   'IMAP de Google Workspace'),
    array('graph.facebook.com',       443, 'tls',   'WhatsApp Cloud API (Meta)'),
);

$IS_CLI = (PHP_SAPI === 'cli');

// ---------------------------------------------------------------------------
// Control de acceso (solo modo web)
// ---------------------------------------------------------------------------
if (!$IS_CLI) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

    if (DIAG_TOKEN === 'CAMBIA-ESTE-TOKEN' || strlen(DIAG_TOKEN) < 24) {
        http_response_code(403);
        exit('Diagnóstico bloqueado: edita el fichero y cambia DIAG_TOKEN por una cadena aleatoria de 24 o más caracteres.');
    }
    $age = time() - (int)@filemtime(__FILE__);
    if ($age > DIAG_MAX_AGE_HOURS * 3600) {
        http_response_code(403);
        exit('Diagnóstico caducado (más de ' . DIAG_MAX_AGE_HOURS . ' h desde que se subió). Bórralo del servidor; si lo necesitas, súbelo de nuevo.');
    }
    $given = isset($_POST['t']) ? (string)$_POST['t'] : (isset($_GET['t']) ? (string)$_GET['t'] : '');
    if (!hash_equals(DIAG_TOKEN, $given)) {
        usleep(300000);
        http_response_code(404);
        exit('Not found');
    }
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $ok = @unlink(__FILE__);
        exit($ok ? 'Fichero de diagnóstico borrado del servidor.' : 'No se pudo borrar automáticamente: bórralo por SFTP.');
    }
}

@set_time_limit(120);

// ---------------------------------------------------------------------------
// Utilidades
// ---------------------------------------------------------------------------
$RESULTS = array(); // cada elemento: array(sección, comprobación, estado, detalle)

function add_result($section, $check, $status, $detail)
{
    global $RESULTS;
    $RESULTS[] = array($section, $check, $status, (string)$detail);
}

function ini_bytes($value)
{
    $value = trim((string)$value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $unit = strtolower(substr($value, -1));
    $num = (float)$value;
    switch ($unit) {
        case 'g': $num *= 1024;
        // no break
        case 'm': $num *= 1024;
        // no break
        case 'k': $num *= 1024;
    }
    return (int)$num;
}

function fmt_bytes($bytes)
{
    if ($bytes < 0) {
        return 'ilimitado';
    }
    $u = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    while ($bytes >= 1024 && $i < 4) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $u[$i];
}

function fn_enabled($name)
{
    if (!function_exists($name)) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array($name, $disabled, true);
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mask($s)
{
    $s = (string)$s;
    return strlen($s) <= 3 ? '***' : substr($s, 0, 3) . str_repeat('*', max(3, strlen($s) - 3));
}

// ---------------------------------------------------------------------------
// 1. PHP
// ---------------------------------------------------------------------------
$S = 'PHP';
$v = PHP_VERSION_ID;
if ($v >= 80300) {
    add_result($S, 'Versión', 'OK', PHP_VERSION . ' (recomendada 8.3 o superior)');
} elseif ($v >= 80200) {
    add_result($S, 'Versión', 'AVISO', PHP_VERSION . ': válida, pero conviene seleccionar 8.3 en el panel de IONOS');
} else {
    add_result($S, 'Versión', 'FALLO', PHP_VERSION . ': la aplicación necesita PHP 8.2 o superior');
}
add_result($S, 'SAPI', 'INFO', PHP_SAPI . ($IS_CLI ? ' (consola)' : ' (web)'));
add_result($S, 'Sistema', 'INFO', PHP_OS . ' ' . php_uname('r') . ', ' . (PHP_INT_SIZE * 8) . ' bits');
add_result($S, 'Servidor web', 'INFO', isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '(no disponible en consola)');
add_result($S, 'php.ini cargado', 'INFO', php_ini_loaded_file() ?: '(ninguno)');
add_result($S, 'Binario PHP', 'INFO', defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : '(desconocido)');
$who = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? @posix_getpwuid(posix_geteuid()) : null;
add_result($S, 'Usuario del proceso', 'INFO', is_array($who) ? $who['name'] : get_current_user());

// ---------------------------------------------------------------------------
// 2. Extensiones
// ---------------------------------------------------------------------------
$S = 'Extensiones';
$required = array(
    'pdo' => 'acceso a datos', 'pdo_mysql' => 'conexión a MariaDB', 'mbstring' => 'texto UTF-8',
    'openssl' => 'TLS en IMAP/SMTP/HTTPS', 'json' => 'JSON', 'ctype' => 'validación', 'fileinfo' => 'tipo real de los adjuntos',
    'session' => 'sesiones', 'filter' => 'validación', 'hash' => 'HMAC de webhooks', 'sodium' => 'cifrado de credenciales',
    'zlib' => 'copias comprimidas', 'iconv' => 'conversión de juegos de caracteres en correos',
);
$recommended = array(
    'curl' => 'llamadas a la API de WhatsApp y Graph (hay alternativa con streams)',
    'intl' => 'formato de fechas y números es_ES',
    'zip' => 'copias de adjuntos y exportación XLSX',
    'imap' => 'lectura IMAP nativa (si falta se usa una librería en PHP puro)',
    'gd' => 'miniaturas de imágenes',
    'Zend OPcache' => 'rendimiento',
    'posix' => 'información del proceso',
);
foreach ($required as $ext => $why) {
    add_result($S, $ext, extension_loaded($ext) ? 'OK' : 'FALLO', extension_loaded($ext) ? $why : 'FALTA (obligatoria): ' . $why);
}
foreach ($recommended as $ext => $why) {
    add_result($S, $ext, extension_loaded($ext) ? 'OK' : 'AVISO', (extension_loaded($ext) ? '' : 'no disponible: ') . $why);
}
if (extension_loaded('intl') && defined('INTL_ICU_VERSION')) {
    add_result($S, 'Versión ICU', 'INFO', INTL_ICU_VERSION);
}
if (extension_loaded('openssl')) {
    add_result($S, 'Versión OpenSSL', 'INFO', OPENSSL_VERSION_TEXT);
}

// ---------------------------------------------------------------------------
// 3. Configuración (ini)
// ---------------------------------------------------------------------------
$S = 'Configuración PHP';
$mem = ini_bytes(ini_get('memory_limit'));
add_result($S, 'memory_limit', ($mem === -1 || $mem >= 128 * 1048576) ? 'OK' : 'AVISO', ini_get('memory_limit') . ' (mínimo recomendado 128M)');
$met = (int)ini_get('max_execution_time');
add_result($S, 'max_execution_time', ($met === 0 || $met >= 30) ? 'OK' : 'AVISO', $met . ' s' . ($IS_CLI ? ' (en consola suele ser 0 = sin límite)' : ' (los procesos se trocean en lotes de 45 s)'));
$upl = ini_bytes(ini_get('upload_max_filesize'));
$post = ini_bytes(ini_get('post_max_size'));
add_result($S, 'upload_max_filesize', $upl >= 15 * 1048576 ? 'OK' : 'AVISO', ini_get('upload_max_filesize') . ' (la aplicación propone 15M por adjunto)');
add_result($S, 'post_max_size', ($post === -1 || $post >= $upl) ? 'OK' : 'AVISO', ini_get('post_max_size') . ' (debe ser mayor que upload_max_filesize)');
foreach (array('max_file_uploads', 'max_input_vars', 'max_input_time', 'default_charset', 'output_buffering',
               'session.save_handler', 'session.save_path', 'session.gc_maxlifetime', 'session.use_strict_mode',
               'session.cookie_secure', 'session.cookie_httponly', 'session.cookie_samesite', 'allow_url_fopen',
               'error_log', 'log_errors', 'realpath_cache_size', 'opcache.enable', 'sendmail_path') as $k) {
    $val = ini_get($k);
    add_result($S, $k, 'INFO', $val === false ? '(no existe)' : ($val === '' ? '(vacío)' : $val));
}
$de = ini_get('display_errors');
add_result($S, 'display_errors', in_array(strtolower((string)$de), array('', '0', 'off', 'stderr'), true) ? 'OK' : 'AVISO', ($de === '' ? 'Off' : $de) . ' (en producción debe estar desactivado; la aplicación lo fuerza)');
add_result($S, 'expose_php', ini_get('expose_php') ? 'AVISO' : 'OK', ini_get('expose_php') ? 'On: revela la versión de PHP en cabeceras' : 'Off');
$ob = (string)ini_get('open_basedir');
add_result($S, 'open_basedir', 'INFO', $ob === '' ? '(sin restricción)' : $ob);
add_result($S, 'disable_functions', 'INFO', ini_get('disable_functions') ?: '(ninguna)');
add_result($S, 'date.timezone (ini)', 'INFO', ini_get('date.timezone') ?: '(sin definir: la aplicación fija Europe/Madrid)');

// ---------------------------------------------------------------------------
// 4. Funciones relevantes
// ---------------------------------------------------------------------------
$S = 'Funciones';
$fns = array(
    'random_bytes' => array(true, 'tokens seguros'),
    'password_hash' => array(true, 'contraseñas'),
    'hash_hmac' => array(true, 'firma de webhooks'),
    'sodium_crypto_secretbox' => array(true, 'cifrado de credenciales'),
    'stream_socket_client' => array(true, 'conexiones IMAP/SMTP'),
    'flock' => array(true, 'bloqueo de procesos concurrentes'),
    'fastcgi_finish_request' => array(false, 'responder al webhook y seguir procesando'),
    'curl_init' => array(false, 'llamadas HTTP salientes'),
    'set_time_limit' => array(false, 'ampliar el tiempo en procesos manuales'),
    'ignore_user_abort' => array(false, 'terminar procesos aunque se cierre el navegador'),
    'exec' => array(false, 'solo informativo: la aplicación NO lo usará'),
    'proc_open' => array(false, 'solo informativo: la aplicación NO lo usará'),
    'mail' => array(false, 'solo informativo: se enviará por SMTP, no con mail()'),
    'disk_free_space' => array(false, 'avisos de espacio'),
);
foreach ($fns as $fn => $info) {
    $on = fn_enabled($fn);
    add_result($S, $fn, $on ? 'OK' : ($info[0] ? 'FALLO' : 'INFO'), ($on ? 'disponible' : 'no disponible') . ' - ' . $info[1]);
}
$argon = defined('PASSWORD_ARGON2ID');
add_result($S, 'Argon2id', $argon ? 'OK' : 'AVISO', $argon ? 'disponible para password_hash' : 'no disponible: se usará bcrypt (aceptable)');
if (fn_enabled('password_hash')) {
    $t0 = microtime(true);
    password_hash('prueba-de-rendimiento', PASSWORD_BCRYPT, array('cost' => 12));
    add_result($S, 'bcrypt coste 12', 'INFO', round((microtime(true) - $t0) * 1000) . ' ms por hash');
}

// ---------------------------------------------------------------------------
// 5. Fecha y zona horaria
// ---------------------------------------------------------------------------
$S = 'Fecha y hora';
try {
    $mad = new DateTimeZone('Europe/Madrid');
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $loc = clone $now;
    $loc->setTimezone($mad);
    add_result($S, 'Europe/Madrid', 'OK', 'UTC ' . $now->format('d/m/Y H:i:s') . ' = Madrid ' . $loc->format('d/m/Y H:i:s T'));
    $tz = timezone_version_get();
    add_result($S, 'Base de datos de zonas (PHP)', 'INFO', $tz);
} catch (Exception $e) {
    add_result($S, 'Europe/Madrid', 'FALLO', 'zona horaria no disponible en PHP');
}
add_result($S, 'Zona por defecto de PHP', 'INFO', date_default_timezone_get());

// ---------------------------------------------------------------------------
// 6. Sistema de ficheros
// ---------------------------------------------------------------------------
$S = 'Ficheros';
$here = __DIR__;
$docroot = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' ? realpath($_SERVER['DOCUMENT_ROOT']) : null;
add_result($S, 'Carpeta del script', 'INFO', $here);
add_result($S, 'DOCUMENT_ROOT', 'INFO', $docroot ? $docroot : '(no disponible en consola)');
add_result($S, 'Carpeta temporal', is_writable(sys_get_temp_dir()) ? 'OK' : 'AVISO', sys_get_temp_dir());
$parent = dirname($docroot ? $docroot : $here);
$probe = $parent . DIRECTORY_SEPARATOR . '.u365diag_' . bin2hex(random_bytes(6));
$wrote = @file_put_contents($probe, 'x') === 1;
if ($wrote) {
    @unlink($probe);
}
add_result($S, 'Escribir fuera de la raíz web', $wrote ? 'OK' : 'AVISO',
    $parent . ($wrote ? ': se puede (prueba creada y borrada al instante); storage/ y config/ irán aquí'
                      : ': no se puede escribir; habría que usar una carpeta protegida dentro de la raíz web'));
add_result($S, 'Leer fuera de la raíz web', @is_readable($parent) ? 'OK' : 'AVISO', $parent);
if (fn_enabled('disk_free_space')) {
    $free = @disk_free_space($here);
    add_result($S, 'Espacio libre (según PHP)', 'INFO', $free === false ? '(no informado)' : fmt_bytes($free) . ' (en hosting compartido suele no reflejar la cuota real)');
}
$ht = $here . DIRECTORY_SEPARATOR . '.htaccess';
add_result($S, '.htaccess en esta carpeta', 'INFO', file_exists($ht) ? 'existe' : 'no existe');

// ---------------------------------------------------------------------------
// 7. HTTPS
// ---------------------------------------------------------------------------
$S = 'HTTPS';
if ($IS_CLI) {
    add_result($S, 'HTTPS', 'INFO', 'no aplica en consola');
} else {
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    add_result($S, 'Petición actual por HTTPS', $https ? 'OK' : 'FALLO', $https ? 'sí' : 'NO: activa el certificado SSL del subdominio en IONOS');
    add_result($S, 'Cómo lo detecta PHP', 'INFO', 'HTTPS=' . (isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : '-')
        . ' X-Forwarded-Proto=' . (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '-')
        . ' puerto=' . (isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : '-'));
    add_result($S, 'Host', 'INFO', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '-');
    if (function_exists('apache_get_modules')) {
        add_result($S, 'mod_rewrite', in_array('mod_rewrite', apache_get_modules(), true) ? 'OK' : 'AVISO', 'según apache_get_modules()');
    } else {
        add_result($S, 'mod_rewrite', 'INFO', 'no se puede detectar desde PHP-FPM/CGI; se comprobará al desplegar');
    }
}

// ---------------------------------------------------------------------------
// 8. Conectividad saliente
// ---------------------------------------------------------------------------
$S = 'Red saliente';
foreach ($NETWORK_TARGETS as $t) {
    list($host, $port, $mode, $why) = $t;
    $label = $host . ':' . $port;
    $ip = @gethostbyname($host);
    if ($ip === $host) {
        add_result($S, $label, 'FALLO', 'DNS no resuelve - ' . $why);
        continue;
    }
    $ctx = stream_context_create(array('ssl' => array(
        'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true,
    )));
    $uri = ($mode === 'tls' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $t0 = microtime(true);
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($uri, $errno, $errstr, DIAG_NET_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
    $ms = round((microtime(true) - $t0) * 1000);
    if (!$fp) {
        add_result($S, $label, 'FALLO', 'sin conexión (' . ($errstr !== '' ? $errstr : 'error ' . $errno) . ', ' . $ms . ' ms) - ' . $why);
        continue;
    }
    $banner = '';
    if ($port !== 443) {
        stream_set_timeout($fp, DIAG_NET_TIMEOUT);
        $banner = trim((string)@fgets($fp, 256));
        $banner = $banner === '' ? '' : ', saludo: "' . substr(preg_replace('/[^\x20-\x7E]/', '', $banner), 0, 60) . '"';
    }
    fclose($fp);
    add_result($S, $label, 'OK', ($mode === 'tls' ? 'TLS verificado' : 'TCP abierto') . ' en ' . $ms . ' ms' . $banner . ' - ' . $why);
}

// ---------------------------------------------------------------------------
// 9. Base de datos (opcional)
// ---------------------------------------------------------------------------
$S = 'MariaDB';
$db = null;
if ($IS_CLI) {
    if (getenv('DIAG_DB_HOST')) {
        $db = array('host' => getenv('DIAG_DB_HOST'), 'port' => getenv('DIAG_DB_PORT') ?: '3306',
                    'name' => (string)getenv('DIAG_DB_NAME'), 'user' => (string)getenv('DIAG_DB_USER'),
                    'pass' => (string)getenv('DIAG_DB_PASS'));
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'db'
          && ((!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
              || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'))) {
    // Solo se aceptan credenciales por HTTPS
    $db = array('host' => (string)$_POST['db_host'], 'port' => (string)$_POST['db_port'],
                'name' => (string)$_POST['db_name'], 'user' => (string)$_POST['db_user'], 'pass' => (string)$_POST['db_pass']);
}

if ($db === null) {
    add_result($S, 'Conexión', 'INFO', $IS_CLI ? 'no probada (define DIAG_DB_HOST, DIAG_DB_NAME, DIAG_DB_USER y DIAG_DB_PASS)' : 'no probada (usa el formulario de abajo)');
} elseif (!extension_loaded('pdo_mysql')) {
    add_result($S, 'Conexión', 'FALLO', 'pdo_mysql no está disponible');
} else {
    try {
        $dsn = 'mysql:host=' . $db['host'] . ';port=' . (int)$db['port'] . ';dbname=' . $db['name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $db['user'], $db['pass'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
        $db['pass'] = null;
        unset($_POST['db_pass']);
        add_result($S, 'Conexión', 'OK', 'host ' . $db['host'] . ', base ' . $db['name'] . ', usuario ' . mask($db['user']));

        $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($ver, 'mariadb') !== false;
        preg_match('/^(\d+)\.(\d+)/', $ver, $m);
        $num = isset($m[1]) ? ((int)$m[1] * 100 + (int)$m[2]) : 0;
        if ($isMaria) {
            add_result($S, 'Versión', $num >= 1006 ? 'OK' : 'FALLO', $ver . ($num >= 1006 ? '' : ': se necesita MariaDB 10.6 o superior'));
        } else {
            add_result($S, 'Versión', 'AVISO', $ver . ': no es MariaDB; el esquema está probado en MariaDB 10.11');
        }
        $vars = array('version_comment', 'character_set_server', 'collation_server', 'character_set_database', 'sql_mode',
                      'max_allowed_packet', 'time_zone', 'system_time_zone', 'lower_case_table_names', 'innodb_file_per_table',
                      'innodb_ft_min_token_size', 'wait_timeout', 'max_user_connections', 'default_storage_engine');
        foreach ($vars as $var) {
            try {
                $val = $pdo->query('SELECT @@' . $var)->fetchColumn();
                add_result($S, '@@' . $var, 'INFO', $var === 'max_allowed_packet' ? fmt_bytes((int)$val) : $val);
            } catch (Exception $e) {
                add_result($S, '@@' . $var, 'INFO', '(no disponible)');
            }
        }
        $row = $pdo->query("SELECT NOW() AS n, UTC_TIMESTAMP() AS u, CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', 'Europe/Madrid') AS m")->fetch(PDO::FETCH_ASSOC);
        add_result($S, 'Hora del servidor', 'INFO', 'NOW() ' . $row['n'] . ' / UTC ' . $row['u']);
        add_result($S, 'Zonas horarias con nombre', $row['m'] === null ? 'INFO' : 'OK',
            $row['m'] === null ? 'no cargadas (esperable): la aplicación guarda UTC y convierte en PHP' : 'cargadas');
        $phpUtc = gmdate('Y-m-d H:i:s');
        $drift = abs(strtotime($phpUtc . ' UTC') - strtotime($row['u'] . ' UTC'));
        add_result($S, 'Desfase reloj PHP / BD', $drift <= 5 ? 'OK' : 'AVISO', $drift . ' s');

        try {
            $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($grants as $g) {
                add_result($S, 'Permisos', 'INFO', preg_replace("/IDENTIFIED BY PASSWORD '[^']*'/i", 'IDENTIFIED BY PASSWORD ***', $g));
            }
        } catch (Exception $e) {
            add_result($S, 'Permisos', 'INFO', 'SHOW GRANTS no permitido');
        }

        $tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN);
        add_result($S, 'Tablas existentes en la base', count($tables) === 0 ? 'OK' : 'AVISO',
            count($tables) === 0 ? 'vacía: apta para instalar' : count($tables) . ' tablas. NO se instalará encima sin tu confirmación: ' . implode(', ', array_slice($tables, 0, 25)) . (count($tables) > 25 ? '...' : ''));

        // Pruebas de funcionalidad sobre tablas TEMPORARY (desaparecen al cerrar la conexión)
        try {
            $pdo->exec("CREATE TEMPORARY TABLE u365_diag (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                owner INT UNSIGNED NULL,
                ended TINYINT(1) NOT NULL DEFAULT 0,
                running INT UNSIGNED AS (IF(ended = 0, owner, NULL)) PERSISTENT,
                doc JSON NULL,
                txt VARCHAR(100) NOT NULL,
                UNIQUE KEY uq_running (running),
                CONSTRAINT ck_txt CHECK (txt <> 'prohibido'),
                CONSTRAINT ck_doc CHECK (doc IS NULL OR JSON_VALID(doc))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            add_result($S, 'Tabla temporal InnoDB utf8mb4', 'OK', 'creada');

            $pdo->exec("INSERT INTO u365_diag (owner, txt, doc) VALUES (1, 'Señal de administración ¿año? 🖨', '{\"a\":1}')");
            $back = $pdo->query('SELECT txt FROM u365_diag WHERE id = 1')->fetchColumn();
            add_result($S, 'utf8mb4 (acentos y emoji)', $back === 'Señal de administración ¿año? 🖨' ? 'OK' : 'FALLO', $back === 'Señal de administración ¿año? 🖨' ? 'ida y vuelta correcta' : 'el texto vuelve alterado');

            try {
                $pdo->exec("INSERT INTO u365_diag (owner, txt) VALUES (2, 'prohibido')");
                add_result($S, 'Restricciones CHECK', 'FALLO', 'no se aplican');
            } catch (Exception $e) {
                add_result($S, 'Restricciones CHECK', 'OK', 'se aplican');
            }
            try {
                $pdo->exec("INSERT INTO u365_diag (owner, txt) VALUES (1, 'segundo temporizador')");
                add_result($S, 'Índice único en columna generada', 'FALLO', 'no impide el duplicado');
            } catch (Exception $e) {
                add_result($S, 'Índice único en columna generada', 'OK', 'impide dos temporizadores activos');
            }

            $pdo->exec('CREATE TEMPORARY TABLE u365_seq (y SMALLINT PRIMARY KEY, v INT UNSIGNED NOT NULL) ENGINE=InnoDB');
            $pdo->exec('INSERT INTO u365_seq (y, v) VALUES (2026, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE v = LAST_INSERT_ID(v + 1)');
            $pdo->exec('INSERT INTO u365_seq (y, v) VALUES (2026, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE v = LAST_INSERT_ID(v + 1)');
            $seq = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
            add_result($S, 'Secuencia con LAST_INSERT_ID', $seq === 2 ? 'OK' : 'FALLO', 'resultado ' . $seq . ' (esperado 2)');

            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO u365_diag (owner, ended, txt) VALUES (9, 1, 'tx')");
            $pdo->rollBack();
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM u365_diag WHERE txt = 'tx'")->fetchColumn();
            add_result($S, 'Transacciones', $cnt === 0 ? 'OK' : 'FALLO', $cnt === 0 ? 'el rollback funciona' : 'el rollback no deshizo el cambio');
        } catch (Exception $e) {
            add_result($S, 'Pruebas con tablas temporales', 'AVISO', 'no se pudieron ejecutar (¿falta el permiso CREATE TEMPORARY TABLES?): ' . $e->getMessage());
        }

        $lock = $pdo->query("SELECT GET_LOCK('u365_diag_lock', 1)")->fetchColumn();
        $pdo->query("SELECT RELEASE_LOCK('u365_diag_lock')");
        add_result($S, 'GET_LOCK (bloqueo del cron)', (string)$lock === '1' ? 'OK' : 'AVISO', (string)$lock === '1' ? 'disponible' : 'no disponible: se usará solo flock');
        $pdo = null;
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (isset($db['pass']) && $db['pass'] !== '' && $db['pass'] !== null) {
            $msg = str_replace($db['pass'], '***', $msg);
        }
        add_result($S, 'Conexión', 'FALLO', $msg);
    }
}

// ---------------------------------------------------------------------------
// 10. Cron (no se puede detectar desde PHP)
// ---------------------------------------------------------------------------
add_result('Cron', 'Tareas programadas', 'INFO', 'No detectable desde PHP. Indica en tu respuesta si tu plan de IONOS permite cron y con qué intervalo mínimo.');

// ---------------------------------------------------------------------------
// Informe
// ---------------------------------------------------------------------------
$counts = array('OK' => 0, 'AVISO' => 0, 'FALLO' => 0, 'INFO' => 0);
foreach ($RESULTS as $r) {
    $counts[$r[2]]++;
}
$text = "UNIVERS365 - Diagnóstico de alojamiento\n";
$text .= 'Fecha (UTC): ' . gmdate('d/m/Y H:i:s') . "\n";
$text .= 'Modo: ' . ($IS_CLI ? 'consola' : 'web') . "\n";
$text .= sprintf("Resumen: %d OK, %d AVISO, %d FALLO\n", $counts['OK'], $counts['AVISO'], $counts['FALLO']);
$section = '';
foreach ($RESULTS as $r) {
    if ($r[0] !== $section) {
        $section = $r[0];
        $text .= "\n== " . $section . " ==\n";
    }
    $text .= sprintf("[%-5s] %s: %s\n", $r[2], $r[1], $r[3]);
}

if ($IS_CLI) {
    echo $text;
    exit($counts['FALLO'] > 0 ? 1 : 0);
}

$colors = array('OK' => '#198754', 'AVISO' => '#b58105', 'FALLO' => '#dc3545', 'INFO' => '#6c757d');
$tokenField = '<input type="hidden" name="t" value="' . h(DIAG_TOKEN) . '">';
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Diagnóstico UNIVERS365</title>
<style>
body{font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;margin:0;background:#f4f6fa;color:#1d2433}
header{background:#0b1f3a;color:#fff;padding:16px 24px}header h1{margin:0;font-size:18px}
main{max-width:1100px;margin:0 auto;padding:16px}
.card{background:#fff;border:1px solid #dde3ec;border-radius:8px;padding:16px;margin-bottom:16px}
table{width:100%;border-collapse:collapse}td,th{padding:6px 8px;border-bottom:1px solid #eef1f5;text-align:left;vertical-align:top}
th{font-size:12px;color:#5b6577;text-transform:uppercase}
.b{display:inline-block;min-width:52px;text-align:center;color:#fff;border-radius:4px;font-size:12px;padding:1px 6px}
textarea{width:100%;height:260px;font:12px/1.4 ui-monospace,Consolas,monospace;box-sizing:border-box}
label{display:block;margin:6px 0 2px}input[type=text],input[type=password],input[type=number]{width:100%;max-width:360px;padding:6px;box-sizing:border-box}
button{background:#0d6efd;color:#fff;border:0;border-radius:6px;padding:8px 14px;cursor:pointer;margin-top:10px}
button.danger{background:#dc3545}.warn{background:#fff3cd;border-color:#ffe08a}
</style>
</head>
<body>
<header><h1>UNIVERS365 · Diagnóstico de alojamiento</h1></header>
<main>
<div class="card warn"><strong>Importante:</strong> copia el informe de texto y borra este fichero en cuanto termines. Deja de funcionar solo a las <?= (int)DIAG_MAX_AGE_HOURS ?> h de subirlo.</div>
<div class="card">
  <strong>Resumen:</strong>
  <span class="b" style="background:<?= $colors['OK'] ?>"><?= (int)$counts['OK'] ?> OK</span>
  <span class="b" style="background:<?= $colors['AVISO'] ?>"><?= (int)$counts['AVISO'] ?> AVISO</span>
  <span class="b" style="background:<?= $colors['FALLO'] ?>"><?= (int)$counts['FALLO'] ?> FALLO</span>
</div>
<div class="card">
  <strong>Informe para copiar y enviar</strong> (no contiene contraseñas)
  <textarea readonly><?= h($text) ?></textarea>
</div>
<div class="card">
  <strong>Probar la base de datos</strong> (opcional, solo lectura y tablas temporales)
  <?php if (!$isHttps): ?>
    <p style="color:#dc3545">La página no se está sirviendo por HTTPS. No envíes credenciales hasta activar el certificado.</p>
  <?php else: ?>
  <form method="post" autocomplete="off">
    <?= $tokenField ?><input type="hidden" name="action" value="db">
    <label>Servidor (IONOS: dbXXXXXXXX.hosting-data.io)</label><input type="text" name="db_host" required>
    <label>Puerto</label><input type="number" name="db_port" value="3306" required>
    <label>Base de datos</label><input type="text" name="db_name" required>
    <label>Usuario</label><input type="text" name="db_user" required>
    <label>Contraseña (no se guarda ni aparece en el informe)</label><input type="password" name="db_pass" required>
    <button type="submit">Probar conexión</button>
  </form>
  <?php endif; ?>
</div>
<div class="card">
<table>
<tr><th>Estado</th><th>Sección</th><th>Comprobación</th><th>Detalle</th></tr>
<?php foreach ($RESULTS as $r): ?>
<tr><td><span class="b" style="background:<?= $colors[$r[2]] ?>"><?= h($r[2]) ?></span></td><td><?= h($r[0]) ?></td><td><?= h($r[1]) ?></td><td><?= h($r[3]) ?></td></tr>
<?php endforeach; ?>
</table>
</div>
<div class="card">
  <form method="post">
    <?= $tokenField ?><input type="hidden" name="action" value="delete">
    <label><input type="checkbox" required> Ya he copiado el informe</label>
    <button class="danger" type="submit">Borrar este fichero del servidor</button>
  </form>
</div>
</main>
</body>
</html>
