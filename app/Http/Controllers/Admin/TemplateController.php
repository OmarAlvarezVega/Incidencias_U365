<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Services\Templates;

/**
 * Plantillas de correo y WhatsApp. Solo admiten marcadores {{ variable }} de
 * una lista cerrada: el texto nunca se ejecuta como código.
 */
final class TemplateController extends Controller
{
    private const SAMPLE = [
        'ticket.number' => 'INC-2026-000123', 'ticket.subject' => 'No imprime la impresora de recepción', 'ticket.status' => 'En curso',
        'ticket.priority' => 'Alta', 'ticket.url' => 'https://incidencias.univers365.com/incidencias/123', 'client.name' => 'Cliente de ejemplo S.L.',
        'contact.name' => 'Laura', 'agent.name' => 'Técnico de ejemplo', 'message' => "Hemos revisado el equipo.\nYa debería funcionar.",
        'tickets.list' => "1) INC-2026-000120 · Correo no sincroniza\n2) INC-2026-000123 · No imprime", 'reset.url' => 'https://incidencias.univers365.com/restablecer/…', 'reset.minutes' => '30',
    ];

    public function index(): Response
    {
        $svc = $this->app->make(Templates::class);
        $selected = $this->request->str('plantilla', 'email:ticket_created_ack');
        $catalog = $svc->catalog();
        $current = null;
        foreach ($catalog as $t) {
            if ($t['key'] === $selected) {
                $current = $t;
            }
        }
        $current ??= $catalog[0];
        $vars = self::SAMPLE + ['company.name' => (string)$this->app->settings()->get('company.name', 'UNIVERS365 TECHNOLOGY')];
        $preview = [
            'subject' => Templates::fill((string)$current['subject'], $vars, true),
            'body' => Templates::fill((string)$current['body'], $vars, false),
        ];
        return $this->render('admin/templates.twig', [
            'catalog' => $catalog,
            'current' => $current,
            'preview' => $preview,
            'variables' => Templates::VARIABLES,
        ]);
    }

    public function save(): Response
    {
        $key = $this->request->str('key');
        if (!isset(Templates::DEFAULTS[$key])) {
            throw HttpException::notFound();
        }
        [$channel, $code] = explode(':', $key, 2);
        $db = $this->db();
        if ($this->request->bool('restore')) {
            $db->run('DELETE FROM message_templates WHERE channel = ? AND code = ?', [$channel, $code]);
            $this->audit()->log('template.restored', 'template', null, ['plantilla' => $key]);
            return $this->redirect('/admin/plantillas?plantilla=' . rawurlencode($key), 'Plantilla restaurada a la versión de fábrica.');
        }
        $d = $this->validate(['subject' => 'string|max:250', 'body' => 'required|text|max:20000'], ['subject' => 'El asunto', 'body' => 'El texto']);
        if ($channel === 'email' && $d['subject'] === null) {
            throw ValidationException::single('subject', 'El asunto es obligatorio en las plantillas de correo.');
        }
        // Avisa de marcadores mal escritos en lugar de enviarlos tal cual al cliente
        preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', ($d['subject'] ?? '') . "\n" . $d['body'], $m);
        $unknown = array_values(array_diff(array_unique($m[1]), array_keys(Templates::VARIABLES)));
        if ($unknown !== []) {
            throw ValidationException::single('body', 'Variables desconocidas: ' . implode(', ', array_map(static fn ($v) => '{{ ' . $v . ' }}', $unknown)) . '.');
        }
        if ($key === 'email:password_reset' && !str_contains($d['body'], 'reset.url')) {
            throw ValidationException::single('body', 'La plantilla de recuperación debe incluir {{ reset.url }}.');
        }
        $db->run(
            'INSERT INTO message_templates (code, channel, subject, body, active, updated_by, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), active = 1, updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$code, $channel, $channel === 'email' ? $d['subject'] : null, $d['body'], $this->user()->id, Clock::nowDb()]
        );
        $this->audit()->log('template.saved', 'template', null, ['plantilla' => $key]);
        return $this->redirect('/admin/plantillas?plantilla=' . rawurlencode($key), 'Plantilla guardada.');
    }
}
