<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cliente HTTP mínimo para las API de Microsoft y Meta. Usa cURL si está
 * disponible y, si no, flujos de PHP. Siempre verifica TLS. Nunca registra
 * cabeceras de autorización.
 */
final class HttpClient
{
    public function __construct(private readonly int $timeout = 20)
    {
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,json:mixed}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('Solo se permiten URL https.');
        }
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $resp = curl_exec($ch);
            if ($resp === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new \RuntimeException('Error de conexión: ' . $err);
            }
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $lines),
                    'content' => $body ?? '',
                    'timeout' => $this->timeout,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp === false) {
                throw new \RuntimeException('Error de conexión con ' . parse_url($url, PHP_URL_HOST));
            }
            $status = 0;
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int)$m[1];
                }
            }
        }
        $json = json_decode((string)$resp, true);
        return ['status' => $status, 'body' => (string)$resp, 'json' => $json];
    }

    /** @param array<string,string> $fields @return array{status:int,body:string,json:mixed} */
    public function postForm(string $url, array $fields): array
    {
        return $this->request('POST', $url, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    }

    /** @param array<string,string> $headers @return array{status:int,body:string,json:mixed} */
    public function postJson(string $url, mixed $data, array $headers = []): array
    {
        return $this->request('POST', $url, $headers + ['Content-Type' => 'application/json'], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
