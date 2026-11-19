<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\App;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Envío SMTP autenticado con PHPMailer (nunca mail()).
 * Construye Message-ID propio para poder enlazar las respuestas del cliente
 * con su incidencia (In-Reply-To / References).
 */
final class Mailer
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string,mixed> $account fila de email_accounts
     * @param list<string> $references
     * @param list<array{path:string,name:string,mime:string}> $attachments
     * @return string Message-ID usado (sin <>)
     */
    public function send(
        array $account,
        string $to,
        string $subject,
        string $bodyText,
        ?string $inReplyTo = null,
        array $references = [],
        array $attachments = [],
        bool $autoSubmitted = false,
        ?string $messageId = null,
    ): string {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new MailException('Destinatario no válido.');
        }
        $m = $this->configured($account);
        $domain = substr(strrchr((string)$account['address'], '@') ?: '@univers365.com', 1);
        $messageId ??= self::newMessageId($domain);
        $m->MessageID = '<' . $messageId . '>';
        $m->addAddress($to);
        $m->Subject = self::cleanHeader($subject);
        $m->Body = $bodyText;
        $m->isHTML(false);
        if ($inReplyTo !== null) {
            $m->addCustomHeader('In-Reply-To', '<' . trim($inReplyTo, '<> ') . '>');
        }
        $refs = array_slice(array_values(array_unique(array_filter(array_map(static fn ($r) => trim((string)$r, '<> '), $references)))), -20);
        if ($refs !== []) {
            $m->addCustomHeader('References', implode(' ', array_map(static fn ($r) => "<$r>", $refs)));
        }
        if ($autoSubmitted) {
            $m->addCustomHeader('Auto-Submitted', 'auto-generated');
        }
        $m->addCustomHeader('X-Auto-Response-Suppress', 'All');
        foreach ($attachments as $a) {
            $m->addAttachment($a['path'], $a['name'], PHPMailer::ENCODING_BASE64, $a['mime']);
        }
        if (!$m->send()) {
            throw new MailException('SMTP: ' . $m->ErrorInfo);
        }
        return $messageId;
    }

    /** Prueba de conexión y autenticación SMTP sin enviar nada. @param array<string,mixed> $account */
    public function test(array $account): void
    {
        $m = $this->configured($account);
        if (!$m->smtpConnect()) {
            throw new MailException('SMTP: ' . $m->ErrorInfo);
        }
        $m->smtpClose();
    }

    /** @param array<string,mixed> $account */
    private function configured(array $account): PHPMailer
    {
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $m->Host = (string)$account['smtp_host'];
        $m->Port = (int)$account['smtp_port'];
        $m->SMTPSecure = $account['smtp_encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $m->SMTPAutoTLS = false;
        $m->SMTPAuth = true;
        $m->Timeout = 20;
        $m->XMailer = ' ';
        $m->SMTPDebug = SMTP::DEBUG_OFF;
        $m->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
        if ($account['auth_type'] === 'oauth2_microsoft') {
            $m->AuthType = 'XOAUTH2';
            $m->Username = (string)($account['smtp_username'] ?: $account['imap_username']);
            $m->setOAuth($this->app->make(EmailAccounts::class)->oauth($account));
        } else {
            $secret = $this->app->crypto()->decrypt($account['smtp_secret_enc'] ?? null)
                ?? $this->app->crypto()->decrypt($account['imap_secret_enc'] ?? null);
            if ($secret === null) {
                throw new MailException('No hay contraseña SMTP guardada para este buzón.');
            }
            $m->Username = (string)($account['smtp_username'] ?: $account['imap_username']);
            $m->Password = $secret;
        }
        $m->setFrom((string)$account['address'], (string)$account['from_name'], false);
        $m->Sender = (string)$account['address'];
        return $m;
    }

    public static function newMessageId(string $domain): string
    {
        $domain = preg_replace('/[^A-Za-z0-9.\-]/', '', $domain) ?: 'univers365.com';
        return 'u365.' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $domain;
    }

    public static function cleanHeader(string $s): string
    {
        return mb_substr(trim((string)preg_replace('/[\r\n\t]+/', ' ', $s)), 0, 250);
    }
}
