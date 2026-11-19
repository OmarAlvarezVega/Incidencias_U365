<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Response;
use App\Http\Controller;
use App\Security\RateLimiter;
use App\Services\WhatsApp\WhatsAppService;

/**
 * Webhook de WhatsApp Cloud API. Sin sesión ni CSRF: se autentica por la
 * firma HMAC de Meta. Responde rápido; el trabajo real va a la cola.
 */
final class WebhookController extends Controller
{
    public function verify(): Response
    {
        $wa = $this->app->make(WhatsAppService::class);
        $challenge = $wa->verifyChallenge(
            (string)($this->request->query['hub_mode'] ?? ''),
            (string)($this->request->query['hub_verify_token'] ?? ''),
            (string)($this->request->query['hub_challenge'] ?? '')
        );
        if ($challenge === null) {
            return Response::text('Forbidden', 403);
        }
        return Response::text($challenge);
    }

    public function receive(): Response
    {
        if (!$this->app->rateLimiter()->hit(RateLimiter::key('wa-webhook', $this->request->ip()), 600, 60)) {
            return Response::text('Too Many Requests', 429);
        }
        $raw = $this->request->rawBody();
        if (strlen($raw) > 1048576) {
            return Response::text('Payload Too Large', 413);
        }
        $wa = $this->app->make(WhatsAppService::class);
        if (!$wa->validSignature($raw, $this->request->header('X-Hub-Signature-256'))) {
            $this->app->logger()->warning('Webhook de WhatsApp con firma no válida desde ' . $this->request->ip());
            return Response::text('Invalid signature', 401);
        }
        if (!$this->app->settings()->bool('whatsapp.enabled', false)) {
            return Response::text('OK');
        }
        $wa->store($raw);
        return Response::text('OK');
    }
}
