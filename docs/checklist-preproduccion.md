# Checklist de preproducción

Marca cada punto **en el servidor de IONOS**, no en local. Si un punto falla,
no se pasa a producción hasta resolverlo.

## Servidor

- [ ] `tools/diagnostico.php` ejecutado, informe guardado y **fichero borrado**.
- [ ] PHP ≥ 8.2 con todas las extensiones obligatorias (el instalador las lista).
- [ ] El subdominio apunta a `public/` (o plan B con `.htaccess` comprobado).
- [ ] HTTPS activo y forzado; certificado válido.
- [ ] `https://incidencias.univers365.com/config/config.php`, `/storage/`,
      `/vendor/`, `/app/`, `/.git/` devuelven 403 o 404 (nunca contenido).
- [ ] `storage/install.token` no existe; `storage/installed.lock` sí.
- [ ] `/instalar` devuelve 404.
- [ ] `config/config.php` con permisos 0600 y `env` = `production`.
- [ ] `config/app.key` guardada **fuera** del servidor.
- [ ] Límites de subida de PHP ≥ tamaño máximo de adjunto configurado
      (Ajustes avisa si no).

## Cron

- [ ] Cron de IONOS o disparador HTTPS configurado cada 5 minutos.
- [ ] Administración → Procesos muestra ejecuciones recientes de todas las tareas.
- [ ] `/cron/run` sin clave responde 403/404.

## Accesos

- [ ] Superadministrador con contraseña fuerte y único.
- [ ] Empleados dados de alta con el rol correcto; probado que un técnico no
      ve incidencias ajenas (abrir la URL de una ajena da 404).
- [ ] Bloqueo tras intentos fallidos comprobado con un usuario de prueba.
- [ ] Recuperación de contraseña recibida por correo y enlace de un solo uso.
- [ ] Usuarios de prueba desactivados antes de abrir.

## Correo

- [ ] Buzón probado con **Probar conexión** (IMAP y SMTP).
- [ ] Correo de un cliente conocido → incidencia identificada.
- [ ] Correo de un desconocido → cola de revisión.
- [ ] Respuesta del técnico llega al cliente con `[INC-…]` en el asunto.
- [ ] Respuesta del cliente a ese correo → se añade a la misma incidencia.
- [ ] Adjunto PDF llega; un `.php` o `.exe` queda rechazado.
- [ ] Una respuesta automática (fuera de oficina) no genera bucle.
- [ ] SPF/DKIM/DMARC del dominio permiten enviar desde el servidor SMTP usado
      (si no, los correos acabarán en spam).

## WhatsApp (si se activa)

- [ ] Webhook verificado en Meta con el token de la aplicación.
- [ ] **Probar** en Administración → WhatsApp devuelve el número correcto.
- [ ] Mensaje desde un móvil → incidencia; respuesta del técnico llega.
- [ ] Respuesta fuera de 24 h: plantilla configurada o aviso al técnico.

## SLA

- [ ] Horario laboral real y festivos del año cargados.
- [ ] Simulador comprobado con 3 casos conocidos (incluido un viernes tarde).
- [ ] Política **activada**.

## Copias

- [ ] Copia automática diaria activada.
- [ ] Copia manual creada y **verificada**.
- [ ] **Restauración real** probada en una base de datos vacía aparte.
- [ ] Procedimiento para sacar copias fuera del servidor decidido (quién y
      cada cuánto).

## Datos y legal

- [ ] Ninguna incidencia, cliente o empleado de prueba queda en la base de
      producción (o se instala de cero tras las pruebas).
- [ ] Texto legal / información RGPD en las plantillas de respuesta revisado.
- [ ] Plazo de conservación de datos decidido.

## Último paso

- [ ] Copia completa justo antes de abrir al equipo.
- [ ] Equipo informado de la URL y de cómo pedir acceso.
