<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Clock;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Services\StaffService;

final class EmployeeController extends Controller
{
    private const RULES = [
        'first_name' => 'required|string|max:80',
        'last_name' => 'required|string|max:120',
        'email' => 'required|email',
        'username' => 'string|max:60',
        'role_id' => 'required|int',
        'corporate_phone' => 'phone|max:30',
        'hired_on' => 'day',
        'max_open_tickets' => 'int|min:1|max:500',
        'team_ids' => 'intlist',
        'lead_team_ids' => 'intlist',
    ];
    private const LABELS = [
        'first_name' => 'El nombre', 'last_name' => 'Los apellidos', 'email' => 'El correo', 'username' => 'El usuario',
        'role_id' => 'El rol', 'corporate_phone' => 'El teléfono', 'hired_on' => 'La fecha de alta', 'max_open_tickets' => 'El límite de incidencias',
        'left_on' => 'La fecha de baja', 'team_ids' => 'Los equipos', 'lead_team_ids' => 'Los equipos que coordina',
    ];

    private function svc(): StaffService
    {
        return $this->app->make(StaffService::class);
    }

    /** @return array<string,mixed> */
    private function employee(): array
    {
        $e = $this->db()->one(
            "SELECT u.id, u.email, u.email_normalized, u.username, u.role_id, u.status, u.last_login_at, u.must_change_password, u.locked_until, u.created_at,
                    r.name AS role, r.code AS role_code, e.id AS employee_id, e.first_name, e.last_name, e.corporate_phone, e.hired_on, e.left_on, e.max_open_tickets
               FROM users u JOIN employees e ON e.user_id = u.id JOIN roles r ON r.id = u.role_id
              WHERE u.id = ? AND u.user_type = 'staff' AND u.deleted_at IS NULL",
            [$this->request->paramInt('id')]
        );
        if ($e === null) {
            throw HttpException::notFound();
        }
        return $e;
    }

    public function index(): Response
    {
        $q = mb_substr($this->request->str('q'), 0, 100);
        $status = $this->request->str('estado', 'active');
        $team = $this->request->int('equipo');
        $where = ["u.user_type = 'staff'", 'u.deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            $like = '%' . Database::likeEscape($q) . '%';
            $where[] = "(CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
            array_push($params, $like, $like, $like);
        }
        if (in_array($status, ['active', 'blocked', 'disabled'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        if ($team !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM team_members tm WHERE tm.user_id = u.id AND tm.team_id = ?)';
            $params[] = $team;
        }
        $w = implode(' AND ', $where);
        $rows = $this->db()->all(
            "SELECT u.id, u.email, u.status, u.last_login_at, r.name AS role, e.first_name, e.last_name, e.corporate_phone,
                    (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.user_id = u.id) AS teams,
                    (SELECT COUNT(*) FROM tickets tk JOIN ticket_statuses s ON s.id = tk.status_id WHERE tk.owner_user_id = u.id AND s.phase IN ('new','open','pending') AND tk.deleted_at IS NULL) AS open_tickets
               FROM users u JOIN employees e ON e.user_id = u.id JOIN roles r ON r.id = u.role_id
              WHERE $w ORDER BY e.first_name, e.last_name",
            $params
        );
        $teams = $this->db()->all('SELECT id, name FROM teams WHERE active = 1 ORDER BY name');
        return $this->render('employees/index.twig', compact('rows', 'q', 'status', 'team', 'teams'));
    }

    public function create(): Response
    {
        return $this->render('employees/form.twig', ['e' => null, 'memberOf' => [], 'leads' => [], 'mySpecialties' => []] + $this->catalog());
    }

    public function store(): Response
    {
        $d = array_filter($this->validate(self::RULES, self::LABELS), static fn ($v) => $v !== null);
        // La contraseña se toma sin recortar: los espacios forman parte de ella
        $d['password'] = is_string($this->request->post['password'] ?? null) ? $this->request->post['password'] : '';
        $d['password_confirmation'] = is_string($this->request->post['password_confirmation'] ?? null) ? $this->request->post['password_confirmation'] : '';
        if ($d['password'] === '') {
            throw ValidationException::single('password', 'La contraseña es obligatoria.');
        }
        if (!$this->user()->can('permissions.manage')) {
            // Sin permiso de roles solo se puede dar de alta con el rol de técnico
            $tech = (int)$this->db()->value("SELECT id FROM roles WHERE code = 'technician'");
            if ((int)$d['role_id'] !== $tech) {
                throw ValidationException::single('role_id', 'Solo puedes crear empleados con el rol de técnico.');
            }
        }
        $id = $this->svc()->create($d, $this->user()->id, true);
        $this->syncSpecialties($id);
        return $this->redirect("/empleados/$id", 'Empleado creado. Deberá cambiar la contraseña en su primer acceso.');
    }

    public function show(): Response
    {
        $e = $this->employee();
        $db = $this->db();
        $uid = (int)$e['id'];
        $teams = $db->all('SELECT t.id, t.name, tm.is_lead FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.user_id = ? ORDER BY t.name', [$uid]);
        $specialties = $db->column('SELECT s.name FROM employee_specialties es JOIN specialties s ON s.id = es.specialty_id WHERE es.employee_id = ? ORDER BY s.name', [(int)$e['employee_id']]);
        $tickets = $db->all(
            "SELECT t.id, t.ticket_number, t.subject, t.last_activity_at, s.name AS status, s.color AS status_color, pr.name AS priority, pr.color AS priority_color,
                    COALESCE(c.trade_name, c.legal_name) AS client, IF(t.owner_user_id = ?, 'owner', 'collaborator') AS role
               FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id JOIN ticket_priorities pr ON pr.id = t.priority_id LEFT JOIN clients c ON c.id = t.client_id
              WHERE t.deleted_at IS NULL AND s.phase IN ('new','open','pending')
                AND (t.owner_user_id = ? OR EXISTS (SELECT 1 FROM ticket_participants p WHERE p.ticket_id = t.id AND p.user_id = ? AND p.role = 'collaborator' AND p.removed_at IS NULL))
           ORDER BY pr.level DESC, t.last_activity_at DESC LIMIT 100",
            [$uid, $uid, $uid]
        );
        $from = Clock::now()->modify('-30 days')->format(Clock::DB_FORMAT);
        $stats = $db->one(
            "SELECT (SELECT COALESCE(SUM(minutes),0) FROM ticket_time_entries WHERE user_id = ? AND deleted_at IS NULL AND work_date >= ?) AS minutes_30,
                    (SELECT COUNT(*) FROM tickets WHERE owner_user_id = ? AND resolved_at >= ?) AS resolved_30",
            [$uid, Clock::now()->setTimezone(Clock::local())->modify('-30 days')->format('Y-m-d'), $uid, $from]
        );
        $permissions = [];
        $overrides = [];
        if ($this->user()->can('permissions.manage')) {
            $permissions = $db->all("SELECT p.code, p.module, p.description, EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = ? AND rp.permission_id = p.id) AS in_role FROM permissions p WHERE p.module <> 'portal' ORDER BY p.module, p.code", [(int)$e['role_id']]);
            $overrides = array_column($db->all('SELECT p.code, up.effect FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = ?', [$uid]), 'effect', 'code');
        }
        $staff = $db->all("SELECT u.id, CONCAT(e.first_name, ' ', e.last_name) AS name FROM users u JOIN employees e ON e.user_id = u.id WHERE u.status = 'active' AND u.user_type = 'staff' AND u.deleted_at IS NULL AND u.id <> ? ORDER BY e.first_name", [$uid]);
        return $this->render('employees/show.twig', compact('e', 'teams', 'specialties', 'tickets', 'stats', 'permissions', 'overrides', 'staff'));
    }

    public function edit(): Response
    {
        $e = $this->employee();
        $tm = $this->db()->all('SELECT team_id, is_lead FROM team_members WHERE user_id = ?', [(int)$e['id']]);
        return $this->render('employees/form.twig', [
            'e' => $e,
            'mySpecialties' => array_map('intval', $this->db()->column('SELECT specialty_id FROM employee_specialties WHERE employee_id = ?', [(int)$e['employee_id']])),
            'memberOf' => array_map('intval', array_column($tm, 'team_id')),
            'leads' => array_map('intval', array_column(array_filter($tm, static fn ($r) => (int)$r['is_lead'] === 1), 'team_id')),
        ] + $this->catalog());
    }

    public function update(): Response
    {
        $e = $this->employee();
        $d = $this->validate(self::RULES + ['left_on' => 'day'], self::LABELS);
        $this->svc()->update($e, $d, $this->user());
        $this->syncSpecialties((int)$e['id']);
        return $this->redirect('/empleados/' . $e['id'], 'Empleado actualizado.');
    }

    public function status(): Response
    {
        $e = $this->employee();
        $status = $this->request->str('status');
        $this->svc()->setStatus((int)$e['id'], $status, $this->user());
        $msg = ['active' => 'Cuenta activada.', 'blocked' => 'Cuenta bloqueada y sesiones cerradas.', 'disabled' => 'Cuenta desactivada y sesiones cerradas.'][$status] ?? 'Estado actualizado.';
        if ($status !== 'active') {
            $open = (int)$this->db()->value("SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.owner_user_id = ? AND s.phase IN ('new','open','pending') AND t.deleted_at IS NULL", [(int)$e['id']]);
            if ($open > 0) {
                $msg .= " Tiene $open incidencias abiertas: reasígnalas desde su ficha.";
            }
        }
        return $this->redirect('/empleados/' . $e['id'], $msg);
    }

    public function password(): Response
    {
        $e = $this->employee();
        if ((int)$e['id'] === $this->user()->id) {
            throw new ValidationException([], 'Para cambiar tu propia contraseña usa tu perfil.');
        }
        $pw = $this->request->post['password'] ?? '';
        $confirm = $this->request->post['password_confirmation'] ?? '';
        $this->svc()->adminSetPassword((int)$e['id'], is_string($pw) ? $pw : '', is_string($confirm) ? $confirm : '');
        return $this->redirect('/empleados/' . $e['id'], 'Contraseña temporal establecida. Se le pedirá cambiarla al entrar; sus sesiones se han cerrado.');
    }

    public function permissions(): Response
    {
        $e = $this->employee();
        $raw = $this->request->get('perm');
        $overrides = [];
        foreach (is_array($raw) ? $raw : [] as $code => $effect) {
            if (is_string($code) && in_array($effect, ['allow', 'deny'], true)) {
                $overrides[$code] = $effect;
            }
        }
        $this->svc()->setOverrides((int)$e['id'], $overrides, $this->user());
        return $this->redirect('/empleados/' . $e['id'] . '#permisos', 'Permisos actualizados. Se aplican desde su siguiente clic.');
    }

    public function reassign(): Response
    {
        $e = $this->employee();
        $to = $this->request->int('to_user_id');
        if ($to !== null && !$this->db()->value("SELECT 1 FROM users WHERE id = ? AND user_type = 'staff' AND status = 'active' AND deleted_at IS NULL", [$to])) {
            throw ValidationException::single('to_user_id', 'Elige un empleado activo.');
        }
        if ($to === (int)$e['id']) {
            throw ValidationException::single('to_user_id', 'Elige otro empleado.');
        }
        $n = $this->svc()->reassignOpenTickets((int)$e['id'], $to, $this->user(), $this->request->bool('include_collaborations'));
        return $this->redirect('/empleados/' . $e['id'], $n === 1 ? 'Se ha reasignado 1 incidencia.' : "Se han reasignado $n incidencias.");
    }

    /** @return array<string,mixed> */
    private function catalog(): array
    {
        return [
            'roles' => $this->db()->all("SELECT id, code, name FROM roles WHERE user_type = 'staff' ORDER BY id"),
            'teams' => $this->db()->all('SELECT id, name FROM teams WHERE active = 1 ORDER BY name'),
            'specialties' => $this->db()->all('SELECT id, name FROM specialties WHERE active = 1 ORDER BY name'),
        ];
    }

    private function syncSpecialties(int $userId): void
    {
        $db = $this->db();
        $empId = (int)$db->value('SELECT id FROM employees WHERE user_id = ?', [$userId]);
        $ids = $this->request->intList('specialty_ids');
        $valid = $ids === [] ? [] : array_map('intval', $db->column('SELECT id FROM specialties WHERE id IN (' . implode(',', $ids) . ')'));
        $db->run('DELETE FROM employee_specialties WHERE employee_id = ?', [$empId]);
        foreach ($valid as $sid) {
            $db->run('INSERT INTO employee_specialties (employee_id, specialty_id) VALUES (?, ?)', [$empId, $sid]);
        }
    }
}
