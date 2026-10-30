<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Services\Sla;

/**
 * Políticas de SLA, horario laboral y festivos. Los cambios se aplican a las
 * incidencias nuevas; las abiertas conservan los vencimientos ya calculados.
 */
final class SlaController extends Controller
{
    public function index(): Response
    {
        $db = $this->db();
        $simulation = $this->app->session()->get('sla_simulation');
        $this->app->session()->remove('sla_simulation');
        $policies = $db->all('SELECT p.*, c.name AS calendar, (SELECT COUNT(*) FROM clients cl WHERE cl.sla_policy_id = p.id AND cl.deleted_at IS NULL) AS clients FROM sla_policies p JOIN business_calendars c ON c.id = p.calendar_id ORDER BY p.is_default DESC, p.name');
        $targets = [];
        foreach ($db->all('SELECT * FROM sla_policy_targets') as $t) {
            $targets[(int)$t['policy_id']][(int)$t['priority_id']] = $t;
        }
        $calendars = $db->all('SELECT * FROM business_calendars ORDER BY id');
        $hours = [];
        foreach ($db->all('SELECT * FROM business_hours ORDER BY weekday, start_time') as $h) {
            $hours[(int)$h['calendar_id']][(int)$h['weekday']][] = substr((string)$h['start_time'], 0, 5) . '-' . substr((string)$h['end_time'], 0, 5);
        }
        $year = (int)Clock::now()->setTimezone(Clock::local())->format('Y');
        $holidays = $db->all('SELECT * FROM calendar_holidays WHERE holiday_date >= ? ORDER BY holiday_date', [($year - 1) . '-01-01']);
        return $this->render('admin/sla.twig', [
            'policies' => $policies,
            'targets' => $targets,
            'priorities' => $db->all('SELECT id, name, color FROM ticket_priorities WHERE active = 1 ORDER BY level DESC'),
            'calendars' => $calendars,
            'hours' => $hours,
            'holidays' => $holidays,
            'simulation' => $simulation,
            'no_active' => !$db->value('SELECT 1 FROM sla_policies WHERE active = 1'),
        ]);
    }

    public function savePolicy(): Response
    {
        $db = $this->db();
        $id = $this->request->int('id');
        $d = $this->validate([
            'name' => 'required|string|max:80', 'calendar_id' => 'required|int', 'is_default' => 'bool',
            'pause_when_pending' => 'bool', 'active' => 'bool',
        ], ['name' => 'El nombre', 'calendar_id' => 'El calendario']);
        if (!$db->value('SELECT 1 FROM business_calendars WHERE id = ?', [$d['calendar_id']])) {
            throw ValidationException::single('calendar_id', 'Calendario no válido.');
        }
        if ($db->value('SELECT 1 FROM sla_policies WHERE name = ? AND id <> ?', [$d['name'], $id ?? 0])) {
            throw ValidationException::single('name', 'Ya existe una política con ese nombre.');
        }
        // Objetivos por prioridad: horas y minutos para cada uno
        $targets = [];
        $raw = $this->request->get('target');
        $prioIds = array_map('intval', $db->column('SELECT id FROM ticket_priorities'));
        foreach (is_array($raw) ? $raw : [] as $pid => $t) {
            if (!in_array((int)$pid, $prioIds, true) || !is_array($t)) {
                continue;
            }
            $fr = $this->minutes($t['fr_h'] ?? '', $t['fr_m'] ?? '');
            $res = $this->minutes($t['res_h'] ?? '', $t['res_m'] ?? '');
            if ($fr === null) {
                continue; // Sin objetivo para esta prioridad
            }
            $warn = (int)($t['warn'] ?? 80);
            $targets[(int)$pid] = [
                'first_response_minutes' => max(1, $fr),
                'resolution_minutes' => $res !== null && $res > 0 ? max($res, $fr) : null,
                'time_mode' => ($t['mode'] ?? 'business') === 'calendar' ? 'calendar' : 'business',
                'warn_at_percent' => max(1, min(99, $warn)),
            ];
        }
        if ($d['active'] && $targets === []) {
            throw new ValidationException([], 'Una política activa necesita al menos un objetivo de primera respuesta.');
        }
        $now = Clock::nowDb();
        $id = $db->transaction(function () use ($db, $id, $d, $targets, $now) {
            $row = ['name' => $d['name'], 'calendar_id' => $d['calendar_id'], 'pause_when_pending' => $d['pause_when_pending'] ? 1 : 0, 'active' => $d['active'] ? 1 : 0, 'updated_at' => $now];
            if ($id === null) {
                $id = $db->insert('sla_policies', $row + ['is_default' => 0, 'created_at' => $now]);
            } else {
                $db->value('SELECT 1 FROM sla_policies WHERE id = ?', [$id]) ?: throw HttpException::notFound();
                $db->update('sla_policies', $row, 'id = ?', [$id]);
            }
            if ($d['is_default']) {
                $db->run('UPDATE sla_policies SET is_default = (id = ?)', [$id]);
            }
            $db->run('DELETE FROM sla_policy_targets WHERE policy_id = ?', [$id]);
            foreach ($targets as $pid => $t) {
                $db->insert('sla_policy_targets', $t + ['policy_id' => $id, 'priority_id' => $pid]);
            }
            return $id;
        });
        $this->audit()->log('sla.policy_saved', 'sla_policy', $id, ['nombre' => $d['name'], 'activa' => $d['active'], 'objetivos' => count($targets)]);
        return $this->redirect('/admin/sla#politica-' . $id, 'Política guardada. Se aplica a las incidencias que se creen desde ahora.');
    }

    public function saveCalendar(): Response
    {
        $db = $this->db();
        $id = $this->request->int('id');
        $d = $this->validate(['name' => 'required|string|max:80'], ['name' => 'El nombre']);
        $slots = [];
        $raw = $this->request->get('hours');
        for ($wd = 1; $wd <= 7; $wd++) {
            $text = is_array($raw) && is_string($raw[$wd] ?? null) ? trim($raw[$wd]) : '';
            if ($text === '') {
                continue;
            }
            foreach (preg_split('/[\s,;]+/', $text) ?: [] as $range) {
                if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]|24):([0-5]\d)$/', $range, $m)) {
                    throw ValidationException::single("hours.$wd", "Tramo no válido «{$range}». Usa HH:MM-HH:MM, por ejemplo 09:00-14:00, 15:00-18:00.");
                }
                $start = $m[1] . ':' . $m[2];
                $end = $m[3] === '24' ? '23:59' : $m[3] . ':' . $m[4];
                if ($end <= $start) {
                    throw ValidationException::single("hours.$wd", "En «{$range}» la hora final debe ser posterior a la inicial.");
                }
                $slots[] = [$wd, $start, $end];
            }
        }
        if ($slots === []) {
            throw new ValidationException([], 'El calendario necesita al menos un tramo de horario.');
        }
        $now = Clock::nowDb();
        $id = $db->transaction(function () use ($db, $id, $d, $slots, $now) {
            if ($id === null) {
                $id = $db->insert('business_calendars', ['name' => $d['name'], 'timezone' => 'Europe/Madrid', 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $db->value('SELECT 1 FROM business_calendars WHERE id = ?', [$id]) ?: throw HttpException::notFound();
                $db->update('business_calendars', ['name' => $d['name'], 'updated_at' => $now], 'id = ?', [$id]);
            }
            $db->run('DELETE FROM business_hours WHERE calendar_id = ?', [$id]);
            foreach ($slots as [$wd, $s, $e]) {
                $db->insert('business_hours', ['calendar_id' => $id, 'weekday' => $wd, 'start_time' => $s, 'end_time' => $e]);
            }
            return $id;
        });
        $this->app->make(Sla::class)->forgetCalendars();
        $this->audit()->log('sla.calendar_saved', 'calendar', $id, ['tramos' => count($slots)]);
        return $this->redirect('/admin/sla#calendarios', 'Horario guardado.');
    }

    public function holidays(): Response
    {
        $db = $this->db();
        $cal = $this->request->int('calendar_id');
        if ($cal === null || !$db->value('SELECT 1 FROM business_calendars WHERE id = ?', [$cal])) {
            throw HttpException::notFound();
        }
        if (($del = $this->request->int('delete_id')) !== null) {
            $db->run('DELETE FROM calendar_holidays WHERE id = ? AND calendar_id = ?', [$del, $cal]);
            $msg = 'Festivo eliminado.';
        } else {
            // Uno por línea: DD/MM/AAAA Nombre
            $lines = preg_split('/\R/', $this->request->str('lines')) ?: [];
            $n = 0;
            foreach ($lines as $i => $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (!preg_match('#^(\d{1,2}/\d{1,2}/\d{4}|\d{4}-\d{2}-\d{2})\s+(.{2,120})$#u', $line, $m) || ($day = Clock::parseLocal($m[1])) === null) {
                    throw ValidationException::single('lines', 'Línea ' . ($i + 1) . ' no válida: «' . mb_substr($line, 0, 60) . '». Formato: 06/12/2026 Día de la Constitución.');
                }
                $db->run('INSERT INTO calendar_holidays (calendar_id, holiday_date, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)', [$cal, $day->setTimezone(Clock::local())->format('Y-m-d'), trim($m[2])]);
                $n++;
            }
            $msg = $n === 1 ? 'Festivo añadido.' : "$n festivos añadidos.";
        }
        $this->app->make(Sla::class)->forgetCalendars();
        $this->audit()->log('sla.holidays', 'calendar', $cal);
        return $this->redirect('/admin/sla#festivos', $msg);
    }

    /** Calcula un vencimiento de prueba para comprobar horario y festivos. */
    public function simulate(): Response
    {
        $d = $this->validate(['start' => 'required|date', 'hours' => 'int|min:0|max:2000', 'minutes' => 'int|min:0|max:59', 'mode' => 'required|in:business,calendar', 'calendar_id' => 'required|int'], ['start' => 'La fecha de inicio']);
        $total = (int)($d['hours'] ?? 0) * 60 + (int)($d['minutes'] ?? 0);
        if ($total <= 0) {
            throw ValidationException::single('hours', 'Indica una duración.');
        }
        $start = Clock::fromDb($d['start']);
        $due = $this->app->make(Sla::class)->addMinutes($start, $total, $d['mode'], $d['calendar_id']);
        $this->app->session()->set('sla_simulation', [
            'start' => Clock::format($d['start']),
            'duration' => Clock::minutesToHuman($total),
            'mode' => $d['mode'] === 'business' ? 'laborable' : 'natural',
            'due' => Clock::format(Clock::toDb($due)),
        ]);
        return $this->redirect('/admin/sla#simulador');
    }

    private function minutes(mixed $h, mixed $m): ?int
    {
        $h = is_string($h) ? trim($h) : '';
        $m = is_string($m) ? trim($m) : '';
        if ($h === '' && $m === '') {
            return null;
        }
        if (($h !== '' && !ctype_digit($h)) || ($m !== '' && !ctype_digit($m))) {
            throw new ValidationException([], 'Los objetivos de SLA deben ser números enteros de horas y minutos.');
        }
        return (int)$h * 60 + (int)$m;
    }
}
