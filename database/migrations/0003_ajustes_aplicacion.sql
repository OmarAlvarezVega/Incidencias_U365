-- =============================================================================
-- Migración 0003: ajustes necesarios para la aplicación v1
--  - Microsoft 365 OAuth2 (flujo de credenciales de cliente): inquilino e id de
--    aplicación por buzón. El secreto va cifrado en imap_secret_enc.
--  - Buzón predeterminado para envíos que no vienen de un correo.
--  - Ajustes iniciales que la aplicación usa.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

ALTER TABLE email_accounts
  ADD COLUMN oauth_tenant_id VARCHAR(64) NULL AFTER auth_type,
  ADD COLUMN oauth_client_id VARCHAR(64) NULL AFTER oauth_tenant_id,
  ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER active;

INSERT IGNORE INTO application_settings (setting_key, value_json, updated_at) VALUES
 ('company.name',                 '"UNIVERS365 TECHNOLOGY"', UTC_TIMESTAMP()),
 ('tickets.default_priority',     '"normal"',                UTC_TIMESTAMP()),
 ('tickets.auto_close_days',      '7',                       UTC_TIMESTAMP()),
 ('tickets.ack_email',            'true',                    UTC_TIMESTAMP()),
 ('notifications.email_enabled',  'true',                    UTC_TIMESTAMP()),
 ('workload.default_capacity',    '15',                      UTC_TIMESTAMP()),
 ('backups.keep',                 '14',                      UTC_TIMESTAMP()),
 ('backups.include_files',        'true',                    UTC_TIMESTAMP()),
 ('security.reset_minutes',       '30',                      UTC_TIMESTAMP());

-- Formatos habituales de WhatsApp (notas de voz, vídeo, fotos de iPhone) y Office antiguo
UPDATE application_settings
   SET value_json = '["pdf","png","jpg","jpeg","gif","webp","heic","txt","log","csv","xlsx","xls","docx","doc","pptx","zip","eml","msg","ogg","opus","mp3","mp4"]',
       updated_at = UTC_TIMESTAMP()
 WHERE setting_key = 'uploads.allowed_extensions';

-- Relaciones creadas por el sistema (respuesta a una incidencia ya cerrada)
ALTER TABLE ticket_relations MODIFY created_by INT UNSIGNED NULL;
