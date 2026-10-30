<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;

/**
 * Permisos por rol. El rol de superadministrador no se edita: siempre tiene
 * todos los permisos de personal, para que nadie pueda quedarse sin acceso.
 */
final class RoleController extends Controller
{
    public function index(): Response
    {
        $db = $this->db();
        $roles = $db->all("SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.deleted_at IS NULL AND u.status = 'active') AS users FROM roles r WHERE r.user_type = 'staff' ORDER BY r.id");
        $permissions = $db->all("SELECT id, code, module, description FROM permissions WHERE module <> 'portal' ORDER BY FIELD(module, 'tickets', 'time', 'clients', 'employees', 'reports', 'admin'), code");
        $matrix = [];
        foreach ($db->all('SELECT role_id, permission_id FROM role_permissions') as $rp) {
            $matrix[(int)$rp['role_id']][(int)$rp['permission_id']] = true;
        }
        $selected = $this->request->int('rol') ?? (int)($roles[1]['id'] ?? $roles[0]['id'] ?? 0);
        return $this->render('admin/roles.twig', compact('roles', 'permissions', 'matrix', 'selected'));
    }

    public function update(): Response
    {
        $db = $this->db();
        $role = $db->one("SELECT * FROM roles WHERE id = ? AND user_type = 'staff'", [$this->request->paramInt('role')]);
        if ($role === null) {
            throw HttpException::notFound();
        }
        if ($role['code'] === 'superadmin') {
            throw new ValidationException([], 'El rol de superadministrador siempre tiene todos los permisos y no se puede modificar.');
        }
        if ((int)$role['id'] === (int)$db->value('SELECT role_id FROM users WHERE id = ?', [$this->user()->id])) {
            throw new ValidationException([], 'No puedes modificar los permisos de tu propio rol.');
        }
        $valid = array_map('intval', $db->column("SELECT id FROM permissions WHERE module <> 'portal'"));
        $ids = array_values(array_intersect($this->request->intList('permissions'), $valid));
        $before = $db->column('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [(int)$role['id']]);
        $db->transaction(function () use ($db, $role, $ids) {
            $db->run('DELETE FROM role_permissions WHERE role_id = ?', [(int)$role['id']]);
            foreach ($ids as $pid) {
                $db->run('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [(int)$role['id'], $pid]);
            }
            $db->run('UPDATE roles SET updated_at = UTC_TIMESTAMP() WHERE id = ?', [(int)$role['id']]);
        });
        $after = $ids === [] ? [] : $db->column('SELECT code FROM permissions WHERE id IN (' . implode(',', $ids) . ')');
        $this->audit()->log('role.permissions', 'role', (int)$role['id'], [
            'rol' => $role['code'],
            'añadidos' => array_values(array_diff($after, $before)),
            'quitados' => array_values(array_diff($before, $after)),
        ]);
        $this->app->permissions()->forget();
        return $this->redirect('/admin/roles?rol=' . $role['id'], 'Permisos del rol «' . $role['name'] . '» guardados.');
    }
}
