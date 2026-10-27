# Tareas pendientes

## Bloquean la fase 2 (dependen de Omar)

- [ ] Ejecutar `tools/diagnostico.php` en IONOS (web y, si hay SSH, consola) con la prueba de base de datos y compartir el informe.
- [ ] Confirmar el plan de IONOS: SSH, cron e intervalo mínimo.
- [ ] Indicar dónde está el buzón `soporte@univers365.com` (IONOS Mail, Microsoft 365 o Google).
- [ ] Confirmar si el subdominio puede apuntar a la subcarpeta `public/`.

## Decisiones provisionales por confirmar

- [ ] Ámbito del coordinador (equipos, categorías o cartera de clientes).
- [ ] SLA natural o laborable para crítica, urgente y alta, y horario laboral real.
- [ ] Número de WhatsApp: nuevo dedicado o el actual.
- [ ] Cierre automático de incidencias resueltas (propuesto: 7 días).

## Fase 2 (base del sistema)

- [ ] Núcleo: router, petición/respuesta, contenedor, configuración, PDO.
- [ ] Ejecutor de migraciones con checksum (`bin/migrate.php`).
- [ ] Autenticación, sesiones, CSRF, límites de intentos, recuperación de contraseña.
- [ ] Usuarios, roles y permisos con políticas en servidor.
- [ ] Auditoría encadenada por hash.
- [ ] Layout con menú lateral, barra superior y estados vacíos.
- [ ] Instalador bloqueable.
- [ ] Despliegue de prueba en IONOS con pruebas de seguridad básicas.
