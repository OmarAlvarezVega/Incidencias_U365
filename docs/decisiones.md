# Registro de decisiones técnicas

Cada decisión indica su estado: **Aceptada**, **Provisional** (aplicada por
defecto, pendiente de confirmar) o **Abierta** (no se puede cerrar sin datos).

| Nº | Fecha | Decisión | Estado | Motivo |
| --- | --- | --- | --- | --- |
| 001 | 27/10/2026 | Monolito PHP 8.3 con micro-arquitectura propia (MVC ligero), sin Laravel | Provisional | Despliegue por SFTP sin paso de compilación, sin dependencia de cron por minuto. Se reevalúa si IONOS ofrece SSH, Composer y cron por minuto |
| 002 | 27/10/2026 | Dependencias: twig/twig, phpmailer/phpmailer, zbateson/mail-mime-parser; phpunit solo en desarrollo (webklex/php-imap descartado, ver 016) | Aceptada | Autoescape contra XSS, SMTP con TLS/XOAUTH2, parser MIME en PHP puro. ext-imap salió del núcleo en PHP 8.4 |
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
| 016 | 27/10/2026 | Cliente IMAP propio sobre sockets TLS (LOGIN y XOAUTH2), sin `ext-imap` ni librerías IMAP de terceros | Provisional | `ext-imap` salió del núcleo en PHP 8.4 y no se puede presuponer en IONOS. Probado contra Dovecot (SSL, STARTTLS, LOGIN); falta IONOS/Microsoft y XOAUTH2 |
| 017 | 27/10/2026 | Microsoft 365 con OAuth2 *client credentials* (IMAP.AccessAsApp, SMTP.SendAsApp) | Provisional | Sin usuario interactivo ni refresh tokens que caduquen al cambiar contraseñas. Requiere permisos de buzón en Exchange Online |
| 018 | 27/10/2026 | La primera lectura de un buzón no importa el correo existente: toma como punto de partida el último UID | Aceptada | Conectar un buzón con histórico no debe crear miles de incidencias |
| 019 | 27/10/2026 | Webhook de WhatsApp a través del controlador frontal (`/webhooks/whatsapp`), sin sesión ni CSRF, autenticado por HMAC; con WhatsApp desactivado se valida la firma y se descarta | Aceptada | Un solo punto de entrada; responde a Meta al instante y procesa en la cola |
| 020 | 27/10/2026 | Copias de base de datos con volcado en PHP (sin `mysqldump`), con huella SHA-256 y marca de fin | Provisional | No se puede presuponer `exec` ni `mysqldump` en IONOS. Para bases de varios GB habría que usar las copias del panel |
| 021 | 27/10/2026 | La restauración nunca escribe sobre la base en uso: se restaura en una base nueva vacía y se cambia `config.php` | Aceptada | Siempre hay vuelta atrás; impide una restauración destructiva por error |
| 022 | 27/10/2026 | Instalador con fichero `storage/install.token`, base de datos vacía obligatoria, reutiliza `app.key` existente y escribe `config.php` lo último | Aceptada | Nadie puede instalar en la ventana en que la web está vacía; un fallo no deja la instalación a medias ni pierde la clave |
| 023 | 27/10/2026 | Plantillas de mensajes con variables `{{ variable }}` de una lista cerrada (sin Twig para el usuario) | Aceptada | Un administrador no puede ejecutar código ni leer datos ajenos desde una plantilla; las variables desconocidas se rechazan al guardar |
| 024 | 27/10/2026 | CSP estricta (`script-src 'self'`), todo el JavaScript en `public/assets/js/app.js` y comportamiento por atributos `data-*` | Aceptada | Sin JavaScript en línea, un HTML que se colara no podría ejecutar scripts |
| 025 | 27/10/2026 | Adjuntos fuera de la web con nombre aleatorio sin extensión; tipo detectado por contenido; rechazados anotados pero no guardados | Aceptada | Ningún adjunto puede ejecutarse en el servidor; el técnico sabe que existía |
| 026 | 27/10/2026 | Pruebas: PHPUnit con base de datos real de pruebas (nombre con `test` obligatorio) y servidor `php -S` para las de seguridad HTTP | Aceptada | Prueba la aplicación de verdad, no simulaciones; la condición del nombre evita vaciar una base que no es de pruebas |
