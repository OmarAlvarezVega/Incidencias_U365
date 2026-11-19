<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Clock;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Security\CurrentUser;
use PDOException;

/**
 * Registro de tiempo: temporizador (uno en marcha por persona, garantizado
 * por un índice único en la base de datos) y apuntes manuales.
 * tickets.total_minutes es una caché que se recalcula tras cada cambio.
 */
final class TimeService
{
    public const MAX_TIMER_HOURS = 12;

    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string,mixed>|null */
    public function running(int $userId): ?array
    {
        return $this->app->db()->one(
            'SELECT e.*, t.ticket_number, t.subject FROM ticket_time_entries e JOIN tickets t ON t.id = e.ticket_id WHERE e.running_user_id = ?',
            [$userId]
        );
    }

    /** @param array<string,mixed> $ticket */
    public function start(array $ticket, CurrentUser $user): void
    {
        $current = $this->running($user->id);
        if ($current !== null) {
            if ((int)$current['ticket_id'] === (int)$ticket['id']) {
                return;
            }
            // Cambiar de incidencia para el temporizador anterior
            $this->stop($user, null);
        }
        $now = Clock::now();
        try {
            $this->app->db()->insert('ticket_time_entries', [
                'ticket_id' => (int)$ticket['id'],
                'user_id' => $user->id,
                'source' => 'timer',
                'started_at' => Clock::toDb($now),
                'work_date' => $now->setTimezone(Clock::local())->format('Y-m-d'),
                'billable' => 1,
                'created_at' => Clock::toDb($now),
                'updated_at' => Clock::toDb($now),
            ]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_tte_one_running') || $e->getCode() === '23000') {
                throw new ValidationException([], 'Ya tienes un temporizador en marcha. Páralo antes de iniciar otro.');
            }
            throw $e;
        }
        $this->app->make(TicketService::class)->event((int)$ticket['id'], $user->id, 'timer_started', null, null);
    }

    /** Para el temporizador del usuario. Devuelve los minutos registrados. */
    public function stop(CurrentUser $user, ?string $description): int
    {
        $e = $this->running($user->id);
        if ($e === null) {
            throw new ValidationException([], 'No tienes ningún temporizador en marcha.');
        }
        return $this->finish($e, $user->id, $description);
    }

    /** @param array<string,mixed> $entry */
    private function finish(array $entry, ?int $byUserId, ?string $description, ?\DateTimeImmutable $at = null): int
    {
        $start = Clock::fromDb((string)$entry['started_at']);
        $end = $at ?? Clock::now();
        $cap = $start->modify('+' . self::MAX_TIMER_HOURS . ' hours');
        $capped = false;
        if ($end > $cap) {
            $end = $cap;
            $capped = true;
        }
        $minutes = max(1, (int)ceil(($end->getTimestamp() - $start->getTimestamp()) / 60));
        $minutes = min(1440, $minutes);
        $desc = $description !== null && trim($description) !== '' ? mb_substr(trim($description), 0, 1000) : $entry['description'];
        if ($capped) {
            $desc = trim(($desc ?? '') . ' [Limitado automáticamente a ' . self::MAX_TIMER_HOURS . ' h: revisar]');
        }
        $this->app->db()->run(
            'UPDATE ticket_time_entries SET ended_at = ?, minutes = ?, description = ?, updated_at = ?, updated_by = ? WHERE id = ? AND ended_at IS NULL',
            [Clock::toDb($end), $minutes, $desc, Clock::nowDb(), $byUserId, (int)$entry['id']]
        );
        $this->recompute((int)$entry['ticket_id']);
        $this->app->make(TicketService::class)->event((int)$entry['ticket_id'], $byUserId, 'timer_stopped', null, ['minutos' => $minutes]);
        return $minutes;
    }

    /** Para todos los temporizadores de una incidencia (al resolverla, cerrarla o borrarla). */
    public function stopAllForTicket(int $ticketId, ?int $byUserId): void
    {
        foreach ($this->app->db()->all('SELECT * FROM ticket_time_entries WHERE ticket_id = ? AND running_user_id IS NOT NULL', [$ticketId]) as $e) {
            $this->finish($e, $byUserId, null);
        }
    }

    /** Cierra temporizadores olvidados (cron). */
    public function closeStale(): int
    {
        $limit = Clock::now()->modify('-' . self::MAX_TIMER_HOURS . ' hours')->format(Clock::DB_FORMAT);
        $n = 0;
        foreach ($this->app->db()->all('SELECT * FROM ticket_time_entries WHERE running_user_id IS NOT NULL AND started_at < ?', [$limit]) as $e) {
            $this->finish($e, null, null);
            $this->app->make(Notifications::class)->notify([(int)$e['user_id']], 'ticket.status', (int)$e['ticket_id'],
                'Temporizador detenido automáticamente', 'Llevaba más de ' . self::MAX_TIMER_HOURS . ' h en marcha. Revisa el tiempo registrado.', 'stale_timer.' . $e['id']);
            $n++;
        }
        return $n;
    }

    /** @param array<string,mixed> $ticket */
    public function addManual(array $ticket, CurrentUser $user, int $minutes, string $workDate, ?string $description, bool $billable, ?int $forUserId = null): int
    {
        if ($minutes < 1 || $minutes > 1440) {
            throw ValidationException::single('minutes', 'El tiempo debe estar entre 1 minuto y 24 horas.');
        }
        $today = Clock::now()->setTimezone(Clock::local())->format('Y-m-d');
        if ($workDate > $today) {
            throw ValidationException::single('work_date', 'La fecha no puede ser futura.');
        }
        $owner = $forUserId ?? $user->id;
        if ($owner !== $user->id && !$user->can('time.edit_any')) {
            throw HttpException::forbidden('No puedes registrar tiempo en nombre de otra persona.');
        }
        $now = Clock::nowDb();
        $id = $this->app->db()->insert('ticket_time_entries', [
            'ticket_id' => (int)$ticket['id'],
            'user_id' => $owner,
            'source' => 'manual',
            'minutes' => $minutes,
            'work_date' => $workDate,
            'description' => $description !== null ? mb_substr($description, 0, 1000) : null,
            'billable' => $billable ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
            'updated_by' => $user->id,
        ]);
        $this->recompute((int)$ticket['id']);
        $this->app->make(TicketService::class)->event((int)$ticket['id'], $user->id, 'time_added', null, ['minutos' => $minutes, 'fecha' => $workDate]);
        return $id;
    }

    /** @return array<string,mixed> registro editable por el usuario */
    public function editable(int $entryId, CurrentUser $user): array
    {
        $e = $this->app->db()->one('SELECT * FROM ticket_time_entries WHERE id = ? AND deleted_at IS NULL', [$entryId]);
        if ($e === null) {
            throw HttpException::notFound();
        }
        if ((int)$e['user_id'] !== $user->id && !$user->can('time.edit_any')) {
            throw HttpException::forbidden('Solo puedes corregir tus propios registros de tiempo.');
        }
        return $e;
    }

    /** @param array<string,mixed> $entry */
    public function update(array $entry, CurrentUser $user, int $minutes, string $workDate, ?string $description, bool $billable): void
    {
        if ($entry['running_user_id'] !== null) {
            throw new ValidationException([], 'Para el temporizador antes de corregirlo.');
        }
        if ($minutes < 1 || $minutes > 1440) {
            throw ValidationException::single('minutes', 'El tiempo debe estar entre 1 minuto y 24 horas.');
        }
        $this->app->db()->run(
            "UPDATE ticket_time_entries SET minutes = ?, work_date = ?, description = ?, billable = ?, updated_at = ?, updated_by = ?,
                    source = IF(source = 'timer' AND minutes <> ?, 'manual', source)
              WHERE id = ?",
            [$minutes, $workDate, $description, $billable ? 1 : 0, Clock::nowDb(), $user->id, $minutes, (int)$entry['id']]
        );
        $this->recompute((int)$entry['ticket_id']);
        $this->app->make(TicketService::class)->event((int)$entry['ticket_id'], $user->id, 'time_edited', ['minutos' => (int)$entry['minutes']], ['minutos' => $minutes]);
        $this->app->audit()->log('time.edited', 'time_entry', (int)$entry['id'], ['antes' => (int)$entry['minutes'], 'despues' => $minutes, 'de_usuario' => (int)$entry['user_id']]);
    }

    /** @param array<string,mixed> $entry */
    public function delete(array $entry, CurrentUser $user): void
    {
        $this->app->db()->run('UPDATE ticket_time_entries SET deleted_at = ?, updated_by = ? WHERE id = ?', [Clock::nowDb(), $user->id, (int)$entry['id']]);
        $this->recompute((int)$entry['ticket_id']);
        $this->app->make(TicketService::class)->event((int)$entry['ticket_id'], $user->id, 'time_deleted', ['minutos' => (int)$entry['minutes']], null);
        $this->app->audit()->log('time.deleted', 'time_entry', (int)$entry['id'], ['minutos' => (int)$entry['minutes'], 'de_usuario' => (int)$entry['user_id']]);
    }

    public function recompute(int $ticketId): void
    {
        $this->app->db()->run(
            'UPDATE tickets SET total_minutes = (SELECT COALESCE(SUM(minutes), 0) FROM ticket_time_entries WHERE ticket_id = ? AND deleted_at IS NULL AND minutes IS NOT NULL) WHERE id = ?',
            [$ticketId, $ticketId]
        );
    }
}
