# Manual de administración

Menú **Administración** (cada apartado exige su permiso; ver
`docs/usuarios-y-permisos.md`).

## Primeros pasos tras instalar

1. **Ajustes**: nombre de la empresa, cierre automático, tamaño de adjuntos,
   copia automática diaria (viene desactivada).
2. **Equipos**: crea los equipos y marca quién coordina cada uno. El
   coordinador ve y gestiona las incidencias de sus equipos.
3. **Empleados**: da de alta al personal con su rol, equipos, especialidades
   y capacidad.
4. **Catálogos**: revisa categorías, etiquetas y especialidades.
5. **SLA**: ajusta el horario laboral real y los festivos y **activa la
   política** (viene desactivada a propósito, ver abajo).
6. **Correo** y **WhatsApp**: ver `docs/correo.md` y `docs/whatsapp.md`.
7. **Plantillas**: revisa los textos que recibe el cliente.
8. **Procesos**: comprueba que el cron se ejecuta.

## Catálogos

- **Estados**: nombre, color, fase (nueva, abierta, pendiente, resuelta,
  cerrada, cancelada) y si pausan el SLA. Los estados del sistema (nueva,
  asignada, resuelta, cerrada…) no se pueden desactivar porque la lógica
  depende de ellos.
- **Transiciones**: de qué estado se puede pasar a cuál y qué permiso exige
  (ninguno, `tickets.edit` o `tickets.reopen`). Desde cada estado solo se
  ofrecen los destinos definidos aquí.
- **Prioridades**, **categorías** (dos niveles), **etiquetas**,
  **canales**, **especialidades**.

Estados, prioridades y categorías no se borran: se desactivan y dejan de
ofrecerse, pero las incidencias antiguas conservan su valor. Las
**etiquetas** sí se pueden eliminar, y desaparecen de todas las incidencias
que la tenían (se pide confirmación).

## SLA

- **Calendario**: zona horaria (Europe/Madrid), tramos por día
  (`09:00-14:00, 15:00-18:00`), festivos uno por línea `DD/MM/AAAA Nombre`.
  Los festivos dependen de la comunidad y el municipio: hay que cargarlos
  cada año.
- **Política**: objetivo de primera respuesta y de resolución por prioridad,
  en horas y minutos, laborable o natural. Se puede asignar una política
  distinta a un cliente desde su ficha.
- **Simulador**: indica fecha de inicio y minutos y muestra el vencimiento.
  Úsalo para comprobar el calendario antes de activar la política.
- Los estados que pausan el SLA (pendiente del cliente, en espera) detienen
  el reloj mientras la incidencia esté en ellos.

## Procesos

Muestra la última ejecución de cada tarea programada, la cola de trabajos
(envíos de correo, WhatsApp, avisos) con sus errores y un botón para
reintentar. Desde aquí se genera la clave del disparador HTTP del cron.
**Ejecutar ahora** lanza las tareas pendientes sin esperar al cron.

## Auditoría

Registra accesos, cambios de configuración, permisos, exportaciones,
anonimizaciones y copias. Cada entrada lleva el hash de la anterior:
**Verificar cadena** detecta si alguien ha borrado o modificado registros
directamente en la base de datos. No impide la manipulación (en un hosting
compartido no hay forma), pero la hace visible.

## RGPD

- **Solicitudes**: registra cada solicitud de derechos (acceso, rectificación,
  supresión, oposición, portabilidad, limitación) con su plazo legal de un
  mes, ampliable a tres justificándolo.
- **Exportar** los datos de un contacto (JSON) para atender un derecho de
  acceso o portabilidad.
- **Anonimizar** un contacto: sustituye su nombre, borra sus correos y
  teléfonos, quita su nombre de los mensajes y de las incidencias y **borra
  los correos originales** guardados. Conserva las incidencias para las
  estadísticas. Es irreversible y pide confirmación.
- **Ojo:** el *texto* de los mensajes no se toca (puede contener una firma con
  datos personales). Si la solicitud lo exige, revísalo a mano. Ver
  `docs/limitaciones.md`.

## Copias de seguridad

Ver `docs/copias-y-recuperacion.md`.

## Informes

Informes → elige el informe y el periodo (máximo dos años), filtra por
cliente, equipo o empleado y exporta a CSV o Excel (permiso
`tickets.export`). En pantalla se muestran las 500 primeras filas; la
exportación incluye todas. Los campos que empiezan por `=`, `+`, `-` o `@` se
neutralizan para que Excel no los ejecute como fórmulas.
