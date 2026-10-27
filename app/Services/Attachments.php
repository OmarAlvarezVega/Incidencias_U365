<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Clock;
use App\Core\ValidationException;

/**
 * Adjuntos de incidencias.
 *
 * - Se guardan en storage/attachments (fuera de la raíz web) con un nombre
 *   aleatorio sin extensión: nunca se pueden ejecutar ni adivinar.
 * - El tipo se detecta con finfo (no se confía en el navegador) y debe
 *   encajar con la extensión permitida.
 * - El nombre original solo se muestra; jamás se usa como ruta.
 * - Se descargan siempre a través de un controlador que comprueba permisos.
 */
final class Attachments
{
    /** Extensión → tipos MIME aceptados según finfo. */
    public const MIME_MAP = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'log' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'],
        'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'eml' => ['message/rfc822', 'text/plain'],
        'msg' => ['application/vnd.ms-outlook', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'],
        'mp3' => ['audio/mpeg'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        'opus' => ['audio/ogg', 'application/ogg'],
        'mp4' => ['video/mp4'],
        'heic' => ['image/heic', 'image/heif'],
    ];

    /** Nunca se aceptan, aunque se configure la extensión. */
    private const BLOCKED_MIME = [
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'text/html', 'application/xhtml+xml',
        'image/svg+xml', 'application/x-executable', 'application/x-dosexec', 'application/x-msdownload',
        'application/x-sh', 'text/x-shellscript', 'application/javascript', 'text/javascript', 'application/java-archive',
    ];
    private const BLOCKED_EXT = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps', 'pht', 'html', 'htm', 'svg', 'js', 'mjs', 'exe', 'bat', 'cmd', 'com', 'scr', 'sh', 'ps1', 'vbs', 'jar', 'msi', 'dll', 'htaccess', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp'];

    public const INLINE_MIME = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

    public function __construct(private readonly App $app)
    {
    }

    public function maxBytes(): int
    {
        return max(1, $this->app->settings()->int('uploads.max_mb', 15)) * 1048576;
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        $list = $this->app->settings()->get('uploads.allowed_extensions', []);
        $list = is_array($list) ? array_map('strtolower', array_map('strval', $list)) : [];
        return array_values(array_filter($list, static fn ($e) => isset(self::MIME_MAP[$e]) && !in_array($e, self::BLOCKED_EXT, true)));
    }

    /**
     * Comprueba un fichero. Devuelve el MIME detectado o lanza un mensaje claro.
     */
    public function inspect(string $path, string $originalName, ?int $maxBytes = null): string
    {
        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            throw new \InvalidArgumentException("«{$originalName}» está vacío.");
        }
        if ($size > ($maxBytes ?? $this->maxBytes())) {
            throw new \InvalidArgumentException("«{$originalName}» supera el tamaño máximo de " . intdiv($maxBytes ?? $this->maxBytes(), 1048576) . ' MB.');
        }
        $name = strtolower($originalName);
        $parts = explode('.', $name);
        $ext = count($parts) > 1 ? (string)end($parts) : '';
        foreach ($parts as $i => $p) {
            // doble extensión peligrosa: factura.php.pdf
            if ($i > 0 && in_array($p, self::BLOCKED_EXT, true)) {
                throw new \InvalidArgumentException("«{$originalName}» tiene una extensión no permitida.");
            }
        }
        if (!in_array($ext, $this->allowedExtensions(), true)) {
            throw new \InvalidArgumentException("El tipo de archivo de «{$originalName}» no está permitido. Permitidos: " . implode(', ', $this->allowedExtensions()) . '.');
        }
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (in_array($mime, self::BLOCKED_MIME, true)) {
            throw new \InvalidArgumentException("«{$originalName}» no está permitido por su contenido.");
        }
        if (!in_array($mime, self::MIME_MAP[$ext], true)) {
            throw new \InvalidArgumentException("El contenido de «{$originalName}» no corresponde a un archivo .$ext.");
        }
        // Un "texto" que contiene código PHP no se guarda
        if (str_starts_with($mime, 'text/')) {
            $head = (string)file_get_contents($path, false, null, 0, 65536);
            if (preg_match('/<\?php|<\?=|<script\b/i', $head)) {
                throw new \InvalidArgumentException("«{$originalName}» contiene código y no se admite.");
            }
        }
        return $mime;
    }

    /** Valida y guarda subidas de formulario. @param list<array{name:string,type:string,tmp_name:string,error:int,size:int}> $files @return list<int> */
    public function storeUploads(int $ticketId, array $files, int $userId, string $visibility, ?int $messageId = null, ?int $activityId = null): array
    {
        $errors = [];
        $checked = [];
        foreach ($files as $f) {
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors[] = match ($f['error']) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "«{$f['name']}» supera el tamaño permitido por el servidor.",
                    UPLOAD_ERR_PARTIAL => "«{$f['name']}» no se subió completo.",
                    default => "No se pudo subir «{$f['name']}».",
                };
                continue;
            }
            if (!is_uploaded_file($f['tmp_name']) && PHP_SAPI !== 'cli') {
                $errors[] = 'Subida no válida.';
                continue;
            }
            try {
                $checked[] = [$f, $this->inspect($f['tmp_name'], $f['name'])];
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if (count($files) > 10) {
            $errors[] = 'Como máximo 10 archivos a la vez.';
        }
        if ($errors !== []) {
            throw new ValidationException(['files' => $errors], implode(' ', $errors));
        }
        $ids = [];
        foreach ($checked as [$f, $mime]) {
            $ids[] = $this->persist($ticketId, $f['tmp_name'], $f['name'], $mime, $userId, $visibility, $messageId, $activityId, true);
        }
        return $ids;
    }

    /**
     * Guarda contenido recibido por correo o WhatsApp. Si no supera la
     * inspección se registra como rechazado (sin guardar el contenido) para
     * que el técnico sepa que existía.
     */
    public function storeContent(int $ticketId, string $content, string $originalName, ?int $messageId, string $visibility = 'public', ?int $maxBytes = null): int
    {
        $tmp = tempnam($this->app->storagePath('tmp'), 'att');
        if ($tmp === false) {
            throw new \RuntimeException('No se pudo crear un archivo temporal.');
        }
        try {
            file_put_contents($tmp, $content);
            $originalName = self::safeName($originalName);
            try {
                $mime = $this->inspect($tmp, $originalName, $maxBytes);
            } catch (\InvalidArgumentException $e) {
                return (int)$this->app->db()->insert('ticket_attachments', [
                    'ticket_id' => $ticketId,
                    'message_id' => $messageId,
                    'uploaded_by_user_id' => null,
                    'original_name' => $originalName,
                    'storage_key' => bin2hex(random_bytes(32)),
                    'mime_type' => 'application/octet-stream',
                    'size_bytes' => strlen($content),
                    'sha256' => hash('sha256', $content),
                    'visibility' => $visibility,
                    'status' => 'rejected',
                    'created_at' => Clock::nowDb(),
                ]);
            }
            return $this->persist($ticketId, $tmp, $originalName, $mime, null, $visibility, $messageId, null, false);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function persist(int $ticketId, string $src, string $name, string $mime, ?int $userId, string $visibility, ?int $messageId, ?int $activityId, bool $uploaded): int
    {
        $key = bin2hex(random_bytes(32));
        $path = $this->path($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear la carpeta de adjuntos.');
        }
        $ok = $uploaded && PHP_SAPI !== 'cli' ? move_uploaded_file($src, $path) : copy($src, $path);
        if (!$ok) {
            throw new \RuntimeException('No se pudo guardar el adjunto.');
        }
        @chmod($path, 0640);
        return $this->app->db()->insert('ticket_attachments', [
            'ticket_id' => $ticketId,
            'message_id' => $messageId,
            'activity_id' => $activityId,
            'uploaded_by_user_id' => $userId,
            'original_name' => self::safeName($name),
            'storage_key' => $key,
            'mime_type' => $mime,
            'size_bytes' => (int)filesize($path),
            'sha256' => (string)hash_file('sha256', $path),
            'visibility' => $visibility === 'public' ? 'public' : 'internal',
            'status' => 'stored',
            'created_at' => Clock::nowDb(),
        ]);
    }

    public function path(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
            throw new \InvalidArgumentException('Clave de adjunto no válida.');
        }
        return $this->app->storagePath('attachments/' . substr($key, 0, 2) . '/' . substr($key, 2, 2) . '/' . $key);
    }

    public static function safeName(string $name): string
    {
        $name = str_replace(["\0", '/', '\\'], '_', $name);
        $name = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name, " .");
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }
        return mb_substr($name !== '' ? $name : 'archivo', 0, 200);
    }
}
