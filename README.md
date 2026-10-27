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
decisiones técnicas se registran en [`docs/decisiones.md`](docs/decisiones.md).

## Seguridad del repositorio

Este repositorio no debe contener nunca contraseñas, tokens ni la clave de
cifrado. La configuración real vive en `config/config.php`, excluido por
`.gitignore`.
