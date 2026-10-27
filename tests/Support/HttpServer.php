<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Levanta la aplicación real con el servidor embebido de PHP sobre una copia
 * temporal de la raíz (enlaces al código + config de pruebas) y hace
 * peticiones HTTP con cookies, como un navegador.
 */
final class HttpServer
{
    /** @var resource|null */
    private static $proc = null;
    private static string $base = '';

    public static function base(): string
    {
        if (self::$proc === null) {
            self::start();
        }
        return self::$base;
    }

    private static function start(): void
    {
        TestEnv::app();
        $repo = dirname(__DIR__, 2);
        $root = TestEnv::dir() . '/web';
        foreach (['', '/public', '/config'] as $d) {
            if (!is_dir($root . $d)) {
                mkdir($root . $d, 0700, true);
            }
        }
        foreach (['app', 'templates', 'database', 'vendor'] as $l) {
            if (!file_exists("$root/$l")) {
                symlink("$repo/$l", "$root/$l");
            }
        }
        if (!file_exists("$root/public/assets")) {
            symlink("$repo/public/assets", "$root/public/assets");
        }
        // Copia real: index.php usa dirname(__DIR__), que resolvería un enlace hacia el repositorio
        copy("$repo/public/index.php", "$root/public/index.php");
        file_put_contents("$root/config/config.php", "<?php\nreturn " . var_export(TestEnv::config(), true) . ";\n");

        $port = (int)(getenv('TEST_HTTP_PORT') ?: 0) ?: random_int(20000, 40000);
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/public/index.php"];
        $log = TestEnv::dir() . '/servidor-http.log';
        self::$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
        if (!is_resource(self::$proc)) {
            throw new RuntimeException('No se pudo arrancar php -S');
        }
        self::$base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($s !== false) {
                fclose($s);
                register_shutdown_function(static function (): void {
                    if (is_resource(self::$proc)) {
                        proc_terminate(self::$proc);
                    }
                });
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('php -S no responde. Log: ' . @file_get_contents($log));
    }

    /**
     * @param array<string,mixed>|string|null $body
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public static function request(string $method, string $path, array|string|null $body = null, array $headers = [], ?string $cookieJar = null): array
    {
        $ch = curl_init(self::base() . $path);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);
        if ($cookieJar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('Petición fallida: ' . curl_error($ch));
        }
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $out = [];
        foreach (explode("\r\n", substr((string)$raw, 0, $size)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $out[strtolower(trim($k))] = trim($v);
            }
        }
        return ['status' => (int)$status, 'headers' => $out, 'body' => substr((string)$raw, $size)];
    }

    /** Token CSRF de la página indicada (meta csrf-token o campo _token). */
    public static function csrf(string $path, string $jar): string
    {
        $r = self::request('GET', $path, null, [], $jar);
        if (!preg_match('/name="(?:csrf-token|_token)"\s+(?:content|value)="([a-f0-9]+)"/', $r['body'], $m)) {
            throw new RuntimeException("Sin token CSRF en $path (HTTP {$r['status']})");
        }
        return $m[1];
    }

    /** Inicia sesión y devuelve el fichero de cookies. */
    public static function login(string $email, string $password = TestEnv::PASSWORD): string
    {
        $jar = tempnam(TestEnv::dir(), 'cookies');
        $token = self::csrf('/login', $jar);
        $r = self::request('POST', '/login', ['_token' => $token, 'identifier' => $email, 'password' => $password], [], $jar);
        if (!in_array($r['status'], [302, 303], true) || str_contains($r['headers']['location'] ?? '', '/login')) {
            throw new RuntimeException("No se pudo iniciar sesión como $email (HTTP {$r['status']})");
        }
        return $jar;
    }
}
