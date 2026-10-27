-- =============================================================================
-- UNIVERS365 Incident Management
-- Migración 0002: catálogos iniciales (BORRADOR DE FASE 1)
--
-- Solo catálogos editables: roles, permisos, estados, prioridades, categorías,
-- canales y una política de SLA orientativa. NO crea usuarios, clientes ni
-- incidencias: el superadministrador lo crea el instalador con datos reales.
-- Fechas en UTC.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET @now = UTC_TIMESTAMP();

-- -----------------------------------------------------------------------------
-- Roles
-- -----------------------------------------------------------------------------
INSERT INTO roles (code, name, description, user_type, is_system, created_at, updated_at) VALUES
 ('superadmin',  'Superadministrador',      'Acceso completo a la plataforma',                       'staff',  1, @now, @now),
 ('coordinator', 'Responsable / coordinador','Gestiona las incidencias y la carga de sus equipos',    'staff',  1, @now, @now),
 ('technician',  'Técnico',                 'Trabaja las incidencias asignadas o compartidas',       'staff',  1, @now, @now),
 ('client',      'Cliente (portal)',        'Consulta sus propias incidencias. Fase posterior',      'client', 1, @now, @now);

-- -----------------------------------------------------------------------------
-- Permisos
-- -----------------------------------------------------------------------------
INSERT INTO permissions (code, module, description) VALUES
 ('tickets.view.all',           'tickets',   'Ver todas las incidencias'),
 ('tickets.view.team',          'tickets',   'Ver las incidencias de sus equipos'),
 ('tickets.view.own',           'tickets',   'Ver las incidencias de las que es responsable o participante'),
 ('tickets.view.unassigned',    'tickets',   'Ver la bandeja de incidencias sin asignar'),
 ('tickets.create',             'tickets',   'Crear incidencias'),
 ('tickets.edit',               'tickets',   'Editar datos de las incidencias que puede ver'),
 ('tickets.assign',             'tickets',   'Asignar responsable y colaboradores'),
 ('tickets.reassign',           'tickets',   'Cambiar el responsable de una incidencia ya asignada'),
 ('tickets.change_priority',    'tickets',   'Cambiar la prioridad'),
 ('tickets.change_status',      'tickets',   'Cambiar el estado según las transiciones permitidas'),
 ('tickets.reopen',             'tickets',   'Reabrir incidencias resueltas, cerradas o canceladas'),
 ('tickets.reply_public',       'tickets',   'Enviar respuestas al cliente'),
 ('tickets.note_internal',      'tickets',   'Añadir notas internas y actuaciones'),
 ('tickets.delete',             'tickets',   'Eliminación lógica de incidencias'),
 ('tickets.review_queue',       'tickets',   'Gestionar la cola de mensajes sin identificar'),
 ('tickets.export',             'reports',   'Exportar incidencias autorizadas'),
 ('time.log',                   'time',      'Registrar su propio tiempo'),
 ('time.edit_any',              'time',      'Corregir registros de tiempo de otros'),
 ('attachments.upload',         'tickets',   'Adjuntar archivos'),
 ('attachments.view_internal',  'tickets',   'Ver adjuntos internos'),
 ('clients.view',               'clients',   'Consultar clientes y contactos'),
 ('clients.manage',             'clients',   'Crear y modificar clientes y contactos'),
 ('employees.view',             'employees', 'Consultar fichas de empleados'),
 ('employees.manage',           'employees', 'Crear, modificar y desactivar empleados'),
 ('workload.view.all',          'employees', 'Ver la carga de trabajo de todos'),
 ('workload.view.team',         'employees', 'Ver la carga de trabajo de sus equipos'),
 ('users.manage',               'admin',     'Crear, bloquear y desactivar usuarios'),
 ('permissions.manage',         'admin',     'Gestionar roles y permisos de otros usuarios'),
 ('catalogs.manage',            'admin',     'Configurar estados, prioridades, categorías y etiquetas'),
 ('sla.manage',                 'admin',     'Configurar calendarios y políticas de SLA'),
 ('integrations.manage',        'admin',     'Configurar correo y WhatsApp'),
 ('templates.manage',           'admin',     'Editar plantillas de mensajes'),
 ('settings.manage',            'admin',     'Configuración general'),
 ('notifications.manage',       'admin',     'Definir límites de notificaciones'),
 ('reports.view.all',           'reports',   'Estadísticas generales'),
 ('reports.view.team',          'reports',   'Estadísticas de sus equipos'),
 ('audit.view',                 'admin',     'Consultar el registro de auditoría'),
 ('gdpr.manage',                'admin',     'Atender solicitudes de derechos y anonimizar'),
 ('backups.manage',             'admin',     'Lanzar y descargar copias de seguridad'),
 ('portal.tickets.view_own',    'portal',    'Cliente: ver sus incidencias'),
 ('portal.tickets.create',      'portal',    'Cliente: abrir incidencias'),
 ('portal.tickets.reply',       'portal',    'Cliente: responder en sus incidencias');

-- Superadministrador: todos los permisos de personal
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.module <> 'portal'
WHERE r.code = 'superadmin';

-- Coordinador
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
  'tickets.view.team','tickets.view.own','tickets.view.unassigned','tickets.create','tickets.edit',
  'tickets.assign','tickets.reassign','tickets.change_priority','tickets.change_status','tickets.reopen',
  'tickets.reply_public','tickets.note_internal','tickets.review_queue','tickets.export',
  'time.log','time.edit_any','attachments.upload','attachments.view_internal',
  'clients.view','clients.manage','employees.view','workload.view.team','reports.view.team')
WHERE r.code = 'coordinator';

-- Técnico
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
  'tickets.view.own','tickets.create','tickets.edit','tickets.change_status',
  'tickets.reply_public','tickets.note_internal','time.log',
  'attachments.upload','attachments.view_internal','clients.view')
WHERE r.code = 'technician';

-- Cliente (portal, fase posterior)
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.module = 'portal'
WHERE r.code = 'client';

-- -----------------------------------------------------------------------------
-- Estados, prioridades, canales
-- -----------------------------------------------------------------------------
INSERT INTO ticket_statuses (code, name, phase, color, pauses_sla, is_initial, sort_order) VALUES
 ('new',              'Nueva',                   'new',       '#0D6EFD', 0, 1, 10),
 ('pending_assign',   'Pendiente de asignación', 'new',       '#6F42C1', 0, 0, 20),
 ('assigned',         'Asignada',                'open',      '#0DCAF0', 0, 0, 30),
 ('in_progress',      'En curso',                'open',      '#198754', 0, 0, 40),
 ('pending_customer', 'Pendiente del cliente',   'pending',   '#FFC107', 1, 0, 50),
 ('pending_vendor',   'Pendiente de proveedor',  'pending',   '#FD7E14', 0, 0, 60),
 ('on_hold',          'En espera',               'pending',   '#ADB5BD', 1, 0, 70),
 ('resolved',         'Resuelta',                'resolved',  '#20C997', 0, 0, 80),
 ('closed',           'Cerrada',                 'closed',    '#343A40', 0, 0, 90),
 ('cancelled',        'Cancelada',               'cancelled', '#6C757D', 0, 0, 100);

-- Transiciones: cualquier estado activo (new/open/pending) puede pasar a otro
-- activo o a resuelta/cancelada. Cerrar solo desde resuelta. Reabrir exige permiso.
INSERT INTO ticket_status_transitions (from_status_id, to_status_id, permission_code)
SELECT f.id, t.id, NULL
FROM ticket_statuses f JOIN ticket_statuses t ON f.id <> t.id
WHERE f.phase IN ('new','open','pending')
  AND t.phase IN ('open','pending','resolved','cancelled');

INSERT INTO ticket_status_transitions (from_status_id, to_status_id, permission_code)
SELECT f.id, t.id, NULL FROM ticket_statuses f JOIN ticket_statuses t
WHERE f.code = 'resolved' AND t.code = 'closed';

INSERT INTO ticket_status_transitions (from_status_id, to_status_id, permission_code)
SELECT f.id, t.id, 'tickets.reopen' FROM ticket_statuses f JOIN ticket_statuses t
WHERE f.code IN ('resolved','closed','cancelled') AND t.code = 'in_progress';

INSERT INTO ticket_priorities (code, name, level, color) VALUES
 ('low',      'Baja',    1, '#6C757D'),
 ('normal',   'Normal',  2, '#0D6EFD'),
 ('high',     'Alta',    3, '#FFC107'),
 ('urgent',   'Urgente', 4, '#FD7E14'),
 ('critical', 'Crítica', 5, '#DC3545');

INSERT INTO ticket_channels (code, name) VALUES
 ('manual',     'Alta manual'),
 ('email',      'Correo electrónico'),
 ('whatsapp',   'WhatsApp'),
 ('portal',     'Portal de clientes'),
 ('api',        'API'),
 ('monitoring', 'Monitorización');

-- -----------------------------------------------------------------------------
-- Categorías iniciales (editables)
-- -----------------------------------------------------------------------------
INSERT INTO ticket_categories (parent_id, name, sort_order) VALUES
 (NULL, 'Hardware', 10), (NULL, 'Software', 20), (NULL, 'Redes', 30),
 (NULL, 'Servidores', 40), (NULL, 'Ciberseguridad', 50), (NULL, 'Microsoft 365', 60),
 (NULL, 'Copias de seguridad', 70), (NULL, 'Impresoras', 80), (NULL, 'ERP', 90),
 (NULL, 'Odoo', 100), (NULL, 'Web', 110), (NULL, 'Correo electrónico', 120),
 (NULL, 'Telefonía', 130), (NULL, 'Usuarios y permisos', 140), (NULL, 'Consultoría', 150),
 (NULL, 'Otros', 999);

-- -----------------------------------------------------------------------------
-- SLA orientativo. Horario y modo natural/laborable son SUPOSICIONES a validar.
-- Sin festivos: dependen de la comunidad autónoma y del municipio.
-- -----------------------------------------------------------------------------
INSERT INTO business_calendars (name, timezone, created_at, updated_at)
VALUES ('Horario laboral UNIVERS365', 'Europe/Madrid', @now, @now);
SET @cal = LAST_INSERT_ID();

INSERT INTO business_hours (calendar_id, weekday, start_time, end_time) VALUES
 (@cal,1,'09:00','14:00'),(@cal,1,'15:00','18:00'),
 (@cal,2,'09:00','14:00'),(@cal,2,'15:00','18:00'),
 (@cal,3,'09:00','14:00'),(@cal,3,'15:00','18:00'),
 (@cal,4,'09:00','14:00'),(@cal,4,'15:00','18:00'),
 (@cal,5,'09:00','14:00'),(@cal,5,'15:00','18:00');

INSERT INTO sla_policies (name, calendar_id, is_default, pause_when_pending, active, created_at, updated_at)
VALUES ('SLA general', @cal, 1, 1, 0, @now, @now);  -- active = 0 hasta validar cálculos (fase SLA)
SET @pol = LAST_INSERT_ID();

INSERT INTO sla_policy_targets (policy_id, priority_id, first_response_minutes, resolution_minutes, time_mode)
SELECT @pol, p.id,
  CASE p.code WHEN 'critical' THEN 15 WHEN 'urgent' THEN 60 WHEN 'high' THEN 240
              WHEN 'normal' THEN 480 WHEN 'low' THEN 960 END,
  NULL,
  'business'  -- Laborable por defecto. Pasar a 'calendar' (natural) solo si hay guardias 24x7.
FROM ticket_priorities p;

-- -----------------------------------------------------------------------------
-- Ajustes generales
-- -----------------------------------------------------------------------------
INSERT INTO application_settings (setting_key, value_json, updated_at) VALUES
 ('app.timezone',               '"Europe/Madrid"', @now),
 ('app.date_format',            '"d/m/Y"',         @now),
 ('app.currency',               '"EUR"',           @now),
 ('app.locale',                 '"es_ES"',         @now),
 ('tickets.number_prefix',      '"INC"',           @now),
 ('time.allow_parallel_timers', 'false',           @now),
 ('security.password_min_length','12',             @now),
 ('security.session_idle_minutes','60',            @now),
 ('security.login_max_attempts','5',               @now),
 ('uploads.max_mb',             '15',              @now),
 ('uploads.allowed_extensions', '["pdf","png","jpg","jpeg","gif","webp","txt","log","csv","xlsx","docx","pptx","zip","eml","msg"]', @now),
 ('email.unknown_sender_policy','"review"',        @now),
 ('whatsapp.enabled',           'false',           @now),
 ('whatsapp.ambiguity_policy',  '"ask_customer"',  @now),
 ('retention.closed_ticket_years','null',          @now);

INSERT INTO schema_migrations (version, description, checksum, applied_at) VALUES
 ('0001', 'Esquema inicial',      REPEAT('0',64), @now),
 ('0002', 'Catálogos iniciales',  REPEAT('0',64), @now);
-- El instalador real sustituirá el checksum por el SHA-256 de cada fichero.
