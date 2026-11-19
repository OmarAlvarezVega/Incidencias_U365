-- =============================================================================
-- Migración 0004 (versión 1.1.0)
--  - Sesión recordada en un dispositivo («mantener la sesión iniciada»).
--  - La interfaz pasa a llamar «ticket» a lo que antes era «incidencia»:
--    se actualizan las descripciones de roles y permisos solo si siguen con el
--    texto de fábrica (no se pisa lo que haya cambiado un administrador).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- Un token por dispositivo. La cookie lleva «selector.validador»; aquí solo se
-- guarda el SHA-256 del validador. session_version permite revocarlos todos a
-- la vez (desactivación, bloqueo, cambio de contraseña).
CREATE TABLE remember_tokens (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  selector        CHAR(24)     NOT NULL,
  validator_hash  CHAR(64)     NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  session_version INT UNSIGNED NOT NULL,
  ip              VARBINARY(16) NULL,
  user_agent      VARCHAR(255) NULL,
  created_at      DATETIME     NOT NULL,
  last_used_at    DATETIME     NOT NULL,
  expires_at      DATETIME     NOT NULL,
  revoked_at      DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_remember_selector (selector),
  KEY ix_remember_user (user_id, revoked_at),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO application_settings (setting_key, value_json, updated_at) VALUES
 ('security.remember_days', '180', UTC_TIMESTAMP());

UPDATE roles SET description = 'Gestiona los tickets y la carga de sus equipos' WHERE code = 'coordinator' AND description = 'Gestiona las incidencias y la carga de sus equipos';
UPDATE roles SET description = 'Trabaja los tickets asignados o compartidos' WHERE code = 'technician' AND description = 'Trabaja las incidencias asignadas o compartidas';
UPDATE roles SET description = 'Consulta sus propios tickets. Fase posterior' WHERE code = 'client' AND description = 'Consulta sus propias incidencias. Fase posterior';

UPDATE permissions SET description = 'Ver todos los tickets' WHERE code = 'tickets.view.all' AND description = 'Ver todas las incidencias';
UPDATE permissions SET description = 'Ver los tickets de sus equipos' WHERE code = 'tickets.view.team' AND description = 'Ver las incidencias de sus equipos';
UPDATE permissions SET description = 'Ver los tickets de los que es responsable o participante' WHERE code = 'tickets.view.own' AND description = 'Ver las incidencias de las que es responsable o participante';
UPDATE permissions SET description = 'Ver la bandeja de tickets sin asignar' WHERE code = 'tickets.view.unassigned' AND description = 'Ver la bandeja de incidencias sin asignar';
UPDATE permissions SET description = 'Crear tickets' WHERE code = 'tickets.create' AND description = 'Crear incidencias';
UPDATE permissions SET description = 'Editar datos de los tickets que puede ver' WHERE code = 'tickets.edit' AND description = 'Editar datos de las incidencias que puede ver';
UPDATE permissions SET description = 'Cambiar el responsable de un ticket ya asignado' WHERE code = 'tickets.reassign' AND description = 'Cambiar el responsable de una incidencia ya asignada';
UPDATE permissions SET description = 'Reabrir tickets resueltos, cerrados o cancelados' WHERE code = 'tickets.reopen' AND description = 'Reabrir incidencias resueltas, cerradas o canceladas';
UPDATE permissions SET description = 'Eliminación lógica de tickets' WHERE code = 'tickets.delete' AND description = 'Eliminación lógica de incidencias';
UPDATE permissions SET description = 'Exportar tickets autorizados' WHERE code = 'tickets.export' AND description = 'Exportar incidencias autorizadas';
UPDATE permissions SET description = 'Cliente: ver sus tickets' WHERE code = 'portal.tickets.view_own' AND description = 'Cliente: ver sus incidencias';
UPDATE permissions SET description = 'Cliente: abrir tickets' WHERE code = 'portal.tickets.create' AND description = 'Cliente: abrir incidencias';
UPDATE permissions SET description = 'Cliente: responder en sus tickets' WHERE code = 'portal.tickets.reply' AND description = 'Cliente: responder en sus incidencias';

-- Estados: los nombres de fábrica concordaban con «incidencia» (femenino)
UPDATE ticket_statuses SET name = 'Nuevo'    WHERE code = 'new'       AND name = 'Nueva';
UPDATE ticket_statuses SET name = 'Asignado' WHERE code = 'assigned'  AND name = 'Asignada';
UPDATE ticket_statuses SET name = 'Resuelto' WHERE code = 'resolved'  AND name = 'Resuelta';
UPDATE ticket_statuses SET name = 'Cerrado'  WHERE code = 'closed'    AND name = 'Cerrada';
UPDATE ticket_statuses SET name = 'Cancelado' WHERE code = 'cancelled' AND name = 'Cancelada';
