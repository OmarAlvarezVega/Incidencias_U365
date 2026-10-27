# UNIVERS365 Incident Management

Plataforma de gestión de incidencias técnicas de UNIVERS365 TECHNOLOGY para
`https://incidencias.univers365.com`, pensada para un alojamiento compartido de
IONOS: PHP 8.2+ y MariaDB 10.6+, sin procesos persistentes ni Node.js en
producción.

## Estado

| Fase | Contenido | Estado |
| --- | --- | --- |
| 1 | Análisis y arquitectura | En revisión |
| 2 | Base del sistema | Pendiente del diagnóstico de IONOS |

El documento de arquitectura completo está en el proyecto de Claude; las
decisiones técnicas se registran en [`docs/decisiones.md`](docs/decisiones.md)
y las tareas pendientes en [`docs/pendientes.md`](docs/pendientes.md).

## Contenido actual

| Ruta | Qué es |
| --- | --- |
| `database/migrations/` | Migraciones numeradas e inmutables (esquema inicial y catálogos) |
| `tools/diagnostico.php` | Diagnóstico del alojamiento para ejecutar una vez en IONOS y borrar |
| `tests/Schema/` | Pruebas de restricciones del esquema contra una base de datos de pruebas |
| `.github/workflows/ci.yml` | CI: migraciones en MariaDB 10.6, 10.11 y 11.4, y sintaxis del diagnóstico en PHP 7.0 a 8.4 |

## Probar el esquema en local

```bash
mysql -u root -e "CREATE DATABASE u365t CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
for f in database/migrations/*.sql; do mysql -u root u365t < "$f"; done
TEST_DB_DSN='mysql:host=127.0.0.1;dbname=u365t;charset=utf8mb4' TEST_DB_USER=root TEST_DB_PASS='' \
  php tests/Schema/schema_test.php
```

Usa siempre una base de datos de pruebas: la prueba crea 800 incidencias.

## Seguridad del repositorio

Este repositorio no debe contener nunca contraseñas, tokens ni la clave de
cifrado. La configuración real vive en `config/config.php`, excluido por
`.gitignore`.
