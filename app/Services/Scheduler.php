<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Clock;

/**
 * Tareas periódicas. Se lanza cada minuto desde el cron de IONOS
 * (php bin/cron.php) o, si el plan no tiene cron, desde el disparador HTTP
 * protegido con clave (/cron/run). Un bloqueo de base de datos impide dos
 * ejecuciones simultáneas; cada tarea tiene su intervalo y su presupuesto.
 */
final class Scheduler
{
    /** Tarea => [intervalo en minutos, descripción] */
    public const TASKS = [
        'jobs.process' => [0, 'Cola de trabajos (envíos de correo, WhatsApp, avisos)'],
        'email.poll' => [0, 'Lectura de buzones (cada buzón con su intervalo)'],
        'audit.seal' => [0, 'Sellado de la cadena de auditoría'],
        'sla.check' => [5, 'Avisos e incumplimientos de SLA'],
        'timers.stale' => [30, 'Cierre de temporizadores olvidados (> 12 h)'],
        'tickets.autoclose' => [60, 'Cierre automático de incidencias resueltas'],
        'cleanup' => [1440, 'Limpieza de datos temporales'],
        'backup.auto' => [1440, 'Copia de seguridad diaria (si está activada)'],
    ];

    public function __construct(private readonly App $app)
    {
    }

    public function registerJobHandlers(Jobs $jobs): void
    {
        $app = $this->app;
        $jobs->handle('notification.email', static fn (array $p) => $app->make(Notifications::class)->deliverEmail((int)$p['notification_id']));
        $jobs->handle('email.send_reply', static fn (array $p) => $app->make(Mail\MailOutbound::class)->sendReply((int)$p['ticket_message_id']));
        $jobs->handle('email.customer_notice', static fn (array $p) => $app->make(Mail\MailOutbound::class)->sendCustomerNotice((int)$p['ticket_id'], (string)$p['template'], isset($p['reply_to_email_id']) ? (int)$p['reply_to_email_id'] : null));
        $jobs->handle('whatsapp.send_reply', static fn (array $p) => $app->make(WhatsApp\WhatsAppService::class)->sendReply((int)$p['ticket_message_id']));
        $jobs->handle('whatsapp.process_event', static fn (array $p) => $app->make(WhatsApp\WhatsAppService::class)->processEvent((int)$p['event_id']));
        $jobs->handle('whatsapp.download_media', static fn (array $p) => $app->make(WhatsApp\WhatsAppService::class)->downloadMedia((int)$p['whatsapp_message_id']));
    }

    /**
     * @param list<string>|null $only ejecutar solo estas tareas (manual)
     * @return array<string,string> tarea => resultado
     */
    public function run(string $trigger = 'cron', ?array $only = null, float $budget = 50.0): array
    {
        $db = $this->app->db();
        $start = microtime(true);
        $results = [];
        if (!$db->value("SELECT GET_LOCK('u365_scheduler', 0)")) {
            $db->insert('scheduled_task_runs', [
                'run_id' => Jobs::ulid(), 'task' => 'scheduler', 'trigger_src' => $trigger,
                'started_at' => Clock::now()->format('Y-m-d H:i:s.v'), 'finished_at' => Clock::now()->format('Y-m-d H:i:s.v'), 'status' => 'skipped_locked',
            ]);
            return ['scheduler' => 'Otra ejecución en curso'];
        }
        try {
            foreach (self::TASKS as $task => [$interval]) {
                if ($only !== null && !in_array($task, $only, true)) {
                    continue;
                }
                $remaining = $budget - (microtime(true) - $start);
                if ($remaining < 3) {
                    $results[$task] = 'sin tiempo';
                    continue;
                }
                if ($only === null && $interval > 0 && !$this->due($task, $interval)) {
                    continue;
                }
                $results[$task] = $this->runTask($task, $trigger, min($remaining - 2, 40.0));
            }
        } finally {
            $db->value("SELECT RELEASE_LOCK('u365_scheduler')");
        }
        return $results;
    }

    private function due(string $task, int $interval): bool
    {
        $last = $this->app->db()->value("SELECT MAX(started_at) FROM scheduled_task_runs WHERE task = ? AND status IN ('ok','error')", [$task]);
        if ($last === null) {
            return true;
        }
        $lastDt = Clock::fromDb(substr((string)$last, 0, 19));
        // Copia diaria: a partir de las 03:00 hora de Madrid
        if ($task === 'backup.auto') {
            $local = Clock::now()->setTimezone(Clock::local());
            return (int)$local->format('G') >= 3 && $lastDt->setTimezone(Clock::local())->format('Y-m-d') !== $local->format('Y-m-d');
        }
        return Clock::now()->getTimestamp() - $lastDt->getTimestamp() >= $interval * 60 - 5;
    }

    private function runTask(string $task, string $trigger, float $budget): string
    {
        $db = $this->app->db();
        $runId = $db->insert('scheduled_task_runs', [
            'run_id' => Jobs::ulid(), 'task' => $task, 'trigger_src' => $trigger,
            'started_at' => Clock::now()->format('Y-m-d H:i:s.v'), 'status' => 'running',
        ]);
        try {
            $items = $this->execute($task, $budget);
            $db->run("UPDATE scheduled_task_runs SET status = 'ok', finished_at = ?, items = ? WHERE id = ?", [Clock::now()->format('Y-m-d H:i:s.v'), max(0, $items), $runId]);
            return "ok ($items)";
        } catch (\Throwable $e) {
            $msg = mb_substr(\App\Core\Logger::redact($e->getMessage()), 0, 1000);
            $db->run("UPDATE scheduled_task_runs SET status = 'error', finished_at = ?, error = ? WHERE id = ?", [Clock::now()->format('Y-m-d H:i:s.v'), $msg, $runId]);
            $this->app->logger()->error("Tarea $task: " . $msg, ['file' => $e->getFile() . ':' . $e->getLine()]);
            return 'error: ' . $msg;
        }
    }

    private function execute(string $task, float $budget): int
    {
        $db = $this->app->db();
        switch ($task) {
            case 'jobs.process':
                $jobs = $this->app->make(Jobs::class);
                $this->registerJobHandlers($jobs);
                return $jobs->work(min($budget, 25.0));
            case 'email.poll':
                return $this->app->make(Mail\MailProcessor::class)->pollAll(min($budget, 30.0));
            case 'audit.seal':
                return $this->app->audit()->seal();
            case 'sla.check':
                return $this->app->make(Sla::class)->check();
            case 'timers.stale':
                return $this->app->make(TimeService::class)->closeStale();
            case 'tickets.autoclose':
                $days = $this->app->settings()->int('tickets.auto_close_days', 7);
                if ($days <= 0) {
                    return 0;
                }
                $svc = $this->app->make(TicketService::class);
                $closed = $svc->statusByCode('closed');
                if ($closed === null) {
                    return 0;
                }
                $n = 0;
                $limit = Clock::now()->modify("-$days days")->format(Clock::DB_FORMAT);
                foreach ($db->all("SELECT t.* FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE s.phase = 'resolved' AND t.resolved_at < ? AND t.deleted_at IS NULL LIMIT 200", [$limit]) as $t) {
                    $svc->changeStatus($t, (int)$closed['id'], null, false, true);
                    $n++;
                }
                return $n;
            case 'cleanup':
                $n = $this->app->rateLimiter()->purge(86400);
                $n += $db->run('DELETE FROM login_attempts WHERE attempted_at < ?', [Clock::now()->modify('-90 days')->format(Clock::DB_FORMAT)])->rowCount();
                $n += $db->run('DELETE FROM password_resets WHERE expires_at < ?', [Clock::now()->modify('-7 days')->format(Clock::DB_FORMAT)])->rowCount();
                $n += $db->run('DELETE FROM user_sessions WHERE last_seen_at < ?', [Clock::now()->modify('-30 days')->format(Clock::DB_FORMAT)])->rowCount();
                $n += $db->run("DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < ?", [Clock::now()->modify('-180 days')->format(Clock::DB_FORMAT)])->rowCount();
                $n += $this->app->make(Jobs::class)->purge(30);
                $n += $db->run('DELETE FROM scheduled_task_runs WHERE started_at < ?', [Clock::now()->modify('-30 days')->format(Clock::DB_FORMAT)])->rowCount();
                $n += $db->run("DELETE FROM whatsapp_webhook_events WHERE processing_status IN ('processed','ignored') AND received_at < ?", [Clock::now()->modify('-90 days')->format(Clock::DB_FORMAT)])->rowCount();
                foreach (glob($this->app->storagePath('tmp') . '/*') ?: [] as $f) {
                    if (is_file($f) && filemtime($f) < time() - 86400) {
                        @unlink($f);
                        $n++;
                    }
                }
                foreach (glob($this->app->storagePath('sessions') . '/sess_*') ?: [] as $f) {
                    if (is_file($f) && filemtime($f) < time() - 13 * 3600) {
                        @unlink($f);
                        $n++;
                    }
                }
                return $n;
            case 'backup.auto':
                if (!$this->app->settings()->bool('backups.auto', false)) {
                    return 0;
                }
                $this->app->make(Backup::class)->run('full');
                return 1;
        }
        throw new \InvalidArgumentException("Tarea desconocida: $task");
    }
}
