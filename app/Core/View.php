<?php

declare(strict_types=1);

namespace App\Core;

use App\Security\CurrentUser;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Plantillas Twig con autoescape HTML activado. Ninguna plantilla debe usar
 * el filtro |raw con datos de usuarios: el HTML de correos se sanea antes.
 */
final class View
{
    private Environment $twig;
    private ?Request $request = null;
    private ?CurrentUser $user = null;
    /** @var array{old:array<string,mixed>,errors:array<string,list<string>>}|null */
    private ?array $form = null;

    public function __construct(private readonly App $app)
    {
        $loader = new FilesystemLoader($app->root . '/templates');
        $this->twig = new Environment($loader, [
            'cache' => $app->isProduction() ? $app->storagePath('cache/twig') : false,
            'autoescape' => 'html',
            'strict_variables' => !$app->isProduction(),
            'auto_reload' => true,
        ]);
        $this->registerExtensions();
    }

    public function setContext(?Request $request, ?CurrentUser $user): void
    {
        $this->request = $request;
        $this->user = $user;
    }

    public function twig(): Environment
    {
        return $this->twig;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $session = $this->app->session();
        $globals = [
            'current_user' => $this->user,
            'current_path' => $this->request?->path ?? '/',
            'flashes' => $session->isStarted() ? $session->takeFlash() : [],
            'app_name' => 'UNIVERS365 Incidencias',
        ];
        return $this->twig->render($template, $data + $globals);
    }

    /** Renderiza una plantilla sin contexto de petición (correos). */
    public function renderPlain(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    private function formState(): array
    {
        if ($this->form === null) {
            $s = $this->app->session();
            $this->form = $s->isStarted() ? $s->takeForm() : ['old' => [], 'errors' => []];
        }
        return $this->form;
    }

    private function registerExtensions(): void
    {
        $t = $this->twig;
        $t->addFilter(new TwigFilter('fecha', static fn (?string $v) => Clock::format($v)));
        $t->addFilter(new TwigFilter('dia', static fn (?string $v) => Clock::format($v, false)));
        $t->addFilter(new TwigFilter('hace', static fn (?string $v) => Clock::ago($v)));
        $t->addFilter(new TwigFilter('minutos', static fn ($v) => Clock::minutesToHuman((int)$v)));
        $t->addFilter(new TwigFilter('eur', static fn ($v) => number_format((float)$v, 2, ',', '.') . ' €'));
        $t->addFilter(new TwigFilter('num', static fn ($v, int $d = 0) => number_format((float)$v, $d, ',', '.')));
        $t->addFilter(new TwigFilter('bytes', static function ($v) {
            $v = (int)$v;
            return $v >= 1048576 ? number_format($v / 1048576, 1, ',', '.') . ' MB' : number_format(max(1, intdiv($v, 1024)), 0, ',', '.') . ' KB';
        }));
        // Fecha local para inputs (date / datetime-local)
        $t->addFilter(new TwigFilter('input_date', static function (?string $v, bool $time = false) {
            $dt = Clock::fromDb($v);
            return $dt ? $dt->setTimezone(Clock::local())->format($time ? 'Y-m-d\TH:i' : 'Y-m-d') : '';
        }));
        // Texto plano con saltos de línea: se escapa y luego se convierten los saltos
        $t->addFilter(new TwigFilter('nl2br_safe', static fn (?string $v) => nl2br(htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), ['is_safe' => ['html']]));
        // Color de contraste para etiquetas con fondo configurable
        $t->addFilter(new TwigFilter('contrast', static function (?string $hex) {
            $hex = ltrim((string)$hex, '#');
            if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
                return '#000000';
            }
            [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));
            return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#111827' : '#FFFFFF';
        }));
        $t->addFilter(new TwigFilter('json_decode', static fn (?string $v) => $v === null ? null : json_decode($v, true)));
        $t->addFilter(new TwigFilter('safe_color', static fn (?string $hex) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$hex) ? $hex : '#6C757D'));

        $t->addFunction(new TwigFunction('csrf_token', fn () => $this->app->csrf()->token()));
        $t->addFunction(new TwigFunction('csrf_field', fn () => '<input type="hidden" name="_token" value="' . htmlspecialchars($this->app->csrf()->token(), ENT_QUOTES) . '">', ['is_safe' => ['html']]));
        $t->addFunction(new TwigFunction('can', fn (string $perm) => $this->user?->can($perm) ?? false));
        $t->addFunction(new TwigFunction('can_any', fn (string ...$perms) => $this->user?->canAny(...$perms) ?? false));
        $t->addFunction(new TwigFunction('asset', function (string $path) {
            $file = $this->app->root . '/public/assets/' . ltrim($path, '/');
            $v = is_file($file) ? substr(md5((string)filemtime($file)), 0, 8) : '0';
            return '/assets/' . ltrim($path, '/') . '?v=' . $v;
        }));
        $t->addFunction(new TwigFunction('old', function (string $field, mixed $default = '') {
            $old = $this->formState()['old'];
            return array_key_exists($field, $old) ? $old[$field] : $default;
        }));
        $t->addFunction(new TwigFunction('error', fn (string $field) => $this->formState()['errors'][$field][0] ?? null));
        $t->addFunction(new TwigFunction('has_errors', fn () => $this->formState()['errors'] !== []));
        $t->addFunction(new TwigFunction('active', fn (string $prefix, bool $exact = false) => $this->request !== null && ($exact ? $this->request->path === $prefix : ($this->request->path === $prefix || str_starts_with($this->request->path, rtrim($prefix, '/') . '/'))) ? 'active' : ''));
        $t->addFunction(new TwigFunction('query', function (array $changes = []) {
            $q = $this->request?->query ?? [];
            foreach ($changes as $k => $v) {
                if ($v === null) {
                    unset($q[$k]);
                } else {
                    $q[$k] = $v;
                }
            }
            $qs = http_build_query($q);
            return ($this->request?->path ?? '/') . ($qs !== '' ? '?' . $qs : '');
        }));
        $t->addFunction(new TwigFunction('unread_notifications', function () {
            if ($this->user === null) {
                return 0;
            }
            return (int)$this->app->db()->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$this->user->id]);
        }));
        $t->addFunction(new TwigFunction('setting', fn (string $key, mixed $default = null) => $this->app->settings()->get($key, $default)));
    }
}
