<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Clock;

/**
 * Copias de seguridad hechas con PHP (no depende de mysqldump ni de exec,
 * que en hosting compartido pueden no estar disponibles).
 *
 *  - Base de datos: volcado SQL comprimido (.sql.gz) con estructura y datos.
 *  - Archivos: ZIP de storage/attachments y storage/mail-raw.
 *  - Cada copia guarda su SHA-256 y se puede verificar.
 *  - Rotación: se conservan las N más recientes de cada tipo.
 *  - Restaurar: SOLO desde la línea de comandos y SOLO sobre una base de datos
 *    vacía distinta de la de producción (bin/restore.php). Nunca sobrescribe.
 *
 * La clave config/app.key NO va en la copia (sin ella no se pueden descifrar
 * las credenciales): debe guardarse aparte, en un gestor de contraseñas.
 */
final class Backup
{
    public function __construct(private readonly App $app)
    {
    }

    public function dir(): string
    {
        $d = $this->app->storagePath('backups');
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d;
    }

    /** @return int id de backup_runs */
    public function run(string $kind = 'full'): int
    {
        $db = $this->app->db();
        if (!in_array($kind, ['database', 'files', 'full'], true)) {
            throw new \InvalidArgumentException('Tipo de copia no válido.');
        }
        if (!$db->value("SELECT GET_LOCK('u365_backup', 0)")) {
            throw new \RuntimeException('Ya hay una copia de seguridad en curso.');
        }
        $id = $db->insert('backup_runs', ['kind' => $kind, 'started_at' => Clock::nowDb(), 'status' => 'running']);
        try {
            $stamp = Clock::now()->setTimezone(Clock::local())->format('Ymd-His');
            $files = [];
            if ($kind !== 'files') {
                $files[] = $this->dumpDatabase($this->dir() . "/u365-bd-$stamp.sql.gz");
            }
            if ($kind !== 'database' && $this->app->settings()->bool('backups.include_files', true)) {
                $files[] = $this->zipFiles($this->dir() . "/u365-archivos-$stamp.zip");
            }
            $files = array_values(array_filter($files));
            $size = array_sum(array_map('filesize', $files));
            $names = implode(',', array_map('basename', $files));
            $hash = implode(',', array_map(static fn ($f) => hash_file('sha256', $f), $files));
            $db->run(
                "UPDATE backup_runs SET status = 'ok', finished_at = ?, file_name = ?, size_bytes = ?, sha256 = ? WHERE id = ?",
                [Clock::nowDb(), mb_substr($names, 0, 255), $size, substr($hash, 0, 64), $id]
            );
            // Se guarda el hash de cada fichero junto a él para verificarlo después
            foreach ($files as $f) {
                file_put_contents($f . '.sha256', hash_file('sha256', $f) . '  ' . basename($f) . "\n");
            }
            $this->rotate();
            $this->app->audit()->log('backup.created', 'backup', $id, ['tipo' => $kind, 'ficheros' => $names], 'system', null);
            return $id;
        } catch (\Throwable $e) {
            $db->run("UPDATE backup_runs SET status = 'error', finished_at = ?, error = ? WHERE id = ?", [Clock::nowDb(), mb_substr($e->getMessage(), 0, 1000), $id]);
            throw $e;
        } finally {
            $db->value("SELECT RELEASE_LOCK('u365_backup')");
        }
    }

    public function dumpDatabase(string $path): string
    {
        $db = $this->app->db();
        $pdo = $db->pdo();
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('No se pudo crear el fichero de copia.');
        }
        $w = static function (string $s) use ($gz): void {
            gzwrite($gz, $s);
        };
        $w("-- UNIVERS365 Incidencias: copia de base de datos\n-- Fecha (UTC): " . Clock::nowDb() . "\n-- Restaurar SOLO con bin/restore.php sobre una base de datos vacía.\n");
        $w("SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET foreign_key_checks = 0;\nSET unique_checks = 0;\nSET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = $db->column("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name");
        foreach ($tables as $table) {
            $table = (string)$table;
            \App\Core\Database::assertIdentifier($table);
            $create = $db->one("SHOW CREATE TABLE `$table`");
            $w("-- Tabla $table\nCREATE TABLE `$table` " . substr((string)$create['Create Table'], strpos((string)$create['Create Table'], '(')) . ";\n");
            $cols = $db->all(
                "SELECT column_name AS name, data_type AS type, extra FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position",
                [$table]
            );
            $cols = array_values(array_filter($cols, static fn ($c) => !str_contains(strtoupper((string)$c['extra']), 'GENERATED')));
            $names = array_map(static fn ($c) => '`' . $c['name'] . '`', $cols);
            $binary = array_map(static fn ($c) => in_array(strtolower((string)$c['type']), ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob'], true), $cols);
            $stmt = $pdo->query('SELECT ' . implode(',', $names) . " FROM `$table`", \PDO::FETCH_NUM);
            $batch = [];
            foreach ($stmt as $row) {
                $vals = [];
                foreach ($row as $i => $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif ($binary[$i]) {
                        $vals[] = $v === '' ? "''" : '0x' . bin2hex((string)$v);
                    } else {
                        $vals[] = $pdo->quote((string)$v);
                    }
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 200) {
                    $w("INSERT INTO `$table` (" . implode(',', $names) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $w("INSERT INTO `$table` (" . implode(',', $names) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $w("\n");
        }
        $w("SET foreign_key_checks = 1;\nSET unique_checks = 1;\n-- FIN DE LA COPIA\n");
        gzclose($gz);
        @chmod($path, 0640);
        return $path;
    }

    public function zipFiles(string $path): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('La copia de archivos necesita la extensión zip de PHP.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el ZIP de archivos.');
        }
        $n = 0;
        foreach (['attachments', 'mail-raw'] as $sub) {
            $base = $this->app->storagePath($sub);
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $zip->addFile($file->getPathname(), $sub . '/' . substr($file->getPathname(), strlen($base) + 1));
                    $n++;
                }
            }
        }
        $zip->addFromString('LEEME.txt', "Archivos de UNIVERS365 Incidencias (adjuntos y correos originales).\nSe restauran copiando las carpetas dentro de storage/.\n");
        $zip->close();
        @chmod($path, 0640);
        return $path;
    }

    /** @return list<array{name:string,size:int,mtime:int,verified:?bool}> */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->dir() . '/u365-*.{sql.gz,zip}', GLOB_BRACE) ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'mtime' => (int)filemtime($f), 'verified' => null];
        }
        usort($out, static fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    public function pathFor(string $name): string
    {
        if (!preg_match('/^u365-(bd|archivos)-\d{8}-\d{6}\.(sql\.gz|zip)$/', $name)) {
            throw new \InvalidArgumentException('Nombre de copia no válido.');
        }
        $p = $this->dir() . '/' . $name;
        if (!is_file($p)) {
            throw new \RuntimeException('La copia no existe.');
        }
        return $p;
    }

    /** Comprueba hash y que el fichero se puede leer entero. Devuelve null si está bien o el problema. */
    public function verify(string $name): ?string
    {
        $p = $this->pathFor($name);
        $expected = is_file($p . '.sha256') ? strtok((string)file_get_contents($p . '.sha256'), ' ') : null;
        if ($expected !== null && !hash_equals($expected, (string)hash_file('sha256', $p))) {
            return 'El fichero ha cambiado desde que se creó (hash distinto).';
        }
        if (str_ends_with($name, '.sql.gz')) {
            $gz = gzopen($p, 'rb');
            if ($gz === false) {
                return 'No se puede abrir.';
            }
            $last = '';
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 1048576);
                if ($chunk === false) {
                    gzclose($gz);
                    return 'El fichero comprimido está dañado.';
                }
                $last = substr($last . $chunk, -200);
            }
            gzclose($gz);
            if (!str_contains($last, '-- FIN DE LA COPIA')) {
                return 'La copia está incompleta (no llega al final).';
            }
        } else {
            $zip = new \ZipArchive();
            if ($zip->open($p, \ZipArchive::CHECKCONS) !== true) {
                return 'El ZIP está dañado.';
            }
            $zip->close();
        }
        $this->app->db()->run('UPDATE backup_runs SET verified_at = ? WHERE file_name LIKE ?', [Clock::nowDb(), '%' . \App\Core\Database::likeEscape($name) . '%']);
        return null;
    }

    public function rotate(): int
    {
        $keep = max(2, $this->app->settings()->int('backups.keep', 14));
        $removed = 0;
        foreach (['bd', 'archivos'] as $type) {
            $files = glob($this->dir() . "/u365-$type-*") ?: [];
            $files = array_values(array_filter($files, static fn ($f) => !str_ends_with($f, '.sha256')));
            rsort($files);
            foreach (array_slice($files, $keep) as $old) {
                @unlink($old);
                @unlink($old . '.sha256');
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Restaura un volcado en OTRA base de datos vacía (nunca en la actual).
     * @param array{host:string,port:int,name:string,user:string,pass:string} $target
     */
    public function restoreInto(string $name, array $target): int
    {
        $current = (array)$this->app->config('db');
        if (strtolower($target['name']) === strtolower((string)$current['name']) && strtolower($target['host']) === strtolower((string)$current['host'])) {
            throw new \RuntimeException('Por seguridad no se restaura sobre la base de datos en uso. Indica una base de datos nueva y vacía.');
        }
        $db = \App\Core\Database::connect($target);
        if ((int)$db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') > 0) {
            throw new \RuntimeException('La base de datos de destino no está vacía. No se restaura.');
        }
        $p = $this->pathFor($name);
        if (($problem = $this->verify($name)) !== null) {
            throw new \RuntimeException('La copia no supera la verificación: ' . $problem);
        }
        $sql = (string)gzdecode((string)file_get_contents($p));
        $statements = \App\Services\Migrator::splitStatements($sql);
        $n = 0;
        foreach ($statements as $st) {
            $db->pdo()->exec($st);
            $n++;
        }
        return $n;
    }
}
