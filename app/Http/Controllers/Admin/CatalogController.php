<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;

/**
 * Catálogos editables: estados, transiciones, prioridades, categorías,
 * etiquetas, canales y especialidades. Los códigos de estado del sistema
 * tienen significado en el código y no se pueden desactivar ni renombrar
 * (el nombre visible sí).
 */
final class CatalogController extends Controller
{
    public const SYSTEM_STATUSES = ['new', 'pending_assign', 'assigned', 'in_progress', 'pending_customer', 'resolved', 'closed', 'cancelled'];
    private const TABS = [
        'estados' => 'Estados', 'transiciones' => 'Flujo de estados', 'prioridades' => 'Prioridades', 'categorias' => 'Categorías',
        'etiquetas' => 'Etiquetas', 'canales' => 'Canales', 'especialidades' => 'Especialidades',
    ];

    public function index(): Response
    {
        $tab = $this->request->str('tab', 'estados');
        if (!isset(self::TABS[$tab])) {
            $tab = 'estados';
        }
        $db = $this->db();
        $data = [
            'tab' => $tab,
            'tabs' => self::TABS,
            'system' => self::SYSTEM_STATUSES,
            'statuses' => $db->all('SELECT s.*, (SELECT COUNT(*) FROM tickets t WHERE t.status_id = s.id) AS used FROM ticket_statuses s ORDER BY sort_order, id'),
        ];
        switch ($tab) {
            case 'transiciones':
                $data['transitions'] = [];
                foreach ($db->all('SELECT * FROM ticket_status_transitions') as $t) {
                    $data['transitions'][(int)$t['from_status_id']][(int)$t['to_status_id']] = $t['permission_code'] ?? '';
                }
                break;
            case 'prioridades':
                $data['rows'] = $db->all('SELECT p.*, (SELECT COUNT(*) FROM tickets t WHERE t.priority_id = p.id) AS used FROM ticket_priorities p ORDER BY level');
                break;
            case 'categorias':
                $data['rows'] = $db->all('SELECT c.*, p.name AS parent, (SELECT COUNT(*) FROM tickets t WHERE t.category_id = c.id) AS used FROM ticket_categories c LEFT JOIN ticket_categories p ON p.id = c.parent_id ORDER BY COALESCE(p.sort_order, c.sort_order), COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.sort_order, c.name');
                $data['parents'] = $db->all('SELECT id, name FROM ticket_categories WHERE parent_id IS NULL ORDER BY sort_order, name');
                break;
            case 'etiquetas':
                $data['rows'] = $db->all('SELECT g.*, (SELECT COUNT(*) FROM ticket_tag_relations r WHERE r.tag_id = g.id) AS used FROM ticket_tags g ORDER BY name');
                break;
            case 'canales':
                $data['rows'] = $db->all('SELECT * FROM ticket_channels ORDER BY name');
                break;
            case 'especialidades':
                $data['rows'] = $db->all('SELECT s.*, (SELECT COUNT(*) FROM employee_specialties es WHERE es.specialty_id = s.id) AS used FROM specialties s ORDER BY name');
                break;
        }
        return $this->render('admin/catalogs.twig', $data);
    }

    public function save(): Response
    {
        $type = $this->request->param('type');
        $id = $this->request->int('id');
        $msg = match ($type) {
            'estados' => $this->saveStatus($id),
            'transiciones' => $this->saveTransitions(),
            'prioridades' => $this->savePriority($id),
            'categorias' => $this->saveCategory($id),
            'etiquetas' => $this->saveTag($id),
            'canales' => $this->saveChannel(),
            'especialidades' => $this->saveSpecialty($id),
            default => throw HttpException::notFound(),
        };
        $this->audit()->log('catalog.saved', 'catalog', $id, ['catalogo' => $type]);
        return $this->redirect('/admin/catalogos?tab=' . $type, $msg);
    }

    private function saveStatus(?int $id): string
    {
        $db = $this->db();
        $d = $this->validate([
            'name' => 'required|string|max:80', 'color' => 'required|color', 'sort_order' => 'int|min:0|max:9999',
            'pauses_sla' => 'bool', 'active' => 'bool', 'phase' => 'in:new,open,pending,resolved,closed,cancelled',
        ], ['name' => 'El nombre', 'color' => 'El color', 'sort_order' => 'El orden', 'phase' => 'La fase']);
        $row = ['name' => $d['name'], 'color' => $d['color'], 'sort_order' => $d['sort_order'] ?? 0, 'pauses_sla' => $d['pauses_sla'] ? 1 : 0, 'active' => $d['active'] ? 1 : 0];
        if ($id === null) {
            if ($d['phase'] === null) {
                throw ValidationException::single('phase', 'Elige la fase del nuevo estado.');
            }
            $code = $this->slug($d['name'], 'ticket_statuses', 'code');
            $id = $db->insert('ticket_statuses', $row + ['code' => $code, 'phase' => $d['phase'], 'is_initial' => 0, 'active' => 1]);
            // Por defecto, desde y hacia los estados activos (se puede ajustar en el flujo)
            if (in_array($d['phase'], ['new', 'open', 'pending'], true)) {
                $db->run("INSERT IGNORE INTO ticket_status_transitions (from_status_id, to_status_id, permission_code) SELECT s.id, ?, NULL FROM ticket_statuses s WHERE s.phase IN ('new','open','pending') AND s.id <> ?", [$id, $id]);
                $db->run("INSERT IGNORE INTO ticket_status_transitions (from_status_id, to_status_id, permission_code) SELECT ?, s.id, NULL FROM ticket_statuses s WHERE s.phase IN ('open','pending','resolved','cancelled') AND s.id <> ?", [$id, $id]);
            }
            return 'Estado creado. Revisa el flujo de estados si necesitas ajustar sus transiciones.';
        }
        $cur = $db->one('SELECT * FROM ticket_statuses WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        if (in_array($cur['code'], self::SYSTEM_STATUSES, true)) {
            $row['active'] = 1;
        }
        $db->update('ticket_statuses', $row, 'id = ?', [$id]);
        return 'Estado guardado.';
    }

    private function saveTransitions(): string
    {
        $db = $this->db();
        $ids = array_map('intval', $db->column('SELECT id FROM ticket_statuses'));
        $raw = $this->request->get('t');
        $rows = [];
        foreach (is_array($raw) ? $raw : [] as $from => $tos) {
            if (!is_array($tos) || !in_array((int)$from, $ids, true)) {
                continue;
            }
            foreach ($tos as $to => $perm) {
                if ((int)$to === (int)$from || !in_array((int)$to, $ids, true) || !in_array($perm, ['none', 'edit', 'tickets.reopen'], true)) {
                    continue;
                }
                if ($perm !== 'none') {
                    $rows[] = [(int)$from, (int)$to, $perm === 'edit' ? null : $perm];
                }
            }
        }
        $db->transaction(function () use ($db, $rows) {
            $db->run('DELETE FROM ticket_status_transitions');
            foreach ($rows as [$f, $t, $p]) {
                $db->run('INSERT INTO ticket_status_transitions (from_status_id, to_status_id, permission_code) VALUES (?, ?, ?)', [$f, $t, $p]);
            }
        });
        return 'Flujo de estados guardado (' . count($rows) . ' transiciones).';
    }

    private function savePriority(?int $id): string
    {
        $db = $this->db();
        $d = $this->validate(['name' => 'required|string|max:80', 'color' => 'required|color', 'level' => 'required|int|min:1|max:20', 'active' => 'bool'], ['name' => 'El nombre', 'color' => 'El color', 'level' => 'El nivel']);
        if ($db->value('SELECT 1 FROM ticket_priorities WHERE level = ? AND id <> ?', [$d['level'], $id ?? 0])) {
            throw ValidationException::single('level', 'Ya hay otra prioridad con ese nivel.');
        }
        $row = ['name' => $d['name'], 'color' => $d['color'], 'level' => $d['level'], 'active' => $d['active'] ? 1 : 0];
        if ($id === null) {
            $db->insert('ticket_priorities', $row + ['code' => $this->slug($d['name'], 'ticket_priorities', 'code'), 'active' => 1]);
            return 'Prioridad creada. Añade sus objetivos en las políticas de SLA.';
        }
        $cur = $db->one('SELECT code FROM ticket_priorities WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        if (!$row['active'] && $cur['code'] === $this->app->settings()->get('tickets.default_priority', 'normal')) {
            throw new ValidationException([], 'No puedes desactivar la prioridad predeterminada. Cámbiala antes en Ajustes.');
        }
        $db->update('ticket_priorities', $row, 'id = ?', [$id]);
        return 'Prioridad guardada.';
    }

    private function saveCategory(?int $id): string
    {
        $db = $this->db();
        $d = $this->validate(['name' => 'required|string|max:80', 'parent_id' => 'int', 'sort_order' => 'int|min:0|max:9999', 'active' => 'bool'], ['name' => 'El nombre', 'parent_id' => 'La categoría superior', 'sort_order' => 'El orden']);
        if ($d['parent_id'] !== null) {
            $parent = $db->one('SELECT id, parent_id FROM ticket_categories WHERE id = ?', [$d['parent_id']]);
            if ($parent === null || $parent['parent_id'] !== null || (int)$parent['id'] === $id) {
                throw ValidationException::single('parent_id', 'Solo se admiten dos niveles: categoría y subcategoría.');
            }
            if ($id !== null && $db->value('SELECT 1 FROM ticket_categories WHERE parent_id = ?', [$id])) {
                throw ValidationException::single('parent_id', 'Esta categoría tiene subcategorías; no puede pasar a ser subcategoría.');
            }
        }
        if ($db->value('SELECT 1 FROM ticket_categories WHERE parent_key = ? AND name = ? AND id <> ?', [$d['parent_id'] ?? 0, $d['name'], $id ?? 0])) {
            throw ValidationException::single('name', 'Ya existe una categoría con ese nombre en el mismo nivel.');
        }
        $row = ['name' => $d['name'], 'parent_id' => $d['parent_id'], 'sort_order' => $d['sort_order'] ?? 0];
        if ($id === null) {
            $db->insert('ticket_categories', $row + ['active' => 1]);
            return 'Categoría creada.';
        }
        $db->value('SELECT 1 FROM ticket_categories WHERE id = ?', [$id]) ?: throw HttpException::notFound();
        $db->update('ticket_categories', $row + ['active' => $d['active'] ? 1 : 0], 'id = ?', [$id]);
        return 'Categoría guardada.';
    }

    private function saveTag(?int $id): string
    {
        $db = $this->db();
        if ($id !== null && $this->request->bool('delete')) {
            $db->run('DELETE FROM ticket_tags WHERE id = ?', [$id]);
            return 'Etiqueta eliminada de todas las incidencias.';
        }
        $d = $this->validate(['name' => 'required|string|max:50', 'color' => 'color'], ['name' => 'El nombre', 'color' => 'El color']);
        if ($db->value('SELECT 1 FROM ticket_tags WHERE name = ? AND id <> ?', [$d['name'], $id ?? 0])) {
            throw ValidationException::single('name', 'Ya existe esa etiqueta.');
        }
        if ($id === null) {
            $db->insert('ticket_tags', $d);
            return 'Etiqueta creada.';
        }
        $db->update('ticket_tags', $d, 'id = ?', [$id]);
        return 'Etiqueta guardada.';
    }

    private function saveChannel(): string
    {
        $code = $this->request->str('code');
        $d = $this->validate(['name' => 'required|string|max:60', 'active' => 'bool'], ['name' => 'El nombre']);
        if ($code === 'manual' && !$d['active']) {
            throw new ValidationException([], 'El canal de alta manual no se puede desactivar.');
        }
        $n = $this->db()->run('UPDATE ticket_channels SET name = ?, active = ? WHERE code = ?', [$d['name'], $d['active'] ? 1 : 0, $code])->rowCount();
        if ($n === 0 && !$this->db()->value('SELECT 1 FROM ticket_channels WHERE code = ?', [$code])) {
            throw HttpException::notFound();
        }
        return 'Canal guardado.';
    }

    private function saveSpecialty(?int $id): string
    {
        $db = $this->db();
        $d = $this->validate(['name' => 'required|string|max:80', 'active' => 'bool'], ['name' => 'El nombre']);
        if ($db->value('SELECT 1 FROM specialties WHERE name = ? AND id <> ?', [$d['name'], $id ?? 0])) {
            throw ValidationException::single('name', 'Ya existe esa especialidad.');
        }
        if ($id === null) {
            $db->insert('specialties', ['name' => $d['name'], 'active' => 1]);
            return 'Especialidad creada.';
        }
        $db->update('specialties', ['name' => $d['name'], 'active' => $d['active'] ? 1 : 0], 'id = ?', [$id]);
        return 'Especialidad guardada.';
    }

    /** Código interno único a partir del nombre. */
    private function slug(string $name, string $table, string $col): string
    {
        $base = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '_', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)), '_')) ?: 'item';
        $base = substr($base, 0, 30);
        $code = $base;
        for ($i = 2; $this->db()->value("SELECT 1 FROM $table WHERE $col = ?", [$code]); $i++) {
            $code = $base . '_' . $i;
        }
        return $code;
    }
}
