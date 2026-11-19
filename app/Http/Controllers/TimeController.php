<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Clock;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Services\TicketAccess;
use App\Services\TimeService;

final class TimeController extends Controller
{
    private function time(): TimeService
    {
        return $this->app->make(TimeService::class);
    }

    public function current(): Response
    {
        $e = $this->time()->running($this->user()->id);
        if ($e === null) {
            return $this->json(['running' => false]);
        }
        return $this->json([
            'running' => true,
            'ticket_id' => (int)$e['ticket_id'],
            'ticket_number' => $e['ticket_number'],
            'elapsed_seconds' => max(0, Clock::now()->getTimestamp() - Clock::fromDb((string)$e['started_at'])->getTimestamp()),
        ]);
    }

    public function start(): Response
    {
        $u = $this->user();
        $access = $this->app->make(TicketAccess::class);
        $t = $access->find($u, $this->request->paramInt('id'));
        if (!$access->isWorker($u, $t) && !$access->supervises($u, $t)) {
            throw new ValidationException([], 'Solo pueden registrar tiempo el responsable, los colaboradores o sus coordinadores.');
        }
        $this->time()->start($t, $u);
        return $this->redirect('/incidencias/' . $t['id'], 'Temporizador iniciado.');
    }

    public function stop(): Response
    {
        $u = $this->user();
        $running = $this->time()->running($u->id);
        $minutes = $this->time()->stop($u, $this->request->str('description') ?: null);
        $back = $running !== null ? '/incidencias/' . $running['ticket_id'] : '/';
        return $this->redirect($back, 'Temporizador detenido: ' . Clock::minutesToHuman($minutes) . ' registrados.');
    }

    public function store(): Response
    {
        $u = $this->user();
        $access = $this->app->make(TicketAccess::class);
        $t = $access->find($u, $this->request->paramInt('id'));
        if (!$access->isWorker($u, $t) && !$access->supervises($u, $t)) {
            throw new ValidationException([], 'Solo pueden registrar tiempo el responsable, los colaboradores o sus coordinadores.');
        }
        $d = $this->validate([
            'hours' => 'int|min:0|max:24',
            'minutes' => 'int|min:0|max:1440',
            'work_date' => 'required|day',
            'description' => 'string|max:1000',
            'billable' => 'bool',
            'user_id' => 'int',
        ], ['hours' => 'Las horas', 'minutes' => 'Los minutos', 'work_date' => 'El día']);
        $total = (int)$d['hours'] * 60 + (int)$d['minutes'];
        $this->time()->addManual($t, $u, $total, $d['work_date'], $d['description'], $d['billable'], $d['user_id']);
        return $this->redirect('/incidencias/' . $t['id'], 'Tiempo registrado: ' . Clock::minutesToHuman($total) . '.');
    }

    public function update(): Response
    {
        $u = $this->user();
        $e = $this->time()->editable($this->request->paramInt('id'), $u);
        $this->app->make(TicketAccess::class)->find($u, (int)$e['ticket_id']);
        $d = $this->validate([
            'minutes' => 'required|int|min:1|max:1440',
            'work_date' => 'required|day',
            'description' => 'string|max:1000',
            'billable' => 'bool',
        ], ['minutes' => 'Los minutos', 'work_date' => 'El día']);
        $this->time()->update($e, $u, $d['minutes'], $d['work_date'], $d['description'], $d['billable']);
        return $this->redirect('/incidencias/' . $e['ticket_id'], 'Registro de tiempo corregido.');
    }

    public function destroy(): Response
    {
        $u = $this->user();
        $e = $this->time()->editable($this->request->paramInt('id'), $u);
        $this->app->make(TicketAccess::class)->find($u, (int)$e['ticket_id']);
        if ($e['running_user_id'] !== null) {
            throw new ValidationException([], 'Para el temporizador antes de eliminarlo.');
        }
        $this->time()->delete($e, $u);
        return $this->redirect('/incidencias/' . $e['ticket_id'], 'Registro de tiempo eliminado.');
    }

    /** Parte de horas propio por semana. */
    public function mine(): Response
    {
        $u = $this->user();
        $ref = Clock::parseLocal($this->request->str('semana')) ?? Clock::now();
        $local = $ref->setTimezone(Clock::local());
        $monday = $local->modify('monday this week')->setTime(0, 0);
        $sunday = $monday->modify('+6 days');
        $rows = $this->db()->all(
            "SELECT te.*, t.ticket_number, t.subject, COALESCE(c.trade_name, c.legal_name) AS client
               FROM ticket_time_entries te JOIN tickets t ON t.id = te.ticket_id LEFT JOIN clients c ON c.id = t.client_id
              WHERE te.user_id = ? AND te.deleted_at IS NULL AND te.work_date BETWEEN ? AND ?
           ORDER BY te.work_date, te.id",
            [$u->id, $monday->format('Y-m-d'), $sunday->format('Y-m-d')]
        );
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->modify("+$i days");
            $days[$d->format('Y-m-d')] = ['label' => $d->format('d/m'), 'dow' => ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'][$i], 'minutes' => 0];
        }
        foreach ($rows as $r) {
            $days[$r['work_date']]['minutes'] += (int)$r['minutes'];
        }
        return $this->render('time/mine.twig', [
            'rows' => $rows, 'days' => $days,
            'week_label' => $monday->format('d/m/Y') . ' – ' . $sunday->format('d/m/Y'),
            'prev' => $monday->modify('-7 days')->format('Y-m-d'), 'next' => $monday->modify('+7 days')->format('Y-m-d'),
            'total' => array_sum(array_column($days, 'minutes')),
        ]);
    }
}
