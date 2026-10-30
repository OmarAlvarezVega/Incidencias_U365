<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Clock;
use App\Core\Logger;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Security\Crypto;
use App\Services\WhatsApp\WhatsAppService;

/**
 * WhatsApp Business Cloud API (oficial de Meta). El token de acceso, el secreto
 * de la aplicación y el token de verificación se guardan cifrados y no se
 * muestran nunca en la interfaz.
 */
final class WhatsAppController extends Controller
{
    public function index(): Response
    {
        $s = $this->app->settings();
        $newVerifyToken = $this->app->session()->get('wa_new_verify_token');
        $this->app->session()->remove('wa_new_verify_token');
        $number = $this->db()->one('SELECT * FROM whatsapp_numbers ORDER BY active DESC, id LIMIT 1');
        $stats = $this->db()->one(
            "SELECT (SELECT COUNT(*) FROM whatsapp_webhook_events WHERE received_at >= ?) AS events_7d,
                    (SELECT COUNT(*) FROM whatsapp_webhook_events WHERE processing_status = 'failed') AS failed,
                    (SELECT MAX(received_at) FROM whatsapp_webhook_events) AS last_event,
                    (SELECT COUNT(*) FROM whatsapp_messages WHERE direction = 'outbound' AND status = 'failed' AND created_at >= ?) AS send_failed_7d",
            [Clock::now()->modify('-7 days')->format('Y-m-d H:i:s'), Clock::now()->modify('-7 days')->format(Clock::DB_FORMAT)]
        );
        return $this->render('admin/whatsapp.twig', [
            'enabled' => $s->bool('whatsapp.enabled', false),
            'api_version' => $s->get('whatsapp.api_version', 'v21.0'),
            'ambiguity' => $s->get('whatsapp.ambiguity_policy', 'ask_customer'),
            'tpl' => (array)$s->get('whatsapp.reopen_template', []),
            'has_app_secret' => $s->hasSecret('whatsapp.app_secret'),
            'has_verify_token' => $s->hasSecret('whatsapp.verify_token'),
            'number' => $number,
            'has_token' => $number !== null && $number['access_token_enc'] !== null,
            'webhook_url' => $this->app->url('/webhooks/whatsapp'),
            'stats' => $stats,
            'new_verify_token' => $newVerifyToken,
        ]);
    }

    public function save(): Response
    {
        $s = $this->app->settings();
        $uid = $this->user()->id;
        $d = $this->validate([
            'enabled' => 'bool',
            'api_version' => 'required|string|max:8',
            'ambiguity_policy' => 'required|in:ask_customer,latest',
            'phone_number_id' => 'string|max:40',
            'waba_id' => 'string|max:40',
            'display_phone' => 'phone|max:30',
            'tpl_name' => 'string|max:120',
            'tpl_lang' => 'string|max:10',
            'tpl_with_text' => 'bool',
        ], ['api_version' => 'La versión de la API', 'phone_number_id' => 'El Phone Number ID', 'waba_id' => 'El WABA ID', 'display_phone' => 'El número', 'tpl_name' => 'El nombre de la plantilla']);
        if (!preg_match('/^v\d{1,3}\.\d$/', (string)$d['api_version'])) {
            throw ValidationException::single('api_version', 'Formato vNN.N, por ejemplo v21.0.');
        }
        foreach (['phone_number_id', 'waba_id'] as $k) {
            if ($d[$k] !== null && !preg_match('/^\d{5,25}$/', (string)$d[$k])) {
                throw ValidationException::single($k, 'Solo dígitos, tal como aparece en el panel de Meta.');
            }
        }
        if ($d['tpl_name'] !== null && !preg_match('/^[a-z0-9_]{1,120}$/', (string)$d['tpl_name'])) {
            throw ValidationException::single('tpl_name', 'Nombre de plantilla de Meta: minúsculas, números y guion bajo.');
        }
        $raw = fn (string $k) => is_string($this->request->post[$k] ?? null) ? trim((string)$this->request->post[$k]) : '';
        $token = $raw('access_token');
        $appSecret = $raw('app_secret');

        $db = $this->db();
        $number = $db->one('SELECT * FROM whatsapp_numbers ORDER BY active DESC, id LIMIT 1');
        if ($d['phone_number_id'] !== null) {
            if ($d['waba_id'] === null || $d['display_phone'] === null) {
                throw ValidationException::single('waba_id', 'Completa también el WABA ID y el número visible.');
            }
            $row = ['phone_number_id' => $d['phone_number_id'], 'waba_id' => $d['waba_id'], 'display_phone' => $d['display_phone'], 'updated_at' => Clock::nowDb(), 'active' => 1];
            if ($token !== '') {
                $row['access_token_enc'] = $this->app->crypto()->encrypt($token);
            }
            if ($number === null) {
                $db->insert('whatsapp_numbers', $row + ['created_at' => Clock::nowDb()]);
            } else {
                $db->update('whatsapp_numbers', $row, 'id = ?', [(int)$number['id']]);
            }
        }
        if ($appSecret !== '') {
            $s->setSecret('whatsapp.app_secret', $appSecret, $uid);
        }
        if ($this->request->bool('regenerate_verify_token') || !$s->hasSecret('whatsapp.verify_token')) {
            $vt = Crypto::randomToken(24);
            $s->setSecret('whatsapp.verify_token', $vt, $uid);
            // Se muestra una sola vez para pegarlo en Meta
            $this->app->session()->set('wa_new_verify_token', $vt);
        }
        $s->set('whatsapp.api_version', $d['api_version'], $uid);
        $s->set('whatsapp.ambiguity_policy', $d['ambiguity_policy'], $uid);
        $s->set('whatsapp.reopen_template', $d['tpl_name'] === null ? null : ['name' => $d['tpl_name'], 'lang' => $d['tpl_lang'] ?: 'es', 'with_text' => $d['tpl_with_text']], $uid);
        if ($d['enabled']) {
            $num = $db->one('SELECT * FROM whatsapp_numbers WHERE active = 1 LIMIT 1');
            if ($num === null || $num['access_token_enc'] === null || !$s->hasSecret('whatsapp.app_secret')) {
                throw new ValidationException([], 'Para activar WhatsApp faltan el número, el token de acceso o el secreto de la aplicación. Se ha guardado el resto.');
            }
        }
        $s->set('whatsapp.enabled', $d['enabled'], $uid);
        $this->audit()->log('whatsapp.settings', null, null, [
            'activo' => $d['enabled'], 'token_cambiado' => $token !== '', 'secreto_cambiado' => $appSecret !== '', 'plantilla' => $d['tpl_name'],
        ]);
        return $this->redirect('/admin/whatsapp', 'Configuración de WhatsApp guardada.');
    }

    public function test(): Response
    {
        try {
            $info = $this->app->make(WhatsAppService::class)->api()->phoneInfo();
            $this->flash('success', 'Conexión correcta con Meta: ' . ($info['display_phone_number'] ?? '¿?') . ' · ' . ($info['verified_name'] ?? '') . ' · calidad ' . ($info['quality_rating'] ?? 'desconocida') . '.');
        } catch (\Throwable $e) {
            $this->flash('danger', 'Meta respondió con error: ' . Logger::redact($e->getMessage()));
        }
        $this->audit()->log('whatsapp.tested');
        return $this->redirect('/admin/whatsapp');
    }
}
