<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Clock;
use App\Security\CurrentUser;

/**
 * Indicadores del panel e informes. Todo se calcula con datos reales de la
 * base de datos y respetando lo que el usuario puede ver.
 */
final class Reports
{
    public const REPORTS = [
        'incidencias' => 'Listado de incidencias',
        'clientes' => 'Incidencias y tiempo por cliente',
        'empleados' => 'Rendimiento por empleado',
        'tiempos' => 'Registro de tiempos (detalle)',
        'sla' => 'Cumplimiento de SLA por prioridad',
        'categorias' => 'Incidencias por categoría',
        'canales' => 'Incidencias por canal de entrada',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Ámbito de informes: todos, o los equipos del usuario (sus incidencias y
     * las de sus miembros).
     * @return array{0:string,1:list<mixed>}
     */
    public function scope(CurrentUser $user, string $t = 't'): array
    {
        if ($user->can('reports.view.all')) {
            return ["$t.deleted_at IS NULL", []];
        }
        if ($user->can('reports.view.team') && $user->teamIds !== []) {
            $teams = implode(',', array_map('intval', $user->teamIds));
            return ["$t.deleted_at IS NULL AND ($t.team_id IN ($teams) OR $t.owner_user_id IN (SELECT user_id FROM team_members WHERE team_id IN ($teams)))", []];
        }
        return $this->app->make(TicketAccess::class)->scope($user, $t);
    }

    /** @return array<string,int> */
    public function kpis(CurrentUser $user): array
    {
        $db = $this->app->db();
        [$w, $p] = $this->app->make(TicketAccess::class)->scope($user);
        $now = Clock::nowDb();
        $todayStart = Clock::toDb(Clock::now()->setTimezone(Clock::local())->setTime(0, 0));
        $row = $db->one(
            "SELECT
                SUM(s.phase IN ('new','open','pending')) AS open_total,
                SUM(s.phase IN ('new','open','pending') AND t.owner_user_id IS NULL) AS unassigned,
                SUM(s.phase IN ('new','open','pending') AND t.owner_user_id = ?) AS mine,
                SUM(s.phase IN ('new','open','pending') AND (t.due_at < ? OR sla.first_response_breached_at IS NOT NULL OR sla.resolution_breached_at IS NOT NULL)) AS overdue,
                SUM(s.phase IN ('new','open','pending') AND sla.paused_since IS NULL AND (sla.first_response_warned_at IS NOT NULL OR sla.resolution_warned_at IS NOT NULL) AND sla.first_response_breached_at IS NULL AND sla.resolution_breached_at IS NULL) AS at_risk,
                SUM(s.code = 'pending_customer') AS waiting_customer,
                SUM(t.created_at >= ?) AS created_today,
                SUM(t.resolved_at >= ?) AS resolved_today,
                SUM(t.identification_status = 'pending' AND s.phase IN ('new','open','pending')) AS unidentified
               FROM tickets t
               JOIN ticket_statuses s ON s.id = t.status_id
          LEFT JOIN ticket_sla sla ON sla.ticket_id = t.id
              WHERE $w",
            array_merge([$user->id, $now, $todayStart, $todayStart], $p)
        ) ?? [];
        $row['review_queue'] = $user->can('tickets.review_queue')
            ? (int)$db->value("SELECT COUNT(*) FROM email_messages WHERE processing_status = 'review'") : 0;
        $row['my_minutes_today'] = (int)$db->value(
            "SELECT COALESCE(SUM(minutes), 0) FROM ticket_time_entries WHERE user_id = ? AND work_date = ? AND deleted_at IS NULL",
            [$user->id, Clock::localDate()]
        );
        return array_map('intval', $row);
    }

    /** Datos de los gráficos del panel. @return array<string,mixed> */
    public function charts(CurrentUser $user): array
    {
        $db = $this->app->db();
        [$w, $p] = $this->app->make(TicketAccess::class)->scope($user);
        $tz = Clock::local();
        $days = [];
        $start = Clock::now()->setTimezone($tz)->setTime(0, 0)->modify('-29 days');
        for ($i = 0; $i < 30; $i++) {
            $days[$start->modify("+$i days")->format('Y-m-d')] = [0, 0];
        }
        $fromDb = Clock::toDb($start);
        // Se agrupa en PHP por día local (la BD trabaja en UTC)
        foreach ($db->all("SELECT t.created_at, t.resolved_at FROM tickets t WHERE (t.created_at >= ? OR t.resolved_at >= ?) AND $w", array_merge([$fromDb, $fromDb], $p)) as $r) {
            foreach (['created_at' => 0, 'resolved_at' => 1] as $col => $idx) {
                if ($r[$col] !== null) {
                    $d = Clock::fromDb($r[$col])->setTimezone($tz)->format('Y-m-d');
                    if (isset($days[$d])) {
                        $days[$d][$idx]++;
                    }
                }
            }
        }
        $labels = array_map(static fn ($d) => substr($d, 8, 2) . '/' . substr($d, 5, 2), array_keys($days));

        $byStatus = $db->all("SELECT s.name, s.color, COUNT(*) AS n FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE s.phase IN ('new','open','pending') AND $w GROUP BY s.id, s.name, s.color, s.sort_order ORDER BY s.sort_order", $p);
        $byPriority = $db->all("SELECT pr.name, pr.color, COUNT(*) AS n FROM tickets t JOIN ticket_priorities pr ON pr.id = t.priority_id JOIN ticket_statuses s ON s.id = t.status_id WHERE s.phase IN ('new','open','pending') AND $w GROUP BY pr.id, pr.name, pr.color, pr.level ORDER BY pr.level", $p);
        $byCategory = $db->all("SELECT COALESCE(c.name, 'Sin categoría') AS name, COUNT(*) AS n FROM tickets t LEFT JOIN ticket_categories c ON c.id = t.category_id WHERE t.created_at >= ? AND $w GROUP BY c.id, c.name ORDER BY n DESC LIMIT 10", array_merge([$fromDb], $p));
        $byChannel = $db->all("SELECT ch.name, COUNT(*) AS n FROM tickets t JOIN ticket_channels ch ON ch.code = t.channel_code WHERE t.created_at >= ? AND $w GROUP BY ch.code, ch.name ORDER BY n DESC", array_merge([$fromDb], $p));
        $byOwner = $db->all(
            "SELECT COALESCE(CONCAT(e.first_name, ' ', LEFT(e.last_name, 1), '.'), 'Sin asignar') AS name, COUNT(*) AS n
               FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id LEFT JOIN employees e ON e.user_id = t.owner_user_id
              WHERE s.phase IN ('new','open','pending') AND $w GROUP BY t.owner_user_id, e.first_name, e.last_name ORDER BY n DESC LIMIT 12",
            $p
        );
        $col = static fn (array $rows, string $k) => array_map(static fn ($r) => $r[$k], $rows);
        return [
            'trend' => ['type' => 'line', 'labels' => $labels, 'datasets' => [
                ['label' => 'Creadas', 'data' => array_column($days, 0), 'color' => '#1f6feb'],
                ['label' => 'Resueltas', 'data' => array_column($days, 1), 'color' => '#20c997'],
            ]],
            'status' => ['type' => 'doughnut', 'labels' => $col($byStatus, 'name'), 'datasets' => [['label' => 'Abiertas', 'data' => array_map('intval', $col($byStatus, 'n')), 'colors' => $col($byStatus, 'color')]]],
            'priority' => ['type' => 'bar', 'labels' => $col($byPriority, 'name'), 'datasets' => [['label' => 'Abiertas', 'data' => array_map('intval', $col($byPriority, 'n')), 'backgroundColor' => $col($byPriority, 'color')]]],
            'category' => ['type' => 'bar', 'labels' => $col($byCategory, 'name'), 'datasets' => [['label' => 'Últimos 30 días', 'data' => array_map('intval', $col($byCategory, 'n'))]]],
            'channel' => ['type' => 'doughnut', 'labels' => $col($byChannel, 'name'), 'datasets' => [['label' => 'Últimos 30 días', 'data' => array_map('intval', $col($byChannel, 'n'))]]],
            'owner' => ['type' => 'bar', 'labels' => $col($byOwner, 'name'), 'datasets' => [['label' => 'Abiertas', 'data' => array_map('intval', $col($byOwner, 'n'))]]],
        ];
    }

    /**
     * Ejecuta un informe. Devuelve cabeceras y filas ya formateadas.
     * @param array{from:string,to:string,client_id:?int,team_id:?int,user_id:?int} $f fechas en UTC (DB)
     * @return array{headers:list<string>,rows:list<list<mixed>>,totals:?list<mixed>}
     */
    public function run(string $code, CurrentUser $user, array $f): array
    {
        $db = $this->app->db();
        [$w, $p] = $this->scope($user);
        $extra = '';
        $ep = [];
        if ($f['client_id'] !== null) {
            $extra .= ' AND t.client_id = ?';
            $ep[] = $f['client_id'];
        }
        if ($f['team_id'] !== null) {
            $extra .= ' AND t.team_id = ?';
            $ep[] = $f['team_id'];
        }
        if ($f['user_id'] !== null && $code !== 'tiempos' && $code !== 'empleados') {
            $extra .= ' AND t.owner_user_id = ?';
            $ep[] = $f['user_id'];
        }
        $range = [$f['from'], $f['to']];
        // Los registros de tiempo usan el día local de trabajo, no instantes UTC
        $dayFrom = Clock::fromDb($f['from'])?->setTimezone(Clock::local())->format('Y-m-d');
        $dayTo = Clock::fromDb($f['to'])?->setTimezone(Clock::local())->format('Y-m-d');
        $hm = static fn ($m) => Clock::minutesToHuman((int)$m);

        switch ($code) {
            case 'incidencias':
                $rows = $db->all(
                    "SELECT t.ticket_number, t.created_at, t.subject, COALESCE(c.trade_name, c.legal_name, '(sin identificar)') AS client,
                            TRIM(CONCAT(COALESCE(cc.first_name,''), ' ', COALESCE(cc.last_name,''))) AS contact, s.name AS status, pr.name AS priority,
                            COALESCE(cat.name, '') AS category, ch.name AS channel, COALESCE(CONCAT(e.first_name, ' ', e.last_name), '') AS owner,
                            COALESCE(tm.name, '') AS team, t.first_response_at, t.resolved_at, t.closed_at, t.total_minutes,
                            IF(sla.first_response_breached_at IS NULL AND sla.resolution_breached_at IS NULL, IF(sla.ticket_id IS NULL, 'Sin SLA', 'Cumple'), 'Incumplido') AS sla
                       FROM tickets t
                       JOIN ticket_statuses s ON s.id = t.status_id
                       JOIN ticket_priorities pr ON pr.id = t.priority_id
                       JOIN ticket_channels ch ON ch.code = t.channel_code
                  LEFT JOIN clients c ON c.id = t.client_id
                  LEFT JOIN client_contacts cc ON cc.id = t.contact_id
                  LEFT JOIN ticket_categories cat ON cat.id = t.category_id
                  LEFT JOIN employees e ON e.user_id = t.owner_user_id
                  LEFT JOIN teams tm ON tm.id = t.team_id
                  LEFT JOIN ticket_sla sla ON sla.ticket_id = t.id
                      WHERE t.created_at BETWEEN ? AND ? AND $w $extra
                   ORDER BY t.created_at DESC LIMIT 20000",
                    array_merge($range, $p, $ep)
                );
                return [
                    'headers' => ['Número', 'Creada', 'Asunto', 'Cliente', 'Contacto', 'Estado', 'Prioridad', 'Categoría', 'Canal', 'Responsable', 'Equipo', 'Primera respuesta', 'Resuelta', 'Cerrada', 'Minutos', 'Tiempo', 'SLA'],
                    'rows' => array_map(static fn ($r) => [
                        $r['ticket_number'], Clock::format($r['created_at']), $r['subject'], $r['client'], $r['contact'], $r['status'], $r['priority'],
                        $r['category'], $r['channel'], $r['owner'], $r['team'], Clock::format($r['first_response_at']), Clock::format($r['resolved_at']),
                        Clock::format($r['closed_at']), (int)$r['total_minutes'], $hm($r['total_minutes']), $r['sla'],
                    ], $rows),
                    'totals' => null,
                ];

            case 'clientes':
                $rows = $db->all(
                    "SELECT COALESCE(c.trade_name, c.legal_name, '(sin identificar)') AS client, c.code,
                            COUNT(*) AS created, SUM(t.resolved_at IS NOT NULL) AS resolved,
                            AVG(IF(t.resolved_at IS NOT NULL, TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at), NULL)) AS avg_res,
                            SUM(t.total_minutes) AS minutes
                       FROM tickets t LEFT JOIN clients c ON c.id = t.client_id
                      WHERE t.created_at BETWEEN ? AND ? AND $w $extra
                   GROUP BY t.client_id, c.trade_name, c.legal_name, c.code ORDER BY created DESC",
                    array_merge($range, $p, $ep)
                );
                return [
                    'headers' => ['Cliente', 'Código', 'Creadas', 'Resueltas', 'Resolución media (natural)', 'Minutos registrados', 'Tiempo registrado'],
                    'rows' => array_map(static fn ($r) => [$r['client'], $r['code'] ?? '', (int)$r['created'], (int)$r['resolved'], $r['avg_res'] !== null ? $hm(round((float)$r['avg_res'])) : '', (int)$r['minutes'], $hm($r['minutes'])], $rows),
                    'totals' => ['Total', '', array_sum(array_column($rows, 'created')), array_sum(array_column($rows, 'resolved')), '', array_sum(array_column($rows, 'minutes')), $hm(array_sum(array_column($rows, 'minutes')))],
                ];

            case 'empleados':
                $userFilter = $f['user_id'] !== null ? ' AND u.id = ' . (int)$f['user_id'] : '';
                $rows = $db->all(
                    "SELECT CONCAT(e.first_name, ' ', e.last_name) AS name, u.id,
                            (SELECT COUNT(*) FROM tickets t WHERE t.owner_user_id = u.id AND t.created_at BETWEEN ? AND ? AND $w $extra) AS assigned,
                            (SELECT COUNT(*) FROM tickets t WHERE t.owner_user_id = u.id AND t.resolved_at BETWEEN ? AND ? AND $w $extra) AS resolved,
                            (SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.owner_user_id = u.id AND s.phase IN ('new','open','pending') AND t.deleted_at IS NULL) AS open_now,
                            (SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, t.first_response_at)) FROM tickets t WHERE t.owner_user_id = u.id AND t.first_response_at BETWEEN ? AND ? AND $w $extra) AS avg_fr,
                            (SELECT COALESCE(SUM(te.minutes),0) FROM ticket_time_entries te JOIN tickets t ON t.id = te.ticket_id WHERE te.user_id = u.id AND te.deleted_at IS NULL AND te.work_date BETWEEN ? AND ? AND $w $extra) AS minutes
                       FROM users u JOIN employees e ON e.user_id = u.id
                      WHERE u.user_type = 'staff' AND u.deleted_at IS NULL $userFilter
                   ORDER BY name",
                    array_merge($range, $p, $ep, $range, $p, $ep, $range, $p, $ep, [$dayFrom, $dayTo], $p, $ep)
                );
                return [
                    'headers' => ['Empleado', 'Asignadas (creadas en el periodo)', 'Resueltas en el periodo', 'Abiertas ahora', 'Primera respuesta media (natural)', 'Minutos registrados', 'Tiempo registrado'],
                    'rows' => array_map(static fn ($r) => [$r['name'], (int)$r['assigned'], (int)$r['resolved'], (int)$r['open_now'], $r['avg_fr'] !== null ? $hm(round((float)$r['avg_fr'])) : '', (int)$r['minutes'], $hm($r['minutes'])], $rows),
                    'totals' => null,
                ];

            case 'tiempos':
                $uf = '';
                $up = [];
                if ($f['user_id'] !== null) {
                    $uf = ' AND te.user_id = ?';
                    $up[] = $f['user_id'];
                }
                $rows = $db->all(
                    "SELECT te.work_date, CONCAT(e.first_name, ' ', e.last_name) AS employee, t.ticket_number, t.subject,
                            COALESCE(c.trade_name, c.legal_name, '') AS client, te.minutes, te.source, te.billable, te.description
                       FROM ticket_time_entries te
                       JOIN tickets t ON t.id = te.ticket_id
                       JOIN employees e ON e.user_id = te.user_id
                  LEFT JOIN clients c ON c.id = t.client_id
                      WHERE te.deleted_at IS NULL AND te.minutes IS NOT NULL AND te.work_date BETWEEN ? AND ? AND $w $extra $uf
                   ORDER BY te.work_date DESC, employee LIMIT 50000",
                    array_merge([$dayFrom, $dayTo], $p, $ep, $up)
                );
                return [
                    'headers' => ['Fecha', 'Empleado', 'Incidencia', 'Asunto', 'Cliente', 'Minutos', 'Tiempo', 'Origen', 'Facturable', 'Descripción'],
                    'rows' => array_map(static fn ($r) => [
                        Clock::format($r['work_date'] . ' 12:00:00', false), $r['employee'], $r['ticket_number'], $r['subject'], $r['client'],
                        (int)$r['minutes'], $hm($r['minutes']), $r['source'] === 'timer' ? 'Temporizador' : 'Manual', $r['billable'] ? 'Sí' : 'No', (string)$r['description'],
                    ], $rows),
                    'totals' => ['Total', '', '', '', '', array_sum(array_column($rows, 'minutes')), $hm(array_sum(array_column($rows, 'minutes'))), '', '', ''],
                ];

            case 'sla':
                $rows = $db->all(
                    "SELECT pr.name, COUNT(*) AS total,
                            SUM(sla.first_response_breached_at IS NOT NULL) AS fr_breached,
                            SUM(sla.resolution_due_at IS NOT NULL) AS res_total,
                            SUM(sla.resolution_breached_at IS NOT NULL) AS res_breached
                       FROM tickets t JOIN ticket_sla sla ON sla.ticket_id = t.id JOIN ticket_priorities pr ON pr.id = t.priority_id
                      WHERE t.created_at BETWEEN ? AND ? AND $w $extra
                   GROUP BY pr.id, pr.name, pr.level ORDER BY pr.level DESC",
                    array_merge($range, $p, $ep)
                );
                $pct = static fn ($ok, $tot) => $tot > 0 ? number_format(100 * $ok / $tot, 1, ',', '.') . ' %' : '';
                return [
                    'headers' => ['Prioridad', 'Incidencias con SLA', 'Primera respuesta incumplida', '% cumplimiento 1.ª respuesta', 'Con objetivo de resolución', 'Resolución incumplida', '% cumplimiento resolución'],
                    'rows' => array_map(static fn ($r) => [
                        $r['name'], (int)$r['total'], (int)$r['fr_breached'], $pct((int)$r['total'] - (int)$r['fr_breached'], (int)$r['total']),
                        (int)$r['res_total'], (int)$r['res_breached'], $pct((int)$r['res_total'] - (int)$r['res_breached'], (int)$r['res_total']),
                    ], $rows),
                    'totals' => null,
                ];

            case 'categorias':
            case 'canales':
                $join = $code === 'categorias'
                    ? "LEFT JOIN ticket_categories g ON g.id = t.category_id"
                    : "JOIN ticket_channels g ON g.code = t.channel_code";
                $key = $code === 'categorias' ? 't.category_id' : 't.channel_code';
                $rows = $db->all(
                    "SELECT COALESCE(g.name, 'Sin categoría') AS name, COUNT(*) AS n, SUM(t.resolved_at IS NOT NULL) AS resolved, SUM(t.total_minutes) AS minutes
                       FROM tickets t $join WHERE t.created_at BETWEEN ? AND ? AND $w $extra
                   GROUP BY $key, g.name ORDER BY n DESC",
                    array_merge($range, $p, $ep)
                );
                return [
                    'headers' => [$code === 'categorias' ? 'Categoría' : 'Canal', 'Incidencias', 'Resueltas', 'Minutos registrados', 'Tiempo registrado'],
                    'rows' => array_map(static fn ($r) => [$r['name'], (int)$r['n'], (int)$r['resolved'], (int)$r['minutes'], $hm($r['minutes'])], $rows),
                    'totals' => ['Total', array_sum(array_column($rows, 'n')), array_sum(array_column($rows, 'resolved')), array_sum(array_column($rows, 'minutes')), $hm(array_sum(array_column($rows, 'minutes')))],
                ];
        }
        throw \App\Core\HttpException::notFound('Informe desconocido.');
    }
}
