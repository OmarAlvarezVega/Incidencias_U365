<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\ValidationException;
use Throwable;

/**
 * Ciclo de una petición: ruta → sesión → CSRF → autenticación → permiso →
 * controlador. Todos los errores acaban en una respuesta controlada sin
 * rutas internas ni SQL.
 *
 * Opciones de ruta:
 *  - public  => true   no exige sesión iniciada
 *  - guest   => true   solo para usuarios sin sesión (login)
 *  - session => false  no abre sesión (webhooks, cron); implica CSRF desactivado
 *  - csrf    => false  desactiva CSRF (solo endpoints con otra autenticación)
 *  - perm    => 'x|y'  exige alguno de los permisos
 */
final class Kernel
{
    public function __construct(private readonly App $app, private readonly Router $router)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->dispatch($request);
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                return Response::json(['ok' => false, 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
            }
            $session = $this->app->session();
            if ($session->isStarted()) {
                $session->flashForm($request->post, $e->errors);
                $session->flash('danger', $e->getMessage());
            }
            return Response::redirect(Controller::internalReferer($request) ?? '/');
        } catch (HttpException $e) {
            return $this->errorResponse($request, $e->status, $e->getMessage());
        } catch (Throwable $e) {
            $ref = $this->app->logger()->error(get_class($e) . ': ' . $e->getMessage(), [
                'file' => $e->getFile() . ':' . $e->getLine(),
                'path' => $request->path,
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
            ]);
            $msg = 'Se ha producido un error interno. Referencia: ' . $ref;
            if (!$this->app->isProduction()) {
                $msg .= ' · ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
            }
            return $this->errorResponse($request, 500, $msg);
        }
    }

    private function dispatch(Request $request): Response
    {
        $route = $this->router->match($request->method, $request->path);
        $opts = $route['opts'];
        $request->routeParams = $route['params'];
        $https = $request->isHttps();

        if ($this->app->isProduction() && !$https && ($opts['allow_http'] ?? false) !== true) {
            $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($request->server['HTTP_HOST'] ?? ''));
            return new Response('', 301, ['Location' => 'https://' . $host . $request->uri()]);
        }

        $useSession = ($opts['session'] ?? true) !== false;
        if ($useSession) {
            $this->app->session()->start($https);
        }

        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $useSession && ($opts['csrf'] ?? true) !== false) {
            $this->app->csrf()->verify($request);
        }

        $user = $useSession ? $this->app->auth()->user() : null;
        $this->app->audit()->setContext($user?->id, $request->ip());
        $this->app->view()->setContext($request, $user);

        if (($opts['guest'] ?? false) && $user !== null) {
            return Response::redirect('/');
        }
        if (!($opts['public'] ?? false) && $user === null) {
            if ($request->wantsJson()) {
                return Response::json(['ok' => false, 'message' => 'Sesión caducada. Recarga la página.'], 401);
            }
            if ($request->method === 'GET' && $useSession) {
                $this->app->session()->set('intended', $request->uri());
            }
            return Response::redirect('/login');
        }
        if ($user !== null && $user->mustChangePassword && !($opts['allow_password_change'] ?? false)) {
            $this->app->session()->flash('warning', 'Debes cambiar tu contraseña antes de continuar.');
            return Response::redirect('/perfil/contrasena');
        }
        if (isset($opts['perm'])) {
            if ($user === null || !$user->canAny(...explode('|', (string)$opts['perm']))) {
                throw HttpException::forbidden();
            }
        }

        [$class, $method] = $route['handler'];
        $controller = new $class($this->app, $request);
        $response = $controller->$method();
        if (!$response instanceof Response) {
            throw new \LogicException("El controlador $class::$method no devolvió una respuesta.");
        }
        if ($useSession) {
            $response->withHeader('Cache-Control', $response->headers['Cache-Control'] ?? 'no-store, private');
        }
        return $response;
    }

    private function errorResponse(Request $request, int $status, string $message): Response
    {
        if ($request->wantsJson() || str_starts_with($request->path, '/webhooks/') || str_starts_with($request->path, '/cron/')) {
            return Response::json(['ok' => false, 'message' => $message], $status);
        }
        if ($status === 419 && $this->app->session()->isStarted()) {
            $this->app->session()->flash('warning', $message);
            return Response::redirect(Controller::internalReferer($request) ?? '/');
        }
        try {
            $user = $this->app->session()->isStarted() ? $this->app->auth()->user() : null;
            $this->app->view()->setContext($request, $user);
            $html = $this->app->view()->render('errors/error.twig', ['status' => $status, 'message' => $message]);
        } catch (Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>Error</title><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
        }
        return Response::html($html, $status);
    }
}
