<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Response;
use App\Http\Controller;
use App\Services\Attachments;
use App\Services\TicketAccess;
use App\Services\TicketService;

/**
 * Descarga de adjuntos: siempre comprueba que el usuario ve la incidencia y,
 * si el adjunto es interno, que tiene permiso para adjuntos internos.
 */
final class AttachmentController extends Controller
{
    /** @return array<string,mixed> */
    private function find(): array
    {
        $u = $this->user();
        $a = $this->db()->one("SELECT * FROM ticket_attachments WHERE id = ? AND deleted_at IS NULL AND status = 'stored'", [$this->request->paramInt('id')]);
        if ($a === null) {
            throw HttpException::notFound();
        }
        $this->app->make(TicketAccess::class)->find($u, (int)$a['ticket_id']);
        if ($a['visibility'] === 'internal' && !$u->can('attachments.view_internal')) {
            throw HttpException::notFound();
        }
        return $a;
    }

    public function download(): Response
    {
        $a = $this->find();
        $path = $this->app->make(Attachments::class)->path((string)$a['storage_key']);
        if (!is_file($path)) {
            throw HttpException::notFound('El archivo no está en el servidor.');
        }
        return Response::file($path, (string)$a['original_name'], 'application/octet-stream');
    }

    public function inline(): Response
    {
        $a = $this->find();
        if (!in_array($a['mime_type'], Attachments::INLINE_MIME, true)) {
            return $this->download();
        }
        $path = $this->app->make(Attachments::class)->path((string)$a['storage_key']);
        if (!is_file($path)) {
            throw HttpException::notFound('El archivo no está en el servidor.');
        }
        return Response::file($path, (string)$a['original_name'], (string)$a['mime_type'], true)
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    }

    public function destroy(): Response
    {
        $u = $this->user();
        $a = $this->find();
        $t = $this->app->make(TicketAccess::class)->find($u, (int)$a['ticket_id']);
        if ((int)$a['uploaded_by_user_id'] !== $u->id && !$this->app->make(TicketAccess::class)->supervises($u, $t)) {
            throw HttpException::forbidden('Solo quien lo subió o un responsable puede quitar un adjunto.');
        }
        $this->db()->run('UPDATE ticket_attachments SET deleted_at = ? WHERE id = ?', [Clock::nowDb(), (int)$a['id']]);
        $this->app->make(TicketService::class)->event((int)$t['id'], $u->id, 'attachments_added', ['eliminado' => $a['original_name']], null);
        return $this->redirect('/incidencias/' . $t['id'], 'Adjunto retirado.');
    }
}
