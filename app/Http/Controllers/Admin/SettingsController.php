<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Services\Attachments;

/** Ajustes generales. Las integraciones y el SLA tienen su propia pantalla. */
final class SettingsController extends Controller
{
    /** clave => [tipo, etiqueta, mínimo, máximo] */
    private const FIELDS = [
        'company.name' => ['string', 'Nombre de la empresa', 2, 120],
        'tickets.default_priority' => ['priority', 'Prioridad predeterminada', 0, 0],
        'tickets.auto_close_days' => ['int', 'Cierre automático de resueltas (días)', 0, 90],
        'tickets.ack_email' => ['bool', 'Acuse de recibo por correo al abrir una incidencia por correo', 0, 0],
        'notifications.email_enabled' => ['bool', 'Enviar avisos internos por correo', 0, 0],
        'workload.default_capacity' => ['int', 'Capacidad por defecto de un técnico (incidencias abiertas)', 1, 500],
        'security.password_min_length' => ['int', 'Longitud mínima de contraseña', 10, 64],
        'security.session_idle_minutes' => ['int', 'Cierre de sesión por inactividad (minutos)', 15, 480],
        'security.login_max_attempts' => ['int', 'Intentos fallidos antes de bloquear temporalmente', 3, 20],
        'security.reset_minutes' => ['int', 'Validez del enlace de recuperación (minutos)', 10, 120],
        'uploads.max_mb' => ['int', 'Tamaño máximo por adjunto (MB)', 1, 50],
        'uploads.allowed_extensions' => ['extensions', 'Extensiones permitidas', 0, 0],
        'backups.auto' => ['bool', 'Copia automática diaria (a partir de las 03:00)', 0, 0],
        'backups.include_files' => ['bool', 'Incluir adjuntos en las copias', 0, 0],
        'backups.keep' => ['int', 'Copias que se conservan', 3, 90],
    ];
    private const DEFAULTS = [
        'company.name' => 'UNIVERS365 TECHNOLOGY', 'tickets.default_priority' => 'normal', 'tickets.auto_close_days' => 7, 'tickets.ack_email' => true,
        'notifications.email_enabled' => true, 'workload.default_capacity' => 15, 'security.password_min_length' => 12, 'security.session_idle_minutes' => 60,
        'security.login_max_attempts' => 5, 'security.reset_minutes' => 30, 'uploads.max_mb' => 15, 'uploads.allowed_extensions' => [],
        'backups.auto' => false, 'backups.include_files' => true, 'backups.keep' => 14,
    ];

    public function index(): Response
    {
        $s = $this->app->settings();
        $values = [];
        foreach (self::DEFAULTS as $k => $def) {
            $values[$k] = $s->get($k, $def);
        }
        return $this->render('admin/settings.twig', [
            'fields' => self::FIELDS,
            'values' => $values,
            'priorities' => $this->db()->all('SELECT code, name FROM ticket_priorities WHERE active = 1 ORDER BY level'),
            'known_ext' => array_keys(Attachments::MIME_MAP),
            'php_upload_mb' => $this->iniMb('upload_max_filesize'),
            'php_post_mb' => $this->iniMb('post_max_size'),
        ]);
    }

    public function save(): Response
    {
        $s = $this->app->settings();
        $errors = [];
        $new = [];
        foreach (self::FIELDS as $key => [$type, $label, $min, $max]) {
            $field = str_replace('.', '__', $key);
            $raw = $this->request->post[$field] ?? null;
            switch ($type) {
                case 'bool':
                    $new[$key] = in_array($raw, ['1', 'on'], true);
                    break;
                case 'int':
                    if (!is_string($raw) || !preg_match('/^\d{1,4}$/', trim($raw)) || (int)$raw < $min || (int)$raw > $max) {
                        $errors[$field][] = "$label debe estar entre $min y $max.";
                        break;
                    }
                    $new[$key] = (int)$raw;
                    break;
                case 'string':
                    $v = is_string($raw) ? trim($raw) : '';
                    if (mb_strlen($v) < $min || mb_strlen($v) > $max) {
                        $errors[$field][] = "$label debe tener entre $min y $max caracteres.";
                        break;
                    }
                    $new[$key] = $v;
                    break;
                case 'priority':
                    if (!is_string($raw) || !$this->db()->value('SELECT 1 FROM ticket_priorities WHERE code = ? AND active = 1', [$raw])) {
                        $errors[$field][] = 'Elige una prioridad activa.';
                        break;
                    }
                    $new[$key] = $raw;
                    break;
                case 'extensions':
                    $list = array_values(array_intersect(array_keys(Attachments::MIME_MAP), is_array($raw) ? $raw : []));
                    if ($list === []) {
                        $errors[$field][] = 'Debe quedar al menos una extensión permitida.';
                        break;
                    }
                    $new[$key] = $list;
                    break;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $changed = [];
        foreach ($new as $k => $v) {
            if ($s->get($k, self::DEFAULTS[$k]) !== $v) {
                $s->set($k, $v, $this->user()->id);
                $changed[] = $k;
            }
        }
        $this->audit()->log('settings.updated', null, null, ['claves' => $changed]);
        $msg = $changed === [] ? 'No había cambios.' : 'Ajustes guardados.';
        if (($new['uploads.max_mb'] ?? 0) > min($this->iniMb('upload_max_filesize'), $this->iniMb('post_max_size'))) {
            $msg .= ' Aviso: el servidor PHP admite como máximo ' . min($this->iniMb('upload_max_filesize'), $this->iniMb('post_max_size')) . ' MB por subida; los archivos mayores fallarán hasta que se amplíe en IONOS.';
        }
        return $this->redirect('/admin/ajustes', $msg);
    }

    private function iniMb(string $key): int
    {
        $v = trim((string)ini_get($key));
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024,
            'k' => max(1, intdiv($n, 1024)),
            'm' => $n,
            default => max(1, intdiv($n, 1048576)),
        };
    }
}
