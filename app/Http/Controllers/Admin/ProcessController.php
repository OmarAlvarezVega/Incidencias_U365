<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Security\Crypto;
use App\Services\Jobs;
use App\Services\Scheduler;

/** Estado de las tareas programadas y de la cola; ejecución manual. */
final class ProcessController extends Controller
{
    public function index(): Response
    {
        $db = $this->db();
        $tasks = [];
        foreach (Scheduler::TASKS as $code => [$every, $label]) {
            $last = $db->one('SELECT * FROM scheduled_task_runs WHERE task = ? ORDER BY id DESC LIMIT 1', [$code]);
            $lastOk = $db->value("SELECT MAX(finished_at) FROM scheduled_task_runs WHERE task = ? AND status = 'ok'", [$code]);
            $tasks[] = ['code' => $code, 'label' => $label, 'every' => $every, 'last' => $last, 'last_ok' => $lastOk];
        }
        $queue = array_column($db->all('SELECT status, COUNT(*) AS n FROM background_jobs GROUP BY status'), 'n', 'status');
        $problems = $db->all("SELECT id, job_type, status, attempts, max_attempts, last_error, available_at, created_at FROM background_jobs WHERE status IN ('failed','dead') OR (status = 'pending' AND attempts > 0) ORDER BY id DESC LIMIT 50");
        $lastCron = $db->value("SELECT MAX(started_at) FROM scheduled_task_runs WHERE trigger_src IN ('cron','http')");
        return $this->render('admin/processes.twig', [
            'tasks' => $tasks,
            'queue' => $queue,
            'problems' => $problems,
            'last_cron' => $lastCron,
            'http_key_configured' => (string)$this->app->settings()->get('cron.key_sha256', '') !== '' || (string)$this->app->config('cron_key_sha256', '') !== '',
            'new_key' => $this->takeOnce('cron_new_key'),
            'results' => $this->takeOnce('process_results'),
            'cron_url' => $this->app->url('/cron/run'),
            'php_binary' => PHP_BINARY,
            'root' => $this->app->root,
        ]);
    }

    public function run(): Response
    {
        $only = $this->request->str('task');
        if ($only !== '' && !isset(Scheduler::TASKS[$only])) {
            throw new ValidationException([], 'Tarea desconocida.');
        }
        $results = $this->app->make(Scheduler::class)->run('manual', $only === '' ? null : [$only], 25.0);
        $this->audit()->log('scheduler.manual_run', null, null, ['tarea' => $only ?: 'todas']);
        $this->app->session()->set('process_results', $results);
        return $this->redirect('/admin/procesos');
    }

    public function retry(): Response
    {
        $id = $this->request->paramInt('id');
        $ok = $this->app->make(Jobs::class)->retry($id);
        $this->audit()->log('job.retried', 'job', $id);
        return $this->redirect('/admin/procesos', $ok ? 'Trabajo puesto de nuevo en cola.' : 'Ese trabajo no se puede reintentar.');
    }

    /** Genera la clave del disparador HTTP. Se guarda solo su hash y se muestra una vez. */
    public function cronKey(): Response
    {
        if ($this->request->bool('disable')) {
            $this->app->settings()->set('cron.key_sha256', null, $this->user()->id);
            $this->audit()->log('cron.key_disabled');
            return $this->redirect('/admin/procesos', 'Disparador HTTP desactivado.');
        }
        $key = Crypto::randomToken(32);
        $this->app->settings()->set('cron.key_sha256', hash('sha256', $key), $this->user()->id);
        $this->app->session()->set('cron_new_key', $key);
        $this->audit()->log('cron.key_generated');
        return $this->redirect('/admin/procesos');
    }

    private function takeOnce(string $key): mixed
    {
        $v = $this->app->session()->get($key);
        $this->app->session()->remove($key);
        return $v;
    }
}
