<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Clock;

/**
 * Cola de trabajos en base de datos, procesada por el cron.
 *
 * - El payload solo lleva identificadores, nunca secretos ni contenido.
 * - unique_key evita encolar dos veces el mismo trabajo mientras está vivo;
 *   al terminar se libera para permitir uno nuevo.
 * - Reintentos con espera exponencial; tras max_attempts pasa a 'dead' y se
 *   ve en Administración → Procesos, desde donde se puede reintentar.
 */
final class Jobs
{
    /** @var array<string,callable(array<string,mixed>):void> */
    private array $handlers = [];

    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string,mixed> $payload */
    public function push(string $type, array $payload, ?string $uniqueKey = null, int $delaySeconds = 0, int $maxAttempts = 5, string $queue = 'default'): ?int
    {
        $now = Clock::now();
        $inserted = $this->app->db()->run(
            'INSERT IGNORE INTO background_jobs (queue, job_type, payload, unique_key, status, attempts, max_attempts, available_at, created_at)
             VALUES (?, ?, ?, ?, \'pending\', 0, ?, ?, ?)',
            [
                $queue, $type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $uniqueKey,
                max(1, min(20, $maxAttempts)),
                $now->modify('+' . max(0, $delaySeconds) . ' seconds')->format(Clock::DB_FORMAT),
                $now->format(Clock::DB_FORMAT),
            ]
        )->rowCount();
        return $inserted > 0 ? $this->app->db()->lastInsertId() : null;
    }

    /** @param callable(array<string,mixed>):void $handler */
    public function handle(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * Procesa trabajos hasta agotar el presupuesto de tiempo.
     * @return int trabajos procesados
     */
    public function work(float $budgetSeconds = 40.0, string $queue = 'default'): int
    {
        $db = $this->app->db();
        $deadline = microtime(true) + $budgetSeconds;
        $worker = self::ulid();
        $done = 0;

        // Trabajos que quedaron "en ejecución" por un proceso que murió
        $db->run(
            "UPDATE background_jobs SET status = 'pending', reserved_at = NULL, reserved_by = NULL
              WHERE status = 'running' AND reserved_at < ?",
            [Clock::now()->modify('-15 minutes')->format(Clock::DB_FORMAT)]
        );

        while (microtime(true) < $deadline) {
            $now = Clock::nowDb();
            $n = $db->run(
                "UPDATE background_jobs SET status = 'running', reserved_at = ?, reserved_by = ?, attempts = attempts + 1
                  WHERE status = 'pending' AND queue = ? AND available_at <= ?
                  ORDER BY available_at, id LIMIT 1",
                [$now, $worker, $queue, $now]
            )->rowCount();
            if ($n === 0) {
                break;
            }
            $job = $db->one("SELECT * FROM background_jobs WHERE reserved_by = ? AND status = 'running' ORDER BY reserved_at DESC, id DESC LIMIT 1", [$worker]);
            if ($job === null) {
                break;
            }
            $this->runOne($job);
            $done++;
        }
        return $done;
    }

    /** @param array<string,mixed> $job */
    private function runOne(array $job): void
    {
        $db = $this->app->db();
        $handler = $this->handlers[$job['job_type']] ?? null;
        try {
            if ($handler === null) {
                throw new \RuntimeException('Tipo de trabajo desconocido: ' . $job['job_type']);
            }
            $handler((array)json_decode((string)$job['payload'], true));
            $db->run(
                "UPDATE background_jobs SET status = 'done', finished_at = ?, unique_key = NULL, last_error = NULL WHERE id = ?",
                [Clock::nowDb(), (int)$job['id']]
            );
        } catch (\Throwable $e) {
            $attempts = (int)$job['attempts'];
            $dead = $e instanceof PermanentJobFailure || $attempts >= (int)$job['max_attempts'];
            $delay = min(3600, 60 * (2 ** max(0, $attempts - 1)));
            $msg = mb_substr(\App\Core\Logger::redact($e->getMessage()), 0, 1900);
            $db->run(
                'UPDATE background_jobs SET status = ?, available_at = ?, reserved_at = NULL, reserved_by = NULL, last_error = ?, finished_at = ?, unique_key = ? WHERE id = ?',
                [
                    $dead ? 'dead' : 'pending',
                    Clock::now()->modify("+$delay seconds")->format(Clock::DB_FORMAT),
                    $msg,
                    $dead ? Clock::nowDb() : null,
                    $dead ? null : $job['unique_key'],
                    (int)$job['id'],
                ]
            );
            $this->app->logger()->warning('Trabajo ' . $job['job_type'] . ' #' . $job['id'] . ' falló: ' . $msg);
        }
    }

    public function retry(int $id): bool
    {
        return $this->app->db()->run(
            "UPDATE background_jobs SET status = 'pending', attempts = 0, available_at = ?, last_error = NULL, finished_at = NULL
              WHERE id = ? AND status IN ('dead','failed')",
            [Clock::nowDb(), $id]
        )->rowCount() > 0;
    }

    public function purge(int $days = 30): int
    {
        return $this->app->db()->run(
            "DELETE FROM background_jobs WHERE status = 'done' AND finished_at < ?",
            [Clock::now()->modify("-$days days")->format(Clock::DB_FORMAT)]
        )->rowCount();
    }

    /** Identificador ordenable de 26 caracteres (Crockford base32). */
    public static function ulid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = (int)(microtime(true) * 1000);
        $out = '';
        for ($i = 9; $i >= 0; $i--) {
            $out = $alphabet[$time % 32] . $out;
            $time = intdiv($time, 32);
        }
        $rand = random_bytes(16);
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[ord($rand[$i]) % 32];
        }
        return $out;
    }
}
