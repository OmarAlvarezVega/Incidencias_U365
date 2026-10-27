<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Clock;
use App\Core\ValidationException;
use App\Core\Validator;
use PDOException;

/**
 * Clientes, contactos, direcciones, dominios de correo e identidades
 * (correos y teléfonos con los que se reconoce a un contacto).
 */
final class ClientService
{
    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string,mixed> $d */
    public function createClient(array $d, int $actorId): int
    {
        $db = $this->app->db();
        $now = Clock::nowDb();
        $taxNorm = Validator::normalizeTaxId($d['tax_id'] ?? null);
        if ($taxNorm !== null && $db->value('SELECT id FROM clients WHERE tax_id_normalized = ?', [$taxNorm])) {
            throw ValidationException::single('tax_id', 'Ya existe un cliente con ese NIF/CIF.');
        }
        return $db->transaction(function () use ($db, $d, $now, $taxNorm, $actorId) {
            $next = (int)$db->value("SELECT COALESCE(MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)), 0) + 1 FROM clients WHERE code LIKE 'CLI-%' FOR UPDATE");
            $id = $db->insert('clients', [
                'code' => sprintf('CLI-%05d', $next),
                'client_type' => $d['client_type'],
                'legal_name' => $d['legal_name'],
                'trade_name' => $d['trade_name'] ?? null,
                'tax_id' => $d['tax_id'] ?? null,
                'tax_id_normalized' => $taxNorm,
                'phone' => $d['phone'] ?? null,
                'email' => isset($d['email']) ? Validator::normalizeEmail((string)$d['email']) : null,
                'internal_notes' => $d['internal_notes'] ?? null,
                'sla_policy_id' => $d['sla_policy_id'] ?? null,
                'status' => $d['status'] ?? 'active',
                'registered_on' => $d['registered_on'] ?? Clock::localDate(),
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $actorId,
            ]);
            $this->app->audit()->log('client.created', 'client', $id, ['nombre' => $d['legal_name']]);
            return $id;
        });
    }

    /** @param array<string,mixed> $client @param array<string,mixed> $d */
    public function updateClient(array $client, array $d): void
    {
        $db = $this->app->db();
        $taxNorm = Validator::normalizeTaxId($d['tax_id'] ?? null);
        if ($taxNorm !== null && $db->value('SELECT id FROM clients WHERE tax_id_normalized = ? AND id <> ?', [$taxNorm, (int)$client['id']])) {
            throw ValidationException::single('tax_id', 'Ya existe otro cliente con ese NIF/CIF.');
        }
        $db->update('clients', [
            'client_type' => $d['client_type'],
            'legal_name' => $d['legal_name'],
            'trade_name' => $d['trade_name'] ?? null,
            'tax_id' => $d['tax_id'] ?? null,
            'tax_id_normalized' => $taxNorm,
            'phone' => $d['phone'] ?? null,
            'email' => isset($d['email']) ? Validator::normalizeEmail((string)$d['email']) : null,
            'internal_notes' => $d['internal_notes'] ?? null,
            'sla_policy_id' => $d['sla_policy_id'] ?? null,
            'status' => $d['status'] ?? 'active',
            'registered_on' => $d['registered_on'] ?? $client['registered_on'],
            'updated_at' => Clock::nowDb(),
        ], 'id = ?', [(int)$client['id']]);
        $this->app->audit()->log('client.updated', 'client', (int)$client['id'], array_intersect_key($d, array_flip(['legal_name', 'status', 'sla_policy_id'])));
        if ((string)$client['sla_policy_id'] !== (string)($d['sla_policy_id'] ?? '')) {
            $sla = $this->app->make(Sla::class);
            foreach ($db->column("SELECT t.id FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.client_id = ? AND s.phase IN ('new','open','pending') AND t.deleted_at IS NULL", [(int)$client['id']]) as $tid) {
                $sla->attach((int)$tid);
            }
        }
    }

    /**
     * @param array<string,mixed> $d first_name, last_name, job_title, notes, is_primary, address_id, emails[], phones[]
     */
    public function saveContact(int $clientId, array $d, int $actorId, ?int $contactId = null): int
    {
        $db = $this->app->db();
        $now = Clock::nowDb();
        if (isset($d['address_id']) && $d['address_id'] !== null && !$db->value('SELECT 1 FROM client_addresses WHERE id = ? AND client_id = ? AND deleted_at IS NULL', [(int)$d['address_id'], $clientId])) {
            throw ValidationException::single('address_id', 'Dirección no válida.');
        }
        return $db->transaction(function () use ($db, $clientId, $d, $actorId, $contactId, $now) {
            $data = [
                'first_name' => $d['first_name'],
                'last_name' => $d['last_name'] ?? null,
                'job_title' => $d['job_title'] ?? null,
                'notes' => $d['notes'] ?? null,
                'is_primary' => !empty($d['is_primary']) ? 1 : 0,
                'address_id' => $d['address_id'] ?? null,
                'active' => array_key_exists('active', $d) ? (!empty($d['active']) ? 1 : 0) : 1,
                'updated_at' => $now,
            ];
            if ($contactId === null) {
                $contactId = $db->insert('client_contacts', $data + ['client_id' => $clientId, 'created_at' => $now, 'created_by' => $actorId]);
                $this->app->audit()->log('contact.created', 'contact', $contactId, ['cliente' => $clientId]);
            } else {
                $db->update('client_contacts', $data, 'id = ? AND client_id = ?', [$contactId, $clientId]);
                $this->app->audit()->log('contact.updated', 'contact', $contactId, ['cliente' => $clientId]);
            }
            if (!empty($d['is_primary'])) {
                $db->run('UPDATE client_contacts SET is_primary = 0 WHERE client_id = ? AND id <> ?', [$clientId, $contactId]);
            }
            $this->syncIdentities($contactId, 'email', (array)($d['emails'] ?? []));
            $this->syncIdentities($contactId, 'phone', (array)($d['phones'] ?? []));
            return $contactId;
        });
    }

    /** Sustituye las identidades de un tipo por la lista dada (la primera es la principal). @param list<string> $values */
    private function syncIdentities(int $contactId, string $kind, array $values): void
    {
        $db = $this->app->db();
        $norm = [];
        foreach ($values as $v) {
            $v = trim((string)$v);
            if ($v === '') {
                continue;
            }
            $n = $kind === 'email' ? Validator::normalizeEmail($v) : Validator::normalizePhone($v);
            if ($kind === 'email' && !filter_var($n, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::single($kind . 's', "«$v» no es un correo válido.");
            }
            if ($kind === 'phone' && !preg_match('/^\d{6,15}$/', $n)) {
                throw ValidationException::single($kind . 's', "«$v» no es un teléfono válido.");
            }
            $norm[$n] = $v;
        }
        $existing = $db->all('SELECT id, value_normalized FROM contact_identities WHERE contact_id = ? AND kind = ?', [$contactId, $kind]);
        $keep = [];
        foreach ($existing as $e) {
            if (!isset($norm[$e['value_normalized']])) {
                $db->run('DELETE FROM contact_identities WHERE id = ?', [(int)$e['id']]);
            } else {
                $keep[$e['value_normalized']] = (int)$e['id'];
            }
        }
        $first = true;
        foreach ($norm as $n => $raw) {
            $n = (string)$n; // los teléfonos son claves numéricas y PHP las convierte a int
            if (isset($keep[$n])) {
                $db->run('UPDATE contact_identities SET is_primary = ?, value = ?, updated_at = ? WHERE id = ?', [$first ? 1 : 0, $raw, Clock::nowDb(), $keep[$n]]);
            } else {
                $this->insertIdentity($contactId, $kind, $raw, $n, $first);
            }
            $first = false;
        }
    }

    private function insertIdentity(int $contactId, string $kind, string $raw, string $norm, bool $primary): void
    {
        $db = $this->app->db();
        $owner = $db->one(
            "SELECT ci.contact_id, CONCAT(cc.first_name, ' ', COALESCE(cc.last_name, '')) AS name, c.legal_name
               FROM contact_identities ci JOIN client_contacts cc ON cc.id = ci.contact_id JOIN clients c ON c.id = cc.client_id
              WHERE ci.kind = ? AND ci.value_normalized = ?",
            [$kind, $norm]
        );
        if ($owner !== null && (int)$owner['contact_id'] !== $contactId) {
            throw ValidationException::single($kind . 's', "«$raw» ya pertenece a " . trim((string)$owner['name']) . " ({$owner['legal_name']}). Un correo o teléfono solo puede identificar a un contacto.");
        }
        $now = Clock::nowDb();
        try {
            $db->insert('contact_identities', [
                'contact_id' => $contactId, 'kind' => $kind, 'value' => $raw, 'value_normalized' => $norm,
                'is_primary' => $primary ? 1 : 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    /** Añade una identidad si no existe (al identificar un remitente desconocido). */
    public function addIdentity(int $contactId, string $kind, string $value, bool $primary): void
    {
        $norm = $kind === 'email' ? Validator::normalizeEmail($value) : Validator::normalizePhone($value);
        $exists = $this->app->db()->value('SELECT contact_id FROM contact_identities WHERE kind = ? AND value_normalized = ?', [$kind, $norm]);
        if ($exists !== null) {
            return;
        }
        $this->insertIdentity($contactId, $kind, $value, $norm, $primary);
    }

    /**
     * Identifica a quién pertenece un correo o teléfono.
     * Devuelve contact_id y client_id si hay un único dueño; si el correo no es
     * de un contacto pero su dominio es de un único cliente, solo client_id.
     * @return array{contact_id:?int,client_id:?int,method:string}
     */
    public function resolveSender(string $kind, string $value): array
    {
        $db = $this->app->db();
        $norm = $kind === 'email' ? Validator::normalizeEmail($value) : Validator::normalizePhone($value);
        $row = $db->one(
            "SELECT ci.contact_id, cc.client_id FROM contact_identities ci
               JOIN client_contacts cc ON cc.id = ci.contact_id
               JOIN clients c ON c.id = cc.client_id
              WHERE ci.kind = ? AND ci.value_normalized = ? AND cc.deleted_at IS NULL AND cc.active = 1 AND c.deleted_at IS NULL",
            [$kind, $norm]
        );
        if ($row !== null) {
            return ['contact_id' => (int)$row['contact_id'], 'client_id' => (int)$row['client_id'], 'method' => 'identity'];
        }
        if ($kind === 'email' && str_contains($norm, '@')) {
            $domain = substr($norm, strrpos($norm, '@') + 1);
            $clients = $db->column('SELECT d.client_id FROM client_email_domains d JOIN clients c ON c.id = d.client_id WHERE d.domain = ? AND c.deleted_at IS NULL', [$domain]);
            if (count($clients) === 1) {
                return ['contact_id' => null, 'client_id' => (int)$clients[0], 'method' => 'domain'];
            }
        }
        return ['contact_id' => null, 'client_id' => null, 'method' => 'none'];
    }

    /** @param array<string,mixed> $d */
    public function saveAddress(int $clientId, array $d, ?int $addressId = null): int
    {
        $db = $this->app->db();
        $now = Clock::nowDb();
        $data = [
            'label' => $d['label'] ?? null,
            'address_line' => $d['address_line'],
            'postal_code' => $d['postal_code'] ?? null,
            'city' => $d['city'] ?? null,
            'province' => $d['province'] ?? null,
            'country_code' => strtoupper((string)($d['country_code'] ?? 'ES')),
            'is_primary' => !empty($d['is_primary']) ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($addressId === null) {
            $addressId = $db->insert('client_addresses', $data + ['client_id' => $clientId, 'created_at' => $now]);
        } else {
            $db->update('client_addresses', $data, 'id = ? AND client_id = ?', [$addressId, $clientId]);
        }
        if (!empty($d['is_primary'])) {
            $db->run('UPDATE client_addresses SET is_primary = 0 WHERE client_id = ? AND id <> ?', [$clientId, $addressId]);
        }
        return $addressId;
    }

    public function addDomain(int $clientId, string $domain): void
    {
        $db = $this->app->db();
        $domain = strtolower(trim($domain));
        $public = ['gmail.com', 'hotmail.com', 'hotmail.es', 'outlook.com', 'outlook.es', 'yahoo.com', 'yahoo.es', 'icloud.com', 'live.com', 'msn.com', 'gmx.com', 'gmx.es', 'protonmail.com', 'proton.me', 'telefonica.net', 'movistar.es'];
        if (in_array($domain, $public, true)) {
            throw ValidationException::single('domain', 'No se pueden asociar dominios de correo públicos (gmail, hotmail…): identificarían a desconocidos como este cliente.');
        }
        $other = $db->value('SELECT c.legal_name FROM client_email_domains d JOIN clients c ON c.id = d.client_id WHERE d.domain = ? AND d.client_id <> ?', [$domain, $clientId]);
        if ($other !== null) {
            throw ValidationException::single('domain', "Ese dominio ya está asociado a $other.");
        }
        $db->run('INSERT IGNORE INTO client_email_domains (client_id, domain, created_at) VALUES (?, ?, ?)', [$clientId, $domain, Clock::nowDb()]);
    }
}
