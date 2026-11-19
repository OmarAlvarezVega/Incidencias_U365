<?php

/**
 * Configuración de ejemplo. El instalador genera config/config.php a partir
 * de estos valores; ese fichero NO se versiona (ver .gitignore) y debe tener
 * permisos 0600 en el servidor.
 *
 * Nunca pongas credenciales reales en este fichero de ejemplo.
 */

return [
    // 'production' en IONOS. 'development' muestra errores detallados y permite HTTP.
    'env' => 'production',

    // URL pública, sin barra final
    'app_url' => 'https://incidencias.univers365.com',

    // Base de datos MariaDB (panel de IONOS → Bases de datos)
    'db' => [
        'host' => 'dbXXXXXXXXX.hosting-data.io',
        'port' => 3306,
        'name' => 'dbsXXXXXXXX',
        'user' => 'dbuXXXXXXXX',
        'pass' => 'CAMBIAR',
    ],

    // Clave de cifrado de credenciales (la crea el instalador). Guárdala
    // también en un gestor de contraseñas: sin ella no se pueden recuperar
    // las credenciales de correo y WhatsApp de una copia de seguridad.
    'key_file' => __DIR__ . '/app.key',

    'paths' => [
        // Carpeta escribible fuera de la raíz web
        'storage' => dirname(__DIR__) . '/storage',
    ],

    // Hash SHA-256 de la clave del disparador HTTP del cron (/cron/run).
    // Vacío = disparador HTTP desactivado. Se genera desde Administración → Procesos.
    'cron_key_sha256' => '',
];
