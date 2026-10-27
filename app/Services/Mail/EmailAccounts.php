<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\App;

/**
 * Buzones configurados y sus credenciales descifradas solo en memoria.
 */
final class EmailAccounts
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->app->db()->one('SELECT * FROM email_accounts WHERE id = ?', [$id]);
    }

    /** Buzón para envíos que no responden a un correo concreto. @return array<string,mixed>|null */
    public function default(): ?array
    {
        return $this->app->db()->one('SELECT * FROM email_accounts WHERE active = 1 ORDER BY is_default DESC, id LIMIT 1');
    }

    /** @return list<array<string,mixed>> */
    public function active(): array
    {
        return $this->app->db()->all('SELECT * FROM email_accounts WHERE active = 1 ORDER BY id');
    }

    /** @param array<string,mixed> $acc */
    public function oauth(array $acc): MicrosoftOAuth
    {
        if ($acc['auth_type'] !== 'oauth2_microsoft') {
            throw new MailException('Este buzón no usa OAuth2 de Microsoft.');
        }
        $secret = $this->app->crypto()->decrypt($acc['imap_secret_enc']);
        if ($secret === null || $acc['oauth_tenant_id'] === null || $acc['oauth_client_id'] === null) {
            throw new MailException('Faltan datos OAuth2 (inquilino, aplicación o secreto).');
        }
        return new MicrosoftOAuth((string)$acc['oauth_tenant_id'], (string)$acc['oauth_client_id'], $secret, (string)$acc['imap_username']);
    }

    /** Conexión IMAP autenticada. @param array<string,mixed> $acc */
    public function imap(array $acc): ImapClient
    {
        if ($acc['auth_type'] === 'oauth2_google') {
            throw new MailException('OAuth2 de Google aún no está implementado. Usa contraseña de aplicación.');
        }
        $client = new ImapClient((string)$acc['imap_host'], (int)$acc['imap_port'], (string)$acc['imap_encryption']);
        $client->connect();
        if ($acc['auth_type'] === 'oauth2_microsoft') {
            $client->authenticateXoauth2($this->oauth($acc)->getOauth64());
        } else {
            $secret = $this->app->crypto()->decrypt($acc['imap_secret_enc']);
            if ($secret === null) {
                throw new MailException('No hay contraseña IMAP guardada para este buzón.');
            }
            $client->login((string)$acc['imap_username'], $secret);
        }
        return $client;
    }
}
