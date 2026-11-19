<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Response;
use App\Http\Controller;
use App\Services\Export;
use App\Services\Reports;

final class ReportController extends Controller
{
    public function index(): Response
    {
        return $this->render('reports/index.twig', ['reports' => Reports::REPORTS]);
    }

    public function show(): Response
    {
        $code = $this->request->param('code');
        if (!isset(Reports::REPORTS[$code])) {
            throw HttpException::notFound();
        }
        $f = $this->filters();
        $result = $this->app->make(Reports::class)->run($code, $this->user(), $f['db']);
        $format = $this->request->str('formato');
        if ($format === 'csv' || $format === 'xlsx') {
            $this->authorize('tickets.export');
            $rows = $result['rows'];
            if ($result['totals'] !== null) {
                $rows[] = $result['totals'];
            }
            $this->audit()->log('report.exported', null, null, ['informe' => $code, 'filtros' => $f['ui'], 'filas' => count($result['rows'])]);
            $name = "informe-$code-" . $f['ui']['desde'] . '-' . $f['ui']['hasta'] . ".$format";
            return $format === 'xlsx'
                ? Export::xlsx($name, mb_substr(Reports::REPORTS[$code], 0, 31), $result['headers'], $rows, $this->app->storagePath('tmp'))
                : Export::csv($name, $result['headers'], $rows);
        }
        $db = $this->db();
        $all = $this->user()->can('reports.view.all');
        $teamIds = array_map('intval', $this->user()->teamIds);
        $teams = $all ? $db->all('SELECT id, name FROM teams ORDER BY name')
            : ($teamIds === [] ? [] : $db->all('SELECT id, name FROM teams WHERE id IN (' . implode(',', $teamIds) . ') ORDER BY name'));
        $staff = $db->all(
            "SELECT u.id, CONCAT(e.first_name, ' ', e.last_name) AS name FROM users u JOIN employees e ON e.user_id = u.id
              WHERE u.user_type = 'staff' AND u.deleted_at IS NULL" . ($all ? '' : ($teamIds === [] ? ' AND u.id = ' . $this->user()->id : ' AND u.id IN (SELECT user_id FROM team_members WHERE team_id IN (' . implode(',', $teamIds) . '))')) . ' ORDER BY e.first_name'
        );
        $clients = $db->all("SELECT id, COALESCE(trade_name, legal_name) AS name FROM clients WHERE deleted_at IS NULL ORDER BY name LIMIT 2000");
        return $this->render('reports/show.twig', [
            'code' => $code,
            'title' => Reports::REPORTS[$code],
            'reports' => Reports::REPORTS,
            'result' => $result,
            'f' => $f['ui'],
            'teams' => $teams,
            'staff' => $staff,
            'clients' => $clients,
        ]);
    }

    /** @return array{db:array<string,mixed>,ui:array<string,mixed>} */
    private function filters(): array
    {
        $local = Clock::now()->setTimezone(Clock::local());
        $from = Clock::parseLocal($this->request->str('desde')) ?? Clock::parseLocal($local->modify('first day of this month')->format('Y-m-d'));
        $to = Clock::parseLocal($this->request->str('hasta'), true) ?? Clock::parseLocal($local->format('Y-m-d'), true);
        if ($to < $from) {
            [$from, $to] = [$to->setTimezone(Clock::local())->setTime(0, 0)->setTimezone(Clock::utc()), $from->setTimezone(Clock::local())->setTime(23, 59, 59)->setTimezone(Clock::utc())];
        }
        // Máximo dos años por consulta para no saturar el alojamiento
        if ($from->diff($to)->days > 731) {
            $from = $to->modify('-731 days');
        }
        $client = $this->request->int('cliente');
        $team = $this->request->int('equipo');
        $user = $this->request->int('empleado');
        return [
            'db' => ['from' => Clock::toDb($from), 'to' => Clock::toDb($to), 'client_id' => $client, 'team_id' => $team, 'user_id' => $user],
            'ui' => [
                'desde' => $from->setTimezone(Clock::local())->format('Y-m-d'),
                'hasta' => $to->setTimezone(Clock::local())->format('Y-m-d'),
                'cliente' => $client, 'equipo' => $team, 'empleado' => $user,
            ],
        ];
    }
}
