<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Clock;
use App\Security\CurrentUser;

/**
 * Derechos de protección de datos (RGPD / LOPDGDD) sobre contactos de clientes.
 *
 *  - Exportación (acceso / portabilidad): JSON con la ficha, identidades y
 *    los mensajes que escribió o recibió.
 *  - Anonimización (supresión): se sustituyen nombre, cargo, notas,
 *    correos y teléfonos; en las incidencias se borran remitente y datos de
 *    contacto. El contenido técnico de las incidencias se conserva por
 *    interés legítimo y obligaciones contractuales; si hay que borrarlo, se
 *    hace incidencia por incidencia tras valoración jurídica (ver manual).
 */
final class Gdpr
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string,mixed> */
    public function export(int $contactId): array
    {
        $db = $this->app->db();
        $c = $db->one('SELECT cc.*, c.legal_name, c.code AS client_code FROM client_contacts cc JOIN clients c ON c.id = cc.client_id WHERE cc.id = ?', [$contactId])
            ?? throw \App\Core\HttpException::notFound();
        $identities = $db->all('SELECT kind, value, is_primary, whatsapp_opt_in_at, created_at FROM contact_identities WHERE contact_id = ?', [$contactId]);
        $tickets = $db->all(
            'SELECT t.id, t.ticket_number, t.subject, t.description, t.created_at, t.resolved_at, t.closed_at, s.name AS estado
               FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.contact_id = ? AND t.deleted_at IS NULL ORDER BY t.created_at',
            [$contactId]
        );
        foreach ($tickets as &$t) {
            $t['mensajes'] = $db->all(
                "SELECT direction AS sentido, channel_code AS canal, sender_label AS remitente, body_text AS texto, created_at AS fecha
                   FROM ticket_messages WHERE ticket_id = ? ORDER BY id",
                [(int)$t['id']]
            );
            $t['actuaciones_publicas'] = $db->all("SELECT body AS texto, created_at AS fecha FROM ticket_activities WHERE ticket_id = ? AND visibility = 'public' AND deleted_at IS NULL", [(int)$t['id']]);
            unset($t['id']);
        }
        unset($t);
        $wa = $db->all(
            'SELECT m.direction, m.msg_type, m.body_text, m.created_at FROM whatsapp_messages m JOIN whatsapp_conversations c ON c.id = m.conversation_id WHERE c.contact_id = ? ORDER BY m.id',
            [$contactId]
        );
        $this->app->audit()->log('gdpr.export', 'contact', $contactId);
        return [
            'generado' => Clock::format(Clock::nowDb()),
            'responsable' => (string)$this->app->settings()->get('company.name', 'UNIVERS365 TECHNOLOGY'),
            'interesado' => [
                'nombre' => $c['first_name'], 'apellidos' => $c['last_name'], 'cargo' => $c['job_title'],
                'cliente' => $c['legal_name'] . ' (' . $c['client_code'] . ')', 'alta' => $c['created_at'],
            ],
            'identidades' => $identities,
            'incidencias' => $tickets,
            'whatsapp' => $wa,
        ];
    }

    public function anonymize(int $contactId, CurrentUser $actor): void
    {
        $db = $this->app->db();
        $c = $db->one('SELECT * FROM client_contacts WHERE id = ?', [$contactId]) ?? throw \App\Core\HttpException::notFound();
        if ($c['anonymized_at'] !== null) {
            return;
        }
        $now = Clock::nowDb();
        $label = 'Contacto anonimizado #' . $contactId;
        $db->transaction(function () use ($db, $contactId, $now, $label, $actor) {
            $emails = $db->column("SELECT value_normalized FROM contact_identities WHERE contact_id = ? AND kind = 'email'", [$contactId]);
            $phones = $db->column("SELECT value_normalized FROM contact_identities WHERE contact_id = ? AND kind = 'phone'", [$contactId]);
            $db->run(
                "UPDATE client_contacts SET first_name = 'Anonimizado', last_name = NULL, job_title = NULL, notes = NULL, active = 0, anonymized_at = ?, updated_at = ? WHERE id = ?",
                [$now, $now, $contactId]
            );
            $db->run('DELETE FROM contact_identities WHERE contact_id = ?', [$contactId]);
            $db->run('UPDATE ticket_messages SET sender_label = ? WHERE author_contact_id = ?', [$label, $contactId]);
            $db->run('UPDATE tickets SET requester_email = NULL, requester_phone = NULL WHERE contact_id = ?', [$contactId]);
            foreach ($emails as $e) {
                $db->run('UPDATE tickets SET requester_email = NULL WHERE requester_email = ?', [$e]);
                // Los .eml originales contienen todos los datos: se borran
                $mp = $this->app->make(Mail\MailProcessor::class);
                foreach ($db->column('SELECT raw_storage_key FROM email_messages WHERE from_address = ? AND raw_storage_key IS NOT NULL', [$e]) as $key) {
                    @unlink($mp->rawPath((string)$key));
                }
                $db->run('UPDATE email_messages SET from_address = ?, raw_storage_key = NULL WHERE from_address = ?', ['anonimizado@invalid', $e]);
            }
            foreach ($phones as $p) {
                $db->run("UPDATE whatsapp_conversations SET wa_id = CONCAT('anon', id), profile_name = NULL WHERE wa_id = ?", [$p]);
                $db->run('UPDATE tickets SET requester_phone = NULL WHERE requester_phone IN (?, ?)', [$p, '+' . $p]);
            }
            $db->run('UPDATE whatsapp_conversations SET profile_name = NULL, contact_id = NULL WHERE contact_id = ?', [$contactId]);
            $this->app->audit()->log('gdpr.anonymize', 'contact', $contactId, ['por' => $actor->id]);
        });
    }
}
