<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Services\HttpClient;
use PHPMailer\PHPMailer\OAuthTokenProvider;

/**
 * Token OAuth2 de Microsoft 365 con el flujo de credenciales de cliente
 * (aplicación registrada en Entra ID con permisos IMAP.AccessAsApp y
 * SMTP.SendAsApp, y la entidad de servicio dada de alta en Exchange Online).
 * Ver docs/manual-correo.md. El token solo vive en memoria del proceso.
 */
final class MicrosoftOAuth implements OAuthTokenProvider
{
    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $mailbox,
        private readonly HttpClient $http = new HttpClient(),
    ) {
        if (!preg_match('/^[A-Za-z0-9.\-]{3,64}$/', $tenantId) || !preg_match('/^[A-Za-z0-9\-]{8,64}$/', $clientId)) {
            throw new \InvalidArgumentException('Identificador de inquilino o de aplicación no válido.');
        }
    }

    public function accessToken(): string
    {
        if ($this->token !== null && time() < $this->expiresAt - 60) {
            return $this->token;
        }
        $r = $this->http->postForm('https://login.microsoftonline.com/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://outlook.office365.com/.default',
            'grant_type' => 'client_credentials',
        ]);
        $j = is_array($r['json']) ? $r['json'] : [];
        if ($r['status'] !== 200 || !isset($j['access_token'])) {
            $desc = (string)($j['error_description'] ?? $j['error'] ?? ('HTTP ' . $r['status']));
            throw new \RuntimeException('Microsoft rechazó la autenticación: ' . mb_substr(strtok($desc, "\r\n") ?: $desc, 0, 300));
        }
        $this->token = (string)$j['access_token'];
        $this->expiresAt = time() + (int)($j['expires_in'] ?? 3000);
        return $this->token;
    }

    /** Cadena SASL XOAUTH2 en base64. */
    public function getOauth64(): string
    {
        return base64_encode("user={$this->mailbox}\x01auth=Bearer {$this->accessToken()}\x01\x01");
    }
}
