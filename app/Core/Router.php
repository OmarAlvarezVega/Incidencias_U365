<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Router mínimo: métodos GET/POST, parámetros {nombre} con patrón opcional
 * {id:\d+} y opciones por ruta (público, sin sesión, permiso requerido).
 */
final class Router
{
    /** @var list<array{method:string,regex:string,params:list<string>,handler:array{0:class-string,1:string},opts:array<string,mixed>}> */
    private array $routes = [];

    /** @param array{0:class-string,1:string} $handler */
    public function get(string $pattern, array $handler, array $opts = []): void
    {
        $this->add('GET', $pattern, $handler, $opts);
    }

    /** @param array{0:class-string,1:string} $handler */
    public function post(string $pattern, array $handler, array $opts = []): void
    {
        $this->add('POST', $pattern, $handler, $opts);
    }

    /** @param array{0:class-string,1:string} $handler */
    public function add(string $method, string $pattern, array $handler, array $opts = []): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{([a-z_]+)(?::([^}]+))?\}/', static function ($m) use (&$params) {
            $params[] = $m[1];
            return '(' . ($m[2] ?? '[^/]+') . ')';
        }, $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
            'opts' => $opts,
        ];
    }

    /**
     * @return array{handler:array{0:class-string,1:string},params:array<string,string>,opts:array<string,mixed>}
     */
    public function match(string $method, string $path): array
    {
        $allowed = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                $allowed = true;
                continue;
            }
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = $m[$i + 1];
            }
            return ['handler' => $route['handler'], 'params' => $params, 'opts' => $route['opts']];
        }
        throw new HttpException($allowed ? 405 : 404);
    }
}
