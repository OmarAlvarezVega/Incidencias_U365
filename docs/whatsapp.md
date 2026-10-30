# Manual de configuración de WhatsApp

Solo se usa la **WhatsApp Cloud API oficial de Meta**. No hay WhatsApp Web,
librerías no oficiales ni automatización del móvil.

## Qué necesitas de Meta

Los nombres de menús de Meta cambian a menudo **[Verificar]** en
developers.facebook.com:

1. Una cuenta de **Meta Business** (business.facebook.com) verificada.
2. En developers.facebook.com, una **app de tipo Business** con el producto
   **WhatsApp** añadido.
3. Un número de teléfono dado de alta en WhatsApp Business Platform.
   Recomendado: **un número dedicado**. Un número que ya se usa en la app de
   WhatsApp normal tiene que darse de baja de ella antes de pasar a la API.
4. Un **usuario del sistema** (Configuración del negocio → Usuarios →
   Usuarios del sistema) con acceso a la app y a la cuenta de WhatsApp, y un
   **token permanente** con los permisos `whatsapp_business_messaging` y
   `whatsapp_business_management`.
5. De la app: **Phone Number ID**, **WhatsApp Business Account ID** y la
   **clave secreta de la app** (Configuración de la app → Básica).

## Configuración en la aplicación

Administración → WhatsApp (permiso `integrations.manage`):

1. Rellena Phone Number ID, WABA ID, número visible, token y secreto de la
   app. El token y el secreto se guardan **cifrados** y **nunca** se envían al
   navegador (ni siquiera a la página de administración).
2. Marca **Generar un token de verificación nuevo** y guarda. Se muestra una
   sola vez: cópialo.
3. Deja *WhatsApp activo* desmarcado de momento.

## Webhook en Meta

En la app de Meta → WhatsApp → Configuración → Webhook:

- **URL de devolución de llamada**: `https://incidencias.univers365.com/webhooks/whatsapp`
- **Token de verificación**: el del paso anterior.
- Suscríbete al campo **messages**.

Meta hace una petición GET de verificación; si el token coincide, la
aplicación responde y Meta guarda el webhook.

Cada evento llega firmado con `X-Hub-Signature-256` (HMAC-SHA256 con el
secreto de la app). La aplicación rechaza con 401 cualquier evento sin firma
válida, guarda los válidos, descarta los duplicados y los procesa en la cola
(responde a Meta al instante). Mientras *WhatsApp activo* esté desmarcado, los
eventos firmados se aceptan y **se descartan**.

## Activar y probar

1. Marca *WhatsApp activo* y guarda.
2. **Probar**: comprueba que el token es válido contra la Graph API.
3. Envía un WhatsApp al número desde un móvil y comprueba que aparece la
   incidencia.

## Comportamiento

| Caso | Qué hace |
| --- | --- |
| Número de un contacto conocido sin incidencias abiertas | Crea incidencia identificada |
| Número desconocido | Crea incidencia **pendiente de identificar** y le pide al cliente que diga su empresa y nombre (plantilla *ask_identification*); un técnico la identifica después desde la incidencia |
| Mensaje de seguimiento en la misma conversación | Va a la incidencia activa de la conversación mientras no esté cerrada |
| Contacto con una incidencia abierta | Añade el mensaje a esa incidencia |
| Contacto con varias abiertas | Según el ajuste: le pregunta a cuál se refiere (lista numerada, o NUEVA) o lo añade a la más reciente |
| Si el mensaje de "¿a cuál?" no se puede enviar | Lo añade a la más reciente para no perderlo |
| Fotos, documentos, audio | Se descargan con el token (en la cola) y pasan el filtro de adjuntos |

**Ventana de 24 horas.** WhatsApp solo permite escribir texto libre al
cliente durante 24 h desde su último mensaje. Pasado ese plazo, la respuesta
solo sale si configuras una **plantilla aprobada por Meta** en el apartado
correspondiente (nombre, idioma y si lleva la variable `{{1}}` con el texto);
si no, la aplicación avisa al técnico de que responda por correo.

## Versión de la Graph API

Por defecto `v21.0`. Meta retira versiones antiguas; el campo *Versión de la
Graph API* permite subirla sin tocar código. Revisa la versión vigente en
developers.facebook.com antes de producción **[Verificar]**.

## Seguridad

- No pegues el token ni el secreto en correos, chats ni tickets.
- Si sospechas que el token se ha filtrado: revócalo en Meta, genera otro y
  escríbelo en la aplicación.
- El panel muestra los eventos de los últimos 7 días, los que fallaron y los
  envíos fallidos.
