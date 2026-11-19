<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Clock;
use App\Core\Database;

/** Límite de frecuencia por ventana fija, guardado en la tabla rate_limits. */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Registra un intento en $bucket y devuelve true si sigue dentro del límite.
     */
    public function hit(string $bucket, int $max, int $windowSeconds): bool
    {
        $bucket = mb_substr($bucket, 0, 191);
        $now = Clock::nowDb();
        $windowStart = Clock::now()->modify("-{$windowSeconds} seconds")->format(Clock::DB_FORMAT);
        $this->db->run(
            'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (:b, 1, :now)
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start < :ws, 1, hits + 1),
               window_start = IF(window_start < :ws2, :now2, window_start)',
            ['b' => $bucket, 'now' => $now, 'ws' => $windowStart, 'ws2' => $windowStart, 'now2' => $now]
        );
        $hits = (int)$this->db->value('SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket]);
        return $hits <= $max;
    }

    public function clear(string $bucket): void
    {
        $this->db->run('DELETE FROM rate_limits WHERE bucket = ?', [$bucket]);
    }

    public function purge(int $olderThanSeconds = 86400): int
    {
        return $this->db->run('DELETE FROM rate_limits WHERE window_start < ?', [Clock::now()->modify("-{$olderThanSeconds} seconds")->format(Clock::DB_FORMAT)])->rowCount();
    }

    public static function key(string ...$parts): string
    {
        return implode(':', array_map(static fn ($p) => strlen($p) > 40 ? hash('sha256', $p) : $p, $parts));
    }
}
