<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Clock;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Services\ClientService;
use App\Services\TicketAccess;

final class ClientController extends Controller
{
    private const RULES = [
        'client_type' => 'required|in:company,individual',
        'legal_name' => 'required|string|max:200',
        'trade_name' => 'string|max:200',
        'tax_id' => 'string|max:20',
        'phone' => 'phone|max:30',
        'email' => 'email',
        'internal_notes' => 'text|max:10000',
        'sla_policy_id' => 'int',
        'status' => 'required|in:active,inactive',
        'registered_on' => 'day',
    ];
    private const LABELS = [
        'client_type' => 'El tipo', 'legal_name' => 'La razón social o nombre', 'tax_id' => 'El NIF/CIF', 'phone' => 'El teléfono',
        'email' => 'El correo', 'registered_on' => 'La fecha de alta',
    ];

    private function svc(): ClientService
    {
        return $this->app->make(ClientService::class);
    }

    /** @return array<string,mixed> */
    private function client(): array
    {
        $c = $this->db()->one('SELECT * FROM clients WHERE id = ? AND deleted_at IS NULL', [$this->request->paramInt('id')]);
        if ($c === null) {
            throw HttpException::notFound();
        }
        return $c;
    }

    public function index(): Response
    {
        [$page, $per, $offset] = $this->pagination(30);
        $q = mb_substr($this->request->str('q'), 0, 100);
        $status = $this->request->str('estado', 'active');
        $where = ['c.deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            $like = '%' . Database::likeEscape($q) . '%';
            $where[] = '(c.legal_name LIKE ? OR c.trade_name LIKE ? OR c.code = ? OR c.tax_id_normalized = ? OR c.email LIKE ?)';
            array_push($params, $like, $like, strtoupper($q), (string)\App\Core\Validator::normalizeTaxId($q), $like);
        }
        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        $w = implode(' AND ', $where);
        $db = $this->db();
        $total = (int)$db->value("SELECT COUNT(*) FROM clients c WHERE $w", $params);
        $rows = $db->all(
            "SELECT c.*, (SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.client_id = c.id AND s.phase IN ('new','open','pending') AND t.deleted_at IS NULL) AS open_tickets,
                    (SELECT COUNT(*) FROM client_contacts cc WHERE cc.client_id = c.id AND cc.deleted_at IS NULL) AS contacts
               FROM clients c WHERE $w ORDER BY c.legal_name LIMIT $per OFFSET $offset",
            $params
        );
        return $this->render('clients/index.twig', compact('rows', 'total', 'page', 'per', 'q', 'status'));
    }

    public function create(): Response
    {
        return $this->render('clients/form.twig', ['c' => null, 'policies' => $this->policies()]);
    }

    public function store(): Response
    {
        $d = $this->validate(self::RULES, self::LABELS);
        $id = $this->svc()->createClient($d, $this->user()->id);
        // Primer contacto opcional en el mismo formulario
        $first = trim($this->request->str('contact_first_name'));
        if ($first !== '') {
            $this->svc()->saveContact($id, [
                'first_name' => mb_substr($first, 0, 80),
                'last_name' => mb_substr($this->request->str('contact_last_name'), 0, 120) ?: null,
                'job_title' => mb_substr($this->request->str('contact_job_title'), 0, 100) ?: null,
                'is_primary' => true,
                'emails' => array_filter([$this->request->str('contact_email')]),
                'phones' => array_filter([$this->request->str('contact_phone')]),
            ], $this->user()->id);
        }
        return $this->redirect("/clientes/$id", 'Cliente creado.');
    }

    public function show(): Response
    {
        $c = $this->client();
        $db = $this->db();
        $id = (int)$c['id'];
        $contacts = $db->all('SELECT cc.*, a.label AS address_label FROM client_contacts cc LEFT JOIN client_addresses a ON a.id = cc.address_id WHERE cc.client_id = ? AND cc.deleted_at IS NULL ORDER BY cc.is_primary DESC, cc.first_name', [$id]);
        $ids = array_column($contacts, 'id');
        $identities = [];
        if ($ids !== []) {
            foreach ($db->all('SELECT * FROM contact_identities WHERE contact_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY is_primary DESC, id') as $i) {
                $identities[(int)$i['contact_id']][$i['kind']][] = $i['value'];
            }
        }
        $addresses = $db->all('SELECT * FROM client_addresses WHERE client_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, label', [$id]);
        $domains = $db->all('SELECT * FROM client_email_domains WHERE client_id = ? ORDER BY domain', [$id]);
        [$w, $p] = $this->app->make(TicketAccess::class)->scope($this->user());
        $tickets = $db->all(
            "SELECT t.id, t.ticket_number, t.subject, t.created_at, t.last_activity_at, s.name AS status, s.color AS status_color, s.phase,
                    pr.name AS priority, pr.color AS priority_color, CONCAT(e.first_name, ' ', e.last_name) AS owner
               FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id JOIN ticket_priorities pr ON pr.id = t.priority_id LEFT JOIN employees e ON e.user_id = t.owner_user_id
              WHERE t.client_id = ? AND $w ORDER BY (s.phase IN ('new','open','pending')) DESC, t.last_activity_at DESC LIMIT 50",
            array_merge([$id], $p)
        );
        $stats = $db->one(
            "SELECT COUNT(*) AS total, SUM(s.phase IN ('new','open','pending')) AS open, SUM(t.total_minutes) AS minutes,
                    SUM(t.created_at >= ?) AS last_90
               FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.client_id = ? AND t.deleted_at IS NULL",
            [Clock::now()->modify('-90 days')->format(Clock::DB_FORMAT), $id]
        );
        $policy = $c['sla_policy_id'] ? $db->value('SELECT name FROM sla_policies WHERE id = ?', [(int)$c['sla_policy_id']]) : null;
        return $this->render('clients/show.twig', compact('c', 'contacts', 'identities', 'addresses', 'domains', 'tickets', 'stats', 'policy'));
    }

    public function edit(): Response
    {
        return $this->render('clients/form.twig', ['c' => $this->client(), 'policies' => $this->policies()]);
    }

    public function update(): Response
    {
        $c = $this->client();
        $this->svc()->updateClient($c, $this->validate(self::RULES, self::LABELS));
        return $this->redirect('/clientes/' . $c['id'], 'Cliente actualizado.');
    }

    public function destroy(): Response
    {
        $c = $this->client();
        $open = (int)$this->db()->value("SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.client_id = ? AND s.phase IN ('new','open','pending') AND t.deleted_at IS NULL", [(int)$c['id']]);
        if ($open > 0) {
            throw new ValidationException([], "El cliente tiene $open incidencias abiertas. Ciérralas o márcalo como inactivo.");
        }
        $this->db()->run("UPDATE clients SET deleted_at = ?, status = 'inactive' WHERE id = ?", [Clock::nowDb(), (int)$c['id']]);
        $this->audit()->log('client.deleted', 'client', (int)$c['id'], ['nombre' => $c['legal_name']]);
        return $this->redirect('/clientes', 'Cliente eliminado. Su historial de incidencias se conserva.');
    }

    public function saveContact(): Response
    {
        $c = $this->client();
        $d = $this->validate([
            'first_name' => 'required|string|max:80',
            'last_name' => 'string|max:120',
            'job_title' => 'string|max:100',
            'notes' => 'text|max:5000',
            'is_primary' => 'bool',
            'active' => 'bool',
            'address_id' => 'int',
            'contact_id' => 'int',
        ], ['first_name' => 'El nombre', 'last_name' => 'Los apellidos']);
        $split = static fn (string $s) => array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $s) ?: [])));
        $d['emails'] = $split($this->request->str('emails'));
        $d['phones'] = array_values(array_filter(array_map('trim', explode(',', $this->request->str('phones')))));
        $contactId = $d['contact_id'];
        if ($contactId !== null && !$this->db()->value('SELECT 1 FROM client_contacts WHERE id = ? AND client_id = ? AND deleted_at IS NULL', [$contactId, (int)$c['id']])) {
            throw HttpException::notFound();
        }
        unset($d['contact_id']);
        $this->svc()->saveContact((int)$c['id'], $d, $this->user()->id, $contactId);
        return $this->redirect('/clientes/' . $c['id'] . '#contactos', $contactId ? 'Contacto actualizado.' : 'Contacto añadido.');
    }

    public function deleteContact(): Response
    {
        $c = $this->client();
        $cid = $this->request->paramInt('contact');
        $n = $this->db()->run('UPDATE client_contacts SET deleted_at = ?, active = 0 WHERE id = ? AND client_id = ? AND deleted_at IS NULL', [Clock::nowDb(), $cid, (int)$c['id']])->rowCount();
        if ($n === 0) {
            throw HttpException::notFound();
        }
        // Sus correos y teléfonos dejan de identificarle
        $this->db()->run('DELETE FROM contact_identities WHERE contact_id = ?', [$cid]);
        $this->audit()->log('contact.deleted', 'contact', $cid, ['cliente' => (int)$c['id']]);
        return $this->redirect('/clientes/' . $c['id'] . '#contactos', 'Contacto eliminado.');
    }

    public function saveAddress(): Response
    {
        $c = $this->client();
        $d = $this->validate([
            'label' => 'required|string|max:80',
            'address_line' => 'required|string|max:200',
            'postal_code' => 'string|max:10',
            'city' => 'string|max:100',
            'province' => 'string|max:100',
            'country_code' => 'string|max:2',
            'is_primary' => 'bool',
            'address_id' => 'int',
        ], ['label' => 'El nombre de la sede', 'address_line' => 'La dirección']);
        $aid = $d['address_id'];
        unset($d['address_id']);
        if ($aid !== null && !$this->db()->value('SELECT 1 FROM client_addresses WHERE id = ? AND client_id = ?', [$aid, (int)$c['id']])) {
            throw HttpException::notFound();
        }
        $this->svc()->saveAddress((int)$c['id'], $d, $aid);
        return $this->redirect('/clientes/' . $c['id'] . '#direcciones', 'Dirección guardada.');
    }

    public function deleteAddress(): Response
    {
        $c = $this->client();
        $aid = $this->request->paramInt('address');
        $this->db()->run('UPDATE client_contacts SET address_id = NULL WHERE address_id = ?', [$aid]);
        $this->db()->run('UPDATE client_addresses SET deleted_at = ? WHERE id = ? AND client_id = ?', [Clock::nowDb(), $aid, (int)$c['id']]);
        return $this->redirect('/clientes/' . $c['id'] . '#direcciones', 'Dirección eliminada.');
    }

    public function addDomain(): Response
    {
        $c = $this->client();
        $d = $this->validate(['domain' => 'required|domain'], ['domain' => 'El dominio']);
        $this->svc()->addDomain((int)$c['id'], $d['domain']);
        $this->audit()->log('client.domain_added', 'client', (int)$c['id'], ['dominio' => $d['domain']]);
        return $this->redirect('/clientes/' . $c['id'] . '#dominios', 'Dominio añadido.');
    }

    public function removeDomain(): Response
    {
        $c = $this->client();
        $this->db()->run('DELETE FROM client_email_domains WHERE id = ? AND client_id = ?', [$this->request->paramInt('domain'), (int)$c['id']]);
        return $this->redirect('/clientes/' . $c['id'] . '#dominios', 'Dominio quitado.');
    }

    /** Búsqueda rápida para selectores. */
    public function lookup(): Response
    {
        $q = mb_substr($this->request->str('q'), 0, 100);
        if (mb_strlen($q) < 2) {
            return $this->json([]);
        }
        $like = '%' . Database::likeEscape($q) . '%';
        $rows = $this->db()->all(
            "SELECT id, COALESCE(trade_name, legal_name) AS name, tax_id FROM clients
              WHERE deleted_at IS NULL AND status = 'active' AND (legal_name LIKE ? OR trade_name LIKE ? OR code = ? OR tax_id_normalized = ?)
           ORDER BY legal_name LIMIT 20",
            [$like, $like, strtoupper($q), (string)\App\Core\Validator::normalizeTaxId($q)]
        );
        return $this->json($rows);
    }

    public function contacts(): Response
    {
        $id = $this->request->paramInt('id');
        $rows = $this->db()->all(
            "SELECT cc.id, TRIM(CONCAT(cc.first_name, ' ', COALESCE(cc.last_name, ''))) AS name,
                    (SELECT value FROM contact_identities ci WHERE ci.contact_id = cc.id AND ci.kind = 'email' ORDER BY ci.is_primary DESC LIMIT 1) AS email
               FROM client_contacts cc WHERE cc.client_id = ? AND cc.deleted_at IS NULL AND cc.active = 1 ORDER BY cc.is_primary DESC, cc.first_name",
            [$id]
        );
        return $this->json($rows);
    }

    /** @return list<array<string,mixed>> */
    private function policies(): array
    {
        return $this->db()->all('SELECT id, name, active FROM sla_policies ORDER BY name');
    }
}
