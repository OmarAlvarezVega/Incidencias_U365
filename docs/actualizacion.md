# Procedimiento de actualización

## Generar el paquete

En un ordenador con PHP 8.2+ y Composer (no en IONOS):

```bash
bin/build-release.sh 1.1.0
```

Genera `build/univers365-incidencias-1.1.0.zip` con `vendor/` instalado sin
dependencias de desarrollo y **sin** `.git`, `tests/`, `config/config.php`,
`config/app.key`, contenido de `storage/` ni el diagnóstico. Al final muestra
la huella SHA-256 del ZIP.

## Actualizar producción

1. **Avisa** al equipo: unos minutos sin servicio.
2. **Copia de seguridad manual** (Administración → Copias → Crear, o
   `php bin/backup.php`) y **verifícala**. Descárgala.
3. Copia también, fuera del servidor, `config/config.php` y `config/app.key`.
4. Sube por SFTP el contenido del ZIP **encima** de la instalación,
   sustituyendo `app/`, `bin/`, `database/`, `public/`, `templates/`,
   `vendor/`, `docs/` y los ficheros `VERSION` y `REVISION` de la raíz. **No toques** `config/` ni `storage/`.
   Para minimizar el tiempo a medias, sube primero a una carpeta temporal y
   renómbrala después si el SFTP lo permite.
5. Aplica las migraciones nuevas:
   - **Sin SSH (IONOS):** entra como superadministrador en
     Administración → Actualización. La pantalla muestra la versión subida y
     los cambios de base de datos pendientes (también aparece un aviso
     amarillo en todas las páginas). Marca la casilla y pulsa «Copiar y
     actualizar la base de datos»: primero crea una copia de la base de datos
     (si la copia falla, no migra) y después aplica los cambios. Descarga esa
     copia desde Copias de seguridad.
   - Con SSH: `php bin/migrate.php --status` y luego `php bin/migrate.php`.
6. Entra, revisa el panel y Administración → Procesos.

Las migraciones son **inmutables**: si un fichero ya aplicado ha cambiado,
`migrate.php` se detiene sin tocar nada. Cada migración se registra con su
huella en `schema_migrations`.

## Si algo sale mal

- Error de código: vuelve a subir el ZIP de la versión anterior.
- Una migración ha fallado a medias: MariaDB no deshace los cambios de
  estructura, así que **no** reintentes a ciegas. Restaura la copia del paso
  2 en una base nueva (`docs/copias-y-recuperacion.md`), apunta
  `config/config.php` a ella y sube el código anterior.
