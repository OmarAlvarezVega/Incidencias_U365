# Manual de configuración del correo

La aplicación lee los buzones por **IMAP** y envía por **SMTP**. No usa
reenvíos ni scripts en el servidor de correo. Se configura en
**Administración → Correo** (permiso `integrations.manage`).

## Cómo trata el correo entrante

| Caso | Qué hace |
| --- | --- |
| Respuesta a un correo nuestro (cabeceras `In-Reply-To`/`References`) | Se añade a esa incidencia |
| Asunto con `[INC-2026-000123]` y remitente del mismo cliente | Se añade a esa incidencia |
| Asunto con número, pero remitente de **otro** cliente o desconocido | **No** se añade: va a revisión (evita que un tercero se cuele en una incidencia ajena) |
| Respuesta a una incidencia cerrada o cancelada | Crea una incidencia nueva relacionada con la anterior |
| Remitente conocido (contacto) o de un dominio de cliente | Crea incidencia identificada |
| Remitente desconocido | Cola de **Revisión de entrada** (por defecto) o incidencia pendiente de identificar, según el buzón |
| Respuesta automática (vacaciones, `Auto-Submitted`, `noreply@`) | Se añade si es de una incidencia; si no, se ignora y no se contesta |
| Rebote | Marca como fallida la respuesta original y avisa al técnico |
| Correo enviado por uno de nuestros buzones | Se ignora (evita bucles) |
| Mismo `Message-ID` dos veces | Se procesa una sola vez |

- El HTML se sanea con lista blanca (sin scripts, eventos, formularios ni
  imágenes remotas). El original completo se guarda comprimido en
  `storage/`, fuera de la web.
- Los adjuntos pasan el mismo filtro que las subidas manuales. Los
  ejecutables (`.php`, `.exe`, dobles extensiones, texto con código…) se
  anotan como **rechazados** y no se guardan.
- **La primera vez** que se conecta un buzón no se importa el correo que ya
  había: se toma como punto de partida el último mensaje existente. Así un
  buzón con años de histórico no genera miles de incidencias.

## Opción 1: buzón con usuario y contraseña (IONOS Mail u otro)

Valores típicos de IONOS Mail **[Verificar] en el panel de IONOS**:

| Campo | Valor |
| --- | --- |
| Servidor IMAP | `imap.ionos.es`, puerto 993, SSL/TLS |
| Servidor SMTP | `smtp.ionos.es`, puerto 465 SSL/TLS o 587 STARTTLS |
| Usuario | la dirección completa |

Escribe la contraseña en el formulario. Se guarda cifrada con la clave de
`config/app.key` y nunca se vuelve a mostrar; para cambiarla, escribe una
nueva.

## Opción 2: Microsoft 365 (OAuth2, sin contraseña)

Microsoft desactivó la autenticación básica en Exchange Online, así que hay que
registrar una aplicación. La aplicación usa el flujo *client credentials*
(sin usuario interactivo) con el ámbito
`https://outlook.office365.com/.default`.

Pasos **[Probable]**, según la documentación de Microsoft; verifica los
nombres exactos en el portal porque cambian:

1. **Microsoft Entra ID → Registros de aplicaciones → Nuevo registro.**
   Nombre: `UNIVERS365 Incidencias`. Cuentas solo de este directorio.
2. Anota el **Id. de directorio (tenant)** y el **Id. de aplicación
   (cliente)**.
3. **Certificados y secretos → Nuevo secreto de cliente.** Copia el *valor*
   (no el id) y anota la fecha de caducidad: habrá que renovarlo antes.
4. **Permisos de API → Agregar → API que usa mi organización → Office 365
   Exchange Online → Permisos de aplicación**: `IMAP.AccessAsApp` y
   `SMTP.SendAsApp`. Pulsa **Conceder consentimiento de administrador**.
5. En **Exchange Online PowerShell** (como administrador):

   ```powershell
   Connect-ExchangeOnline
   # ObjectId = el de la "Aplicación empresarial" (no el del registro)
   New-ServicePrincipal -AppId <Id-aplicación> -ObjectId <ObjectId-app-empresarial>
   Add-MailboxPermission -Identity soporte@univers365.com -User <ObjectId-app-empresarial> -AccessRights FullAccess
   Add-RecipientPermission -Identity soporte@univers365.com -Trustee <ObjectId-app-empresarial> -AccessRights SendAs
   # SMTP AUTH tiene que estar permitido en ese buzón
   Set-CASMailbox -Identity soporte@univers365.com -SmtpClientAuthenticationDisabled $false
   ```

6. En la aplicación: autenticación **Microsoft 365 (OAuth2)**, tenant, id de
   cliente y secreto. IMAP `outlook.office365.com:993` SSL; SMTP
   `smtp.office365.com:587` STARTTLS.

Google Workspace no está implementado en esta versión (ver
`docs/limitaciones.md`).

## Probar y activar

1. Guarda el buzón **sin** marcar *Activo*.
2. **Probar conexión**: comprueba IMAP y SMTP y, si indicas una dirección,
   envía un correo de prueba.
3. Marca *Activo* y *Buzón predeterminado para envíos*.
4. **Procesar ahora** lee el buzón sin esperar al cron.
5. **Registro** muestra cada lectura, error y correo tratado.

Si falla, el error se guarda saneado (sin credenciales) en el buzón y en el
registro.

## Plantillas de respuesta

Administración → Plantillas. Variables admitidas entre `{{ }}`; si escribes
una que no existe, el formulario lo rechaza. Cada plantilla puede volver al
texto de fábrica. Las plantillas de fábrica llevan `[{{ ticket.number }}]` en
el asunto: no lo quites. Las cabeceras del correo ya encadenan las respuestas,
pero el número en el asunto es lo que salva los casos en que el cliente
responde desde otro programa o reenvía el mensaje.
