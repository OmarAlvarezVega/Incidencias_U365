<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Response;
use App\Http\Controller;
use App\Services\Reports;
use App\Services\TicketAccess;
use App\Services\TimeService;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        $u = $this->user();
        $kpis = $this->app->make(Reports::class)->kpis($u);
        [$w, $p] = $this->app->make(TicketAccess::class)->scope($u);
        $db = $this->db();
        $mine = $db->all(
            "SELECT t.id, t.ticket_number, t.subject, t.last_activity_at, t.due_at, s.name AS status, s.color AS status_color,
                    pr.name AS priority, pr.color AS priority_color, COALESCE(c.trade_name, c.legal_name) AS client,
                    sla.first_response_due_at, sla.resolution_due_at, sla.first_response_breached_at, sla.resolution_breached_at
               FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id JOIN ticket_priorities pr ON pr.id = t.priority_id
          LEFT JOIN clients c ON c.id = t.client_id LEFT JOIN ticket_sla sla ON sla.ticket_id = t.id
              WHERE s.phase IN ('new','open','pending') AND (t.owner_user_id = ? OR EXISTS (SELECT 1 FROM ticket_participants tp WHERE tp.ticket_id = t.id AND tp.user_id = ? AND tp.removed_at IS NULL)) AND $w
           ORDER BY pr.level DESC, t.last_activity_at DESC LIMIT 10",
            array_merge([$u->id, $u->id], $p)
        );
        $unassigned = $u->canAny('tickets.view.unassigned', 'tickets.view.all') ? $db->all(
            "SELECT t.id, t.ticket_number, t.subject, t.created_at, pr.name AS priority, pr.color AS priority_color, COALESCE(c.trade_name, c.legal_name, t.requester_email, t.requester_phone) AS client
               FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id JOIN ticket_priorities pr ON pr.id = t.priority_id LEFT JOIN clients c ON c.id = t.client_id
              WHERE s.phase IN ('new','open','pending') AND t.owner_user_id IS NULL AND $w ORDER BY pr.level DESC, t.created_at LIMIT 8",
            $p
        ) : [];
        $timer = $this->app->make(TimeService::class)->running($u->id);
        return $this->render('dashboard/index.twig', compact('kpis', 'mine', 'unassigned', 'timer'));
    }

    public function data(): Response
    {
        return $this->json(['charts' => $this->app->make(Reports::class)->charts($this->user())]);
    }
}
