<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use App\Core\Validator;
use App\Security\CurrentUser;

/**
 * Búsqueda global. Solo devuelve lo que el usuario puede ver: las
 * incidencias y mensajes pasan por TicketAccess; clientes y contactos exigen
 * clients.view.
 */
final class Search
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function run(CurrentUser $user, string $q, int $limit = 8): array
    {
        $q = trim(mb_substr($q, 0, 100));
        $out = ['tickets' => [], 'clients' => [], 'contacts' => [], 'messages' => []];
        if (mb_strlen($q) < 2) {
            return $out;
        }
        $db = $this->app->db();
        $like = '%' . Database::likeEscape($q) . '%';
        [$w, $p] = $this->app->make(TicketAccess::class)->scope($user);

        $out['tickets'] = array_map(static fn ($r) => $r + ['url' => '/incidencias/' . $r['id']], $db->all(
            "SELECT t.id, t.ticket_number, t.subject, s.name AS status, s.color AS status_color, COALESCE(c.trade_name, c.legal_name) AS client
               FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id LEFT JOIN clients c ON c.id = t.client_id
              WHERE $w AND (t.ticket_number = ? OR t.ticket_number LIKE ? OR t.subject LIKE ? OR t.description LIKE ? OR t.requester_email LIKE ? OR t.requester_phone LIKE ?)
           ORDER BY (t.ticket_number = ?) DESC, t.last_activity_at DESC LIMIT " . (int)$limit,
            array_merge($p, [strtoupper($q), $like, $like, $like, $like, $like, strtoupper($q)])
        ));

        if ($user->can('clients.view')) {
            $tax = Validator::normalizeTaxId($q);
            $out['clients'] = array_map(static fn ($r) => $r + ['url' => '/clientes/' . $r['id']], $db->all(
                "SELECT id, code, COALESCE(trade_name, legal_name) AS name, tax_id FROM clients
                  WHERE deleted_at IS NULL AND (legal_name LIKE ? OR trade_name LIKE ? OR code = ? OR tax_id_normalized = ? OR email LIKE ? OR phone LIKE ?)
               ORDER BY legal_name LIMIT " . (int)$limit,
                [$like, $like, strtoupper($q), (string)$tax, $like, $like]
            ));
            $out['contacts'] = array_map(static fn ($r) => $r + ['url' => '/clientes/' . $r['client_id'] . '#contacto-' . $r['id']], $db->all(
                "SELECT DISTINCT cc.id, cc.client_id, TRIM(CONCAT(cc.first_name, ' ', COALESCE(cc.last_name, ''))) AS name, COALESCE(c.trade_name, c.legal_name) AS client
                   FROM client_contacts cc JOIN clients c ON c.id = cc.client_id
              LEFT JOIN contact_identities ci ON ci.contact_id = cc.id
                  WHERE cc.deleted_at IS NULL AND c.deleted_at IS NULL
                    AND (CONCAT(cc.first_name, ' ', COALESCE(cc.last_name, '')) LIKE ? OR ci.value LIKE ? OR ci.value_normalized LIKE ?)
               ORDER BY name LIMIT " . (int)$limit,
                [$like, $like, '%' . Database::likeEscape(preg_replace('/\D/', '', $q) ?: $q) . '%']
            ));
        }

        $rows = $db->all(
            "SELECT m.id, m.ticket_id, t.ticket_number, m.body_text FROM ticket_messages m JOIN tickets t ON t.id = m.ticket_id
              WHERE $w AND m.body_text LIKE ? ORDER BY m.id DESC LIMIT " . (int)$limit,
            array_merge($p, [$like])
        );
        if ($user->can('tickets.note_internal')) {
            $rows = array_merge($rows, $db->all(
                "SELECT a.id, a.ticket_id, t.ticket_number, a.body AS body_text FROM ticket_activities a JOIN tickets t ON t.id = a.ticket_id
                  WHERE $w AND a.deleted_at IS NULL AND a.body LIKE ? ORDER BY a.id DESC LIMIT " . (int)$limit,
                array_merge($p, [$like])
            ));
        }
        $out['messages'] = array_map(static function ($r) use ($q) {
            $text = (string)preg_replace('/\s+/', ' ', (string)$r['body_text']);
            $pos = mb_stripos($text, $q);
            $start = max(0, ($pos === false ? 0 : $pos) - 40);
            return [
                'ticket_number' => $r['ticket_number'],
                'snippet' => ($start > 0 ? '…' : '') . mb_substr($text, $start, 120) . '…',
                'url' => '/incidencias/' . $r['ticket_id'],
            ];
        }, array_slice($rows, 0, $limit));
        return $out;
    }
}
