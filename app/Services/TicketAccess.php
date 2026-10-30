<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\HttpException;
use App\Security\CurrentUser;

/**
 * Única fuente de verdad sobre qué incidencias puede ver un usuario.
 * Todas las consultas de listados, búsqueda, informes y exportación usan
 * scope(); todas las acciones sobre una incidencia pasan por find().
 *
 *  tickets.view.all        → todas
 *  tickets.view.team       → las de los equipos a los que pertenece
 *  tickets.view.own        → responsable o participante activo
 *  tickets.view.unassigned → las que no tienen responsable
 */
final class TicketAccess
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * Fragmento WHERE para el alias $t de la tabla tickets.
     * @return array{0:string,1:list<mixed>}
     */
    public function scope(CurrentUser $user, string $t = 't'): array
    {
        if ($user->can('tickets.view.all')) {
            return ["$t.deleted_at IS NULL", []];
        }
        $or = [];
        $params = [];
        if ($user->can('tickets.view.team') && $user->teamIds !== []) {
            $or[] = "$t.team_id IN (" . implode(',', array_map('intval', $user->teamIds)) . ')';
        }
        if ($user->can('tickets.view.own')) {
            $or[] = "$t.owner_user_id = ?";
            $params[] = $user->id;
            $or[] = "EXISTS (SELECT 1 FROM ticket_participants tp_s WHERE tp_s.ticket_id = $t.id AND tp_s.user_id = ? AND tp_s.removed_at IS NULL)";
            $params[] = $user->id;
        }
        if ($user->can('tickets.view.unassigned')) {
            $or[] = "$t.owner_user_id IS NULL";
        }
        if ($or === []) {
            return ['1 = 0', []];
        }
        return ["$t.deleted_at IS NULL AND (" . implode(' OR ', $or) . ')', $params];
    }

    /** @return array<string,mixed>|null */
    public function visible(CurrentUser $user, int $ticketId): ?array
    {
        [$where, $params] = $this->scope($user);
        return $this->app->db()->one("SELECT t.* FROM tickets t WHERE t.id = ? AND $where", array_merge([$ticketId], $params));
    }

    /**
     * Devuelve la incidencia o lanza 404 (no 403, para no revelar que existe).
     * @return array<string,mixed>
     */
    public function find(CurrentUser $user, int $ticketId): array
    {
        $t = $this->visible($user, $ticketId);
        if ($t === null) {
            throw HttpException::notFound('La incidencia no existe o no tienes acceso a ella.');
        }
        return $t;
    }

    /** ¿Trabaja el usuario en la incidencia (responsable o colaborador)? */
    public function isWorker(CurrentUser $user, array $ticket): bool
    {
        if ((int)($ticket['owner_user_id'] ?? 0) === $user->id) {
            return true;
        }
        return (bool)$this->app->db()->value(
            "SELECT 1 FROM ticket_participants WHERE ticket_id = ? AND user_id = ? AND role = 'collaborator' AND removed_at IS NULL",
            [(int)$ticket['id'], $user->id]
        );
    }

    /** ¿Supervisa la incidencia (ve todas, o lidera su equipo)? */
    public function supervises(CurrentUser $user, array $ticket): bool
    {
        if ($user->can('tickets.view.all')) {
            return true;
        }
        return $ticket['team_id'] !== null && in_array((int)$ticket['team_id'], $user->ledTeamIds, true);
    }

    /** Puede modificar la incidencia: trabaja en ella o la supervisa, además del permiso concreto. */
    public function canModify(CurrentUser $user, array $ticket, string $permission): bool
    {
        if (!$user->can($permission)) {
            return false;
        }
        if ($this->supervises($user, $ticket) || $this->isWorker($user, $ticket)) {
            return true;
        }
        // Sin responsable: quien la ve en la bandeja puede actuar (p. ej. asignársela)
        return $ticket['owner_user_id'] === null && $user->can('tickets.view.unassigned');
    }

    public function authorize(CurrentUser $user, array $ticket, string $permission): void
    {
        if (!$this->canModify($user, $ticket, $permission)) {
            throw HttpException::forbidden('No tienes permiso para esta acción en esta incidencia.');
        }
    }
}
