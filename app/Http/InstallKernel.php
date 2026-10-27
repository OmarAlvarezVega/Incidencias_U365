<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Services\Installer;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Instalador web. Solo responde mientras no exista config/config.php y,
 * además, exista storage/install.token: quien instala tiene que haber subido
 * ese fichero por FTP/SFTP y escribir su contenido en el formulario. Al
 * terminar se borra el token y se crea storage/installed.lock.
 */
final class InstallKernel
{
    private const COOKIE = 'u365_install';

    private Installer $installer;

    public function __construct(private readonly string $root)
    {
        $this->installer = new Installer($root);
    }

    public function handle(Request $request): Response
    {
        if ($this->installer->isLocked()) {
            return $this->page('install/locked.twig', ['reason' => 'installed'], 404);
        }
        $token = is_file($this->installer->tokenFile()) ? trim((string)file_get_contents($this->installer->tokenFile())) : '';
        if (strlen($token) < 32) {
            return $this->page('install/locked.twig', ['reason' => 'no_token'], 404);
        }
        if ($request->path !== '/instalar') {
            return Response::redirect('/instalar');
        }
        $https = $request->isHttps();
        $nonce = is_string($_COOKIE[self::COOKIE] ?? null) ? $_COOKIE[self::COOKIE] : "";
        $data = [
            'checks' => $this->installer->checks(),
            'can_install' => $this->installer->checksPass(),
            'errors' => [],
            'message' => null,
            'log' => [],
            'done' => false,
            'old' => ['app_url' => ($https ? 'https://' : 'http://') . preg_replace('/[^a-z0-9.:-]/i', '', (string)($request->server['HTTP_HOST'] ?? 'incidencias.univers365.com')), 'db_port' => '3306'],
            'https' => $https,
        ];
        if ($request->isPost()) {
            $post = $request->post;
            $data['old'] = array_intersect_key($post, array_flip(['app_url', 'db_host', 'db_port', 'db_name', 'db_user', 'first_name', 'last_name', 'email']));
            if ($nonce === '' || !hash_equals($nonce, (string)($post['_nonce'] ?? ''))) {
                $data['message'] = 'La sesión del formulario ha caducado. Vuelve a enviarlo.';
            } elseif (!hash_equals($token, trim((string)($post['install_token'] ?? '')))) {
                usleep(500000);
                $data['errors']['install_token'] = 'El código de instalación no coincide con el contenido de storage/install.token.';
            } else {
                $str = static fn (string $k) => is_string($post[$k] ?? null) ? trim((string)$post[$k]) : '';
                $raw = static fn (string $k) => is_string($post[$k] ?? null) ? (string)$post[$k] : '';
                try {
                    @set_time_limit(120);
                    $this->installer->install(
                        rtrim($str('app_url'), '/'),
                        ['host' => $str('db_host'), 'port' => (int)($str('db_port') ?: 3306), 'name' => $str('db_name'), 'user' => $str('db_user'), 'pass' => $raw('db_pass')],
                        ['first_name' => $str('first_name'), 'last_name' => $str('last_name'), 'email' => $str('email'), 'password' => $raw('password'), 'password_confirmation' => $raw('password_confirmation')],
                        static function (string $line) use (&$data) {
                            $data['log'][] = $line;
                        }
                    );
                    $data['done'] = true;
                } catch (ValidationException $e) {
                    $data['errors'] = array_map(static fn ($m) => $m[0] ?? '', $e->errors);
                    $data['message'] = $e->getMessage();
                } catch (\Throwable $e) {
                    error_log('Instalador: ' . $e->getMessage());
                    $data['message'] = 'La instalación se ha detenido: ' . $e->getMessage();
                }
            }
        }
        $nonce = bin2hex(random_bytes(16));
        $data['nonce'] = $nonce;
        $response = $this->page('install/index.twig', $data, 200);
        $response->withHeader('Set-Cookie', self::COOKIE . '=' . $nonce . '; Path=/instalar; HttpOnly; SameSite=Strict' . ($https ? '; Secure' : ''));
        return $response;
    }

    /** @param array<string,mixed> $data */
    private function page(string $template, array $data, int $status): Response
    {
        $twig = new Environment(new FilesystemLoader($this->root . '/templates'), ['autoescape' => 'html', 'strict_variables' => false]);
        $r = Response::html($twig->render($template, $data), $status);
        $r->withHeader('Cache-Control', 'no-store');
        $r->withHeader('X-Robots-Tag', 'noindex, nofollow');
        return $r;
    }
}
