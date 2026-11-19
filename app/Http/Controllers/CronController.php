<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Response;
use App\Http\Controller;
use App\Security\Crypto;
use App\Security\RateLimiter;
use App\Services\Scheduler;

/**
 * Disparador HTTP del cron, para planes sin cron de sistema. Protegido por
 * una clave en la cabecera X-Cron-Key (solo se guarda su hash en la
 * configuración). Si no hay hash configurado, el disparador está desactivado.
 */
final class CronController extends Controller
{
    public function run(): Response
    {
        $expected = (string)$this->app->config('cron_key_sha256', '');
        $stored = (string)($this->app->settings()->get('cron.key_sha256', '') ?? '');
        $hashes = array_filter([$expected, $stored]);
        if ($hashes === []) {
            return Response::json(['ok' => false, 'message' => 'Disparador HTTP desactivado'], 404);
        }
        if (!$this->app->rateLimiter()->hit(RateLimiter::key('cron', $this->request->ip()), 6, 60)) {
            return Response::json(['ok' => false, 'message' => 'Demasiadas peticiones'], 429);
        }
        $key = (string)$this->request->header('X-Cron-Key');
        $given = Crypto::tokenHash($key);
        $ok = false;
        foreach ($hashes as $h) {
            $ok = $ok || hash_equals($h, $given);
        }
        if ($key === '' || !$ok) {
            return Response::json(['ok' => false, 'message' => 'Clave no válida'], 403);
        }
        @set_time_limit(60);
        ignore_user_abort(true);
        $results = $this->app->make(Scheduler::class)->run('http', null, 45.0);
        return Response::json(['ok' => true, 'tareas' => $results]);
    }
}
