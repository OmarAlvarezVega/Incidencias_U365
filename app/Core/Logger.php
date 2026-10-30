<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Registro en fichero (storage/logs/app-AAAA-MM-DD.log). Enmascara cualquier
 * valor que parezca un secreto antes de escribir.
 */
final class Logger
{
    public function __construct(private readonly string $dir)
    {
    }

    public function error(string $message, array $context = []): string
    {
        return $this->write('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): string
    {
        return $this->write('WARNING', $message, $context);
    }

    public function info(string $message, array $context = []): string
    {
        return $this->write('INFO', $message, $context);
    }

    /** Devuelve un código de referencia que se puede mostrar al usuario. */
    public function write(string $level, string $message, array $context = []): string
    {
        $ref = strtoupper(bin2hex(random_bytes(4)));
        $line = sprintf(
            "[%s] %s ref=%s %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            $ref,
            self::redact($message),
            $context ? self::redact((string)json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)) : ''
        );
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        @file_put_contents($this->dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        return $ref;
    }

    public static function redact(string $text): string
    {
        $patterns = [
            '/("?(?:password|pass|passwd|secret|token|access_token|refresh_token|client_secret|app_secret|authorization|api_key|key)"?\s*[:=]\s*)("[^"]*"|[^\s,&}]+)/i' => '$1"***"',
            '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i' => '$1***',
            '/(AUTHENTICATE\s+XOAUTH2\s+)\S+/i' => '$1***',
            '/(LOGIN\s+"?[^"\s]+"?\s+)("[^"]*"|\S+)/i' => '$1***',
            '/EAA[A-Za-z0-9]{20,}/' => 'EAA***',
        ];
        return (string)preg_replace(array_keys($patterns), array_values($patterns), $text);
    }
}
