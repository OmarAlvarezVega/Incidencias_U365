<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\Backup;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnv;

final class BackupTest extends TestCase
{
    /** @return array{host:string,port:int,name:string,user:string,pass:string} */
    private static function restoreTarget(): array
    {
        $cfg = TestEnv::dbConfig();
        $cfg['name'] = (string)(getenv('TEST_DB_RESTORE_NAME') ?: $cfg['name'] . '_restore');
        if (!str_contains($cfg['name'], 'test')) {
            self::fail('La base de restauración de pruebas debe contener "test".');
        }
        return $cfg;
    }

    public function testCopiaVerificaYRestauraEnOtraBase(): void
    {
        $app = TestEnv::app();
        $backup = $app->make(Backup::class);
        $runId = $backup->run('database');
        $file = (string)TestEnv::db()->value('SELECT file_name FROM backup_runs WHERE id = ? AND status = ?', [$runId, 'ok']);
        $this->assertMatchesRegularExpression('/^u365-bd-\d{8}-\d{6}\.sql\.gz$/', $file);
        $this->assertNull($backup->verify($file));

        // Destino vacío
        try {
            $target = Database::connect(self::restoreTarget());
        } catch (\PDOException $e) {
            $this->markTestSkipped('No hay base de datos de restauración de pruebas: ' . $e->getMessage());
        }
        $target->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($target->column('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()') as $t) {
            $target->pdo()->exec('DROP TABLE `' . str_replace('`', '', (string)$t) . '`');
        }
        $target->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->assertGreaterThan(0, $backup->restoreInto($file, self::restoreTarget()));
        // audit_logs no se compara: la propia copia añade su registro después del volcado
        foreach (['users', 'tickets', 'roles', 'role_permissions', 'application_settings', 'schema_migrations'] as $table) {
            $this->assertSame(
                (int)TestEnv::db()->value("SELECT COUNT(*) FROM `$table`"),
                (int)$target->value("SELECT COUNT(*) FROM `$table`"),
                "Filas de $table"
            );
        }
        // Una segunda restauración sobre la misma base (ya no vacía) se rechaza
        $this->expectException(\RuntimeException::class);
        $backup->restoreInto($file, self::restoreTarget());
    }

    public function testNoRestauraSobreLaBaseEnUso(): void
    {
        $backup = TestEnv::app()->make(Backup::class);
        $runId = $backup->run('database');
        $file = (string)TestEnv::db()->value('SELECT file_name FROM backup_runs WHERE id = ?', [$runId]);
        $this->expectException(\RuntimeException::class);
        $backup->restoreInto($file, TestEnv::dbConfig());
    }

    public function testDetectaUnaCopiaManipulada(): void
    {
        $backup = TestEnv::app()->make(Backup::class);
        $runId = $backup->run('database');
        $file = (string)TestEnv::db()->value('SELECT file_name FROM backup_runs WHERE id = ?', [$runId]);
        file_put_contents($backup->pathFor($file), 'x', FILE_APPEND);
        $this->assertNotNull($backup->verify($file));
    }

    public function testNombresDeCopiaNoPermitenRecorrerCarpetas(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TestEnv::app()->make(Backup::class)->pathFor('../config/config.php');
    }
}
