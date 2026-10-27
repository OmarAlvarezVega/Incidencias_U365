<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Acuerdos de nivel de servicio.
 *
 * - Tiempo laborable: se cuentan solo los minutos dentro del horario del
 *   calendario (hora local, con cambios de horario de verano) y fuera de
 *   festivos. Tiempo natural: minutos de reloj.
 * - Pausa: mientras la incidencia está en un estado con pauses_sla = 1 el
 *   reloj se detiene; al salir, los minutos pausados (en el mismo modo) se
 *   suman y los vencimientos se desplazan.
 * - Vencimiento = inicio + objetivo + minutos pausados, en el modo del objetivo.
 * - Sin política activa no se calcula nada (no se inventan plazos).
 */
final class Sla
{
    /** @var array<int,array{tz:DateTimeZone,hours:array<int,list<array{0:int,1:int}>>,holidays:array<string,bool>}> */
    private array $calendars = [];

    public function __construct(private readonly App $app)
    {
    }

    /** Política aplicable: la del cliente o la predeterminada, solo si está activa. @return array<string,mixed>|null */
    public function policyFor(?int $clientId): ?array
    {
        $db = $this->app->db();
        if ($clientId !== null) {
            $p = $db->one('SELECT p.* FROM sla_policies p JOIN clients c ON c.sla_policy_id = p.id WHERE c.id = ? AND p.active = 1', [$clientId]);
            if ($p !== null) {
                return $p;
            }
        }
        return $db->one('SELECT * FROM sla_policies WHERE is_default = 1 AND active = 1 LIMIT 1');
    }

    /** Aplica o recalcula el SLA de una incidencia (alta, cambio de prioridad o de cliente). */
    public function attach(int $ticketId): void
    {
        $db = $this->app->db();
        $t = $db->one('SELECT id, client_id, priority_id, created_at, first_response_at, resolved_at, status_id FROM tickets WHERE id = ?', [$ticketId]);
        if ($t === null) {
            return;
        }
        $policy = $this->policyFor($t['client_id'] !== null ? (int)$t['client_id'] : null);
        if ($policy === null) {
            $db->run('DELETE FROM ticket_sla WHERE ticket_id = ? AND first_response_breached_at IS NULL AND resolution_breached_at IS NULL', [$ticketId]);
            return;
        }
        $target = $db->one('SELECT * FROM sla_policy_targets WHERE policy_id = ? AND priority_id = ?', [(int)$policy['id'], (int)$t['priority_id']]);
        if ($target === null) {
            $db->run('DELETE FROM ticket_sla WHERE ticket_id = ? AND first_response_breached_at IS NULL AND resolution_breached_at IS NULL', [$ticketId]);
            return;
        }
        $existing = $db->one('SELECT * FROM ticket_sla WHERE ticket_id = ?', [$ticketId]);
        $paused = (int)($existing['paused_minutes_total'] ?? 0);
        $start = Clock::fromDb((string)$t['created_at']);
        $calId = (int)$policy['calendar_id'];
        $mode = (string)$target['time_mode'];
        $frDue = $this->addMinutes($start, (int)$target['first_response_minutes'] + $paused, $mode, $calId);
        $resDue = $target['resolution_minutes'] !== null ? $this->addMinutes($start, (int)$target['resolution_minutes'] + $paused, $mode, $calId) : null;
        $pausesState = (bool)$db->value('SELECT pauses_sla FROM ticket_statuses WHERE id = ?', [(int)$t['status_id']]) && (bool)$policy['pause_when_pending'];

        $db->run(
            'INSERT INTO ticket_sla (ticket_id, policy_id, time_mode, first_response_due_at, resolution_due_at, paused_since, paused_minutes_total, last_computed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE policy_id = VALUES(policy_id), time_mode = VALUES(time_mode),
               first_response_due_at = VALUES(first_response_due_at), resolution_due_at = VALUES(resolution_due_at),
               first_response_warned_at = IF(first_response_breached_at IS NULL, NULL, first_response_warned_at),
               resolution_warned_at = IF(resolution_breached_at IS NULL, NULL, resolution_warned_at),
               last_computed_at = VALUES(last_computed_at)',
            [
                $ticketId, (int)$policy['id'], $mode, Clock::toDb($frDue), $resDue ? Clock::toDb($resDue) : null,
                $existing === null && $pausesState ? Clock::nowDb() : ($existing['paused_since'] ?? null),
                $paused, Clock::nowDb(),
            ]
        );
    }

    /** Llamar al cambiar de estado. */
    public function onStatusChange(int $ticketId, int $fromStatusId, int $toStatusId): void
    {
        $db = $this->app->db();
        $sla = $db->one('SELECT s.*, p.pause_when_pending, p.calendar_id FROM ticket_sla s JOIN sla_policies p ON p.id = s.policy_id WHERE s.ticket_id = ?', [$ticketId]);
        if ($sla === null || !(bool)$sla['pause_when_pending']) {
            return;
        }
        $pauses = array_map('intval', $db->column('SELECT id FROM ticket_statuses WHERE pauses_sla = 1'));
        $wasPaused = $sla['paused_since'] !== null;
        $nowPauses = in_array($toStatusId, $pauses, true);
        if (!$wasPaused && $nowPauses) {
            $db->run('UPDATE ticket_sla SET paused_since = ? WHERE ticket_id = ?', [Clock::nowDb(), $ticketId]);
        } elseif ($wasPaused && !$nowPauses) {
            $from = Clock::fromDb((string)$sla['paused_since']);
            $mins = $this->minutesBetween($from, Clock::now(), (string)$sla['time_mode'], (int)$sla['calendar_id']);
            $db->run('UPDATE ticket_sla SET paused_since = NULL, paused_minutes_total = paused_minutes_total + ? WHERE ticket_id = ?', [$mins, $ticketId]);
            $this->attach($ticketId);
        }
    }

    /**
     * Revisa avisos e incumplimientos. Devuelve el número de cambios.
     */
    public function check(): int
    {
        $db = $this->app->db();
        $now = Clock::now();
        $nowDb = Clock::toDb($now);
        $changes = 0;
        $rows = $db->all(
            "SELECT s.*, t.ticket_number, t.subject, t.first_response_at, t.resolved_at, t.created_at, t.owner_user_id, t.team_id,
                    pt.warn_at_percent, p.calendar_id
               FROM ticket_sla s
               JOIN tickets t ON t.id = s.ticket_id
               JOIN ticket_statuses st ON st.id = t.status_id
               JOIN sla_policies p ON p.id = s.policy_id
          LEFT JOIN sla_policy_targets pt ON pt.policy_id = s.policy_id AND pt.priority_id = t.priority_id
              WHERE t.deleted_at IS NULL AND st.phase IN ('new','open','pending') AND s.paused_since IS NULL
                AND ((t.first_response_at IS NULL AND s.first_response_breached_at IS NULL)
                  OR (s.resolution_due_at IS NOT NULL AND s.resolution_breached_at IS NULL))
              LIMIT 2000"
        );
        $notifier = $this->app->make(Notifications::class);
        foreach ($rows as $r) {
            $tid = (int)$r['ticket_id'];
            $warnPct = (int)($r['warn_at_percent'] ?? 80);
            foreach (['first_response' => 'primera respuesta', 'resolution' => 'resolución'] as $k => $label) {
                $due = $r[$k . '_due_at'];
                $met = $k === 'first_response' ? $r['first_response_at'] : $r['resolved_at'];
                if ($due === null || $met !== null || $r[$k . '_breached_at'] !== null) {
                    continue;
                }
                $dueDt = Clock::fromDb((string)$due);
                $recipients = $r['owner_user_id'] !== null ? [(int)$r['owner_user_id']] : $notifier->supervisors($r['team_id'] !== null ? (int)$r['team_id'] : null);
                if ($now >= $dueDt) {
                    $db->run("UPDATE ticket_sla SET {$k}_breached_at = ? WHERE ticket_id = ? AND {$k}_breached_at IS NULL", [$nowDb, $tid]);
                    $this->app->make(TicketService::class)->event($tid, null, 'sla_breached', null, ['objetivo' => $label]);
                    $notifier->notify(
                        array_merge($recipients, $notifier->supervisors($r['team_id'] !== null ? (int)$r['team_id'] : null)),
                        'sla.breached', $tid, "SLA incumplido ($label): {$r['ticket_number']}", (string)$r['subject'], "sla.breached.$k.$tid"
                    );
                    $changes++;
                } elseif ($r[$k . '_warned_at'] === null) {
                    $start = Clock::fromDb((string)$r['created_at']);
                    $total = max(1, $this->minutesBetween($start, $dueDt, (string)$r['time_mode'], (int)$r['calendar_id']) - (int)$r['paused_minutes_total']);
                    $elapsed = $this->minutesBetween($start, $now, (string)$r['time_mode'], (int)$r['calendar_id']) - (int)$r['paused_minutes_total'];
                    if ($elapsed * 100 >= $total * $warnPct) {
                        $db->run("UPDATE ticket_sla SET {$k}_warned_at = ? WHERE ticket_id = ?", [$nowDb, $tid]);
                        $notifier->notify($recipients, 'sla.warning', $tid, "SLA a punto de vencer ($label): {$r['ticket_number']}", 'Vence el ' . Clock::format((string)$due), "sla.warning.$k.$tid." . substr((string)$due, 0, 16));
                        $changes++;
                    }
                }
            }
        }
        $db->run('UPDATE ticket_sla SET last_computed_at = ? WHERE paused_since IS NULL', [$nowDb]);
        return $changes;
    }

    // ------------------------------------------------------------------
    // Cálculo de tiempo
    // ------------------------------------------------------------------

    public function addMinutes(DateTimeImmutable $startUtc, int $minutes, string $mode, int $calendarId): DateTimeImmutable
    {
        if ($mode === 'calendar') {
            return $startUtc->modify("+$minutes minutes");
        }
        $cal = $this->calendar($calendarId);
        if ($cal['hours'] === []) {
            return $startUtc->modify("+$minutes minutes");
        }
        $remaining = $minutes * 60;
        $cursor = $startUtc->setTimezone($cal['tz']);
        for ($i = 0; $i < 800; $i++) {
            foreach ($this->dayIntervals($cal, $cursor) as [$from, $to]) {
                if ($to <= $cursor) {
                    continue;
                }
                $begin = $from > $cursor ? $from : $cursor;
                $avail = $to->getTimestamp() - $begin->getTimestamp();
                if ($remaining <= $avail) {
                    return $begin->modify("+$remaining seconds")->setTimezone(Clock::utc());
                }
                $remaining -= $avail;
            }
            $cursor = $cursor->setTime(0, 0)->modify('+1 day');
        }
        throw new \RuntimeException('No se pudo calcular el vencimiento del SLA (calendario sin horas).');
    }

    public function minutesBetween(DateTimeImmutable $a, DateTimeImmutable $b, string $mode, int $calendarId): int
    {
        if ($b <= $a) {
            return 0;
        }
        if ($mode === 'calendar') {
            return intdiv($b->getTimestamp() - $a->getTimestamp(), 60);
        }
        $cal = $this->calendar($calendarId);
        if ($cal['hours'] === []) {
            return intdiv($b->getTimestamp() - $a->getTimestamp(), 60);
        }
        $seconds = 0;
        $cursor = $a->setTimezone($cal['tz']);
        $end = $b->setTimezone($cal['tz']);
        for ($i = 0; $i < 3700 && $cursor < $end; $i++) {
            foreach ($this->dayIntervals($cal, $cursor) as [$from, $to]) {
                $s = $from > $cursor ? $from : $cursor;
                $e = $to < $end ? $to : $end;
                if ($e > $s) {
                    $seconds += $e->getTimestamp() - $s->getTimestamp();
                }
            }
            $cursor = $cursor->setTime(0, 0)->modify('+1 day');
        }
        return intdiv($seconds, 60);
    }

    /** @return list<array{0:DateTimeImmutable,1:DateTimeImmutable}> tramos laborables del día local de $day */
    private function dayIntervals(array $cal, DateTimeImmutable $day): array
    {
        if (isset($cal['holidays'][$day->format('Y-m-d')])) {
            return [];
        }
        $out = [];
        foreach ($cal['hours'][(int)$day->format('N')] ?? [] as [$startMin, $endMin]) {
            // setTime respeta el horario de verano de la zona del calendario
            $out[] = [
                $day->setTime(intdiv($startMin, 60), $startMin % 60),
                $day->setTime(intdiv($endMin, 60), $endMin % 60),
            ];
        }
        return $out;
    }

    /** @return array{tz:DateTimeZone,hours:array<int,list<array{0:int,1:int}>>,holidays:array<string,bool>} */
    private function calendar(int $id): array
    {
        if (isset($this->calendars[$id])) {
            return $this->calendars[$id];
        }
        $db = $this->app->db();
        $c = $db->one('SELECT timezone FROM business_calendars WHERE id = ?', [$id]);
        try {
            $tz = new DateTimeZone((string)($c['timezone'] ?? Clock::LOCAL_TZ));
        } catch (\Exception) {
            $tz = Clock::local();
        }
        $hours = [];
        foreach ($db->all('SELECT weekday, start_time, end_time FROM business_hours WHERE calendar_id = ? ORDER BY weekday, start_time', [$id]) as $h) {
            [$sh, $sm] = array_map('intval', explode(':', (string)$h['start_time']));
            [$eh, $em] = array_map('intval', explode(':', (string)$h['end_time']));
            $hours[(int)$h['weekday']][] = [$sh * 60 + $sm, $eh * 60 + $em];
        }
        $holidays = array_fill_keys($db->column('SELECT holiday_date FROM calendar_holidays WHERE calendar_id = ?', [$id]), true);
        return $this->calendars[$id] = ['tz' => $tz, 'hours' => $hours, 'holidays' => $holidays];
    }

    public function forgetCalendars(): void
    {
        $this->calendars = [];
    }
}
