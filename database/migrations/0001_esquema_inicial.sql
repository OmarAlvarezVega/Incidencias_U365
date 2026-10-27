-- =============================================================================
-- UNIVERS365 Incident Management
-- Migración 0001: esquema inicial (BORRADOR DE FASE 1)
--
-- Destino:   MariaDB 10.6+ (validado en MariaDB 10.11), InnoDB, utf8mb4.
-- Fechas:    todas las DATETIME se guardan en UTC. La conversión a
--            Europe/Madrid se hace en PHP. No se usa TIMESTAMP.
-- Borrado:   la información del historial de soporte NO se borra físicamente.
--            Se usa deleted_at / anonymized_at. Solo las tablas de unión puras
--            usan ON DELETE CASCADE.
-- Seguridad: este script NO contiene DROP. El instalador comprueba antes que la
--            base de datos no tenga tablas de la aplicación.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE';

-- -----------------------------------------------------------------------------
-- Control de migraciones
-- -----------------------------------------------------------------------------
CREATE TABLE schema_migrations (
  version     VARCHAR(32)  NOT NULL,
  description VARCHAR(255) NOT NULL,
  checksum    CHAR(64)     NOT NULL COMMENT 'SHA-256 del fichero aplicado',
  applied_at  DATETIME     NOT NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Roles y permisos
-- -----------------------------------------------------------------------------
CREATE TABLE roles (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40)  NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL,
  user_type   ENUM('staff','client') NOT NULL DEFAULT 'staff',
  is_system   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Los roles de sistema no se pueden borrar',
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(80)  NOT NULL COMMENT 'p. ej. tickets.view.all',
  module      VARCHAR(40)  NOT NULL,
  description VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id       SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Usuarios (empleados y, en el futuro, contactos de cliente del portal)
-- -----------------------------------------------------------------------------
CREATE TABLE users (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_type           ENUM('staff','client') NOT NULL DEFAULT 'staff',
  username            VARCHAR(60)  NULL,
  email               VARCHAR(254) NOT NULL,
  email_normalized    VARCHAR(254) NOT NULL COMMENT 'minúsculas y sin espacios',
  password_hash       VARCHAR(255) NOT NULL COMMENT 'password_hash(): Argon2id o bcrypt',
  role_id             SMALLINT UNSIGNED NOT NULL,
  client_contact_id   INT UNSIGNED NULL COMMENT 'Solo usuarios del portal de clientes',
  status              ENUM('active','blocked','disabled') NOT NULL DEFAULT 'active',
  session_version     INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Se incrementa para revocar todas las sesiones',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  password_changed_at DATETIME NULL,
  mfa_enabled         TINYINT(1) NOT NULL DEFAULT 0,
  mfa_secret_enc      VARBINARY(255) NULL COMMENT 'Cifrado con sodium, fase futura',
  failed_login_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until        DATETIME NULL,
  last_login_at       DATETIME NULL,
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NOT NULL,
  created_by          INT UNSIGNED NULL,
  deleted_at          DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email_normalized),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_contact (client_contact_id),
  KEY ix_users_role (role_id),
  KEY ix_users_status (status),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id),
  CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_users_type CHECK (
    (user_type = 'staff'  AND client_contact_id IS NULL) OR
    (user_type = 'client' AND client_contact_id IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_permissions (
  user_id       INT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  effect        ENUM('allow','deny') NOT NULL COMMENT 'deny prevalece sobre el rol',
  granted_by    INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (user_id, permission_id),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_up_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE,
  CONSTRAINT fk_up_granted FOREIGN KEY (granted_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
  id              CHAR(64)     NOT NULL COMMENT 'SHA-256 del identificador de sesión, nunca el id en claro',
  user_id         INT UNSIGNED NOT NULL,
  session_version INT UNSIGNED NOT NULL,
  ip              VARBINARY(16) NULL,
  user_agent      VARCHAR(255) NULL,
  created_at      DATETIME NOT NULL,
  last_seen_at    DATETIME NOT NULL,
  revoked_at      DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_sessions_user (user_id, revoked_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier_hash CHAR(64)      NOT NULL COMMENT 'SHA-256 del usuario/correo introducido, para no guardar intentos en claro',
  ip              VARBINARY(16) NOT NULL,
  succeeded       TINYINT(1)    NOT NULL,
  attempted_at    DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY ix_la_identifier (identifier_hash, attempted_at),
  KEY ix_la_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64)     NOT NULL COMMENT 'SHA-256 del token; el token solo viaja en el correo',
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  requested_ip VARBINARY(16) NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pr_token (token_hash),
  KEY ix_pr_user (user_id, created_at),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Límites de frecuencia genéricos (login, recuperación, webhook, exportaciones)
CREATE TABLE rate_limits (
  bucket       VARCHAR(191) NOT NULL COMMENT 'p. ej. login:ip:<hash>',
  hits         INT UNSIGNED NOT NULL,
  window_start DATETIME     NOT NULL,
  PRIMARY KEY (bucket),
  KEY ix_rl_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Equipos (definen el "ámbito" del coordinador) y empleados
-- -----------------------------------------------------------------------------
CREATE TABLE teams (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_teams_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_members (
  team_id    SMALLINT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  is_lead    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Coordinador del equipo',
  created_at DATETIME   NOT NULL,
  PRIMARY KEY (team_id, user_id),
  KEY ix_tm_user (user_id),
  CONSTRAINT fk_tm_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
  CONSTRAINT fk_tm_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employees (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  first_name      VARCHAR(80)  NOT NULL,
  last_name       VARCHAR(120) NOT NULL,
  corporate_phone VARCHAR(30)  NULL,
  hired_on        DATE         NULL,
  left_on         DATE         NULL,
  work_schedule   JSON         NULL COMMENT 'Horario opcional por día de la semana',
  max_open_tickets SMALLINT UNSIGNED NULL COMMENT 'Umbral de carga para avisos, opcional',
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  created_by      INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employees_user (user_id),
  KEY ix_employees_name (last_name, first_name),
  CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_employees_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_employees_schedule CHECK (work_schedule IS NULL OR JSON_VALID(work_schedule))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE specialties (
  id     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name   VARCHAR(80) NOT NULL,
  active TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_specialties_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employee_specialties (
  employee_id  INT UNSIGNED NOT NULL,
  specialty_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (employee_id, specialty_id),
  CONSTRAINT fk_es_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT fk_es_specialty FOREIGN KEY (specialty_id) REFERENCES specialties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- SLA: calendarios y políticas (se crean antes de clients por la FK)
-- -----------------------------------------------------------------------------
CREATE TABLE business_calendars (
  id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(80) NOT NULL,
  timezone   VARCHAR(64) NOT NULL DEFAULT 'Europe/Madrid',
  active     TINYINT(1)  NOT NULL DEFAULT 1,
  created_at DATETIME    NOT NULL,
  updated_at DATETIME    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bc_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE business_hours (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  calendar_id SMALLINT UNSIGNED NOT NULL,
  weekday     TINYINT UNSIGNED NOT NULL COMMENT '1 = lunes ... 7 = domingo (ISO-8601)',
  start_time  TIME NOT NULL COMMENT 'Hora local del calendario',
  end_time    TIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_bh_calendar (calendar_id, weekday),
  CONSTRAINT fk_bh_calendar FOREIGN KEY (calendar_id) REFERENCES business_calendars (id) ON DELETE CASCADE,
  CONSTRAINT ck_bh_weekday CHECK (weekday BETWEEN 1 AND 7),
  CONSTRAINT ck_bh_range CHECK (end_time > start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE calendar_holidays (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  calendar_id  SMALLINT UNSIGNED NOT NULL,
  holiday_date DATE NOT NULL,
  name         VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ch_day (calendar_id, holiday_date),
  CONSTRAINT fk_ch_calendar FOREIGN KEY (calendar_id) REFERENCES business_calendars (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sla_policies (
  id                 SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name               VARCHAR(80) NOT NULL,
  calendar_id        SMALLINT UNSIGNED NOT NULL,
  is_default         TINYINT(1) NOT NULL DEFAULT 0,
  pause_when_pending TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Pausa el reloj en estados con pauses_sla = 1',
  active             TINYINT(1) NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sla_name (name),
  CONSTRAINT fk_sla_calendar FOREIGN KEY (calendar_id) REFERENCES business_calendars (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Clientes, direcciones, contactos e identidades (correo / teléfono)
-- -----------------------------------------------------------------------------
CREATE TABLE clients (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              VARCHAR(20)  NOT NULL COMMENT 'Código interno, p. ej. CLI-00042',
  client_type       ENUM('company','individual') NOT NULL,
  legal_name        VARCHAR(200) NOT NULL COMMENT 'Razón social o nombre y apellidos',
  trade_name        VARCHAR(200) NULL,
  tax_id            VARCHAR(20)  NULL COMMENT 'NIF/CIF tal como se introdujo',
  tax_id_normalized VARCHAR(20)  NULL COMMENT 'Sin espacios ni guiones, mayúsculas',
  phone             VARCHAR(30)  NULL,
  email             VARCHAR(254) NULL,
  internal_notes    TEXT         NULL,
  sla_policy_id     SMALLINT UNSIGNED NULL COMMENT 'Excepción de SLA por cliente',
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  registered_on     DATE         NOT NULL,
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NOT NULL,
  created_by        INT UNSIGNED NULL,
  deleted_at        DATETIME     NULL,
  anonymized_at     DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clients_code (code),
  UNIQUE KEY uq_clients_taxid (tax_id_normalized),
  KEY ix_clients_name (legal_name),
  KEY ix_clients_trade (trade_name),
  KEY ix_clients_status (status),
  CONSTRAINT fk_clients_sla FOREIGN KEY (sla_policy_id) REFERENCES sla_policies (id),
  CONSTRAINT fk_clients_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_addresses (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id    INT UNSIGNED NOT NULL,
  label        VARCHAR(80)  NOT NULL COMMENT 'Sede central, Delegación Valencia...',
  address_line VARCHAR(200) NOT NULL,
  postal_code  VARCHAR(10)  NULL,
  city         VARCHAR(100) NULL,
  province     VARCHAR(100) NULL,
  country_code CHAR(2)      NOT NULL DEFAULT 'ES',
  is_primary   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL,
  deleted_at   DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_ca_client (client_id),
  CONSTRAINT fk_ca_client FOREIGN KEY (client_id) REFERENCES clients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_contacts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id     INT UNSIGNED NOT NULL,
  address_id    INT UNSIGNED NULL,
  first_name    VARCHAR(80)  NOT NULL,
  last_name     VARCHAR(120) NULL,
  job_title     VARCHAR(100) NULL COMMENT 'Gerencia, Administración, Responsable IT...',
  notes         TEXT         NULL,
  is_primary    TINYINT(1)   NOT NULL DEFAULT 0,
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  created_by    INT UNSIGNED NULL,
  deleted_at    DATETIME     NULL,
  anonymized_at DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_cc_client (client_id, active),
  KEY ix_cc_name (last_name, first_name),
  CONSTRAINT fk_cc_client FOREIGN KEY (client_id) REFERENCES clients (id),
  CONSTRAINT fk_cc_address FOREIGN KEY (address_id) REFERENCES client_addresses (id),
  CONSTRAINT fk_cc_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Una dirección de correo o un teléfono identifican a un único contacto.
-- Es la tabla que usa la asociación automática de correos y WhatsApp.
CREATE TABLE contact_identities (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contact_id       INT UNSIGNED NOT NULL,
  kind             ENUM('email','phone') NOT NULL,
  value            VARCHAR(254) NOT NULL COMMENT 'Tal como se introdujo',
  value_normalized VARCHAR(254) NOT NULL COMMENT 'Correo en minúsculas / teléfono E.164 sin +',
  is_primary       TINYINT(1)   NOT NULL DEFAULT 0,
  whatsapp_opt_in_at DATETIME   NULL COMMENT 'Consentimiento para mensajes salientes',
  verified_at      DATETIME     NULL,
  created_at       DATETIME     NOT NULL,
  updated_at       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ci_value (kind, value_normalized),
  KEY ix_ci_contact (contact_id),
  CONSTRAINT fk_ci_contact FOREIGN KEY (contact_id) REFERENCES client_contacts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dominios corporativos para identificar al CLIENTE (no al contacto) cuando
-- escribe alguien desconocido de @empresa.es. Nunca dominios públicos (gmail...).
CREATE TABLE client_email_domains (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id  INT UNSIGNED NOT NULL,
  domain     VARCHAR(253) NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ced_domain (domain),
  KEY ix_ced_client (client_id),
  CONSTRAINT fk_ced_client FOREIGN KEY (client_id) REFERENCES clients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD CONSTRAINT fk_users_contact FOREIGN KEY (client_contact_id) REFERENCES client_contacts (id);

-- -----------------------------------------------------------------------------
-- Catálogos de incidencias
-- -----------------------------------------------------------------------------
CREATE TABLE ticket_statuses (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40) NOT NULL,
  name        VARCHAR(80) NOT NULL,
  phase       ENUM('new','open','pending','resolved','closed','cancelled') NOT NULL
              COMMENT 'Semántica fija que usa el código; el nombre es editable',
  color       CHAR(7)     NOT NULL,
  pauses_sla  TINYINT(1)  NOT NULL DEFAULT 0,
  is_initial  TINYINT(1)  NOT NULL DEFAULT 0,
  sort_order  SMALLINT    NOT NULL DEFAULT 0,
  active      TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_code (code),
  CONSTRAINT ck_ts_color CHECK (color REGEXP '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Flujo configurable: qué transiciones existen y qué permiso exige cada una
CREATE TABLE ticket_status_transitions (
  from_status_id  SMALLINT UNSIGNED NOT NULL,
  to_status_id    SMALLINT UNSIGNED NOT NULL,
  permission_code VARCHAR(80) NULL COMMENT 'NULL = basta con poder editar la incidencia',
  PRIMARY KEY (from_status_id, to_status_id),
  CONSTRAINT fk_tst_from FOREIGN KEY (from_status_id) REFERENCES ticket_statuses (id) ON DELETE CASCADE,
  CONSTRAINT fk_tst_to FOREIGN KEY (to_status_id) REFERENCES ticket_statuses (id) ON DELETE CASCADE,
  CONSTRAINT ck_tst_distinct CHECK (from_status_id <> to_status_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_priorities (
  id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(40) NOT NULL,
  name       VARCHAR(80) NOT NULL,
  level      TINYINT UNSIGNED NOT NULL COMMENT '1 = baja ... 5 = crítica; ordena y dispara avisos',
  color      CHAR(7)     NOT NULL,
  active     TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tp_code (code),
  UNIQUE KEY uq_tp_level (level),
  CONSTRAINT ck_tp_color CHECK (color REGEXP '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categorías y subcategorías en una sola tabla (parent_id). Máximo 2 niveles,
-- controlado en la aplicación. Sustituye a ticket_subcategories.
CREATE TABLE ticket_categories (
  id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id  SMALLINT UNSIGNED NULL,
  parent_key SMALLINT UNSIGNED AS (IFNULL(parent_id, 0)) PERSISTENT,
  name       VARCHAR(80) NOT NULL,
  sort_order SMALLINT    NOT NULL DEFAULT 0,
  active     TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tc_name (parent_key, name),
  CONSTRAINT fk_tc_parent FOREIGN KEY (parent_id) REFERENCES ticket_categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_channels (
  code   VARCHAR(20) NOT NULL COMMENT 'manual, email, whatsapp, portal, api, monitoring',
  name   VARCHAR(60) NOT NULL,
  active TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Numeración INC-AAAA-NNNNNN sin duplicados bajo concurrencia:
--   INSERT INTO ticket_sequences (year, last_value) VALUES (?, LAST_INSERT_ID(1))
--   ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1);
--   SELECT LAST_INSERT_ID();
-- dentro de la misma transacción que crea la incidencia.
CREATE TABLE ticket_sequences (
  year       SMALLINT UNSIGNED NOT NULL,
  last_value INT UNSIGNED      NOT NULL,
  PRIMARY KEY (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Incidencias
-- -----------------------------------------------------------------------------
CREATE TABLE tickets (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_number           VARCHAR(20)  NOT NULL COMMENT 'INC-2026-000001',
  seq_year                SMALLINT UNSIGNED NOT NULL,
  seq_value               INT UNSIGNED NOT NULL,
  subject                 VARCHAR(255) NOT NULL,
  description             MEDIUMTEXT   NULL,
  client_id               INT UNSIGNED NULL COMMENT 'NULL solo si identification_status = pending',
  contact_id              INT UNSIGNED NULL,
  identification_status   ENUM('identified','pending') NOT NULL DEFAULT 'identified',
  requester_email         VARCHAR(254) NULL COMMENT 'Remitente cuando no está identificado',
  requester_phone         VARCHAR(30)  NULL,
  category_id             SMALLINT UNSIGNED NULL,
  priority_id             SMALLINT UNSIGNED NOT NULL,
  status_id               SMALLINT UNSIGNED NOT NULL,
  channel_code            VARCHAR(20)  NOT NULL,
  team_id                 SMALLINT UNSIGNED NULL,
  owner_user_id           INT UNSIGNED NULL COMMENT 'Responsable principal (único)',
  assignment_mode         ENUM('individual','shared') NOT NULL DEFAULT 'individual',
  due_at                  DATETIME NULL COMMENT 'Fecha límite manual; la de SLA está en ticket_sla',
  first_response_at       DATETIME NULL,
  resolved_at             DATETIME NULL,
  closed_at               DATETIME NULL,
  reopened_count          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_activity_at        DATETIME NOT NULL,
  last_customer_message_at DATETIME NULL,
  total_minutes           INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Caché de ticket_time_entries',
  internal_summary        TEXT NULL COMMENT 'Observaciones internas de cabecera',
  lock_version            INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Bloqueo optimista: evita pisar cambios simultáneos',
  created_by              INT UNSIGNED NULL COMMENT 'NULL = creada por integración',
  created_at              DATETIME NOT NULL,
  updated_at              DATETIME NOT NULL,
  deleted_at              DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tickets_number (ticket_number),
  UNIQUE KEY uq_tickets_seq (seq_year, seq_value),
  KEY ix_tickets_status_prio (status_id, priority_id),
  KEY ix_tickets_owner (owner_user_id, status_id),
  KEY ix_tickets_team (team_id, status_id),
  KEY ix_tickets_client (client_id, created_at),
  KEY ix_tickets_contact (contact_id),
  KEY ix_tickets_category (category_id),
  KEY ix_tickets_channel (channel_code, created_at),
  KEY ix_tickets_created (created_at),
  KEY ix_tickets_due (due_at),
  KEY ix_tickets_activity (last_activity_at),
  KEY ix_tickets_ident (identification_status),
  FULLTEXT KEY ft_tickets_text (subject, description),
  CONSTRAINT fk_tickets_client FOREIGN KEY (client_id) REFERENCES clients (id),
  CONSTRAINT fk_tickets_contact FOREIGN KEY (contact_id) REFERENCES client_contacts (id),
  CONSTRAINT fk_tickets_category FOREIGN KEY (category_id) REFERENCES ticket_categories (id),
  CONSTRAINT fk_tickets_priority FOREIGN KEY (priority_id) REFERENCES ticket_priorities (id),
  CONSTRAINT fk_tickets_status FOREIGN KEY (status_id) REFERENCES ticket_statuses (id),
  CONSTRAINT fk_tickets_channel FOREIGN KEY (channel_code) REFERENCES ticket_channels (code),
  CONSTRAINT fk_tickets_team FOREIGN KEY (team_id) REFERENCES teams (id),
  CONSTRAINT fk_tickets_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
  CONSTRAINT fk_tickets_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_tickets_ident CHECK (identification_status = 'pending' OR client_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colaboradores. El responsable está en tickets.owner_user_id.
-- Quitar a alguien = removed_at, nunca DELETE (se conserva quién participó).
CREATE TABLE ticket_participants (
  ticket_id  BIGINT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  role       ENUM('collaborator','watcher') NOT NULL DEFAULT 'collaborator',
  added_by   INT UNSIGNED NULL,
  added_at   DATETIME NOT NULL,
  removed_at DATETIME NULL,
  PRIMARY KEY (ticket_id, user_id),
  KEY ix_tpart_user (user_id, removed_at),
  CONSTRAINT fk_tpart_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_tpart_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_tpart_added_by FOREIGN KEY (added_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversación con el cliente (siempre visible para el cliente).
CREATE TABLE ticket_messages (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id         BIGINT UNSIGNED NOT NULL,
  direction         ENUM('inbound','outbound') NOT NULL,
  channel_code      VARCHAR(20)  NOT NULL,
  author_user_id    INT UNSIGNED NULL COMMENT 'Empleado que responde',
  author_contact_id INT UNSIGNED NULL COMMENT 'Contacto que escribe',
  sender_label      VARCHAR(254) NULL COMMENT 'Remitente tal como llegó',
  body_text         MEDIUMTEXT   NOT NULL,
  body_html_safe    MEDIUMTEXT   NULL COMMENT 'HTML ya saneado con lista blanca',
  is_automatic      TINYINT(1)   NOT NULL DEFAULT 0,
  delivery_status   ENUM('received','queued','sent','delivered','read','failed') NOT NULL,
  created_at        DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_tm_ticket (ticket_id, created_at),
  CONSTRAINT fk_tmsg_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_tmsg_channel FOREIGN KEY (channel_code) REFERENCES ticket_channels (code),
  CONSTRAINT fk_tmsg_user FOREIGN KEY (author_user_id) REFERENCES users (id),
  CONSTRAINT fk_tmsg_contact FOREIGN KEY (author_contact_id) REFERENCES client_contacts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notas internas y actuaciones técnicas. Una nota interna NUNCA puede ser pública.
CREATE TABLE ticket_activities (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id  BIGINT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  kind       ENUM('internal_note','technical_action') NOT NULL,
  visibility ENUM('internal','public') NOT NULL DEFAULT 'internal',
  body       MEDIUMTEXT NOT NULL,
  created_at DATETIME   NOT NULL,
  updated_at DATETIME   NOT NULL,
  edited_by  INT UNSIGNED NULL,
  deleted_at DATETIME   NULL,
  PRIMARY KEY (id),
  KEY ix_ta_ticket (ticket_id, created_at),
  KEY ix_ta_user (user_id, created_at),
  CONSTRAINT fk_ta_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_ta_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_ta_edited FOREIGN KEY (edited_by) REFERENCES users (id),
  CONSTRAINT ck_ta_note_internal CHECK (kind <> 'internal_note' OR visibility = 'internal')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial inmutable de cambios (estado, prioridad, asignación, campos).
-- Sustituye a ticket_audit_log: la auditoría global está en audit_logs.
CREATE TABLE ticket_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id     BIGINT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NULL COMMENT 'NULL = sistema o integración',
  event_type    VARCHAR(40) NOT NULL COMMENT 'created, status_changed, priority_changed, owner_changed, participant_added...',
  old_value     JSON NULL,
  new_value     JSON NULL,
  created_at    DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_tev_ticket (ticket_id, created_at),
  KEY ix_tev_type (event_type, created_at),
  CONSTRAINT fk_tev_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_tev_actor FOREIGN KEY (actor_user_id) REFERENCES users (id),
  CONSTRAINT ck_tev_old CHECK (old_value IS NULL OR JSON_VALID(old_value)),
  CONSTRAINT ck_tev_new CHECK (new_value IS NULL OR JSON_VALID(new_value))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Se mantiene separada de ticket_events porque el cálculo de SLA (pausas)
-- recorre solo cambios de estado y necesita un índice propio.
CREATE TABLE ticket_status_history (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id      BIGINT UNSIGNED NOT NULL,
  from_status_id SMALLINT UNSIGNED NULL,
  to_status_id   SMALLINT UNSIGNED NOT NULL,
  changed_by     INT UNSIGNED NULL,
  changed_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_tsh_ticket (ticket_id, changed_at),
  CONSTRAINT fk_tsh_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_tsh_from FOREIGN KEY (from_status_id) REFERENCES ticket_statuses (id),
  CONSTRAINT fk_tsh_to FOREIGN KEY (to_status_id) REFERENCES ticket_statuses (id),
  CONSTRAINT fk_tsh_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tiempos. running_user_id impide, a nivel de base de datos, que un mismo
-- usuario tenga dos temporizadores activos a la vez (aunque haya doble clic
-- o dos pestañas). Si algún día se permite, se cambia en una migración.
CREATE TABLE ticket_time_entries (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id       BIGINT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  source          ENUM('timer','manual') NOT NULL,
  started_at      DATETIME NULL,
  ended_at        DATETIME NULL,
  minutes         INT UNSIGNED NULL COMMENT 'Se calcula al parar el temporizador o se introduce a mano',
  work_date       DATE NOT NULL COMMENT 'Día de trabajo en Europe/Madrid',
  description     VARCHAR(1000) NULL,
  billable        TINYINT(1) NOT NULL DEFAULT 1,
  rate_eur        DECIMAL(10,2) NULL COMMENT 'Tarifa congelada al registrar (preparado, sin facturación)',
  running_user_id INT UNSIGNED AS (IF(source = 'timer' AND ended_at IS NULL AND deleted_at IS NULL, user_id, NULL)) PERSISTENT,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  updated_by      INT UNSIGNED NULL,
  deleted_at      DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tte_one_running (running_user_id),
  KEY ix_tte_ticket (ticket_id),
  KEY ix_tte_user_date (user_id, work_date),
  CONSTRAINT fk_tte_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_tte_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_tte_updated_by FOREIGN KEY (updated_by) REFERENCES users (id),
  CONSTRAINT ck_tte_shape CHECK (
    (source = 'manual' AND minutes IS NOT NULL AND minutes > 0) OR
    (source = 'timer'  AND started_at IS NOT NULL AND (ended_at IS NULL OR ended_at >= started_at))
  ),
  CONSTRAINT ck_tte_max CHECK (minutes IS NULL OR minutes <= 1440)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_attachments (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id           BIGINT UNSIGNED NOT NULL,
  message_id          BIGINT UNSIGNED NULL,
  activity_id         BIGINT UNSIGNED NULL,
  uploaded_by_user_id INT UNSIGNED NULL COMMENT 'NULL = llegó por correo o WhatsApp',
  original_name       VARCHAR(255) NOT NULL COMMENT 'Solo para mostrar; nunca se usa como ruta',
  storage_key         CHAR(64)     NOT NULL COMMENT 'Nombre aleatorio en disco, sin extensión',
  mime_type           VARCHAR(127) NOT NULL COMMENT 'Detectado con finfo, no el declarado',
  size_bytes          INT UNSIGNED NOT NULL,
  sha256              CHAR(64)     NOT NULL,
  visibility          ENUM('internal','public') NOT NULL DEFAULT 'internal',
  status              ENUM('stored','rejected','quarantined') NOT NULL DEFAULT 'stored',
  created_at          DATETIME     NOT NULL,
  deleted_at          DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tatt_key (storage_key),
  KEY ix_tatt_ticket (ticket_id),
  KEY ix_tatt_sha (sha256),
  CONSTRAINT fk_tatt_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_tatt_message FOREIGN KEY (message_id) REFERENCES ticket_messages (id),
  CONSTRAINT fk_tatt_activity FOREIGN KEY (activity_id) REFERENCES ticket_activities (id),
  CONSTRAINT fk_tatt_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_tags (
  id    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name  VARCHAR(50) NOT NULL,
  color CHAR(7)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tt_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_tag_relations (
  ticket_id BIGINT UNSIGNED  NOT NULL,
  tag_id    SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (ticket_id, tag_id),
  KEY ix_ttr_tag (tag_id),
  CONSTRAINT fk_ttr_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
  CONSTRAINT fk_ttr_tag FOREIGN KEY (tag_id) REFERENCES ticket_tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_relations (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id         BIGINT UNSIGNED NOT NULL,
  related_ticket_id BIGINT UNSIGNED NOT NULL,
  relation_type     ENUM('related','duplicate_of','parent_of','blocks') NOT NULL,
  created_by        INT UNSIGNED NOT NULL,
  created_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trel (ticket_id, related_ticket_id, relation_type),
  KEY ix_trel_related (related_ticket_id),
  CONSTRAINT fk_trel_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_trel_related FOREIGN KEY (related_ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_trel_user FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_trel_self CHECK (ticket_id <> related_ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- SLA por prioridad y estado de SLA de cada incidencia
-- -----------------------------------------------------------------------------
CREATE TABLE sla_policy_targets (
  id                     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  policy_id              SMALLINT UNSIGNED NOT NULL,
  priority_id            SMALLINT UNSIGNED NOT NULL,
  first_response_minutes INT UNSIGNED NOT NULL,
  resolution_minutes     INT UNSIGNED NULL,
  time_mode              ENUM('calendar','business') NOT NULL COMMENT 'natural o laborable',
  warn_at_percent        TINYINT UNSIGNED NOT NULL DEFAULT 80,
  PRIMARY KEY (id),
  UNIQUE KEY uq_spt (policy_id, priority_id),
  CONSTRAINT fk_spt_policy FOREIGN KEY (policy_id) REFERENCES sla_policies (id) ON DELETE CASCADE,
  CONSTRAINT fk_spt_priority FOREIGN KEY (priority_id) REFERENCES ticket_priorities (id),
  CONSTRAINT ck_spt_warn CHECK (warn_at_percent BETWEEN 1 AND 99)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_sla (
  ticket_id                  BIGINT UNSIGNED NOT NULL,
  policy_id                  SMALLINT UNSIGNED NOT NULL,
  time_mode                  ENUM('calendar','business') NOT NULL COMMENT 'Copia del objetivo al aplicarlo',
  first_response_due_at      DATETIME NULL,
  resolution_due_at          DATETIME NULL,
  first_response_warned_at   DATETIME NULL,
  resolution_warned_at       DATETIME NULL,
  first_response_breached_at DATETIME NULL,
  resolution_breached_at     DATETIME NULL,
  paused_since               DATETIME NULL,
  paused_minutes_total       INT UNSIGNED NOT NULL DEFAULT 0,
  last_computed_at           DATETIME NOT NULL,
  PRIMARY KEY (ticket_id),
  KEY ix_tsla_fr_due (first_response_due_at),
  KEY ix_tsla_res_due (resolution_due_at),
  CONSTRAINT fk_tsla_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_tsla_policy FOREIGN KEY (policy_id) REFERENCES sla_policies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Correo electrónico
-- -----------------------------------------------------------------------------
CREATE TABLE email_accounts (
  id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(80)  NOT NULL,
  address               VARCHAR(254) NOT NULL COMMENT 'soporte@univers365.com',
  auth_type             ENUM('password','oauth2_microsoft','oauth2_google') NOT NULL DEFAULT 'password',
  imap_host             VARCHAR(253) NOT NULL,
  imap_port             SMALLINT UNSIGNED NOT NULL DEFAULT 993,
  imap_encryption       ENUM('ssl','starttls') NOT NULL DEFAULT 'ssl',
  imap_username         VARCHAR(254) NOT NULL,
  imap_secret_enc       VARBINARY(4096) NULL COMMENT 'Contraseña o refresh token cifrado (sodium)',
  imap_folder           VARCHAR(120) NOT NULL DEFAULT 'INBOX',
  processed_folder      VARCHAR(120) NULL COMMENT 'Carpeta a la que se mueven los procesados',
  smtp_host             VARCHAR(253) NOT NULL,
  smtp_port             SMALLINT UNSIGNED NOT NULL DEFAULT 465,
  smtp_encryption       ENUM('ssl','starttls') NOT NULL DEFAULT 'ssl',
  smtp_username         VARCHAR(254) NOT NULL,
  smtp_secret_enc       VARBINARY(4096) NULL,
  from_name             VARCHAR(120) NOT NULL DEFAULT 'Soporte UNIVERS365',
  unknown_sender_policy ENUM('review','create_pending') NOT NULL DEFAULT 'review',
  poll_interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  max_attachment_mb     SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  last_uidvalidity      BIGINT UNSIGNED NULL,
  last_uid              BIGINT UNSIGNED NULL,
  last_polled_at        DATETIME NULL,
  last_error            VARCHAR(1000) NULL COMMENT 'Sin credenciales: se sanea antes de guardar',
  active                TINYINT(1) NOT NULL DEFAULT 0,
  created_at            DATETIME NOT NULL,
  updated_at            DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ea_address (address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_messages (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id        SMALLINT UNSIGNED NOT NULL,
  direction         ENUM('inbound','outbound') NOT NULL,
  ticket_id         BIGINT UNSIGNED NULL,
  ticket_message_id BIGINT UNSIGNED NULL,
  message_id        VARCHAR(998) NOT NULL COMMENT 'Cabecera Message-ID',
  message_id_hash   CHAR(64)     NOT NULL COMMENT 'SHA-256 de message_id normalizado',
  in_reply_to       VARCHAR(998) NULL,
  references_header TEXT         NULL,
  imap_uidvalidity  BIGINT UNSIGNED NULL,
  imap_uid          BIGINT UNSIGNED NULL,
  from_address      VARCHAR(254) NOT NULL,
  to_addresses      JSON         NULL,
  subject           VARCHAR(998) NULL,
  sent_at           DATETIME     NULL COMMENT 'Cabecera Date (orientativa)',
  received_at       DATETIME     NOT NULL,
  raw_storage_key   CHAR(64)     NULL COMMENT '.eml original en storage/mail-raw',
  raw_sha256        CHAR(64)     NULL,
  is_auto_submitted TINYINT(1)   NOT NULL DEFAULT 0,
  is_bounce         TINYINT(1)   NOT NULL DEFAULT 0,
  processing_status ENUM('pending','processed','review','ignored','failed') NOT NULL DEFAULT 'pending',
  match_method      ENUM('headers','subject_tag','new_ticket','review','none') NULL,
  error             VARCHAR(1000) NULL,
  created_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_em_msgid (account_id, direction, message_id_hash),
  UNIQUE KEY uq_em_uid (account_id, imap_uidvalidity, imap_uid),
  KEY ix_em_hash (message_id_hash),
  KEY ix_em_ticket (ticket_id),
  KEY ix_em_status (processing_status, received_at),
  CONSTRAINT fk_em_account FOREIGN KEY (account_id) REFERENCES email_accounts (id),
  CONSTRAINT fk_em_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_em_tmsg FOREIGN KEY (ticket_message_id) REFERENCES ticket_messages (id),
  CONSTRAINT ck_em_to CHECK (to_addresses IS NULL OR JSON_VALID(to_addresses))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_processing_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id SMALLINT UNSIGNED NOT NULL,
  run_id     CHAR(26)    NOT NULL COMMENT 'Identificador de la ejecución del cron',
  level      ENUM('info','warning','error') NOT NULL,
  message    VARCHAR(1000) NOT NULL,
  email_message_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_epl_account (account_id, created_at),
  KEY ix_epl_level (level, created_at),
  CONSTRAINT fk_epl_account FOREIGN KEY (account_id) REFERENCES email_accounts (id),
  CONSTRAINT fk_epl_msg FOREIGN KEY (email_message_id) REFERENCES email_messages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_templates (
  id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(60)  NOT NULL COMMENT 'ticket_created_ack, reply, resolved...',
  channel    ENUM('email','whatsapp') NOT NULL,
  subject    VARCHAR(255) NULL,
  body       MEDIUMTEXT   NOT NULL COMMENT 'Twig en sandbox con variables en lista blanca',
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mt (code, channel),
  CONSTRAINT fk_mt_user FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- WhatsApp Business Cloud API
-- -----------------------------------------------------------------------------
CREATE TABLE whatsapp_numbers (
  id               SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone_number_id  VARCHAR(40)  NOT NULL COMMENT 'Phone Number ID de Meta',
  waba_id          VARCHAR(40)  NOT NULL COMMENT 'WhatsApp Business Account ID',
  display_phone    VARCHAR(30)  NOT NULL,
  access_token_enc VARBINARY(4096) NULL COMMENT 'Token de usuario del sistema, cifrado',
  active           TINYINT(1)   NOT NULL DEFAULT 0,
  created_at       DATETIME     NOT NULL,
  updated_at       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wn_pnid (phone_number_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada POST de Meta se guarda tal cual ANTES de procesarlo.
CREATE TABLE whatsapp_webhook_events (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  received_at       DATETIME(3) NOT NULL,
  signature_valid   TINYINT(1)  NOT NULL,
  payload_sha256    CHAR(64)    NOT NULL,
  payload           MEDIUMTEXT  NOT NULL,
  processing_status ENUM('pending','processed','ignored','failed') NOT NULL DEFAULT 'pending',
  attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  processed_at      DATETIME NULL,
  error             VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wwe_payload (payload_sha256),
  KEY ix_wwe_status (processing_status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_conversations (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  number_id         SMALLINT UNSIGNED NOT NULL,
  wa_id             VARCHAR(20)  NOT NULL COMMENT 'Teléfono del cliente en formato E.164 sin +',
  profile_name      VARCHAR(120) NULL,
  contact_id        INT UNSIGNED NULL,
  active_ticket_id  BIGINT UNSIGNED NULL COMMENT 'Incidencia a la que se adjuntan los mensajes ahora',
  state             ENUM('idle','awaiting_ticket_choice','awaiting_identification') NOT NULL DEFAULT 'idle',
  last_inbound_at   DATETIME NULL COMMENT 'Abre la ventana de 24 h',
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wc (number_id, wa_id),
  KEY ix_wc_contact (contact_id),
  CONSTRAINT fk_wc_number FOREIGN KEY (number_id) REFERENCES whatsapp_numbers (id),
  CONSTRAINT fk_wc_contact FOREIGN KEY (contact_id) REFERENCES client_contacts (id),
  CONSTRAINT fk_wc_ticket FOREIGN KEY (active_ticket_id) REFERENCES tickets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_messages (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id   INT UNSIGNED NOT NULL,
  wamid             VARCHAR(191) NOT NULL COMMENT 'Identificador de mensaje de Meta: evita duplicados',
  direction         ENUM('inbound','outbound') NOT NULL,
  ticket_id         BIGINT UNSIGNED NULL,
  ticket_message_id BIGINT UNSIGNED NULL,
  msg_type          VARCHAR(20)  NOT NULL COMMENT 'text, image, document, audio, template...',
  body_text         TEXT         NULL,
  media_id          VARCHAR(191) NULL,
  attachment_id     BIGINT UNSIGNED NULL,
  template_name     VARCHAR(120) NULL,
  status            ENUM('received','queued','sent','delivered','read','failed') NOT NULL,
  status_updated_at DATETIME NULL,
  error_code        VARCHAR(20)  NULL,
  error_message     VARCHAR(500) NULL,
  sent_by_user_id   INT UNSIGNED NULL,
  meta_timestamp    DATETIME NULL,
  created_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wm_wamid (wamid),
  KEY ix_wm_conv (conversation_id, created_at),
  KEY ix_wm_ticket (ticket_id),
  CONSTRAINT fk_wm_conv FOREIGN KEY (conversation_id) REFERENCES whatsapp_conversations (id),
  CONSTRAINT fk_wm_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_wm_tmsg FOREIGN KEY (ticket_message_id) REFERENCES ticket_messages (id),
  CONSTRAINT fk_wm_att FOREIGN KEY (attachment_id) REFERENCES ticket_attachments (id),
  CONSTRAINT fk_wm_user FOREIGN KEY (sent_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Notificaciones
-- -----------------------------------------------------------------------------
CREATE TABLE notification_preferences (
  user_id    INT UNSIGNED NOT NULL,
  event_code VARCHAR(50)  NOT NULL COMMENT 'ticket.assigned, ticket.customer_reply...',
  channel    ENUM('in_app','email','push') NOT NULL,
  enabled    TINYINT(1)   NOT NULL,
  updated_at DATETIME     NOT NULL,
  PRIMARY KEY (user_id, event_code, channel),
  CONSTRAINT fk_np_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  event_code VARCHAR(50)  NOT NULL,
  ticket_id  BIGINT UNSIGNED NULL,
  title      VARCHAR(200) NOT NULL,
  body       VARCHAR(1000) NULL,
  dedup_key  VARCHAR(191) NOT NULL COMMENT 'Evento + entidad: impide avisos repetidos',
  read_at    DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notif_dedup (user_id, dedup_key),
  KEY ix_notif_unread (user_id, read_at, created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_notif_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_deliveries (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  notification_id BIGINT UNSIGNED NOT NULL,
  channel         ENUM('email','push') NOT NULL,
  status          ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error      VARCHAR(1000) NULL,
  sent_at         DATETIME NULL,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nd (notification_id, channel),
  KEY ix_nd_status (status, created_at),
  CONSTRAINT fk_nd_notif FOREIGN KEY (notification_id) REFERENCES notifications (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Cola de trabajos (sin procesos persistentes: la consume bin/cron.php)
-- -----------------------------------------------------------------------------
CREATE TABLE background_jobs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue        VARCHAR(32)  NOT NULL DEFAULT 'default',
  job_type     VARCHAR(64)  NOT NULL,
  payload      JSON         NOT NULL COMMENT 'Solo identificadores, nunca secretos',
  unique_key   VARCHAR(191) NULL COMMENT 'Idempotencia: el mismo trabajo no se encola dos veces',
  status       ENUM('pending','running','done','failed','dead') NOT NULL DEFAULT 'pending',
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  available_at DATETIME NOT NULL,
  reserved_at  DATETIME NULL,
  reserved_by  CHAR(26) NULL,
  last_error   VARCHAR(2000) NULL,
  created_at   DATETIME NOT NULL,
  finished_at  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bj_unique (unique_key),
  KEY ix_bj_pick (status, queue, available_at),
  CONSTRAINT ck_bj_payload CHECK (JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scheduled_task_runs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id      CHAR(26)    NOT NULL,
  task        VARCHAR(64) NOT NULL COMMENT 'email.poll, jobs.process, sla.check, backup...',
  trigger_src ENUM('cron','http','manual') NOT NULL,
  started_at  DATETIME(3) NOT NULL,
  finished_at DATETIME(3) NULL,
  status      ENUM('running','ok','error','skipped_locked') NOT NULL,
  items       INT UNSIGNED NOT NULL DEFAULT 0,
  error       VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  KEY ix_str_task (task, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Configuración, tarifas (preparado), RGPD, copias y auditoría
-- -----------------------------------------------------------------------------
CREATE TABLE application_settings (
  setting_key  VARCHAR(100) NOT NULL,
  value_json   JSON NULL,
  value_secret VARBINARY(4096) NULL COMMENT 'Valores secretos cifrados; nunca se devuelven a la interfaz',
  updated_by   INT UNSIGNED NULL,
  updated_at   DATETIME NOT NULL,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_as_user FOREIGN KEY (updated_by) REFERENCES users (id),
  CONSTRAINT ck_as_json CHECK (value_json IS NULL OR JSON_VALID(value_json)),
  CONSTRAINT ck_as_one CHECK (value_json IS NULL OR value_secret IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE billing_rates (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope      ENUM('default','client','employee','category') NOT NULL,
  scope_id   INT UNSIGNED NULL,
  rate_eur   DECIMAL(10,2) NOT NULL,
  valid_from DATE NOT NULL,
  valid_to   DATE NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_br_scope (scope, scope_id, valid_from),
  CONSTRAINT fk_br_user FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_br_rate CHECK (rate_eur >= 0),
  CONSTRAINT ck_br_scope CHECK ((scope = 'default') = (scope_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE data_subject_requests (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_type       ENUM('access','rectification','erasure','restriction','portability','objection') NOT NULL,
  contact_id         INT UNSIGNED NULL,
  subject_reference  VARCHAR(254) NOT NULL COMMENT 'Cómo se identificó al interesado',
  received_at        DATETIME NOT NULL,
  due_at             DATETIME NOT NULL COMMENT 'Plazo legal (1 mes, ampliable)',
  status             ENUM('received','in_progress','completed','rejected') NOT NULL DEFAULT 'received',
  handled_by         INT UNSIGNED NULL,
  resolution_notes   TEXT NULL,
  closed_at          DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_dsr_status (status, due_at),
  CONSTRAINT fk_dsr_contact FOREIGN KEY (contact_id) REFERENCES client_contacts (id),
  CONSTRAINT fk_dsr_user FOREIGN KEY (handled_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE backup_runs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind        ENUM('database','files','full') NOT NULL,
  started_at  DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status      ENUM('running','ok','error') NOT NULL,
  file_name   VARCHAR(255) NULL,
  size_bytes  BIGINT UNSIGNED NULL,
  sha256      CHAR(64) NULL,
  verified_at DATETIME NULL COMMENT 'Última comprobación de integridad',
  error       VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  KEY ix_backup_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoría global. Solo INSERT desde la aplicación. Un proceso de sellado
-- encadena las filas en orden (chain_seq) guardando el hash de la anterior:
-- un borrado o una modificación posterior rompe la cadena y se detecta.
-- El sellado es posterior a la inserción para que las transacciones
-- concurrentes no generen dos filas con el mismo predecesor.
CREATE TABLE audit_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occurred_at   DATETIME(6) NOT NULL,
  actor_type    ENUM('user','system','integration','anonymous') NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  action        VARCHAR(64)  NOT NULL COMMENT 'auth.login_failed, user.permission_changed, ticket.exported...',
  entity_type   VARCHAR(40)  NULL,
  entity_id     VARCHAR(40)  NULL,
  ip            VARBINARY(16) NULL,
  details       JSON NULL COMMENT 'Nunca contraseñas, tokens ni secretos',
  chain_seq     BIGINT UNSIGNED NULL COMMENT 'Orden en la cadena; NULL = aún sin sellar',
  prev_hash     CHAR(64) NULL,
  row_hash      CHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_al_chain (chain_seq),
  KEY ix_al_entity (entity_type, entity_id),
  KEY ix_al_actor (actor_user_id, occurred_at),
  KEY ix_al_action (action, occurred_at),
  CONSTRAINT fk_al_user FOREIGN KEY (actor_user_id) REFERENCES users (id),
  CONSTRAINT ck_al_details CHECK (details IS NULL OR JSON_VALID(details))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
