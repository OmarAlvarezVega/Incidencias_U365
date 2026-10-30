# Copias de seguridad y recuperación

## Lo que hay que saber primero

- Las copias se guardan en `storage/backups/`, **en el mismo servidor**. Si
  se pierde el alojamiento, se pierden con él. **Hay que descargarlas fuera**
  con regularidad (Administración → Copias → Descargar) o confiar además en
  las copias propias de IONOS **[Verificar]** qué incluye tu plan y cuánto
  tiempo las guarda.
- Sin `config/app.key` una copia restaurada funciona, pero las contraseñas de
  buzones y los tokens de WhatsApp guardados **no se pueden descifrar** y hay
  que volver a escribirlos. Guarda `app.key` fuera del servidor desde el
  primer día.

## Qué se copia

| Fichero | Contenido |
| --- | --- |
| `u365-bd-AAAAMMDD-HHMMSS.sql.gz` | Base de datos completa (volcado hecho por la propia aplicación en PHP, sin depender de `mysqldump`) |
| `u365-archivos-AAAAMMDD-HHMMSS.zip` | Adjuntos y correos originales de `storage/` |
| `*.sha256` | Huella de cada fichero, para detectar copias dañadas o manipuladas |

No se copian `config/config.php` ni `config/app.key`: contienen credenciales.

## Copias automáticas

- Tarea `backup.auto`, una vez al día a partir de las 03:00, si está marcada *Copia automática diaria* en Ajustes. **Viene desactivada**: actívala al instalar.
- Se conservan las últimas 14 (ajuste *Copias que se conservan*, entre 3 y 90). La rotación borra solo copias generadas por la aplicación.
- Ajuste *Incluir adjuntos en las copias*.

## Copias manuales

- Administración → Copias de seguridad → **Crear copia ahora**.
- Por SSH: `php bin/backup.php` (todo), `php bin/backup.php --solo-bd`,
  `php bin/backup.php --listar`.
- **Haz siempre una copia manual antes de actualizar** (ver
  `docs/actualizacion.md`).

## Verificar una copia

Administración → Copias → **Verificar**, o
`php bin/backup.php --verificar=u365-bd-20261027-030000.sql.gz`.
Comprueba la huella SHA-256, que el comprimido se lee entero y que la copia
llega hasta su marca final (`-- FIN DE LA COPIA`). Una copia cortada a medias
se detecta.

Haz una **prueba de restauración real** al menos una vez antes de producción
y después cada trimestre: una copia que nunca se ha restaurado no está
probada.

## Restaurar

La restauración **nunca** escribe sobre la base de datos en uso. El
procedimiento es restaurar en una base nueva y, cuando esté comprobada,
cambiar la configuración para que apunte a ella. Así siempre hay vuelta atrás.

### Con SSH

1. Panel de IONOS → crea una base de datos MariaDB **nueva y vacía**.
2. Si la copia no está en `storage/backups/`, súbela allí junto con su
   `.sha256`.
3. Ejecuta:

   ```bash
   php bin/restore.php --copia=u365-bd-20261027-030000.sql.gz \
       --host=dbXXXXXXX.hosting-data.io --bd=dbsYYYYYYY --usuario=dbuYYYYYYY
   ```

   Pide la contraseña por teclado (no se queda en el historial) y pide que
   escribas `RESTAURAR`. Se niega si la base de destino no está vacía o si es
   la que está en uso.
4. Comprueba la base restaurada (por ejemplo con phpMyAdmin: número de
   incidencias, últimas fechas).
5. Edita `config/config.php` → sección `db` → pon los datos de la base nueva.
   Guarda antes una copia del fichero tal como estaba.
6. Entra en la aplicación y revisa. Para volver atrás, restaura el
   `config.php` anterior.

Adjuntos: `php bin/restore.php --archivos=u365-archivos-....zip --destino=/ruta/vacia`
los extrae en una carpeta **vacía** (comprueba que ninguna ruta del ZIP salga
de esa carpeta) para revisarlos y moverlos después a `storage/` a mano.

### Sin SSH

1. Crea una base nueva y vacía en el panel.
2. Descomprime el `.sql.gz` en tu ordenador e impórtalo con phpMyAdmin en la
   base nueva. **[Verificar]** el tamaño máximo de importación de phpMyAdmin
   en IONOS; si la copia es mayor, hay que pedir la importación a soporte de
   IONOS o usar SSH.
3. Pasos 5 y 6 de arriba.

### Perder el servidor entero

1. Nuevo alojamiento, subir el paquete de la **misma versión** que generó la
   copia (o una posterior, y luego `php bin/migrate.php`).
2. Restaurar la base de datos en una base vacía (arriba).
3. Copiar `config/app.key` guardada fuera y crear `config/config.php` a mano a
   partir de `config/config.example.php`.
4. Restaurar adjuntos en `storage/`.
5. Reconfigurar cron y comprobar con `docs/checklist-preproduccion.md`.
