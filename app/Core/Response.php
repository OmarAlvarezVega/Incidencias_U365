<?php

declare(strict_types=1);

namespace App\Core;

/** Respuesta HTTP. Las cabeceras de seguridad se añaden siempre al enviar. */
class Response
{
    /** @var array<string,string> */
    public array $headers = [];

    /** @var (callable():void)|null */
    private $streamer = null;

    public function __construct(public string $body = '', public int $status = 200, array $headers = [])
    {
        $this->headers = $headers + ['Content-Type' => 'text/html; charset=UTF-8'];
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Redirección solo a rutas internas, para evitar redirecciones abiertas. */
    public static function redirect(string $to, int $status = 303): self
    {
        if (!str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')) {
            $to = '/';
        }
        return new self('', $status, ['Location' => $to]);
    }

    /**
     * Descarga de un fichero del disco por streaming.
     */
    public static function file(string $path, string $downloadName, string $mime, bool $inline = false): self
    {
        $r = new self('', 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string)filesize($path),
            'Content-Disposition' => self::disposition($inline ? 'inline' : 'attachment', $downloadName),
            'Cache-Control' => 'private, no-store',
        ]);
        $r->streamer = static function () use ($path): void {
            $fh = fopen($path, 'rb');
            if ($fh === false) {
                return;
            }
            while (!feof($fh)) {
                echo fread($fh, 65536);
                flush();
            }
            fclose($fh);
        };
        return $r;
    }

    /** @param callable():void $streamer */
    public static function stream(callable $streamer, string $mime, string $downloadName): self
    {
        $r = new self('', 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => self::disposition('attachment', $downloadName),
            'Cache-Control' => 'private, no-store',
        ]);
        $r->streamer = $streamer;
        return $r;
    }

    public static function disposition(string $type, string $filename): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'archivo';
        return sprintf("%s; filename=\"%s\"; filename*=UTF-8''%s", $type, $ascii, rawurlencode($filename));
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(bool $https = true): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach (self::securityHeaders($https) as $k => $v) {
                if (!isset($this->headers[$k])) {
                    header($k . ': ' . $v);
                }
            }
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . str_replace(["\r", "\n"], '', $v));
            }
        }
        if ($this->streamer !== null) {
            ($this->streamer)();
            return;
        }
        echo $this->body;
    }

    /** @return array<string,string> */
    public static function securityHeaders(bool $https): array
    {
        $h = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            // Scripts solo desde el propio dominio. Los estilos en línea se
            // permiten por los colores de estados/prioridades configurables.
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        ];
        if ($https) {
            $h['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        return $h;
    }
}
