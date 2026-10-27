<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Services\Export;
use App\Services\Jobs;
use App\Services\TicketAccess;
use App\Services\TicketService;
use App\Services\TimeService;
use App\Services\WhatsApp\WhatsAppService;

final class TicketController extends Controller
{
    private const VIEWS = [
        'abiertas' => 'Abiertas',
        'mias' => 'Mis incidencias',
        'colaboro' => 'En las que colaboro',
        'sin_asignar' => 'Sin asignar',
        'equipo' => 'De mis equipos',
        'vencidas' => 'Vencidas o SLA incumplido',
        'sin_identificar' => 'Sin cliente identificado',
        'resueltas' => 'Resueltas y cerradas',
        'todas' => 'Todas',
    ];

    private const SORTS = [
        'actividad' => 't.last_activity_at',
        'creada' => 't.created_at',
        'prioridad' => 'pr.level',
        'numero' => 't.seq_year %s, t.seq_value',
        'vencimiento' => 'COALESCE(sla.resolution_due_at, sla.first_response_due_at, t.due_at)',
        'estado' => 's.sort_order',
    ];

    private function access(): TicketAccess
    {
        return $this->app->make(TicketAccess::class);
    }

    private function svc(): TicketService
    {
        return $this->app->make(TicketService::class);
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        return $this->access()->find($this->user(), $this->request->paramInt('id'));
    }

    // ------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------

    /** @return array{0:string,1:list<mixed>,2:array<string,mixed>} WHERE, parámetros y filtros aplicados */
    private function filters(): array
    {
        $u = $this->user();
        [$w, $p] = $this->access()->scope($u);
        $f = [
            'vista' => array_key_exists($this->request->str('vista'), self::VIEWS) ? $this->request->str('vista') : 'abiertas',
            'q' => mb_substr($this->request->str('q'), 0, 100),
            'estado' => $this->request->str('estado'),
            'prioridad' => $this->request->int('prioridad'),
            'cliente' => $this->request->int('cliente'),
            'responsable' => $this->request->str('responsable'),
            'equipo' => $this->request->int('equipo'),
            'categoria' => $this->request->int('categoria'),
            'canal' => $this->request->str('canal'),
            'etiqueta' => $this->request->int('etiqueta'),
            'desde' => $this->request->str('desde'),
            'hasta' => $this->request->str('hasta'),
        ];
        $where = [$w];
        $params = $p;
        switch ($f['vista']) {
            case 'abiertas':
                $where[] = "s.phase IN ('new','open','pending')";
                break;
            case 'mias':
                $where[] = "s.phase IN ('new','open','pending') AND t.owner_user_id = ?";
                $params[] = $u->id;
                break;
            case 'colaboro':
                $where[] = "s.phase IN ('new','open','pending') AND EXISTS (SELECT 1 FROM ticket_participants tp WHERE tp.ticket_id = t.id AND tp.user_id = ? AND tp.removed_at IS NULL)";
                $params[] = $u->id;
                break;
            case 'sin_asignar':
                $where[] = "s.phase IN ('new','open','pending') AND t.owner_user_id IS NULL";
                break;
            case 'equipo':
                $where[] = $u->teamIds !== [] ? 't.team_id IN (' . implode(',', array_map('intval', $u->teamIds)) . ')' : '1 = 0';
                $where[] = "s.phase IN ('new','open','pending')";
                break;
            case 'vencidas':
                $where[] = "s.phase IN ('new','open','pending') AND (t.due_at < ? OR sla.first_response_breached_at IS NOT NULL OR sla.resolution_breached_at IS NOT NULL)";
                $params[] = Clock::nowDb();
                break;
            case 'sin_identificar':
                $where[] = "t.identification_status = 'pending' AND s.phase IN ('new','open','pending')";
                break;
            case 'resueltas':
                $where[] = "s.phase IN ('resolved','closed','cancelled')";
                break;
        }
        if ($f['q'] !== '') {
            $like = '%' . \App\Core\Database::likeEscape($f['q']) . '%';
            $where[] = '(t.ticket_number LIKE ? OR t.subject LIKE ? OR c.legal_name LIKE ? OR c.trade_name LIKE ? OR t.requester_email LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($f['estado'] !== '') {
            $where[] = 's.code = ?';
            $params[] = $f['estado'];
        }
        foreach (['prioridad' => 't.priority_id', 'cliente' => 't.client_id', 'equipo' => 't.team_id', 'categoria' => 't.category_id'] as $k => $col) {
            if ($f[$k] !== null) {
                $where[] = "$col = ?";
                $params[] = $f[$k];
            }
        }
        if ($f['responsable'] === 'ninguno') {
            $where[] = 't.owner_user_id IS NULL';
        } elseif (ctype_digit($f['responsable'])) {
            $where[] = 't.owner_user_id = ?';
            $params[] = (int)$f['responsable'];
        }
        if ($f['canal'] !== '') {
            $where[] = 't.channel_code = ?';
            $params[] = $f['canal'];
        }
        if ($f['etiqueta'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM ticket_tag_relations ttr WHERE ttr.ticket_id = t.id AND ttr.tag_id = ?)';
            $params[] = $f['etiqueta'];
        }
        if (($d = Clock::parseLocal($f['desde'])) !== null) {
            $where[] = 't.created_at >= ?';
            $params[] = Clock::toDb($d);
        }
        if (($d = Clock::parseLocal($f['hasta'], true)) !== null) {
            $where[] = 't.created_at <= ?';
            $params[] = Clock::toDb($d);
        }
        return [implode(' AND ', $where), $params, $f];
    }

    private function orderBy(): array
    {
        $sort = array_key_exists($this->request->str('orden'), self::SORTS) ? $this->request->str('orden') : 'actividad';
        $dir = $this->request->str('dir') === 'asc' ? 'ASC' : 'DESC';
        $expr = self::SORTS[$sort];
        $sql = str_contains($expr, '%s') ? sprintf($expr, $dir) . " $dir" : "$expr $dir";
        return [$sql . ', t.id DESC', $sort, strtolower($dir)];
    }

    private const LIST_FROM = "FROM tickets t
        JOIN ticket_statuses s ON s.id = t.status_id
        JOIN ticket_priorities pr ON pr.id = t.priority_id
        LEFT JOIN clients c ON c.id = t.client_id
        LEFT JOIN client_contacts cc ON cc.id = t.contact_id
        LEFT JOIN employees e ON e.user_id = t.owner_user_id
        LEFT JOIN ticket_categories cat ON cat.id = t.category_id
        LEFT JOIN teams tm ON tm.id = t.team_id
        LEFT JOIN ticket_sla sla ON sla.ticket_id = t.id";

    public function index(): Response
    {
        [$where, $params, $f] = $this->filters();
        [$order, $sort, $dir] = $this->orderBy();
        [$page, $per, $offset] = $this->pagination(30);
        $db = $this->db();
        $total = (int)$db->value('SELECT COUNT(*) ' . self::LIST_FROM . " WHERE $where", $params);
        $rows = $db->all(
            "SELECT t.id, t.ticket_number, t.subject, t.created_at, t.last_activity_at, t.due_at, t.identification_status, t.channel_code,
                    t.requester_email, t.requester_phone, t.assignment_mode, t.total_minutes,
                    s.name AS status, s.color AS status_color, s.phase, pr.name AS priority, pr.color AS priority_color,
                    COALESCE(c.trade_name, c.legal_name) AS client, TRIM(CONCAT(COALESCE(cc.first_name,''), ' ', COALESCE(cc.last_name,''))) AS contact,
                    CONCAT(e.first_name, ' ', e.last_name) AS owner, cat.name AS category, tm.name AS team,
                    sla.first_response_due_at, sla.resolution_due_at, sla.first_response_breached_at, sla.resolution_breached_at
             " . self::LIST_FROM . " WHERE $where ORDER BY $order LIMIT $per OFFSET $offset",
            $params
        );
        return $this->render('tickets/index.twig', [
            'rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'f' => $f, 'sort' => $sort, 'dir' => $dir,
            'views' => self::VIEWS, 'catalog' => $this->catalog(), 'now' => Clock::nowDb(),
        ]);
    }

    public function export(): Response
    {
        [$where, $params, $f] = $this->filters();
        [$order] = $this->orderBy();
        $rows = $this->db()->all(
            "SELECT t.ticket_number, t.created_at, t.subject, COALESCE(c.trade_name, c.legal_name, '') AS client,
                    TRIM(CONCAT(COALESCE(cc.first_name,''), ' ', COALESCE(cc.last_name,''))) AS contact, s.name AS status, pr.name AS priority,
                    COALESCE(cat.name, '') AS category, t.channel_code, COALESCE(CONCAT(e.first_name, ' ', e.last_name), '') AS owner,
                    COALESCE(tm.name, '') AS team, t.first_response_at, t.resolved_at, t.closed_at, t.total_minutes, t.last_activity_at
             " . self::LIST_FROM . " WHERE $where ORDER BY $order LIMIT 20000",
            $params
        );
        $headers = ['Número', 'Creada', 'Asunto', 'Cliente', 'Contacto', 'Estado', 'Prioridad', 'Categoría', 'Canal', 'Responsable', 'Equipo', 'Primera respuesta', 'Resuelta', 'Cerrada', 'Minutos', 'Última actividad'];
        $data = array_map(static fn ($r) => [
            $r['ticket_number'], Clock::format($r['created_at']), $r['subject'], $r['client'], $r['contact'], $r['status'], $r['priority'],
            $r['category'], $r['channel_code'], $r['owner'], $r['team'], Clock::format($r['first_response_at']), Clock::format($r['resolved_at']),
            Clock::format($r['closed_at']), (int)$r['total_minutes'], Clock::format($r['last_activity_at']),
        ], $rows);
        $this->audit()->log('tickets.exported', null, null, ['filtros' => $f, 'filas' => count($rows)]);
        $name = 'incidencias-' . Clock::localDate() . ($this->request->str('formato') === 'xlsx' ? '.xlsx' : '.csv');
        return $this->request->str('formato') === 'xlsx'
            ? Export::xlsx($name, 'Incidencias', $headers, $data, $this->app->storagePath('tmp'))
            : Export::csv($name, $headers, $data);
    }

    /** Acciones en lote: asignar, cambiar estado o prioridad de varias. */
    public function bulk(): Response
    {
        $u = $this->user();
        $ids = array_slice($this->request->intList('ids'), 0, 200);
        $action = $this->request->str('accion');
        if ($ids === []) {
            throw new ValidationException([], 'No has seleccionado ninguna incidencia.');
        }
        $svc = $this->svc();
        $done = 0;
        $errors = [];
        foreach ($ids as $id) {
            try {
                $t = $this->access()->find($u, $id);
                switch ($action) {
                    case 'asignar':
                        $this->access()->authorize($u, $t, 'tickets.assign');
                        $owner = $this->request->int('owner_user_id');
                        $collabs = array_map('intval', $this->db()->column("SELECT user_id FROM ticket_participants WHERE ticket_id = ? AND role = 'collaborator' AND removed_at IS NULL", [$id]));
                        $svc->assign($t, $owner, $collabs, $u);
                        break;
                    case 'estado':
                        $this->access()->authorize($u, $t, 'tickets.change_status');
                        $svc->changeStatus($t, (int)$this->request->int('status_id'), $u);
                        break;
                    case 'prioridad':
                        $this->access()->authorize($u, $t, 'tickets.change_priority');
                        $svc->changePriority($t, (int)$this->request->int('priority_id'), $u);
                        break;
                    default:
                        throw new ValidationException([], 'Acción no válida.');
                }
                $done++;
            } catch (ValidationException|HttpException $e) {
                $errors[] = ($t['ticket_number'] ?? "#$id") . ': ' . $e->getMessage();
            }
        }
        if ($errors !== []) {
            $this->flash('warning', 'No se pudieron actualizar ' . count($errors) . ': ' . implode(' · ', array_slice($errors, 0, 5)));
        }
        if ($done > 0) {
            $this->flash('success', "Actualizadas $done incidencias.");
        }
        return $this->back('/incidencias');
    }

    // ------------------------------------------------------------------
    // Alta
    // ------------------------------------------------------------------

    public function create(): Response
    {
        $clientId = $this->request->int('cliente');
        $client = $clientId ? $this->db()->one('SELECT id, legal_name, trade_name, tax_id FROM clients WHERE id = ? AND deleted_at IS NULL', [$clientId]) : null;
        return $this->render('tickets/create.twig', ['catalog' => $this->catalog(), 'client' => $client]);
    }

    public function store(): Response
    {
        $u = $this->user();
        $d = $this->validate([
            'subject' => 'required|string|max:255',
            'description' => 'text|max:60000',
            'client_id' => 'int',
            'contact_id' => 'int',
            'priority_id' => 'required|int',
            'category_id' => 'int',
            'team_id' => 'int',
            'owner_user_id' => 'int',
            'collaborators' => 'intlist',
            'tags' => 'intlist',
            'due_at' => 'date',
            'internal_summary' => 'text|max:5000',
            'send_ack' => 'bool',
        ], [
            'subject' => 'El asunto', 'description' => 'La descripción', 'priority_id' => 'La prioridad', 'due_at' => 'La fecha límite',
        ]);
        if ($d['client_id'] === null && !$this->request->bool('sin_cliente')) {
            throw ValidationException::single('client_id', 'Elige el cliente o marca «Cliente sin identificar».');
        }
        if (($d['owner_user_id'] !== null || $d['collaborators'] !== []) && !$u->can('tickets.assign')) {
            if ($d['owner_user_id'] !== $u->id || $d['collaborators'] !== []) {
                throw HttpException::forbidden('No tienes permiso para asignar incidencias a otras personas.');
            }
        }
        $id = $this->svc()->create($d, $u, 'manual');
        $files = $this->request->fileList('files');
        if ($files !== [] && $u->can('attachments.upload')) {
            $this->app->make(\App\Services\Attachments::class)->storeUploads($id, $files, $u->id, $this->request->str('files_visibility') === 'public' ? 'public' : 'internal');
        }
        $num = $this->db()->value('SELECT ticket_number FROM tickets WHERE id = ?', [$id]);
        return $this->redirect("/incidencias/$id", "Incidencia $num creada.");
    }

    // ------------------------------------------------------------------
    // Ficha
    // ------------------------------------------------------------------

    public function show(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $db = $this->db();
        $tid = (int)$t['id'];
        $svc = $this->svc();
        $canInternal = $u->can('attachments.view_internal');

        $ticket = $db->one(
            "SELECT t.*, s.name AS status, s.color AS status_color, s.phase, s.code AS status_code, pr.name AS priority, pr.color AS priority_color,
                    c.legal_name, c.trade_name, c.code AS client_code, c.phone AS client_phone, c.email AS client_email, c.internal_notes AS client_notes,
                    TRIM(CONCAT(COALESCE(cc.first_name,''), ' ', COALESCE(cc.last_name,''))) AS contact_name, cc.job_title AS contact_title,
                    CONCAT(e.first_name, ' ', e.last_name) AS owner_name, cat.name AS category, tm.name AS team, ch.name AS channel,
                    CONCAT(ce.first_name, ' ', ce.last_name) AS created_by_name
               FROM tickets t
               JOIN ticket_statuses s ON s.id = t.status_id
               JOIN ticket_priorities pr ON pr.id = t.priority_id
               JOIN ticket_channels ch ON ch.code = t.channel_code
          LEFT JOIN clients c ON c.id = t.client_id
          LEFT JOIN client_contacts cc ON cc.id = t.contact_id
          LEFT JOIN employees e ON e.user_id = t.owner_user_id
          LEFT JOIN employees ce ON ce.user_id = t.created_by
          LEFT JOIN ticket_categories cat ON cat.id = t.category_id
          LEFT JOIN teams tm ON tm.id = t.team_id
              WHERE t.id = ?",
            [$tid]
        );
        $contactIdentities = $t['contact_id'] !== null ? $db->all('SELECT kind, value FROM contact_identities WHERE contact_id = ? ORDER BY is_primary DESC', [(int)$t['contact_id']]) : [];
        $participants = $db->all(
            "SELECT p.user_id, p.role, CONCAT(e.first_name, ' ', e.last_name) AS name FROM ticket_participants p JOIN employees e ON e.user_id = p.user_id
              WHERE p.ticket_id = ? AND p.removed_at IS NULL ORDER BY name",
            [$tid]
        );

        // Conversación: mensajes, notas y actuaciones en orden cronológico
        $attachments = $db->all(
            "SELECT a.*, CONCAT(e.first_name, ' ', e.last_name) AS uploader FROM ticket_attachments a LEFT JOIN employees e ON e.user_id = a.uploaded_by_user_id
              WHERE a.ticket_id = ? AND a.deleted_at IS NULL" . ($canInternal ? '' : " AND a.visibility = 'public'") . ' ORDER BY a.id',
            [$tid]
        );
        $byMessage = [];
        $byActivity = [];
        $loose = [];
        foreach ($attachments as $a) {
            if ($a['message_id'] !== null) {
                $byMessage[(int)$a['message_id']][] = $a;
            } elseif ($a['activity_id'] !== null) {
                $byActivity[(int)$a['activity_id']][] = $a;
            } else {
                $loose[] = $a;
            }
        }
        $feed = [];
        foreach ($db->all("SELECT m.*, CONCAT(e.first_name, ' ', e.last_name) AS author_name FROM ticket_messages m LEFT JOIN employees e ON e.user_id = m.author_user_id WHERE m.ticket_id = ? ORDER BY m.id", [$tid]) as $m) {
            $feed[] = ['type' => 'message', 'at' => $m['created_at'], 'id' => (int)$m['id'], 'row' => $m, 'files' => $byMessage[(int)$m['id']] ?? []];
        }
        foreach ($db->all("SELECT a.*, CONCAT(e.first_name, ' ', e.last_name) AS author_name FROM ticket_activities a JOIN employees e ON e.user_id = a.user_id WHERE a.ticket_id = ? AND a.deleted_at IS NULL ORDER BY a.id", [$tid]) as $a) {
            $feed[] = ['type' => 'activity', 'at' => $a['created_at'], 'id' => (int)$a['id'], 'row' => $a, 'files' => $byActivity[(int)$a['id']] ?? []];
        }
        usort($feed, static fn ($x, $y) => [$x['at'], $x['type'], $x['id']] <=> [$y['at'], $y['type'], $y['id']]);
        if ($this->request->str('orden_conv') !== 'asc') {
            $feed = array_reverse($feed);
        }

        $events = $db->all(
            "SELECT ev.*, CONCAT(e.first_name, ' ', e.last_name) AS actor FROM ticket_events ev LEFT JOIN employees e ON e.user_id = ev.actor_user_id
              WHERE ev.ticket_id = ? ORDER BY ev.id DESC LIMIT 300",
            [$tid]
        );
        $time = $db->all(
            "SELECT te.*, CONCAT(e.first_name, ' ', e.last_name) AS name FROM ticket_time_entries te JOIN employees e ON e.user_id = te.user_id
              WHERE te.ticket_id = ? AND te.deleted_at IS NULL ORDER BY te.work_date DESC, te.id DESC",
            [$tid]
        );
        $relations = $db->all(
            "SELECT r.id, r.relation_type, 'out' AS dir, t2.id AS other_id, t2.ticket_number, t2.subject, s.name AS status, s.color AS status_color
               FROM ticket_relations r JOIN tickets t2 ON t2.id = r.related_ticket_id JOIN ticket_statuses s ON s.id = t2.status_id WHERE r.ticket_id = ? AND t2.deleted_at IS NULL
             UNION ALL
             SELECT r.id, r.relation_type, 'in' AS dir, t2.id, t2.ticket_number, t2.subject, s.name, s.color
               FROM ticket_relations r JOIN tickets t2 ON t2.id = r.ticket_id JOIN ticket_statuses s ON s.id = t2.status_id WHERE r.related_ticket_id = ? AND t2.deleted_at IS NULL",
            [$tid, $tid]
        );
        $tags = $db->all('SELECT tg.* FROM ticket_tag_relations r JOIN ticket_tags tg ON tg.id = r.tag_id WHERE r.ticket_id = ? ORDER BY tg.name', [$tid]);
        $sla = $db->one('SELECT s.*, p.name AS policy_name FROM ticket_sla s JOIN sla_policies p ON p.id = s.policy_id WHERE s.ticket_id = ?', [$tid]);
        $timer = $this->app->make(TimeService::class)->running($u->id);
        $wa = $this->app->make(WhatsAppService::class);
        $waConv = $wa->enabled() ? $wa->conversationFor($t) : null;
        $access = $this->access();
        $can = [
            'edit' => $access->canModify($u, $t, 'tickets.edit'),
            'status' => $access->canModify($u, $t, 'tickets.change_status') || $access->canModify($u, $t, 'tickets.reopen'),
            'priority' => $access->canModify($u, $t, 'tickets.change_priority'),
            'assign' => $access->canModify($u, $t, 'tickets.assign') || $access->canModify($u, $t, 'tickets.reassign'),
            'reply' => $access->canModify($u, $t, 'tickets.reply_public'),
            'note' => $access->canModify($u, $t, 'tickets.note_internal'),
            'upload' => $access->canModify($u, $t, 'attachments.upload'),
            'time' => $u->can('time.log') && ($access->isWorker($u, $t) || $access->supervises($u, $t)),
            'delete' => $u->can('tickets.delete'),
            'take' => $t['owner_user_id'] === null && $u->can('tickets.edit'),
        ];
        return $this->render('tickets/show.twig', [
            't' => $ticket,
            'contact_identities' => $contactIdentities,
            'participants' => $participants,
            'feed' => $feed,
            'loose_files' => $loose,
            'events' => $events,
            'time' => $time,
            'relations' => $relations,
            'tags' => $tags,
            'sla' => $sla,
            'timer' => $timer,
            'transitions' => $svc->allowedTransitions((int)$t['status_id'], $u),
            'catalog' => $this->catalog(),
            'can' => $can,
            'customer_email' => $svc->customerEmail($t),
            'wa_conv' => $waConv,
            'wa_window' => WhatsAppService::windowOpen($waConv),
            'wa_template' => $this->app->settings()->get('whatsapp.reopen_template'),
            'pending_jobs' => (int)$db->value("SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = ? AND delivery_status = 'queued'", [$tid]),
        ]);
    }

    // ------------------------------------------------------------------
    // Acciones
    // ------------------------------------------------------------------

    public function update(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $this->access()->authorize($u, $t, 'tickets.edit');
        $d = $this->validate([
            'subject' => 'required|string|max:255',
            'description' => 'text|max:60000',
            'category_id' => 'int',
            'team_id' => 'int',
            'due_at' => 'date',
            'internal_summary' => 'text|max:5000',
            'lock_version' => 'required|int',
        ], ['subject' => 'El asunto', 'due_at' => 'La fecha límite']);
        $lock = (int)$d['lock_version'];
        unset($d['lock_version']);
        $this->svc()->update($t, $d, $u, $lock);
        return $this->redirect('/incidencias/' . $t['id'], 'Cambios guardados.');
    }

    public function status(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $to = (int)$this->request->int('status_id');
        $perm = in_array($this->svc()->status((int)$t['status_id'])['phase'], ['resolved', 'closed', 'cancelled'], true) ? 'tickets.reopen' : 'tickets.change_status';
        $this->access()->authorize($u, $t, $perm);
        $comment = trim($this->request->str('comment'));
        $this->svc()->changeStatus($t, $to, $u, $this->request->bool('notify_customer'));
        if ($comment !== '' && $u->can('tickets.note_internal')) {
            $this->svc()->addActivity($t, $u, 'internal_note', 'internal', $comment);
        }
        return $this->redirect('/incidencias/' . $t['id'], 'Estado actualizado.');
    }

    public function priority(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $this->access()->authorize($u, $t, 'tickets.change_priority');
        $this->svc()->changePriority($t, (int)$this->request->int('priority_id'), $u);
        return $this->redirect('/incidencias/' . $t['id'], 'Prioridad actualizada.');
    }

    public function assign(): Response
    {
        $u = $this->user();
        $t = $this->load();
        if (!$this->access()->canModify($u, $t, 'tickets.assign') && !$this->access()->canModify($u, $t, 'tickets.reassign')) {
            throw HttpException::forbidden();
        }
        $owner = $this->request->int('owner_user_id');
        $collabs = $this->request->intList('collaborators');
        $changeTeam = array_key_exists('team_id', $this->request->post);
        $this->svc()->assign($t, $owner, $collabs, $u, $this->request->int('team_id'), $changeTeam);
        return $this->redirect('/incidencias/' . $t['id'], 'Asignación actualizada.');
    }

    public function takeOwnership(): Response
    {
        $u = $this->user();
        $t = $this->load();
        if ($t['owner_user_id'] !== null) {
            throw new ValidationException([], 'La incidencia ya tiene responsable.');
        }
        $collabs = array_map('intval', $this->db()->column("SELECT user_id FROM ticket_participants WHERE ticket_id = ? AND role = 'collaborator' AND removed_at IS NULL", [(int)$t['id']]));
        $this->svc()->assign($t, $u->id, array_values(array_diff($collabs, [$u->id])), $u);
        return $this->redirect('/incidencias/' . $t['id'], 'Ahora eres el responsable de esta incidencia.');
    }

    public function identify(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $this->access()->authorize($u, $t, 'tickets.edit');
        $clientId = $this->request->int('client_id');
        if ($clientId === null) {
            throw ValidationException::single('client_id', 'Elige un cliente.');
        }
        $this->svc()->identify($t, $clientId, $this->request->int('contact_id'), $u, $this->request->bool('remember_sender'));
        return $this->redirect('/incidencias/' . $t['id'], 'Cliente asociado.');
    }

    public function reply(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $this->access()->authorize($u, $t, 'tickets.reply_public');
        $files = $this->request->fileList('files');
        if ($files !== [] && !$u->can('attachments.upload')) {
            throw HttpException::forbidden('No tienes permiso para adjuntar archivos.');
        }
        $this->svc()->reply($t, $u, (string)($this->request->post['body'] ?? ''), $this->request->str('channel'), $files);
        $after = $this->request->int('status_after');
        if ($after !== null) {
            $fresh = $this->db()->one('SELECT * FROM tickets WHERE id = ?', [(int)$t['id']]);
            $this->svc()->changeStatus($fresh, $after, $u);
        }
        return $this->redirect('/incidencias/' . $t['id'], 'Respuesta registrada. Se enviará en unos segundos.');
    }

    public function note(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $this->access()->authorize($u, $t, 'tickets.note_internal');
        $files = $this->request->fileList('files');
        if ($files !== [] && !$u->can('attachments.upload')) {
            throw HttpException::forbidden('No tienes permiso para adjuntar archivos.');
        }
        $kind = $this->request->str('kind') === 'technical_action' ? 'technical_action' : 'internal_note';
        $this->svc()->addActivity($t, $u, $kind, $this->request->str('visibility') === 'public' ? 'public' : 'internal', (string)($this->request->post['body'] ?? ''), $files);
        $minutes = $this->request->int('minutes');
        if ($minutes !== null && $minutes > 0 && $u->can('time.log')) {
            $this->app->make(TimeService::class)->addManual($t, $u, $minutes, Clock::localDate(), mb_substr((string)($this->request->post['body'] ?? ''), 0, 200), true);
        }
        return $this->redirect('/incidencias/' . $t['id'], $kind === 'internal_note' ? 'Nota interna añadida.' : 'Actuación registrada.');
    }

    public function upload(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $this->access()->authorize($u, $t, 'attachments.upload');
        $files = $this->request->fileList('files');
        if ($files === []) {
            throw ValidationException::single('files', 'Elige al menos un archivo.');
        }
        $ids = $this->app->make(\App\Services\Attachments::class)->storeUploads((int)$t['id'], $files, $u->id, $this->request->str('visibility') === 'public' ? 'public' : 'internal');
        $this->svc()->event((int)$t['id'], $u->id, 'attachments_added', null, ['archivos' => count($ids)]);
        return $this->redirect('/incidencias/' . $t['id'] . '#adjuntos', count($ids) . ' archivo(s) adjuntado(s).');
    }

    public function tags(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $this->access()->authorize($u, $t, 'tickets.edit');
        $this->svc()->setTags($t, $this->request->intList('tags'), $u);
        return $this->redirect('/incidencias/' . $t['id'], 'Etiquetas actualizadas.');
    }

    public function relate(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $this->access()->authorize($u, $t, 'tickets.edit');
        $num = strtoupper(trim($this->request->str('related')));
        $relatedId = ctype_digit($num) ? (int)$num : (int)$this->db()->value('SELECT id FROM tickets WHERE ticket_number = ?', [$num]);
        if ($relatedId === 0) {
            throw ValidationException::single('related', 'No existe ninguna incidencia con ese número.');
        }
        $this->svc()->addRelation($t, $relatedId, $this->request->str('relation_type'), $u);
        return $this->redirect('/incidencias/' . $t['id'], 'Relación añadida.');
    }

    public function unrelate(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $this->access()->authorize($u, $t, 'tickets.edit');
        $this->svc()->removeRelation($t, $this->request->paramInt('rel'), $u);
        return $this->redirect('/incidencias/' . $t['id'], 'Relación eliminada.');
    }

    public function destroy(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $reason = trim($this->request->str('reason'));
        if (mb_strlen($reason) < 5) {
            throw ValidationException::single('reason', 'Indica el motivo de la eliminación.');
        }
        $this->svc()->softDelete($t, $u, mb_substr($reason, 0, 500));
        return $this->redirect('/incidencias', 'Incidencia ' . $t['ticket_number'] . ' eliminada.');
    }

    /** Reintenta el envío de una respuesta que falló. */
    public function resend(): Response
    {
        $u = $this->user();
        $t = $this->load();
        $this->access()->authorize($u, $t, 'tickets.reply_public');
        $m = $this->db()->one("SELECT * FROM ticket_messages WHERE id = ? AND ticket_id = ? AND direction = 'outbound' AND delivery_status = 'failed' AND is_automatic = 0", [$this->request->paramInt('msg'), (int)$t['id']]);
        if ($m === null) {
            throw HttpException::notFound();
        }
        $this->db()->run("UPDATE ticket_messages SET delivery_status = 'queued' WHERE id = ?", [(int)$m['id']]);
        $this->db()->run("DELETE FROM background_jobs WHERE unique_key = ? AND status IN ('dead','failed')", ['reply:' . $m['id']]);
        $this->app->make(Jobs::class)->push($m['channel_code'] === 'whatsapp' ? 'whatsapp.send_reply' : 'email.send_reply', ['ticket_message_id' => (int)$m['id']], 'reply:' . $m['id'] . ':' . time());
        $this->svc()->event((int)$t['id'], $u->id, 'reply_resent', null, null);
        return $this->redirect('/incidencias/' . $t['id'], 'Reenvío programado.');
    }

    /** Catálogos para formularios y filtros. @return array<string,mixed> */
    private function catalog(): array
    {
        $db = $this->db();
        return [
            'statuses' => $db->all('SELECT id, code, name, color, phase FROM ticket_statuses WHERE active = 1 ORDER BY sort_order'),
            'priorities' => $db->all('SELECT id, code, name, color, level FROM ticket_priorities WHERE active = 1 ORDER BY level'),
            'categories' => $db->all('SELECT c.id, c.name, p.name AS parent FROM ticket_categories c LEFT JOIN ticket_categories p ON p.id = c.parent_id WHERE c.active = 1 ORDER BY COALESCE(p.sort_order, c.sort_order), p.name, c.sort_order, c.name'),
            'teams' => $db->all('SELECT id, name FROM teams WHERE active = 1 ORDER BY name'),
            'staff' => $db->all("SELECT u.id, CONCAT(e.first_name, ' ', e.last_name) AS name FROM users u JOIN employees e ON e.user_id = u.id WHERE u.status = 'active' AND u.deleted_at IS NULL AND u.user_type = 'staff' ORDER BY e.first_name, e.last_name"),
            'channels' => $db->all('SELECT code, name FROM ticket_channels WHERE active = 1 ORDER BY name'),
            'tags' => $db->all('SELECT id, name, color FROM ticket_tags ORDER BY name'),
            'clients' => $db->all("SELECT id, COALESCE(trade_name, legal_name) AS name FROM clients WHERE deleted_at IS NULL AND status = 'active' ORDER BY name LIMIT 500"),
            'default_priority' => (string)$this->app->settings()->get('tickets.default_priority', 'normal'),
        ];
    }
}
