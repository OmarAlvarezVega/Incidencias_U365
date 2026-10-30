<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Services\Gdpr;

/** Registro de solicitudes de derechos (RGPD), exportación y anonimización de contactos. */
final class GdprController extends Controller
{
    private const TYPES = ['access' => 'Acceso', 'rectification' => 'Rectificación', 'erasure' => 'Supresión', 'restriction' => 'Limitación', 'portability' => 'Portabilidad', 'objection' => 'Oposición'];
    private const STATUSES = ['received' => 'Recibida', 'in_progress' => 'En curso', 'completed' => 'Atendida', 'rejected' => 'Denegada'];

    public function index(): Response
    {
        $rows = $this->db()->all(
            "SELECT r.*, TRIM(CONCAT(cc.first_name, ' ', COALESCE(cc.last_name, ''))) AS contact, cc.client_id, COALESCE(c.trade_name, c.legal_name) AS client,
                    CONCAT(e.first_name, ' ', e.last_name) AS handler
               FROM data_subject_requests r
          LEFT JOIN client_contacts cc ON cc.id = r.contact_id LEFT JOIN clients c ON c.id = cc.client_id LEFT JOIN employees e ON e.user_id = r.handled_by
           ORDER BY (r.status IN ('received','in_progress')) DESC, r.due_at"
        );
        return $this->render('admin/gdpr.twig', ['rows' => $rows, 'types' => self::TYPES, 'statuses' => self::STATUSES, 'now' => Clock::nowDb()]);
    }

    public function store(): Response
    {
        $d = $this->validate([
            'request_type' => 'required|in:' . implode(',', array_keys(self::TYPES)),
            'subject_reference' => 'required|string|max:254',
            'contact_id' => 'int',
            'received_at' => 'required|day',
        ], ['request_type' => 'El tipo', 'subject_reference' => 'La identificación del interesado', 'received_at' => 'La fecha de recepción']);
        if ($d['contact_id'] !== null && !$this->db()->value('SELECT 1 FROM client_contacts WHERE id = ?', [$d['contact_id']])) {
            throw ValidationException::single('contact_id', 'No existe ningún contacto con ese número.');
        }
        $received = Clock::parseLocal($d['received_at']);
        $id = $this->db()->insert('data_subject_requests', [
            'request_type' => $d['request_type'],
            'contact_id' => $d['contact_id'],
            'subject_reference' => $d['subject_reference'],
            'received_at' => Clock::toDb($received),
            // Plazo general del art. 12.3 RGPD: un mes desde la recepción
            'due_at' => Clock::toDb($received->setTimezone(Clock::local())->modify('+1 month')->setTime(23, 59, 59)),
            'status' => 'received',
            'handled_by' => $this->user()->id,
        ]);
        $this->audit()->log('gdpr.request_created', 'gdpr_request', $id, ['tipo' => $d['request_type']]);
        return $this->redirect('/admin/rgpd', 'Solicitud registrada.');
    }

    public function update(): Response
    {
        $id = $this->request->paramInt('id');
        $r = $this->db()->one('SELECT * FROM data_subject_requests WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        $d = $this->validate(['status' => 'required|in:' . implode(',', array_keys(self::STATUSES)), 'resolution_notes' => 'text|max:5000', 'extend' => 'bool'], ['status' => 'El estado']);
        $row = ['status' => $d['status'], 'resolution_notes' => $d['resolution_notes'], 'handled_by' => $this->user()->id];
        if (in_array($d['status'], ['completed', 'rejected'], true)) {
            if ($d['resolution_notes'] === null) {
                throw ValidationException::single('resolution_notes', 'Anota cómo se ha resuelto (o el motivo de la denegación).');
            }
            $row['closed_at'] = $r['closed_at'] ?? Clock::nowDb();
        } else {
            $row['closed_at'] = null;
        }
        if ($d['extend']) {
            // Prórroga de dos meses más (art. 12.3), una sola vez
            $base = Clock::fromDb((string)$r['received_at']);
            $row['due_at'] = Clock::toDb($base->setTimezone(Clock::local())->modify('+3 months')->setTime(23, 59, 59));
        }
        $this->db()->update('data_subject_requests', $row, 'id = ?', [$id]);
        $this->audit()->log('gdpr.request_updated', 'gdpr_request', $id, ['estado' => $d['status'], 'prorroga' => $d['extend']]);
        return $this->redirect('/admin/rgpd', 'Solicitud actualizada.');
    }

    public function export(): Response
    {
        $id = $this->request->paramInt('contact');
        $data = $this->app->make(Gdpr::class)->export($id);
        $this->audit()->log('gdpr.exported', 'contact', $id);
        $json = (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return (new Response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => Response::disposition('attachment', "datos-contacto-$id-" . Clock::localDate() . '.json'),
        ]));
    }

    public function anonymize(): Response
    {
        $id = $this->request->paramInt('contact');
        $clientId = $this->db()->value('SELECT client_id FROM client_contacts WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        $this->app->make(Gdpr::class)->anonymize($id, $this->user());
        return $this->redirect('/clientes/' . $clientId . '#contactos', 'Contacto anonimizado. Las incidencias se conservan sin sus datos personales.');
    }
}
