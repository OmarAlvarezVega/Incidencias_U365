# Limitaciones conocidas

Lo que esta versión **no** hace o hace con límites. Ninguna de estas
limitaciones está escondida en la interfaz.

## Alojamiento compartido

- **Sin procesos permanentes.** Todo lo periódico depende del cron cada 5
  minutos (o del disparador HTTPS). Un correo puede tardar hasta ~5 minutos
  en convertirse en incidencia y un envío otros tantos en salir.
- **Cada ejecución del cron tiene ~50 s** de presupuesto. Un buzón con mucho
  volumen se procesa en varias pasadas.
- El volcado de copias se hace en PHP. Con una base de datos de varios GB
  puede superar los límites de tiempo de IONOS; entonces hay que usar las
  copias del panel de IONOS. **[Suposición]** con los volúmenes previstos
  (decenas de miles de incidencias) cabe holgadamente.
- La auditoría detecta manipulaciones pero no las impide (el usuario de base
  de datos de la aplicación puede escribir en todas las tablas).

## Correo

- Implementado: IMAP/SMTP con contraseña y **Microsoft 365 con OAuth2**
  (client credentials). **Google Workspace (OAuth2) no está implementado**;
  con Google habría que usar una contraseña de aplicación, si la política de
  la organización lo permite.
- Cliente IMAP propio (no depende de `ext-imap`, que ya no viene con PHP 8.4).
  Probado contra un **Dovecot real** (SSL y STARTTLS con certificado
  validado, primera conexión, descarga de un correo con adjunto, mover a la
  carpeta de procesados, contraseña incorrecta), pero **no** contra IONOS
  Mail ni Microsoft 365. La autenticación OAuth2 (XOAUTH2) no se ha probado
  contra ningún servidor.
- **El envío SMTP no se ha probado contra ningún servidor** (usa PHPMailer).
- No se descarga el correo anterior a la primera conexión del buzón.
- Las imágenes remotas de los correos HTML no se muestran (evita píxeles de
  seguimiento); las incrustadas sin nombre (logos de firma) se omiten.

## WhatsApp

- Solo mensajes de texto y medios entrantes; las respuestas salen como texto.
  Fuera de la ventana de 24 h solo con una plantilla aprobada por Meta.
- Sin llamadas, reacciones ni mensajes de voz salientes.
- No se ha probado contra Meta real (el entorno de desarrollo no tiene salida
  a Internet). Pendiente en la checklist.

## Funcionalidad

- **Portal de clientes**: el modelo de datos y los permisos existen, pero no
  hay pantallas. Los clientes se comunican por correo y WhatsApp.
- Sin aplicación móvil; la web es adaptable.
- Sin notificaciones push ni en tiempo real: los avisos internos se
  actualizan al navegar y cada minuto en la campana.
- Sin facturación del tiempo registrado (solo informes y exportación).
- Sin pantalla para aplicar migraciones: las actualizaciones con cambios de
  base de datos necesitan SSH.

## RGPD

- La anonimización borra los datos identificativos del contacto y los correos
  originales, pero **no reescribe el texto** de los mensajes ni de las notas
  internas, que pueden contener nombres o firmas. Se revisa a mano si la
  solicitud lo exige.
- No hay borrado automático por plazo de conservación: hay que definir la
  política y aplicarla (ver pendientes).

## Interfaz

- Solo en castellano de España.
- Fechas en Europe/Madrid para todos los usuarios.
