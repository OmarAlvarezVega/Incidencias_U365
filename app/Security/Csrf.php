<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;

/**
 * Token sincronizado por sesión, exigido en toda petición que modifica datos,
 * más comprobación de la cabecera Origin cuando el navegador la envía.
 */
final class Csrf
{
    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $t = $this->session->get('_csrf');
        if (!is_string($t) || strlen($t) !== 64) {
            $t = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $t);
        }
        return $t;
    }

    public function rotate(): void
    {
        $this->session->set('_csrf', bin2hex(random_bytes(32)));
    }

    public function verify(Request $request): void
    {
        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== 'null') {
            $host = (string)($request->server['HTTP_HOST'] ?? '');
            $originHost = parse_url($origin, PHP_URL_HOST);
            $originPort = parse_url($origin, PHP_URL_PORT);
            $originHostPort = $originHost . ($originPort ? ':' . $originPort : '');
            if ($host === '' || !hash_equals(strtolower($host), strtolower((string)$originHostPort))) {
                throw new HttpException(419);
            }
        }
        $sent = $request->post['_token'] ?? $request->header('X-CSRF-Token') ?? '';
        $expected = $this->session->get('_csrf');
        if (!is_string($sent) || !is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
            throw new HttpException(419);
        }
    }
}
