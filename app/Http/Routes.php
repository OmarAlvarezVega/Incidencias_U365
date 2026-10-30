<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Router;
use App\Http\Controllers as C;
use App\Http\Controllers\Admin as A;

/** Tabla de rutas. Ver Kernel para el significado de las opciones. */
final class Routes
{
    public static function build(): Router
    {
        $r = new Router();
        $id = '{id:\d+}';

        // Acceso
        $r->get('/login', [C\AuthController::class, 'showLogin'], ['public' => true, 'guest' => true]);
        $r->post('/login', [C\AuthController::class, 'login'], ['public' => true, 'guest' => true]);
        $r->post('/logout', [C\AuthController::class, 'logout'], ['public' => true, 'allow_password_change' => true]);
        $r->get('/recuperar', [C\AuthController::class, 'showForgot'], ['public' => true, 'guest' => true]);
        $r->post('/recuperar', [C\AuthController::class, 'forgot'], ['public' => true, 'guest' => true]);
        $r->get('/restablecer/{token:[A-Za-z0-9_\-]{40,64}}', [C\AuthController::class, 'showReset'], ['public' => true, 'guest' => true]);
        $r->post('/restablecer/{token:[A-Za-z0-9_\-]{40,64}}', [C\AuthController::class, 'reset'], ['public' => true, 'guest' => true]);

        // Perfil
        $r->get('/perfil', [C\ProfileController::class, 'show'], ['allow_password_change' => true]);
        $r->get('/perfil/contrasena', [C\ProfileController::class, 'showPassword'], ['allow_password_change' => true]);
        $r->post('/perfil/contrasena', [C\ProfileController::class, 'password'], ['allow_password_change' => true]);
        $r->post('/perfil/notificaciones', [C\ProfileController::class, 'notifications']);
        $r->post('/perfil/sesiones/cerrar', [C\ProfileController::class, 'closeSessions']);

        // Panel, búsqueda y avisos
        $r->get('/', [C\DashboardController::class, 'index']);
        $r->get('/panel/datos', [C\DashboardController::class, 'data']);
        $r->get('/buscar', [C\SearchController::class, 'index']);
        $r->get('/notificaciones', [C\NotificationController::class, 'index']);
        $r->get('/notificaciones/recientes', [C\NotificationController::class, 'recent']);
        $r->get("/notificaciones/$id/abrir", [C\NotificationController::class, 'open']);
        $r->post('/notificaciones/leer-todas', [C\NotificationController::class, 'readAll']);

        // Incidencias
        $r->get('/incidencias', [C\TicketController::class, 'index']);
        $r->get('/incidencias/exportar', [C\TicketController::class, 'export'], ['perm' => 'tickets.export']);
        $r->post('/incidencias/lote', [C\TicketController::class, 'bulk']);
        $r->get('/incidencias/nueva', [C\TicketController::class, 'create'], ['perm' => 'tickets.create']);
        $r->post('/incidencias', [C\TicketController::class, 'store'], ['perm' => 'tickets.create']);
        $r->get("/incidencias/$id", [C\TicketController::class, 'show']);
        $r->post("/incidencias/$id/editar", [C\TicketController::class, 'update'], ['perm' => 'tickets.edit']);
        $r->post("/incidencias/$id/estado", [C\TicketController::class, 'status'], ['perm' => 'tickets.change_status|tickets.reopen']);
        $r->post("/incidencias/$id/prioridad", [C\TicketController::class, 'priority'], ['perm' => 'tickets.change_priority']);
        $r->post("/incidencias/$id/asignacion", [C\TicketController::class, 'assign'], ['perm' => 'tickets.assign|tickets.reassign']);
        $r->post("/incidencias/$id/asumir", [C\TicketController::class, 'takeOwnership'], ['perm' => 'tickets.edit']);
        $r->post("/incidencias/$id/identificar", [C\TicketController::class, 'identify'], ['perm' => 'tickets.edit']);
        $r->post("/incidencias/$id/responder", [C\TicketController::class, 'reply'], ['perm' => 'tickets.reply_public']);
        $r->post("/incidencias/$id/nota", [C\TicketController::class, 'note'], ['perm' => 'tickets.note_internal']);
        $r->post("/incidencias/$id/adjuntos", [C\TicketController::class, 'upload'], ['perm' => 'attachments.upload']);
        $r->post("/incidencias/$id/etiquetas", [C\TicketController::class, 'tags'], ['perm' => 'tickets.edit']);
        $r->post("/incidencias/$id/relaciones", [C\TicketController::class, 'relate'], ['perm' => 'tickets.edit']);
        $r->post("/incidencias/$id/relaciones/{rel:\d+}/quitar", [C\TicketController::class, 'unrelate'], ['perm' => 'tickets.edit']);
        $r->post("/incidencias/$id/eliminar", [C\TicketController::class, 'destroy'], ['perm' => 'tickets.delete']);
        $r->post("/incidencias/$id/reenviar/{msg:\d+}", [C\TicketController::class, 'resend'], ['perm' => 'tickets.reply_public']);

        // Tiempo
        $r->get('/temporizador', [C\TimeController::class, 'current']);
        $r->post("/incidencias/$id/temporizador/iniciar", [C\TimeController::class, 'start'], ['perm' => 'time.log']);
        $r->post('/temporizador/parar', [C\TimeController::class, 'stop'], ['perm' => 'time.log']);
        $r->post("/incidencias/$id/tiempos", [C\TimeController::class, 'store'], ['perm' => 'time.log']);
        $r->post("/tiempos/$id/editar", [C\TimeController::class, 'update'], ['perm' => 'time.log|time.edit_any']);
        $r->post("/tiempos/$id/borrar", [C\TimeController::class, 'destroy'], ['perm' => 'time.log|time.edit_any']);
        $r->get('/mis-tiempos', [C\TimeController::class, 'mine'], ['perm' => 'time.log']);

        // Adjuntos
        $r->get("/adjuntos/$id/descargar", [C\AttachmentController::class, 'download']);
        $r->get("/adjuntos/$id/ver", [C\AttachmentController::class, 'inline']);
        $r->post("/adjuntos/$id/borrar", [C\AttachmentController::class, 'destroy'], ['perm' => 'attachments.upload']);

        // Cola de revisión
        $r->get('/revision', [C\ReviewController::class, 'index'], ['perm' => 'tickets.review_queue']);
        $r->post("/revision/$id", [C\ReviewController::class, 'resolve'], ['perm' => 'tickets.review_queue']);

        // Clientes
        $r->get('/clientes', [C\ClientController::class, 'index'], ['perm' => 'clients.view']);
        $r->get('/clientes/nuevo', [C\ClientController::class, 'create'], ['perm' => 'clients.manage']);
        $r->post('/clientes', [C\ClientController::class, 'store'], ['perm' => 'clients.manage']);
        $r->get("/clientes/$id", [C\ClientController::class, 'show'], ['perm' => 'clients.view']);
        $r->get("/clientes/$id/editar", [C\ClientController::class, 'edit'], ['perm' => 'clients.manage']);
        $r->post("/clientes/$id", [C\ClientController::class, 'update'], ['perm' => 'clients.manage']);
        $r->post("/clientes/$id/eliminar", [C\ClientController::class, 'destroy'], ['perm' => 'clients.manage']);
        $r->post("/clientes/$id/contactos", [C\ClientController::class, 'saveContact'], ['perm' => 'clients.manage']);
        $r->post("/clientes/$id/contactos/{contact:\d+}/eliminar", [C\ClientController::class, 'deleteContact'], ['perm' => 'clients.manage']);
        $r->post("/clientes/$id/direcciones", [C\ClientController::class, 'saveAddress'], ['perm' => 'clients.manage']);
        $r->post("/clientes/$id/direcciones/{address:\d+}/eliminar", [C\ClientController::class, 'deleteAddress'], ['perm' => 'clients.manage']);
        $r->post("/clientes/$id/dominios", [C\ClientController::class, 'addDomain'], ['perm' => 'clients.manage']);
        $r->post("/clientes/$id/dominios/{domain:\d+}/quitar", [C\ClientController::class, 'removeDomain'], ['perm' => 'clients.manage']);
        $r->get('/api/clientes', [C\ClientController::class, 'lookup'], ['perm' => 'clients.view|tickets.create']);
        $r->get("/api/clientes/$id/contactos", [C\ClientController::class, 'contacts'], ['perm' => 'clients.view|tickets.create']);

        // Empleados y carga
        $r->get('/empleados', [C\EmployeeController::class, 'index'], ['perm' => 'employees.view']);
        $r->get('/empleados/nuevo', [C\EmployeeController::class, 'create'], ['perm' => 'employees.manage']);
        $r->post('/empleados', [C\EmployeeController::class, 'store'], ['perm' => 'employees.manage']);
        $r->get("/empleados/$id", [C\EmployeeController::class, 'show'], ['perm' => 'employees.view']);
        $r->get("/empleados/$id/editar", [C\EmployeeController::class, 'edit'], ['perm' => 'employees.manage']);
        $r->post("/empleados/$id", [C\EmployeeController::class, 'update'], ['perm' => 'employees.manage']);
        $r->post("/empleados/$id/estado", [C\EmployeeController::class, 'status'], ['perm' => 'users.manage']);
        $r->post("/empleados/$id/contrasena", [C\EmployeeController::class, 'password'], ['perm' => 'users.manage']);
        $r->post("/empleados/$id/permisos", [C\EmployeeController::class, 'permissions'], ['perm' => 'permissions.manage']);
        $r->post("/empleados/$id/reasignar", [C\EmployeeController::class, 'reassign'], ['perm' => 'tickets.reassign']);
        $r->get('/carga', [C\WorkloadController::class, 'index'], ['perm' => 'workload.view.all|workload.view.team']);

        // Informes
        $r->get('/informes', [C\ReportController::class, 'index'], ['perm' => 'reports.view.all|reports.view.team']);
        $r->get('/informes/{code:[a-z]+}', [C\ReportController::class, 'show'], ['perm' => 'reports.view.all|reports.view.team']);

        // Administración
        $r->get('/admin/roles', [A\RoleController::class, 'index'], ['perm' => 'permissions.manage']);
        $r->post('/admin/roles/{role:\d+}', [A\RoleController::class, 'update'], ['perm' => 'permissions.manage']);
        $r->get('/admin/equipos', [A\TeamController::class, 'index'], ['perm' => 'users.manage']);
        $r->post('/admin/equipos', [A\TeamController::class, 'save'], ['perm' => 'users.manage']);
        $r->post("/admin/equipos/$id", [A\TeamController::class, 'save'], ['perm' => 'users.manage']);
        $r->get('/admin/catalogos', [A\CatalogController::class, 'index'], ['perm' => 'catalogs.manage']);
        $r->post('/admin/catalogos/{type:[a-z]+}', [A\CatalogController::class, 'save'], ['perm' => 'catalogs.manage']);
        $r->get('/admin/ajustes', [A\SettingsController::class, 'index'], ['perm' => 'settings.manage']);
        $r->post('/admin/ajustes', [A\SettingsController::class, 'save'], ['perm' => 'settings.manage']);
        $r->get('/admin/plantillas', [A\TemplateController::class, 'index'], ['perm' => 'templates.manage']);
        $r->post('/admin/plantillas', [A\TemplateController::class, 'save'], ['perm' => 'templates.manage']);
        $r->get('/admin/correo', [A\EmailController::class, 'index'], ['perm' => 'integrations.manage']);
        $r->get('/admin/correo/nuevo', [A\EmailController::class, 'form'], ['perm' => 'integrations.manage']);
        $r->get("/admin/correo/$id", [A\EmailController::class, 'form'], ['perm' => 'integrations.manage']);
        $r->post('/admin/correo', [A\EmailController::class, 'save'], ['perm' => 'integrations.manage']);
        $r->post("/admin/correo/$id", [A\EmailController::class, 'save'], ['perm' => 'integrations.manage']);
        $r->post("/admin/correo/$id/probar", [A\EmailController::class, 'test'], ['perm' => 'integrations.manage']);
        $r->post("/admin/correo/$id/procesar", [A\EmailController::class, 'poll'], ['perm' => 'integrations.manage']);
        $r->get("/admin/correo/$id/registro", [A\EmailController::class, 'log'], ['perm' => 'integrations.manage']);
        $r->get('/admin/whatsapp', [A\WhatsAppController::class, 'index'], ['perm' => 'integrations.manage']);
        $r->post('/admin/whatsapp', [A\WhatsAppController::class, 'save'], ['perm' => 'integrations.manage']);
        $r->post('/admin/whatsapp/probar', [A\WhatsAppController::class, 'test'], ['perm' => 'integrations.manage']);
        $r->get('/admin/sla', [A\SlaController::class, 'index'], ['perm' => 'sla.manage']);
        $r->post('/admin/sla/politica', [A\SlaController::class, 'savePolicy'], ['perm' => 'sla.manage']);
        $r->post('/admin/sla/calendario', [A\SlaController::class, 'saveCalendar'], ['perm' => 'sla.manage']);
        $r->post('/admin/sla/festivos', [A\SlaController::class, 'holidays'], ['perm' => 'sla.manage']);
        $r->post('/admin/sla/simular', [A\SlaController::class, 'simulate'], ['perm' => 'sla.manage']);
        $r->get('/admin/auditoria', [A\AuditController::class, 'index'], ['perm' => 'audit.view']);
        $r->post('/admin/auditoria/verificar', [A\AuditController::class, 'verify'], ['perm' => 'audit.view']);
        $r->get('/admin/procesos', [A\ProcessController::class, 'index'], ['perm' => 'settings.manage']);
        $r->post('/admin/procesos/ejecutar', [A\ProcessController::class, 'run'], ['perm' => 'settings.manage']);
        $r->post("/admin/procesos/trabajos/$id/reintentar", [A\ProcessController::class, 'retry'], ['perm' => 'settings.manage']);
        $r->post('/admin/procesos/clave-cron', [A\ProcessController::class, 'cronKey'], ['perm' => 'settings.manage']);
        $r->get('/admin/copias', [A\BackupController::class, 'index'], ['perm' => 'backups.manage']);
        $r->post('/admin/copias', [A\BackupController::class, 'create'], ['perm' => 'backups.manage']);
        $r->post('/admin/copias/verificar', [A\BackupController::class, 'verify'], ['perm' => 'backups.manage']);
        $r->post('/admin/copias/descargar', [A\BackupController::class, 'download'], ['perm' => 'backups.manage']);
        $r->get('/admin/rgpd', [A\GdprController::class, 'index'], ['perm' => 'gdpr.manage']);
        $r->post('/admin/rgpd', [A\GdprController::class, 'store'], ['perm' => 'gdpr.manage']);
        $r->post("/admin/rgpd/$id", [A\GdprController::class, 'update'], ['perm' => 'gdpr.manage']);
        $r->post('/contactos/{contact:\d+}/exportar', [A\GdprController::class, 'export'], ['perm' => 'gdpr.manage']);
        $r->post('/contactos/{contact:\d+}/anonimizar', [A\GdprController::class, 'anonymize'], ['perm' => 'gdpr.manage']);

        // Integraciones sin sesión
        $r->get('/webhooks/whatsapp', [C\WebhookController::class, 'verify'], ['public' => true, 'session' => false]);
        $r->post('/webhooks/whatsapp', [C\WebhookController::class, 'receive'], ['public' => true, 'session' => false]);
        $r->post('/cron/run', [C\CronController::class, 'run'], ['public' => true, 'session' => false]);

        return $r;
    }
}
