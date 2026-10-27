# Registro de decisiones técnicas

Cada decisión indica su estado: **Aceptada**, **Provisional** (aplicada por
defecto, pendiente de confirmar) o **Abierta** (no se puede cerrar sin datos).

| Nº | Fecha | Decisión | Estado | Motivo |
| --- | --- | --- | --- | --- |
| 001 | 27/10/2026 | Monolito PHP 8.3 con micro-arquitectura propia (MVC ligero), sin Laravel | Provisional | Despliegue por SFTP sin paso de compilación, sin dependencia de cron por minuto. Se reevalúa si IONOS ofrece SSH, Composer y cron por minuto |
| 002 | 27/10/2026 | Dependencias: twig/twig, phpmailer/phpmailer, zbateson/mail-mime-parser; webklex/php-imap solo si falta ext-imap; phpunit solo en desarrollo | Provisional | Autoescape contra XSS, SMTP con TLS/XOAUTH2, parser MIME en PHP puro. ext-imap salió del núcleo en PHP 8.4 |
| 003 | 27/10/2026 | Todas las fechas en BD como DATETIME en UTC; conversión a Europe/Madrid en PHP | Aceptada | Las tablas de zonas horarias de MariaDB no suelen estar cargadas en hosting compartido; evita la conversión implícita de TIMESTAMP |
| 004 | 27/10/2026 | Numeración INC-AAAA-NNNNNN con fila por año en ticket_sequences y LAST_INSERT_ID(expr) dentro de la transacción de alta | Aceptada | Probado con 8 procesos y 800 altas concurrentes sin duplicados ni huecos |
| 005 | 27/10/2026 | Un temporizador activo por usuario, impuesto por índice único sobre columna generada | Aceptada | Evita duplicados por doble clic o varias pestañas a nivel de BD |
| 006 | 27/10/2026 | Conversación con el cliente (ticket_messages) y notas internas (ticket_activities) en tablas separadas, con CHECK que impide notas internas públicas | Aceptada | Un filtro olvidado en el portal no puede exponer notas internas |
| 007 | 27/10/2026 | Categorías y subcategorías en una sola tabla con parent_id | Aceptada | Elimina la tabla redundante ticket_subcategories |
| 008 | 27/10/2026 | Responsable único en tickets.owner_user_id; colaboradores en ticket_participants; individual y compartida comparten modelo (assignment_mode) | Aceptada | Estado único por incidencia, sin duplicar flujos |
| 009 | 27/10/2026 | Auditoría a prueba de manipulación detectable (cadena de hashes), no inmodificable | Aceptada | En hosting compartido no hay usuarios de BD con permisos granulares |
| 010 | 27/10/2026 | Ámbito del coordinador definido por equipos (teams) | Provisional | Pendiente de confirmar por Omar |
| 011 | 27/10/2026 | Objetivos de SLA en tiempo laborable para todas las prioridades; política creada desactivada | Provisional | Natural exigiría guardias 24x7. No se activa hasta probar los cálculos |
| 012 | 27/10/2026 | Correos de remitente desconocido a cola de revisión | Provisional | Evita crear clientes o incidencias a partir de spam o suplantaciones |
| 013 | 27/10/2026 | WhatsApp solo con Cloud API oficial; número dedicado recomendado | Provisional | Sin métodos no oficiales; decisión del número pendiente |
| 014 | 27/10/2026 | Recepción de correo: IMAP con contraseña o OAuth2 según dónde esté el buzón | Abierta | Si el buzón está en Microsoft 365 la autenticación básica no funciona |
| 015 | 27/10/2026 | Ejecución periódica: cron de IONOS o disparador HTTPS externo | Abierta | Depende del plan contratado |
