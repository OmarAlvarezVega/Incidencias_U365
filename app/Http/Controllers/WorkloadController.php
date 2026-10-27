<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Clock;
use App\Core\Response;
use App\Http\Controller;

/** Carga de trabajo por empleado: abiertas, vencidas, colaboraciones y tiempo de la semana. */
final class WorkloadController extends Controller
{
    public function index(): Response
    {
        $u = $this->user();
        $db = $this->db();
        $team = $this->request->int('equipo');
        $where = ["us.user_type = 'staff'", "us.status = 'active'", 'us.deleted_at IS NULL'];
        $params = [];
        if (!$u->can('workload.view.all')) {
            // Solo los miembros de los equipos que coordina (o a los que pertenece si no coordina ninguno)
            $teams = $u->ledTeamIds !== [] ? $u->ledTeamIds : $u->teamIds;
            $teams = array_map('intval', $teams);
            $where[] = $teams === [] ? 'us.id = ' . $u->id : 'us.id IN (SELECT user_id FROM team_members WHERE team_id IN (' . implode(',', $teams) . '))';
        }
        if ($team !== null) {
            $where[] = 'us.id IN (SELECT user_id FROM team_members WHERE team_id = ?)';
            $params[] = $team;
        }
        $w = implode(' AND ', $where);
        $now = Clock::nowDb();
        $weekStart = Clock::now()->setTimezone(Clock::local())->modify('monday this week')->format('Y-m-d');
        $open = "s.phase IN ('new','open','pending') AND t.deleted_at IS NULL";
        $rows = $db->all(
            "SELECT us.id, CONCAT(e.first_name, ' ', e.last_name) AS name, e.max_open_tickets,
                    (SELECT GROUP_CONCAT(tm2.name ORDER BY tm2.name SEPARATOR ', ') FROM team_members x JOIN teams tm2 ON tm2.id = x.team_id WHERE x.user_id = us.id) AS teams,
                    (SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.owner_user_id = us.id AND $open) AS owned,
                    (SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id JOIN ticket_priorities pr ON pr.id = t.priority_id WHERE t.owner_user_id = us.id AND $open AND pr.level >= 4) AS high,
                    (SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id LEFT JOIN ticket_sla sla ON sla.ticket_id = t.id
                      WHERE t.owner_user_id = us.id AND $open AND (t.due_at < ? OR sla.first_response_breached_at IS NOT NULL OR sla.resolution_breached_at IS NOT NULL)) AS overdue,
                    (SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.owner_user_id = us.id AND $open AND s.code = 'pending_customer') AS waiting,
                    (SELECT COUNT(*) FROM ticket_participants p JOIN tickets t ON t.id = p.ticket_id JOIN ticket_statuses s ON s.id = t.status_id
                      WHERE p.user_id = us.id AND p.role = 'collaborator' AND p.removed_at IS NULL AND $open) AS collaborating,
                    (SELECT COALESCE(SUM(te.minutes), 0) FROM ticket_time_entries te WHERE te.user_id = us.id AND te.deleted_at IS NULL AND te.work_date >= ?) AS week_minutes,
                    (SELECT te.ticket_id FROM ticket_time_entries te WHERE te.running_user_id = us.id LIMIT 1) AS running_ticket
               FROM users us JOIN employees e ON e.user_id = us.id
              WHERE $w ORDER BY owned DESC, name",
            array_merge([$now, $weekStart], $params)
        );
        $default = max(1, $this->app->settings()->int('workload.default_capacity', 15));
        foreach ($rows as &$r) {
            $cap = (int)($r['max_open_tickets'] ?: $default);
            $r['capacity'] = $cap;
            $r['load'] = (int)round(100 * ((int)$r['owned'] + 0.5 * (int)$r['collaborating']) / $cap);
        }
        unset($r);
        $unassigned = (int)$db->value("SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.owner_user_id IS NULL AND $open" . ($team !== null ? ' AND t.team_id = ?' : ''), $team !== null ? [$team] : []);
        $teams = $u->can('workload.view.all')
            ? $db->all('SELECT id, name FROM teams WHERE active = 1 ORDER BY name')
            : ($u->teamIds === [] ? [] : $db->all('SELECT id, name FROM teams WHERE id IN (' . implode(',', array_map('intval', $u->teamIds)) . ') ORDER BY name'));
        return $this->render('employees/workload.twig', compact('rows', 'unassigned', 'teams', 'team', 'default'));
    }
}
