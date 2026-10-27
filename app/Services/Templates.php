<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * Plantillas de mensajes al cliente y avisos internos.
 *
 * Se usan marcadores {{ variable }} de una lista blanca, sustituidos como
 * texto (no se ejecuta Twig con contenido editable desde la web: así una
 * plantilla mal escrita o malintencionada no puede leer nada fuera de la
 * lista). Si la plantilla no existe en la base de datos se usa la de fábrica.
 */
final class Templates
{
    /** Variables disponibles y su descripción, para la pantalla de edición. */
    public const VARIABLES = [
        'ticket.number' => 'Número de incidencia (INC-2026-000001)',
        'ticket.subject' => 'Asunto',
        'ticket.status' => 'Estado actual',
        'ticket.priority' => 'Prioridad',
        'ticket.url' => 'Enlace interno a la incidencia (solo avisos a empleados)',
        'client.name' => 'Nombre del cliente',
        'contact.name' => 'Nombre del contacto',
        'agent.name' => 'Empleado que responde',
        'message' => 'Texto de la respuesta o del aviso',
        'company.name' => 'Nombre de la empresa',
        'tickets.list' => 'Lista de incidencias abiertas (WhatsApp, elección)',
        'reset.url' => 'Enlace para restablecer la contraseña',
        'reset.minutes' => 'Minutos de validez del enlace',
    ];

    /** @var array<string,array{name:string,channel:string,subject:?string,body:string}> */
    public const DEFAULTS = [
        'email:ticket_created_ack' => [
            'name' => 'Acuse de recibo de nueva incidencia',
            'channel' => 'email',
            'subject' => '[{{ ticket.number }}] Hemos recibido tu solicitud: {{ ticket.subject }}',
            'body' => "Hola {{ contact.name }}:\n\nHemos registrado tu solicitud con el número {{ ticket.number }}.\nUn técnico la revisará lo antes posible. Para añadir información, responde a este correo sin cambiar el asunto.\n\nUn saludo,\n{{ company.name }}",
        ],
        'email:ticket_reply' => [
            'name' => 'Respuesta de un técnico al cliente',
            'channel' => 'email',
            'subject' => 'Re: [{{ ticket.number }}] {{ ticket.subject }}',
            'body' => "{{ message }}\n\n--\n{{ agent.name }}\n{{ company.name }}\nReferencia: {{ ticket.number }}. Responde a este correo sin cambiar el asunto.",
        ],
        'email:ticket_resolved' => [
            'name' => 'Aviso de incidencia resuelta',
            'channel' => 'email',
            'subject' => '[{{ ticket.number }}] Incidencia resuelta: {{ ticket.subject }}',
            'body' => "Hola {{ contact.name }}:\n\nHemos marcado como resuelta la incidencia {{ ticket.number }}.\nSi el problema continúa, responde a este correo y la reabriremos.\n\nUn saludo,\n{{ company.name }}",
        ],
        'email:staff_notification' => [
            'name' => 'Aviso interno a empleados',
            'channel' => 'email',
            'subject' => '[{{ ticket.number }}] {{ message }}',
            'body' => "{{ message }}\n\nIncidencia: {{ ticket.number }} · {{ ticket.subject }}\nCliente: {{ client.name }}\nPrioridad: {{ ticket.priority }} · Estado: {{ ticket.status }}\n\nAbrir: {{ ticket.url }}",
        ],
        'email:password_reset' => [
            'name' => 'Restablecer contraseña',
            'channel' => 'email',
            'subject' => 'Restablecer tu contraseña de {{ company.name }}',
            'body' => "Hola:\n\nSe ha solicitado restablecer la contraseña de tu cuenta. Si no lo has pedido tú, ignora este correo.\n\nEnlace (válido {{ reset.minutes }} minutos, un solo uso):\n{{ reset.url }}\n\n{{ company.name }}",
        ],
        'whatsapp:ticket_created_ack' => [
            'name' => 'WhatsApp: acuse de nueva incidencia',
            'channel' => 'whatsapp',
            'subject' => null,
            'body' => "Hemos registrado tu solicitud con el número {{ ticket.number }}. Te responderemos por aquí.",
        ],
        'whatsapp:ticket_reply' => [
            'name' => 'WhatsApp: respuesta de un técnico',
            'channel' => 'whatsapp',
            'subject' => null,
            'body' => "{{ message }}\n\n— {{ agent.name }} ({{ ticket.number }})",
        ],
        'whatsapp:choose_ticket' => [
            'name' => 'WhatsApp: elegir incidencia abierta',
            'channel' => 'whatsapp',
            'subject' => null,
            'body' => "Tienes varias incidencias abiertas:\n{{ tickets.list }}\n\nResponde con el número de la lista para añadir tu mensaje a esa incidencia, o con NUEVA para abrir otra.",
        ],
        'whatsapp:ask_identification' => [
            'name' => 'WhatsApp: remitente no identificado',
            'channel' => 'whatsapp',
            'subject' => null,
            'body' => "Hola. No encontramos este número en nuestros clientes. Indícanos el nombre de tu empresa y tu nombre, y un técnico revisará tu mensaje.",
        ],
    ];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string,scalar|null> $vars
     * @return array{subject:string,body:string}
     */
    public function render(string $channel, string $code, array $vars): array
    {
        $row = $this->app->db()->one('SELECT subject, body FROM message_templates WHERE channel = ? AND code = ? AND active = 1', [$channel, $code]);
        $def = self::DEFAULTS["$channel:$code"] ?? null;
        if ($row === null && $def === null) {
            throw new \InvalidArgumentException("Plantilla desconocida: $channel:$code");
        }
        $subject = (string)($row['subject'] ?? $def['subject'] ?? '');
        $body = (string)($row['body'] ?? $def['body']);
        $vars += ['company.name' => (string)$this->app->settings()->get('company.name', 'UNIVERS365 TECHNOLOGY')];
        return [
            'subject' => self::fill($subject, $vars, true),
            'body' => self::fill($body, $vars, false),
        ];
    }

    /** @param array<string,scalar|null> $vars */
    public static function fill(string $text, array $vars, bool $singleLine): string
    {
        $out = (string)preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/', static function (array $m) use ($vars, $singleLine) {
            if (!array_key_exists($m[1], self::VARIABLES) && !array_key_exists($m[1], $vars)) {
                return $m[0];
            }
            $v = (string)($vars[$m[1]] ?? '');
            return $singleLine ? trim((string)preg_replace('/\s+/', ' ', $v)) : $v;
        }, $text);
        return $singleLine ? mb_substr($out, 0, 250) : $out;
    }

    /** Lista para la administración: fábrica + personalizadas. @return list<array<string,mixed>> */
    public function catalog(): array
    {
        $custom = [];
        foreach ($this->app->db()->all('SELECT * FROM message_templates') as $r) {
            $custom[$r['channel'] . ':' . $r['code']] = $r;
        }
        $out = [];
        foreach (self::DEFAULTS as $key => $def) {
            [$channel, $code] = explode(':', $key, 2);
            $c = $custom[$key] ?? null;
            $out[] = [
                'key' => $key,
                'code' => $code,
                'channel' => $channel,
                'name' => $def['name'],
                'subject' => $c['subject'] ?? $def['subject'],
                'body' => $c['body'] ?? $def['body'],
                'customized' => $c !== null,
                'active' => $c === null ? true : (bool)$c['active'],
                'updated_at' => $c['updated_at'] ?? null,
                'default_subject' => $def['subject'],
                'default_body' => $def['body'],
            ];
        }
        return $out;
    }
}
