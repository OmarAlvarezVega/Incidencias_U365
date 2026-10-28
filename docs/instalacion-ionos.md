# Manual de instalación en IONOS

Destino: `https://incidencias.univers365.com`, alojamiento compartido de IONOS
con PHP 8.2 o superior y MariaDB 10.6 o superior.

> Lo que este manual **no** puede afirmar sin comprobarlo en tu cuenta está
> marcado como **[Verificar]**. El script `tools/diagnostico.php` sirve para
> eso: ejecútalo antes de instalar y bórralo después.

## 1. Antes de empezar

Necesitas:

- Acceso al panel de IONOS (dominios, bases de datos, PHP, cron).
- Acceso SFTP (o SSH si el plan lo incluye).
- El paquete `univers365-incidencias-X.Y.Z.zip` (ver `docs/actualizacion.md`
  para generarlo). Ya incluye `vendor/`: no hace falta Composer en IONOS.

Requisitos que comprueba el instalador (bloquean si faltan):

| Requisito | Para qué |
| --- | --- |
| PHP ≥ 8.2 | Sintaxis y funciones usadas |
| pdo_mysql | Base de datos |
| mbstring, ctype, json | Texto UTF-8 y validación |
| sodium, openssl | Cifrado de secretos, TLS |
| fileinfo | Detectar el tipo real de los adjuntos |
| zip, zlib | Exportación XLSX y copias de seguridad |
| `storage/` y `config/` escribibles | Datos y configuración |

Recomendadas: `curl` (WhatsApp y Microsoft) e `intl`.

## 2. Diagnóstico del alojamiento

1. Sube `tools/diagnostico.php` a la raíz web y ábrelo en el navegador.
2. Anota: versión de PHP, extensiones, ruta del binario PHP de consola,
   límites de subida (`upload_max_filesize`, `post_max_size`), si hay
   `proc_open`/`exec`, y la prueba de conexión a MariaDB.
3. **Bórralo del servidor** en cuanto termines.

## 3. Base de datos

1. Panel de IONOS → Hosting → Bases de datos → **Crear base de datos
   MariaDB**. Anota servidor (`dbXXXXXXX.hosting-data.io`), nombre, usuario y
   contraseña. **No** la pegues en ningún chat ni repositorio.
2. La base de datos debe estar **vacía**. El instalador se niega a trabajar
   sobre una base con tablas y nunca borra nada.

## 4. Subida de ficheros

Estructura en el servidor (ejemplo con la carpeta `incidencias/`):

```
incidencias/
  app/  bin/  config/  database/  storage/  templates/  vendor/  docs/
  public/        ← raíz web del subdominio
```

1. Descomprime el ZIP en local y súbelo por SFTP a `incidencias/`.
2. Panel de IONOS → Dominios → `incidencias.univers365.com` → **Destino**:
   apunta a la carpeta `incidencias/public`. **[Verificar]** que el panel
   permite elegir una subcarpeta; es lo habitual en IONOS.
3. Plan B, solo si no se puede: apunta a `incidencias/`. El `.htaccess` de la
   raíz bloquea `app/`, `config/`, `storage/`, etc. y reenvía todo a `public/`.
   Comprueba después que `https://incidencias.univers365.com/config/` y
   `/storage/` devuelven 403.
4. Activa el certificado SSL del subdominio en el panel y fuerza HTTPS.
5. Selecciona PHP 8.2 o superior para el dominio (Panel → PHP).

Permisos: `storage/` y `config/` deben ser escribibles por PHP (en IONOS,
normalmente 755 en carpetas basta porque PHP corre con tu usuario
**[Verificar]**). Nada en `public/` necesita escritura.

## 5. Instalador

El instalador solo se activa si existe un fichero de autorización, para que
nadie pueda instalar en tu lugar en los minutos en que la web está vacía.

1. Crea en tu ordenador un fichero `install.token` con una línea de al menos
   32 caracteres aleatorios (por ejemplo, generada con un gestor de
   contraseñas).
2. Súbelo a `incidencias/storage/install.token`.
3. Abre `https://incidencias.univers365.com/instalar`, pega el mismo valor y
   rellena: URL (`https://incidencias.univers365.com`, sin barra final), datos
   de la base de datos y el primer superadministrador (contraseña de 12+
   caracteres).
4. El instalador, por este orden: valida los datos del administrador, conecta,
   comprueba que la base está vacía, crea la clave de cifrado
   `config/app.key` (si ya existe una válida la reutiliza, nunca la
   sobrescribe), aplica las migraciones, crea el administrador, escribe
   `config/config.php` (permisos 0600), crea `storage/installed.lock` y
   **borra** `storage/install.token`.
5. A partir de ahí `/instalar` devuelve 404.

Alternativa por SSH: `php bin/install.php` hace lo mismo pidiendo los datos
por teclado.

### Después de instalar

- **Guarda una copia de `config/app.key` fuera del servidor** (gestor de
  contraseñas o caja fuerte). Sin ella no se pueden descifrar las contraseñas
  de buzones ni los tokens de WhatsApp de una copia de seguridad.
- Comprueba que `storage/install.token` ya no existe.

## 6. Tareas programadas (cron)

La aplicación necesita ejecutar tareas cada pocos minutos: cola de envíos,
lectura de buzones, SLA, cierre automático y copia diaria.

**Opción A, cron de IONOS** (si el plan lo incluye, **[Verificar]** el
intervalo mínimo):

```
*/5 * * * * /usr/bin/php8.3-cli /ruta/absoluta/incidencias/bin/cron.php
```

La ruta exacta del PHP de consola la indica el diagnóstico o el panel.
Para probarla a mano por SSH: `php bin/cron.php` muestra el resultado de cada
tarea.

**Opción B, disparador HTTPS** (planes sin cron):

1. Administración → Procesos → **Generar clave del cron**. Se muestra una sola
   vez; solo se guarda su hash.
2. Configura un servicio externo de cron (el que uses) para hacer cada 5
   minutos:
   `POST https://incidencias.univers365.com/cron/run` con la cabecera
   `X-Cron-Key: <clave>`.
3. Sin clave configurada, `/cron/run` responde 404.

Administración → Procesos muestra cuándo se ejecutó cada tarea y la cola de
trabajos con sus errores.

## 7. Comprobaciones finales

Sigue `docs/checklist-preproduccion.md` antes de dar acceso al equipo.
