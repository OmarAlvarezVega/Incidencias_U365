<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Security\AuditLogger;
use App\Security\CurrentUser;

/** Base de los controladores: solo traducen HTTP a llamadas a servicios. */
abstract class Controller
{
    public function __construct(protected readonly App $app, protected readonly Request $request)
    {
    }

    protected function user(): CurrentUser
    {
        $u = $this->app->auth()->user();
        if ($u === null) {
            throw new HttpException(401);
        }
        return $u;
    }

    protected function db(): Database
    {
        return $this->app->db();
    }

    protected function audit(): AuditLogger
    {
        return $this->app->audit();
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->app->view()->render($template, $data), $status);
    }

    protected function redirect(string $to, ?string $success = null): Response
    {
        if ($success !== null) {
            $this->app->session()->flash('success', $success);
        }
        return Response::redirect($to);
    }

    protected function flash(string $type, string $message): void
    {
        $this->app->session()->flash($type, $message);
    }

    /** Vuelve a la página anterior si es interna. */
    protected function back(string $fallback = '/'): Response
    {
        return Response::redirect(self::internalReferer($this->request) ?? $fallback);
    }

    public static function internalReferer(Request $request): ?string
    {
        $ref = $request->header('Referer');
        if ($ref === null) {
            return null;
        }
        $host = (string)($request->server['HTTP_HOST'] ?? '');
        $parts = parse_url($ref);
        if (!is_array($parts) || ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') !== $host) {
            return null;
        }
        $path = (string)($parts['path'] ?? '/');
        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    protected function authorize(string ...$permissions): void
    {
        if (!$this->user()->canAny(...$permissions)) {
            throw HttpException::forbidden();
        }
    }

    /**
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     * @return array<string,mixed>
     */
    protected function validate(array $rules, array $labels = []): array
    {
        return (new Validator($this->request->post, $labels))->validate($rules);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /** Paginación estándar: ?page=N, 25 por página. @return array{0:int,1:int,2:int} [page, perPage, offset] */
    protected function pagination(int $perPage = 25): array
    {
        $page = max(1, min(100000, (int)($this->request->int('page') ?? 1)));
        return [$page, $perPage, ($page - 1) * $perPage];
    }
}
