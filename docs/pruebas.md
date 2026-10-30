# Pruebas

## Qué hay

| Suite | Carpeta | Qué cubre |
| --- | --- | --- |
| unit | `tests/Unit` | Validador (fechas locales a UTC, teléfonos, listas), política de contraseñas, neutralización de fórmulas en CSV, saneado de HTML, relleno de plantillas |
| integration | `tests/Integration` | Numeración de incidencias; un técnico no ve ni modifica incidencias ajenas; colaboradores; bloqueo optimista; SLA laborable con pausa de comida, festivos, fin de semana y **cambio de hora** de octubre; entrada de correo con `.eml` reales (alta, duplicados, hilo por número, tercero que intenta colarse, remitente desconocido, respuesta automática, bucle, respuesta a cerrada); filtro de adjuntos (php, doble extensión, código dentro de .txt, HTML/SVG, contenido que no corresponde, tamaño, nombres con `../`); WhatsApp (duplicados, número desconocido, reprocesado); instalador (no toca una base con tablas, valida antes de conectar, bloqueo, exige HTTPS); copias (crear, verificar, detectar manipulación, restaurar en otra base y comparar, negarse a restaurar sobre la base en uso o sobre una no vacía, nombres con `../`) |
| http | `tests/Http` | La aplicación real servida con `php -S`: cabeceras de seguridad, redirección sin sesión, CSRF (sin token, token falso y control positivo), 404 en incidencias ajenas por URL y por POST, escape de HTML en listado y ficha, parámetros de orden y búsqueda con inyección SQL, bloqueo por fuerza bruta, subida de `.php`/doble extensión/código en `.txt` (y control positivo con un PDF), webhook de WhatsApp (sin firma, firma falsa, firma buena, duplicado, verificación GET), instalador inaccesible tras instalar, cron sin clave |
| integration (opcional) | `tests/Integration/ImapServerTest.php` | Lectura real por IMAP contra un servidor de pruebas: SSL y STARTTLS con certificado validado, la primera conexión ignora el correo existente, descarga con adjunto, mueve a *Procesados*, la contraseña incorrecta queda registrada sin filtrarse. Se omite si no hay servidor (ver abajo) |
| — | `tests/Schema/schema_test.php` | Restricciones de la base de datos y numeración concurrente (8 procesos, 800 altas) |

Las pruebas con riesgo de "pasar sin probar nada" llevan un **control
positivo**: por ejemplo, la de CSRF comprueba también que con el token bueno
sí se crea la incidencia.

## Ejecutarlas en local

Necesitan una MariaDB de **pruebas**. La base se **vacía** al empezar, así
que su nombre tiene que contener `test` (si no, las pruebas se niegan a
arrancar).

```bash
mysql -u root -e "CREATE DATABASE u365_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                  CREATE DATABASE u365_test_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                  CREATE USER IF NOT EXISTS 't'@'localhost' IDENTIFIED BY 't';
                  GRANT ALL ON u365_test.* TO 't'@'localhost';
                  GRANT ALL ON u365_test_restore.* TO 't'@'localhost';"
composer install
vendor/bin/phpunit                       # todo
vendor/bin/phpunit --testsuite unit      # sin base de datos
```

Variables: `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER`,
`TEST_DB_PASS`, `TEST_DB_RESTORE_NAME`. Las pruebas HTTP necesitan la
extensión `curl`.

### Prueba con un servidor IMAP real

Con un Dovecot de pruebas (buzón Maildir accesible desde donde corren las
pruebas):

```bash
TEST_IMAP_HOST=localhost TEST_IMAP_PORT=993 TEST_IMAP_STARTTLS_PORT=143 \
TEST_IMAP_USER=soporte@pruebas.test TEST_IMAP_PASS=... \
TEST_IMAP_MAILDIR=/ruta/al/Maildir \
php -d openssl.cafile=/ruta/cert-autofirmado.pem vendor/bin/phpunit tests/Integration/ImapServerTest.php
```

El certificado se valida siempre (como en producción); por eso con uno
autofirmado hay que indicar `openssl.cafile`.

## En GitHub

`.github/workflows/ci.yml` ejecuta en cada PR: esquema en MariaDB 10.6, 10.11
y 11.4; sintaxis de todo el PHP; y PHPUnit en PHP 8.2, 8.3 y 8.4 (la prueba
contra un servidor IMAP real se omite en CI).

## Lo que no cubren (y hay que probar a mano en IONOS)

- IMAP contra IONOS Mail o Microsoft 365 (sí contra Dovecot), OAuth2 de
  Microsoft y **cualquier envío SMTP**.
- Envío real a la API de Meta (en las pruebas no hay salida a Internet).
- Límites reales del alojamiento: tiempo máximo de ejecución, memoria,
  tamaño de subida, cron.
- Navegadores: el JavaScript de la interfaz no tiene pruebas automáticas.

Ver `docs/checklist-preproduccion.md`.
