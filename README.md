# UNIVERS365 Incident Management

Plataforma de gestión de incidencias técnicas de UNIVERS365 TECHNOLOGY para
`https://incidencias.univers365.com`, pensada para un alojamiento compartido de
IONOS: PHP 8.2+ y MariaDB 10.6+, sin procesos persistentes ni Node.js en
producción.

## Estado

Versión 1.0.0: fases 1 a 9 implementadas y con pruebas automáticas. **No
está en producción**: falta el diagnóstico de IONOS, la primera conexión real
al buzón y a Meta, y la checklist de preproducción. Ver
[`docs/pendientes.md`](docs/pendientes.md).

## Qué hace

- Incidencias con numeración `INC-AAAA-NNNNNN`, estados y transiciones
  configurables, prioridades, categorías, etiquetas, relaciones, responsable
  y colaboradores, notas internas separadas de la conversación con el
  cliente, adjuntos seguros y bloqueo optimista.
- Entrada por **correo** (IMAP; contraseña o Microsoft 365 OAuth2) y
  **WhatsApp Cloud API** oficial, con identificación de clientes por contacto
  y dominio, cola de revisión, hilos y protección contra bucles.
- Registro de tiempo (temporizador y manual), carga de trabajo por técnico,
  informes con exportación CSV/Excel.
- SLA con calendario laboral, festivos y cambio de hora; avisos e
  incumplimientos.
- Roles y permisos comprobados en servidor, excepciones por persona,
  auditoría encadenada por hash, RGPD (solicitudes, exportar, anonimizar),
  copias con verificación y restauración segura, instalador bloqueable.

## Documentación

| Manual | Para |
| --- | --- |
| [Instalación en IONOS](docs/instalacion-ionos.md) | Subir, instalar y programar el cron |
| [Correo](docs/correo.md) | IONOS Mail o Microsoft 365, reglas de entrada |
| [WhatsApp](docs/whatsapp.md) | Meta, webhook, ventana de 24 h |
| [Usuarios y permisos](docs/usuarios-y-permisos.md) | Roles, matriz de permisos, bloqueo |
| [Administración](docs/administracion.md) | Catálogos, SLA, procesos, auditoría, RGPD |
| [Copias y recuperación](docs/copias-y-recuperacion.md) | Copias, verificación, restaurar |
| [Actualización](docs/actualizacion.md) | Paquete ZIP, subir una versión, migraciones |
| [Pruebas](docs/pruebas.md) | Qué cubren y cómo ejecutarlas |
| [Limitaciones](docs/limitaciones.md) | Lo que no hace esta versión |
| [Checklist de preproducción](docs/checklist-preproduccion.md) | Antes de abrir al equipo |
| [Decisiones técnicas](docs/decisiones.md) · [Pendientes](docs/pendientes.md) | Registro |

## Estructura

| Ruta | Qué es |
| --- | --- |
| `public/` | Única carpeta accesible desde la web: `index.php` y recursos estáticos |
| `app/` | Código: núcleo (`Core`), seguridad (`Security`), controladores (`Http`), servicios (`Services`) |
| `templates/` | Vistas Twig (escape automático) |
| `database/migrations/` | Migraciones numeradas e inmutables |
| `bin/` | Consola: `install`, `migrate`, `cron`, `backup`, `restore`, `build-release.sh` |
| `config/` | `config.example.php`; el real (`config.php`) y la clave (`app.key`) nunca se versionan |
| `storage/` | Adjuntos, correos originales, copias, registros. Fuera de la web |
| `tests/` | PHPUnit: unitarias, integración y seguridad HTTP |
| `tools/diagnostico.php` | Diagnóstico del alojamiento, para ejecutar una vez y borrar |

## Desarrollo

```bash
composer install
vendor/bin/phpunit --testsuite unit     # sin base de datos
vendor/bin/phpunit                      # todo (necesita MariaDB de pruebas, ver docs/pruebas.md)
bin/build-release.sh 1.0.0              # paquete para IONOS
```

## Seguridad del repositorio

Este repositorio no debe contener nunca contraseñas, tokens ni la clave de
cifrado. La configuración real vive en `config/config.php` y `config/app.key`,
excluidos por `.gitignore`. Los secretos que se configuran desde la
aplicación (buzones, WhatsApp) se guardan cifrados en la base de datos.
