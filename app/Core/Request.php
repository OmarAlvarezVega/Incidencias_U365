<?php

declare(strict_types=1);

namespace App\Core;

/** Petición HTTP inmutable construida a partir de las superglobales. */
final class Request
{
    /** @var array<string,string> */
    public array $routeParams = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     * @param array<string,mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $server,
        private readonly ?string $rawBody = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        $path = '/' . trim($path, '/');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        return new self($method, $path, $_GET, $_POST, $_FILES, $_SERVER);
    }

    public function rawBody(): string
    {
        return $this->rawBody ?? (string)file_get_contents('php://input');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /** Texto recortado; nunca devuelve arrays (evita manipulación de tipos). */
    public function str(string $key, string $default = ''): string
    {
        $v = $this->get($key, $default);
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $v = $this->get($key);
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^-?\d{1,18}$/', trim($v))) {
            return (int)trim($v);
        }
        return $default;
    }

    public function bool(string $key): bool
    {
        $v = $this->get($key);
        return in_array($v, ['1', 'on', 'true', 'yes', 1, true], true);
    }

    /** @return list<int> */
    public function intList(string $key): array
    {
        $v = $this->get($key, []);
        if (!is_array($v)) {
            $v = $v === null || $v === '' ? [] : [$v];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_scalar($item) && preg_match('/^\d{1,18}$/', (string)$item)) {
                $out[] = (int)$item;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public function strList(string $key): array
    {
        $v = $this->get($key, []);
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn ($x) => is_scalar($x) ? trim((string)$x) : '', $v), static fn ($x) => $x !== ''));
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'Content-Type') {
            $key = 'CONTENT_TYPE';
        }
        $v = $this->server[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    public function ip(): string
    {
        return (string)($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string)($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isHttps(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string)$https) !== 'off') {
            return true;
        }
        // IONOS termina TLS antes de PHP en algunos planes y lo indica con esta cabecera
        return strtolower((string)($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (int)($this->server['SERVER_PORT'] ?? 0) === 443;
    }

    public function wantsJson(): bool
    {
        return str_contains((string)$this->header('Accept'), 'application/json')
            || $this->header('X-Requested-With') === 'fetch';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) {
            return null;
        }
        return $f['error'] === UPLOAD_ERR_NO_FILE ? null : $f;
    }

    /**
     * Normaliza un input file múltiple (name="files[]").
     * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public function fileList(string $key): array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $out = [];
        foreach ($f['name'] as $i => $name) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name' => (string)$name,
                'type' => (string)($f['type'][$i] ?? ''),
                'tmp_name' => (string)($f['tmp_name'][$i] ?? ''),
                'error' => (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($f['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    public function param(string $name): string
    {
        return $this->routeParams[$name] ?? '';
    }

    public function paramInt(string $name): int
    {
        return (int)($this->routeParams[$name] ?? 0);
    }

    /** Ruta + query actual, para volver tras una acción. */
    public function uri(): string
    {
        $qs = http_build_query($this->query);
        return $this->path . ($qs !== '' ? '?' . $qs : '');
    }
}
