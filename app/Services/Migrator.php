<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use RuntimeException;

/**
 * Migraciones versionadas: ficheros NNNN_descripcion.sql en
 * database/migrations, aplicados en orden y registrados con su SHA-256.
 *
 * - Nunca se reaplica una migración.
 * - Si un fichero ya aplicado ha cambiado, se detiene (las migraciones son
 *   inmutables: un cambio se hace con una migración nueva).
 * - En MariaDB los cambios de estructura no son transaccionales: antes de
 *   migrar producción hay que hacer copia de seguridad.
 */
final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $dir)
    {
    }

    /** @return list<array{version:string,description:string,file:string,checksum:string}> */
    public function available(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $file) {
            if (!preg_match('/^(\d{4})_([a-z0-9_]+)\.sql$/', basename($file), $m)) {
                continue;
            }
            $out[] = [
                'version' => $m[1],
                'description' => str_replace('_', ' ', $m[2]),
                'file' => $file,
                'checksum' => hash_file('sha256', $file) ?: '',
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['version'], $b['version']));
        return $out;
    }

    public function isInstalled(): bool
    {
        return (bool)$this->db->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'");
    }

    public function tableCount(): int
    {
        return (int)$this->db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
    }

    /** @return array<string,string> version => checksum */
    public function applied(): array
    {
        if (!$this->isInstalled()) {
            return [];
        }
        $rows = $this->db->all('SELECT version, checksum FROM schema_migrations');
        return array_column($rows, 'checksum', 'version');
    }

    /** @return list<array{version:string,description:string,file:string,checksum:string}> */
    public function pending(): array
    {
        $applied = $this->applied();
        $pending = [];
        foreach ($this->available() as $m) {
            if (isset($applied[$m['version']])) {
                if (!hash_equals($applied[$m['version']], $m['checksum'])) {
                    throw new RuntimeException("La migración {$m['version']} ya aplicada ha cambiado. Las migraciones son inmutables: crea una nueva.");
                }
                continue;
            }
            $pending[] = $m;
        }
        return $pending;
    }

    /**
     * Aplica las migraciones pendientes. $log recibe una línea por paso.
     * @param callable(string):void|null $log
     * @return int número de migraciones aplicadas
     */
    public function migrate(?callable $log = null): int
    {
        $log ??= static function (string $m): void {
        };
        if ((string)$this->db->value("SELECT GET_LOCK('u365_migrate', 30)") !== '1') {
            throw new RuntimeException('Otra migración está en curso.');
        }
        try {
            $pending = $this->pending();
            if ($pending === [] ) {
                $log('No hay migraciones pendientes.');
                return 0;
            }
            if (!$this->isInstalled() && $this->tableCount() > 0) {
                throw new RuntimeException('La base de datos no está vacía y no contiene una instalación de esta aplicación. No se modifica nada.');
            }
            foreach ($pending as $m) {
                $log("Aplicando {$m['version']} {$m['description']}...");
                $sql = (string)file_get_contents($m['file']);
                foreach (self::splitStatements($sql) as $stmt) {
                    $this->db->pdo()->exec($stmt);
                }
                $this->db->run(
                    'INSERT INTO schema_migrations (version, description, checksum, applied_at) VALUES (?, ?, ?, ?)',
                    [$m['version'], $m['description'], $m['checksum'], Clock::nowDb()]
                );
                $log("  hecho.");
            }
            return count($pending);
        } finally {
            $this->db->value("SELECT RELEASE_LOCK('u365_migrate')");
        }
    }

    /**
     * Divide un script SQL en sentencias respetando comillas y comentarios.
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`') {
                    $buf .= $n;
                    $i++;
                } elseif ($c === $quote) {
                    if ($n === $quote) {
                        $buf .= $n;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($c === '-' && $n === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $buf .= "\n";
                continue;
            }
            if ($c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $n === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                $buf .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
