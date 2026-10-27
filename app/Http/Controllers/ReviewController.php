<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Services\Mail\MailProcessor;

/** Cola de correos de remitentes desconocidos pendientes de decisión. */
final class ReviewController extends Controller
{
    public function index(): Response
    {
        [$page, $per, $offset] = $this->pagination(20);
        $db = $this->db();
        $total = (int)$db->value("SELECT COUNT(*) FROM email_messages WHERE processing_status = 'review'");
        $rows = $db->all(
            "SELECT m.id, m.from_address, m.subject, m.received_at, a.address AS account
               FROM email_messages m JOIN email_accounts a ON a.id = m.account_id
              WHERE m.processing_status = 'review' ORDER BY m.received_at LIMIT $per OFFSET $offset"
        );
        $mp = $this->app->make(MailProcessor::class);
        foreach ($rows as &$r) {
            $r['preview'] = mb_substr($mp->previewText((int)$r['id']), 0, 1500);
            $domain = substr((string)strrchr((string)$r['from_address'], '@'), 1);
            $r['suggest'] = $domain !== '' ? $db->all(
                "SELECT DISTINCT c.id, COALESCE(c.trade_name, c.legal_name) AS name FROM clients c
                   LEFT JOIN client_email_domains d ON d.client_id = c.id
                  WHERE c.deleted_at IS NULL AND (d.domain = ? OR c.email LIKE ?) LIMIT 5",
                [$domain, '%@' . \App\Core\Database::likeEscape($domain)]
            ) : [];
        }
        unset($r);
        $clients = $db->all("SELECT id, COALESCE(trade_name, legal_name) AS name FROM clients WHERE deleted_at IS NULL AND status = 'active' ORDER BY name LIMIT 500");
        $unidentified = (int)$db->value("SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.identification_status = 'pending' AND s.phase IN ('new','open','pending') AND t.deleted_at IS NULL");
        return $this->render('review/index.twig', compact('rows', 'total', 'page', 'per', 'clients', 'unidentified'));
    }

    public function resolve(): Response
    {
        $u = $this->user();
        $id = $this->request->paramInt('id');
        $action = $this->request->str('accion');
        $mp = $this->app->make(MailProcessor::class);
        switch ($action) {
            case 'ignorar':
                $mp->ignoreReview($id, $u);
                return $this->redirect('/revision', 'Correo descartado.');
            case 'crear':
                $clientId = $this->request->int('client_id');
                $ticketId = $mp->resolveReview($id, 'create', $clientId, $clientId !== null ? $this->request->int('contact_id') : null, null, $u, $this->request->bool('remember_sender'));
                return $this->redirect('/incidencias/' . $ticketId, 'Incidencia creada a partir del correo.');
            case 'anadir':
                $num = strtoupper(trim($this->request->str('ticket_number')));
                $ticketId = (int)$this->db()->value('SELECT id FROM tickets WHERE ticket_number = ? AND deleted_at IS NULL', [$num]);
                if ($ticketId === 0) {
                    throw ValidationException::single('ticket_number', 'No existe esa incidencia.');
                }
                $mp->resolveReview($id, 'attach', null, null, $ticketId, $u, false);
                return $this->redirect('/incidencias/' . $ticketId, 'Correo añadido a la incidencia.');
        }
        throw new ValidationException([], 'Acción no válida.');
    }
}
