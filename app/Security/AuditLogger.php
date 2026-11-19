<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Clock;
use App\Core\Database;

/**
 * Registro de auditoría global (audit_logs).
 *
 * Las filas se insertan dentro de la transacción de la operación auditada y
 * después se "sellan": se les asigna un orden (chain_seq) y un hash que incluye
 * el de la fila anterior. Borrar o modificar una fila sellada rompe la cadena y
 * verify() lo detecta. Sellar después evita que dos transacciones concurrentes
 * tomen el mismo predecesor.
 */
final class AuditLogger
{
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
    private const SENSITIVE_KEYS = '/pass|secret|token|key|authorization|cookie/i';

    private ?int $actorId = null;
    private ?string $ip = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function setContext(?int $actorUserId, ?string $ip): void
    {
        $this->actorId = $actorUserId;
        $this->ip = $ip;
    }

    /**
     * @param array<string,mixed> $details nunca contraseñas ni tokens (se filtran por nombre)
     */
    public function log(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        array $details = [],
        ?string $actorType = null,
        ?int $actorUserId = null,
    ): void {
        $actorUserId ??= $this->actorId;
        $actorType ??= $actorUserId !== null ? 'user' : 'system';
        $this->db->insert('audit_logs', [
            'occurred_at' => Clock::now()->format('Y-m-d H:i:s.u'),
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId,
            'action' => mb_substr($action, 0, 64),
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string)$entityId,
            'ip' => $this->ip !== null ? @inet_pton($this->ip) ?: null : null,
            'details' => $details ? json_encode(self::scrub($details), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function scrub(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SENSITIVE_KEYS, $k)) {
                $data[$k] = '***';
            } elseif (is_array($v)) {
                $data[$k] = self::scrub($v);
            }
        }
        return $data;
    }

    /**
     * Sella las filas pendientes con más de $minAgeSeconds de antigüedad.
     * Devuelve cuántas filas se han sellado.
     */
    public function seal(int $minAgeSeconds = 5, int $limit = 2000): int
    {
        if ((string)$this->db->value("SELECT GET_LOCK('u365_audit_seal', 5)") !== '1') {
            return 0;
        }
        try {
            $last = $this->db->one('SELECT chain_seq, row_hash FROM audit_logs WHERE chain_seq IS NOT NULL ORDER BY chain_seq DESC LIMIT 1');
            $seq = (int)($last['chain_seq'] ?? 0);
            $prev = (string)($last['row_hash'] ?? self::GENESIS);
            $cutoff = Clock::now()->modify("-{$minAgeSeconds} seconds")->format('Y-m-d H:i:s.u');
            $rows = $this->db->all(
                'SELECT * FROM audit_logs WHERE chain_seq IS NULL AND occurred_at <= ? ORDER BY id LIMIT ' . (int)$limit,
                [$cutoff]
            );
            foreach ($rows as $r) {
                $seq++;
                $hash = self::hashRow($r, $prev, $seq);
                $this->db->run(
                    'UPDATE audit_logs SET chain_seq = ?, prev_hash = ?, row_hash = ? WHERE id = ? AND chain_seq IS NULL',
                    [$seq, $prev, $hash, (int)$r['id']]
                );
                $prev = $hash;
            }
            return count($rows);
        } finally {
            $this->db->value("SELECT RELEASE_LOCK('u365_audit_seal')");
        }
    }

    /**
     * Recorre la cadena completa. Devuelve null si es íntegra o la descripción
     * del primer problema encontrado.
     */
    public function verify(): ?string
    {
        $prev = self::GENESIS;
        $expectedSeq = 1;
        $stmt = $this->db->run('SELECT * FROM audit_logs WHERE chain_seq IS NOT NULL ORDER BY chain_seq');
        while ($r = $stmt->fetch()) {
            if ((int)$r['chain_seq'] !== $expectedSeq) {
                return sprintf('Falta el eslabón %d de la cadena (posible borrado de registros).', $expectedSeq);
            }
            if (!hash_equals((string)$r['prev_hash'], $prev) || !hash_equals((string)$r['row_hash'], self::hashRow($r, $prev, $expectedSeq))) {
                return sprintf('El registro de auditoría #%d ha sido modificado o la cadena está rota.', (int)$r['id']);
            }
            $prev = (string)$r['row_hash'];
            $expectedSeq++;
        }
        $stale = (int)$this->db->value('SELECT COUNT(*) FROM audit_logs WHERE chain_seq IS NULL AND occurred_at < ?', [Clock::now()->modify('-1 day')->format('Y-m-d H:i:s')]);
        if ($stale > 0) {
            return sprintf('%d registros de más de un día siguen sin sellar: revisa el cron.', $stale);
        }
        return null;
    }

    /** @param array<string,mixed> $r */
    private static function hashRow(array $r, string $prev, int $seq): string
    {
        $occurred = (string)$r['occurred_at'];
        if (strlen($occurred) === 19) {
            $occurred .= '.000000';
        }
        $payload = implode("\x1f", [
            $prev,
            (string)$seq,
            (string)$r['id'],
            $occurred,
            (string)$r['actor_type'],
            (string)($r['actor_user_id'] ?? ''),
            (string)$r['action'],
            (string)($r['entity_type'] ?? ''),
            (string)($r['entity_id'] ?? ''),
            bin2hex((string)($r['ip'] ?? '')),
            self::canonicalJson($r['details'] ?? null),
        ]);
        return hash('sha256', $payload);
    }

    /** MariaDB puede reformatear el JSON guardado: se normaliza antes de firmar. */
    private static function canonicalJson(mixed $json): string
    {
        if ($json === null || $json === '') {
            return '';
        }
        $decoded = json_decode((string)$json, true);
        return $decoded === null ? (string)$json : (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
