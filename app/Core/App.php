<?php

declare(strict_types=1);

namespace App\Core;

use App\Security\AuditLogger;
use App\Security\Auth;
use App\Security\Crypto;
use App\Security\Csrf;
use App\Security\Permissions;
use App\Security\RateLimiter;
use App\Services\Settings;
use RuntimeException;

/**
 * Contenedor de la aplicación: configuración y servicios compartidos creados
 * bajo demanda (una instancia por petición o proceso).
 */
final class App
{
    /** @var array<string,object> */
    private array $instances = [];

    /** @param array<string,mixed> $config */
    public function __construct(public readonly string $root, private readonly array $config)
    {
    }

    public static function boot(string $root, ?array $config = null): self
    {
        if ($config === null) {
            $file = $root . '/config/config.php';
            if (!is_file($file)) {
                throw new RuntimeException('NOT_INSTALLED');
            }
            $config = require $file;
            if (!is_array($config)) {
                throw new RuntimeException('config/config.php no devuelve un array.');
            }
        }
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');
        ini_set('default_charset', 'UTF-8');
        $app = new self($root, $config);
        if ($app->isProduction()) {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        } else {
            error_reporting(E_ALL);
        }
        ini_set('log_errors', '1');
        ini_set('error_log', $app->storagePath('logs/php-errors.log'));
        ini_set('expose_php', '0');
        return $app;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        $value = $this->config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function isProduction(): bool
    {
        return ($this->config['env'] ?? 'production') === 'production';
    }

    public function storagePath(string $sub = ''): string
    {
        $base = (string)($this->config['paths']['storage'] ?? $this->root . '/storage');
        return rtrim($base, '/') . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
    }

    public function url(string $path = '/'): string
    {
        return rtrim((string)$this->config('app_url', ''), '/') . '/' . ltrim($path, '/');
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable():T $factory
     * @return T
     */
    private function shared(string $class, callable $factory): object
    {
        return $this->instances[$class] ??= $factory();
    }

    /**
     * Servicios de dominio con constructor (App $app).
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function make(string $class): object
    {
        return $this->instances[$class] ??= new $class($this);
    }

    /** Sustituye un servicio (pruebas). */
    public function bind(string $class, object $instance): void
    {
        $this->instances[$class] = $instance;
    }

    public function db(): Database
    {
        return $this->shared(Database::class, fn () => Database::connect((array)$this->config['db']));
    }

    public function logger(): Logger
    {
        return $this->shared(Logger::class, fn () => new Logger($this->storagePath('logs')));
    }

    public function crypto(): Crypto
    {
        return $this->shared(Crypto::class, fn () => new Crypto((string)($this->config['key_file'] ?? $this->root . '/config/app.key')));
    }

    public function settings(): Settings
    {
        return $this->shared(Settings::class, fn () => new Settings($this->db(), $this->crypto()));
    }

    public function session(): Session
    {
        return $this->shared(Session::class, fn () => new Session(
            $this->storagePath('sessions'),
            $this->settings()->int('security.session_idle_minutes', 60)
        ));
    }

    public function csrf(): Csrf
    {
        return $this->shared(Csrf::class, fn () => new Csrf($this->session()));
    }

    public function permissions(): Permissions
    {
        return $this->shared(Permissions::class, fn () => new Permissions($this->db()));
    }

    public function audit(): AuditLogger
    {
        return $this->shared(AuditLogger::class, fn () => new AuditLogger($this->db()));
    }

    public function auth(): Auth
    {
        return $this->shared(Auth::class, fn () => new Auth($this->db(), $this->session(), $this->permissions(), $this->audit(), $this->settings()));
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->shared(RateLimiter::class, fn () => new RateLimiter($this->db()));
    }

    public function view(): View
    {
        return $this->shared(View::class, fn () => new View($this));
    }
}
