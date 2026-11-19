# Manual de usuarios, roles y permisos

Todos los permisos se comprueban **en el servidor** en cada petición. Ocultar
un botón es solo comodidad: aunque alguien fabrique la petición a mano, el
servidor la rechaza. Una incidencia que no puedes ver responde **404** (no
403), para no revelar que existe.

## Roles de fábrica

| Rol | Pensado para | Ve incidencias |
| --- | --- | --- |
| Superadministrador | Dirección / IT interno | Todas |
| Responsable / coordinador | Jefes de equipo | Las de sus equipos, las suyas y las sin asignar |
| Técnico | Personal técnico | Solo aquellas en las que es responsable o colaborador |
| Cliente (portal) | Reservado para el portal de clientes (no incluido en esta versión) | — |

El rol *Superadministrador* no se puede editar y nadie puede editar su propio
rol (evita quedarse sin acceso o darse permisos a sí mismo).

## Permisos

| Permiso | Qué permite | Super | Coord. | Técnico |
| --- | --- | :-: | :-: | :-: |
| `tickets.view.all` | Ver todas las incidencias | ✓ | | |
| `tickets.view.team` | Ver las de sus equipos | ✓ | ✓ | |
| `tickets.view.own` | Ver las suyas (responsable o colaborador) | ✓ | ✓ | ✓ |
| `tickets.view.unassigned` | Bandeja de sin asignar | ✓ | ✓ | |
| `tickets.create` | Crear incidencias | ✓ | ✓ | ✓ |
| `tickets.edit` | Editar datos de las que puede tocar | ✓ | ✓ | ✓ |
| `tickets.assign` / `tickets.reassign` | Asignar / cambiar responsable | ✓ | ✓ | |
| `tickets.change_priority` | Cambiar prioridad | ✓ | ✓ | |
| `tickets.change_status` | Cambiar estado (según transiciones) | ✓ | ✓ | ✓ |
| `tickets.reopen` | Reabrir resueltas o cerradas | ✓ | ✓ | |
| `tickets.reply_public` | Responder al cliente | ✓ | ✓ | ✓ |
| `tickets.note_internal` | Notas internas y actuaciones | ✓ | ✓ | ✓ |
| `tickets.delete` | Eliminación lógica | ✓ | | |
| `tickets.review_queue` | Cola de revisión de entrada | ✓ | ✓ | |
| `tickets.export` | Exportar CSV/Excel | ✓ | ✓ | |
| `time.log` / `time.edit_any` | Registrar su tiempo / corregir el de otros | ✓ | ✓ / ✓ | ✓ / |
| `attachments.upload` / `attachments.view_internal` | Adjuntar / ver adjuntos internos | ✓ | ✓ | ✓ |
| `clients.view` / `clients.manage` | Consultar / gestionar clientes | ✓ | ✓ / ✓ | ✓ / |
| `employees.view` / `employees.manage` | Consultar / gestionar empleados | ✓ | ✓ / | |
| `workload.view.all` / `workload.view.team` | Carga de trabajo | ✓ | / ✓ | |
| `reports.view.all` / `reports.view.team` | Informes | ✓ | / ✓ | |
| `users.manage` | Equipos, alta y bloqueo de usuarios | ✓ | | |
| `permissions.manage` | Roles y permisos individuales | ✓ | | |
| `catalogs.manage` | Estados, prioridades, categorías, etiquetas | ✓ | | |
| `sla.manage` | Calendarios y políticas de SLA | ✓ | | |
| `integrations.manage` | Correo y WhatsApp | ✓ | | |
| `templates.manage` | Plantillas de mensajes | ✓ | | |
| `settings.manage` | Ajustes generales y procesos | ✓ | | |
| `audit.view` | Registro de auditoría | ✓ | | |
| `gdpr.manage` | Solicitudes RGPD, exportar y anonimizar | ✓ | | |
| `backups.manage` | Copias de seguridad | ✓ | | |

Además del permiso, para **modificar** una incidencia hay que trabajar en ella
(responsable o colaborador) o supervisarla (verlas todas o coordinar su
equipo). Una incidencia sin responsable la puede tocar quien ve la bandeja de
sin asignar, por ejemplo para asignársela.

## Excepciones por persona

En la ficha del empleado → **Permisos**, se puede **conceder** o **denegar**
un permiso concreto a una persona sin cambiar su rol. La denegación gana a lo
que dé el rol. Úsalo con moderación: el rol debería cubrir casi todo.

## Alta de empleados

Empleados → Nuevo. Quien no tiene `permissions.manage` solo puede dar de alta
técnicos. Contraseña inicial: mínimo 12 caracteres, no puede contener el
nombre ni el correo; el empleado debe cambiarla en el primer acceso si así se
marca.

## Bloqueo, baja y reasignación

- **Bloquear** impide entrar y cierra sus sesiones abiertas al momento.
- **Desactivar** (baja) hace lo mismo y lo saca de los selectores de
  asignación. Sus datos e historial se conservan.
- Antes de una baja, usa **Reasignar**: pasa sus incidencias abiertas a otra
  persona y deja constancia en cada una.

## Seguridad de acceso

- 5 intentos fallidos en 15 minutos bloquean la cuenta 15 minutos (también
  hay límite por IP). Configurable en Ajustes.
- Sesión caducada tras 60 minutos de inactividad (configurable) y 12 horas
  como máximo.
- Recuperación de contraseña por correo con enlace de un solo uso.
- Cambiar la contraseña o bloquear a alguien invalida todas sus sesiones.
- Cada empleado puede ver y cerrar sus sesiones en **Mi perfil**.
