<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Services\HttpClient;

/**
 * Cliente de la API oficial WhatsApp Business Cloud (Graph API de Meta).
 * El token nunca sale del servidor ni se registra.
 */
final class CloudApi
{
    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $accessToken,
        private readonly string $version = 'v21.0',
        private readonly HttpClient $http = new HttpClient(25),
    ) {
        if (!preg_match('/^\d{5,25}$/', $phoneNumberId) || !preg_match('/^v\d{1,3}\.\d$/', $version)) {
            throw new \InvalidArgumentException('Configuración de WhatsApp no válida.');
        }
    }

    private function base(): string
    {
        return 'https://graph.facebook.com/' . $this->version . '/';
    }

    /** @return array<string,string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->accessToken];
    }

    /** Envía texto libre (solo dentro de la ventana de 24 h). Devuelve el wamid. */
    public function sendText(string $to, string $body): string
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($body, 0, 4096)],
        ]);
    }

    /**
     * Plantilla aprobada por Meta (necesaria fuera de la ventana de 24 h).
     * @param list<string> $bodyParams
     */
    public function sendTemplate(string $to, string $name, string $lang, array $bodyParams): string
    {
        $tpl = ['name' => $name, 'language' => ['code' => $lang]];
        if ($bodyParams !== []) {
            $tpl['components'] = [[
                'type' => 'body',
                'parameters' => array_map(static fn ($p) => ['type' => 'text', 'text' => mb_substr($p, 0, 1000)], $bodyParams),
            ]];
        }
        return $this->send(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => $tpl]);
    }

    /** Documento o imagen ya subido a Meta. */
    public function sendMedia(string $to, string $type, string $mediaId, ?string $filename, ?string $caption): string
    {
        $media = ['id' => $mediaId];
        if ($caption !== null && $caption !== '') {
            $media['caption'] = mb_substr($caption, 0, 1024);
        }
        if ($type === 'document' && $filename !== null) {
            $media['filename'] = $filename;
        }
        return $this->send(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => $type, $type => $media]);
    }

    public function uploadMedia(string $path, string $mime, string $filename): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Enviar archivos por WhatsApp requiere la extensión cURL.');
        }
        $ch = curl_init($this->base() . $this->phoneNumberId . '/media');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->accessToken],
            CURLOPT_POSTFIELDS => [
                'messaging_product' => 'whatsapp',
                'type' => $mime,
                'file' => new \CURLFile($path, $mime, $filename),
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $j = json_decode((string)$resp, true);
        if ($status !== 200 || !isset($j['id'])) {
            throw new \RuntimeException('Meta rechazó el archivo: ' . self::error(is_array($j) ? $j : [], $status));
        }
        return (string)$j['id'];
    }

    /** Descarga un archivo recibido. @return array{content:string,mime:string} */
    public function downloadMedia(string $mediaId, int $maxBytes): array
    {
        if (!preg_match('/^\d{5,30}$/', $mediaId)) {
            throw new \InvalidArgumentException('Identificador de archivo no válido.');
        }
        $meta = $this->http->request('GET', $this->base() . $mediaId, $this->auth());
        $j = is_array($meta['json']) ? $meta['json'] : [];
        if ($meta['status'] !== 200 || !isset($j['url'])) {
            throw new \RuntimeException('No se pudo obtener el archivo de Meta: ' . self::error($j, $meta['status']));
        }
        if ((int)($j['file_size'] ?? 0) > $maxBytes) {
            throw new \RuntimeException('El archivo recibido supera el tamaño máximo.');
        }
        $host = (string)parse_url((string)$j['url'], PHP_URL_HOST);
        if (!preg_match('/(^|\.)(fbsbx\.com|facebook\.com|whatsapp\.net)$/', $host)) {
            throw new \RuntimeException('URL de descarga inesperada.');
        }
        $file = $this->http->request('GET', (string)$j['url'], $this->auth());
        if ($file['status'] !== 200) {
            throw new \RuntimeException('Descarga fallida (HTTP ' . $file['status'] . ').');
        }
        return ['content' => $file['body'], 'mime' => (string)($j['mime_type'] ?? 'application/octet-stream')];
    }

    /** Comprueba token y número (para el botón «Probar»). @return array<string,mixed> */
    public function phoneInfo(): array
    {
        $r = $this->http->request('GET', $this->base() . $this->phoneNumberId . '?fields=display_phone_number,verified_name,quality_rating', $this->auth());
        $j = is_array($r['json']) ? $r['json'] : [];
        if ($r['status'] !== 200) {
            throw new \RuntimeException(self::error($j, $r['status']));
        }
        return $j;
    }

    /** @param array<string,mixed> $payload */
    private function send(array $payload): string
    {
        $r = $this->http->postJson($this->base() . $this->phoneNumberId . '/messages', $payload, $this->auth());
        $j = is_array($r['json']) ? $r['json'] : [];
        if ($r['status'] !== 200 || !isset($j['messages'][0]['id'])) {
            $code = (int)($j['error']['code'] ?? 0);
            $msg = 'Meta rechazó el envío: ' . self::error($j, $r['status']);
            // 131047: fuera de la ventana de 24 h; 131026: destinatario no válido
            if (in_array($code, [131047, 131026, 131051, 100], true)) {
                throw new \App\Services\PermanentJobFailure($msg);
            }
            throw new \RuntimeException($msg);
        }
        return (string)$j['messages'][0]['id'];
    }

    /** @param array<string,mixed> $j */
    private static function error(array $j, int $status): string
    {
        $e = $j['error'] ?? null;
        if (is_array($e)) {
            return trim(($e['code'] ?? '') . ' ' . ($e['message'] ?? '') . ' ' . ($e['error_data']['details'] ?? ''));
        }
        return 'HTTP ' . $status;
    }
}
