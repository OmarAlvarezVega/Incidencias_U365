<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Services\Backup;

/**
 * Copias de seguridad desde la interfaz: crear, verificar y descargar. La
 * restauración solo existe por línea de comandos y sobre una base de datos
 * nueva (ver docs/copias-de-seguridad.md).
 */
final class BackupController extends Controller
{
    public function index(): Response
    {
        $svc = $this->app->make(Backup::class);
        $runs = $this->db()->all('SELECT * FROM backup_runs ORDER BY id DESC LIMIT 30');
        $free = @disk_free_space($svc->dir());
        return $this->render('admin/backups.twig', [
            'files' => $svc->list(),
            'runs' => $runs,
            'free' => $free === false ? null : (int)$free,
            'auto' => $this->app->settings()->bool('backups.auto', false),
            'keep' => $this->app->settings()->int('backups.keep', 14),
        ]);
    }

    public function create(): Response
    {
        $kind = $this->request->str('kind', 'full');
        if (!in_array($kind, ['database', 'files', 'full'], true)) {
            throw new ValidationException([], 'Tipo de copia no válido.');
        }
        @set_time_limit(300);
        try {
            $this->app->make(Backup::class)->run($kind);
        } catch (\RuntimeException $e) {
            throw new ValidationException([], 'No se pudo crear la copia: ' . $e->getMessage());
        }
        return $this->redirect('/admin/copias', 'Copia creada. Descárgala y guárdala fuera de IONOS: una copia en el mismo servidor no protege frente a la pérdida del alojamiento.');
    }

    public function verify(): Response
    {
        $name = $this->request->str('name');
        try {
            $problem = $this->app->make(Backup::class)->verify($name);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            throw new ValidationException([], $e->getMessage());
        }
        $this->audit()->log('backup.verified', 'backup', null, ['fichero' => $name, 'resultado' => $problem ?? 'ok']);
        if ($problem !== null) {
            throw new ValidationException([], "La copia $name NO es válida: $problem");
        }
        return $this->redirect('/admin/copias', "La copia $name está completa y no se ha modificado.");
    }

    public function download(): Response
    {
        $name = $this->request->str('name');
        try {
            $path = $this->app->make(Backup::class)->pathFor($name);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            throw new ValidationException([], $e->getMessage());
        }
        $this->audit()->log('backup.downloaded', 'backup', null, ['fichero' => $name]);
        return Response::file($path, $name, str_ends_with($name, '.zip') ? 'application/zip' : 'application/gzip');
    }
}
