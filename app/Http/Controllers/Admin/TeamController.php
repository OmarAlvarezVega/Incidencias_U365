<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;

final class TeamController extends Controller
{
    public function index(): Response
    {
        $db = $this->db();
        $teams = $db->all(
            "SELECT t.*, (SELECT COUNT(*) FROM tickets tk JOIN ticket_statuses s ON s.id = tk.status_id WHERE tk.team_id = t.id AND s.phase IN ('new','open','pending') AND tk.deleted_at IS NULL) AS open_tickets
               FROM teams t ORDER BY t.active DESC, t.name"
        );
        $members = [];
        foreach ($db->all('SELECT tm.team_id, tm.user_id, tm.is_lead FROM team_members tm') as $m) {
            $members[(int)$m['team_id']][(int)$m['user_id']] = (int)$m['is_lead'] === 1 ? 'lead' : 'member';
        }
        $staff = $db->all("SELECT u.id, CONCAT(e.first_name, ' ', e.last_name) AS name FROM users u JOIN employees e ON e.user_id = u.id WHERE u.user_type = 'staff' AND u.status = 'active' AND u.deleted_at IS NULL ORDER BY e.first_name");
        return $this->render('admin/teams.twig', compact('teams', 'members', 'staff'));
    }

    public function save(): Response
    {
        $db = $this->db();
        $id = $this->request->param('id') !== '' ? $this->request->paramInt('id') : null;
        if ($id !== null && !$db->value('SELECT 1 FROM teams WHERE id = ?', [$id])) {
            throw HttpException::notFound();
        }
        $d = $this->validate(['name' => 'required|string|max:80', 'description' => 'string|max:255', 'active' => 'bool'], ['name' => 'El nombre', 'description' => 'La descripción']);
        if ($db->value('SELECT 1 FROM teams WHERE name = ? AND id <> ?', [$d['name'], $id ?? 0])) {
            throw ValidationException::single('name', 'Ya existe un equipo con ese nombre.');
        }
        $now = Clock::nowDb();
        $members = $this->request->intList('members');
        $leads = array_values(array_intersect($this->request->intList('leads'), $members));
        $valid = $members === [] ? [] : array_map('intval', $db->column("SELECT id FROM users WHERE user_type = 'staff' AND deleted_at IS NULL AND id IN (" . implode(',', $members) . ')'));
        $db->transaction(function () use ($db, &$id, $d, $now, $valid, $leads) {
            if ($id === null) {
                $id = $db->insert('teams', ['name' => $d['name'], 'description' => $d['description'], 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $db->update('teams', ['name' => $d['name'], 'description' => $d['description'], 'active' => $d['active'] ? 1 : 0, 'updated_at' => $now], 'id = ?', [$id]);
            }
            $db->run('DELETE FROM team_members WHERE team_id = ?' . ($valid !== [] ? ' AND user_id NOT IN (' . implode(',', $valid) . ')' : ''), [$id]);
            foreach ($valid as $uid) {
                $db->run('INSERT INTO team_members (team_id, user_id, is_lead, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE is_lead = VALUES(is_lead)', [$id, $uid, in_array($uid, $leads, true) ? 1 : 0, $now]);
            }
        });
        $this->audit()->log('team.saved', 'team', $id, ['nombre' => $d['name'], 'miembros' => count($valid), 'coordinadores' => $leads]);
        return $this->redirect('/admin/equipos#equipo-' . $id, 'Equipo guardado.');
    }
}
