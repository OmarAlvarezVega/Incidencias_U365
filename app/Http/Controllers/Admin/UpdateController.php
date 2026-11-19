<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Services\Backup;
use App\Services\Migrator;

/**
 * Actualización sin SSH: tras subir el código nuevo por SFTP, el
 * superadministrador aplica aquí las migraciones pendientes. Antes de migrar
 * se crea siempre una copia de la base de datos; si la copia falla, no se
 * migra. MariaDB no deshace los cambios de estructura: si una migración falla
 * a medias, se recupera desde esa copia (docs/actualizacion.md).
 */
final class UpdateController extends Controller
{
    public function index(): Response
    {
        $this->requireSuperadmin();
        $migrator = $this->migrator();
        $error = null;
        $pending = [];
        try {
            $pending = $migrator->pending();
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
        $applied = $this->db()->all('SELECT version, description, applied_at FROM schema_migrations ORDER BY version DESC');
        $log = $this->app->session()->get('update_log');
        $this->app->session()->set('update_log', null);
        return $this->render('admin/update.twig', [
            'pending' => $pending,
            'applied' => $applied,
            'error' => $error,
            'log' => is_array($log) ? $log : null,
            'php_version' => PHP_VERSION,
            'db_version' => (string)$this->db()->value('SELECT VERSION()'),
        ]);
    }

    public function migrate(): Response
    {
        $this->requireSuperadmin();
        if (!$this->request->bool('confirm')) {
            throw new ValidationException([], 'Marca la casilla para confirmar que quieres aplicar las migraciones.');
        }
        $migrator = $this->migrator();
        try {
            $pending = $migrator->pending();
        } catch (\RuntimeException $e) {
            throw new ValidationException([], $e->getMessage());
        }
        if ($pending === []) {
            return $this->redirect('/admin/actualizacion', 'No hay migraciones pendientes.');
        }
        @set_time_limit(600);
        $lines = [];
        try {
            $backupId = $this->app->make(Backup::class)->run('database');
        } catch (\Throwable $e) {
            throw new ValidationException([], 'No se ha migrado nada: la copia previa de la base de datos ha fallado (' . $e->getMessage() . ').');
        }
        $file = (string)$this->db()->value('SELECT file_name FROM backup_runs WHERE id = ?', [$backupId]);
        $lines[] = "Copia previa creada: $file";
        $versions = array_column($pending, 'version');
        try {
            $n = $migrator->migrate(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });
        } catch (\Throwable $e) {
            $lines[] = 'ERROR: ' . $e->getMessage();
            $this->audit()->log('system.migration_failed', null, null, ['pendientes' => $versions, 'copia' => $file, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            $this->app->session()->set('update_log', ['ok' => false, 'lines' => $lines, 'backup' => $file]);
            return $this->redirect('/admin/actualizacion');
        }
        $this->audit()->log('system.migrated', null, null, ['aplicadas' => $versions, 'copia' => $file, 'version' => $this->app->version()]);
        $this->app->session()->set('update_log', ['ok' => true, 'lines' => $lines, 'backup' => $file]);
        return $this->redirect('/admin/actualizacion', "Base de datos actualizada: $n migraciones aplicadas.");
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->db(), $this->app->root . '/database/migrations');
    }

    private function requireSuperadmin(): void
    {
        if ($this->user()->roleCode !== 'superadmin') {
            throw \App\Core\HttpException::forbidden('Solo el superadministrador puede actualizar la aplicación.');
        }
    }
}
