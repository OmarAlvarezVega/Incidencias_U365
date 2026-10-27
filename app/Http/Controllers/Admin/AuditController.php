<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Response;
use App\Http\Controller;

/** Consulta del registro de auditoría (solo lectura) y verificación de la cadena. */
final class AuditController extends Controller
{
    public function index(): Response
    {
        [$page, $per, $offset] = $this->pagination(50);
        $where = ['1=1'];
        $params = [];
        $f = [
            'accion' => mb_substr($this->request->str('accion'), 0, 64),
            'usuario' => $this->request->int('usuario'),
            'entidad' => mb_substr($this->request->str('entidad'), 0, 40),
            'id' => mb_substr($this->request->str('id'), 0, 40),
            'desde' => $this->request->str('desde'),
            'hasta' => $this->request->str('hasta'),
        ];
        if ($f['accion'] !== '') {
            $where[] = 'a.action LIKE ?';
            $params[] = Database::likeEscape($f['accion']) . '%';
        }
        if ($f['usuario'] !== null) {
            $where[] = 'a.actor_user_id = ?';
            $params[] = $f['usuario'];
        }
        if ($f['entidad'] !== '') {
            $where[] = 'a.entity_type = ?';
            $params[] = $f['entidad'];
            if ($f['id'] !== '') {
                $where[] = 'a.entity_id = ?';
                $params[] = $f['id'];
            }
        }
        if (($from = Clock::parseLocal($f['desde'])) !== null) {
            $where[] = 'a.occurred_at >= ?';
            $params[] = Clock::toDb($from);
        }
        if (($to = Clock::parseLocal($f['hasta'], true)) !== null) {
            $where[] = 'a.occurred_at <= ?';
            $params[] = Clock::toDb($to);
        }
        $w = implode(' AND ', $where);
        $db = $this->db();
        $total = (int)$db->value("SELECT COUNT(*) FROM audit_logs a WHERE $w", $params);
        $rows = $db->all(
            "SELECT a.id, a.occurred_at, a.actor_type, a.actor_user_id, a.action, a.entity_type, a.entity_id, INET6_NTOA(a.ip) AS ip, a.details, a.chain_seq,
                    CONCAT(e.first_name, ' ', e.last_name) AS actor
               FROM audit_logs a LEFT JOIN employees e ON e.user_id = a.actor_user_id
              WHERE $w ORDER BY a.id DESC LIMIT $per OFFSET $offset",
            $params
        );
        $users = $db->all("SELECT u.id, CONCAT(e.first_name, ' ', e.last_name) AS name FROM users u JOIN employees e ON e.user_id = u.id ORDER BY e.first_name");
        $actions = $db->column('SELECT DISTINCT action FROM audit_logs ORDER BY action LIMIT 300');
        $verification = $this->app->session()->get('audit_verification');
        $this->app->session()->remove('audit_verification');
        return $this->render('admin/audit.twig', compact('rows', 'total', 'page', 'per', 'f', 'users', 'actions', 'verification'));
    }

    public function verify(): Response
    {
        $audit = $this->app->audit();
        $audit->seal(0);
        $problem = $audit->verify();
        $this->app->session()->set('audit_verification', ['ok' => $problem === null, 'message' => $problem ?? 'La cadena de auditoría está íntegra: ningún registro sellado se ha modificado ni borrado.', 'at' => Clock::format(Clock::nowDb())]);
        $audit->log('audit.verified', null, null, ['resultado' => $problem === null ? 'ok' : 'rota']);
        return $this->redirect('/admin/auditoria');
    }
}
