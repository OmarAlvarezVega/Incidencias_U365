# Tareas pendientes

Actualizado: 27/10/2026, versión 1.0.0 (fases 1 a 9 entregadas en código).

## Bloquean producción (dependen de Omar o de IONOS)

- [ ] Ejecutar `tools/diagnostico.php` en IONOS y compartir el informe
      (versión de PHP, extensiones, ruta del PHP de consola, límites).
- [ ] Confirmar plan de IONOS: SSH sí/no, cron e intervalo mínimo.
- [ ] Decir dónde está `soporte@univers365.com` (IONOS Mail, Microsoft 365 o
      Google). Con Google hay que decidir cómo autenticar (ver limitaciones).
- [ ] Confirmar que el subdominio puede apuntar a `public/`.
- [ ] **Primera prueba contra el buzón real**: IMAP solo se ha probado con
      Dovecot; el envío SMTP y el OAuth2 de Microsoft no se han probado
      contra ningún servidor.
- [ ] **Primera prueba real con Meta** (webhook, envío, descarga de medios),
      si WhatsApp entra en el arranque.
- [ ] Recorrer `docs/checklist-preproduccion.md` completo en IONOS.

## Decisiones provisionales por confirmar

- [ ] Ámbito del coordinador: implementado **por equipos** (decisión 010).
- [ ] SLA: implementado en tiempo **laborable** para todas las prioridades,
      política **desactivada** hasta cargar horario y festivos reales (011).
- [ ] Número de WhatsApp: nuevo dedicado o el actual (013).
- [ ] Cierre automático de resueltas: 7 días por defecto (ajustable).
- [ ] Remitentes desconocidos: a revisión por defecto (012).
- [ ] Plazo de conservación de datos personales y de correos originales.

## Mejoras identificadas (no bloquean)

- [ ] Pantalla para aplicar migraciones sin SSH (con copia previa obligatoria).
- [ ] OAuth2 de Google Workspace para buzones de Google.
- [ ] Portal de clientes (el modelo y los permisos ya existen).
- [ ] Anonimización RGPD que también limpie el texto de mensajes y notas.
- [ ] Borrado automático por plazo de conservación.
- [ ] Marcar como fallo definitivo (sin reintentos) el aviso al cliente cuando
      no hay ningún buzón activo; ahora se reintenta hasta agotar intentos.
- [ ] Pruebas automáticas del JavaScript de la interfaz (navegador).
- [ ] Prueba de carga con el volumen real esperado en el plan de IONOS.

## Hecho

- Fase 1: análisis, arquitectura, esquema y diagnóstico (PR #1).
- Fases 2–9: núcleo, autenticación, permisos, incidencias, tiempos, clientes,
  empleados, carga, informes, correo, WhatsApp, SLA, notificaciones,
  administración, auditoría, RGPD, copias, instalador, pruebas y manuales.
