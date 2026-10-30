<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controller;
use App\Services\Mail\EmailAccounts;
use App\Services\Mail\Mailer;
use App\Services\Mail\MailProcessor;

/**
 * Buzones de correo. Los secretos (contraseña o secreto de la aplicación de
 * Microsoft) se guardan cifrados y nunca se vuelven a mostrar: el formulario
 * solo permite sustituirlos.
 */
final class EmailController extends Controller
{
    public function index(): Response
    {
        $rows = $this->db()->all(
            "SELECT a.id, a.name, a.address, a.auth_type, a.active, a.is_default, a.last_polled_at, a.last_error, a.unknown_sender_policy,
                    (SELECT COUNT(*) FROM email_messages m WHERE m.account_id = a.id AND m.direction = 'inbound' AND m.received_at >= ?) AS last_7d,
                    (SELECT COUNT(*) FROM email_messages m WHERE m.account_id = a.id AND m.processing_status = 'review') AS review
               FROM email_accounts a ORDER BY a.is_default DESC, a.name",
            [Clock::now()->modify('-7 days')->format(Clock::DB_FORMAT)]
        );
        $cronOk = $this->db()->value("SELECT MAX(finished_at) FROM scheduled_task_runs WHERE task = 'email.poll' AND status = 'ok'");
        return $this->render('admin/email_index.twig', ['rows' => $rows, 'cron_last' => $cronOk]);
    }

    public function form(): Response
    {
        $acc = null;
        if ($this->request->param('id') !== '') {
            $acc = $this->account();
        }
        return $this->render('admin/email_form.twig', [
            'a' => $acc,
            'has_secret' => $acc !== null && $acc['imap_secret_enc'] !== null,
            'has_smtp_secret' => $acc !== null && $acc['smtp_secret_enc'] !== null,
        ]);
    }

    public function save(): Response
    {
        $db = $this->db();
        $acc = $this->request->param('id') !== '' ? $this->account() : null;
        $d = $this->validate([
            'name' => 'required|string|max:80',
            'address' => 'required|email',
            'auth_type' => 'required|in:password,oauth2_microsoft',
            'oauth_tenant_id' => 'string|max:64',
            'oauth_client_id' => 'string|max:64',
            'imap_host' => 'required|string|max:253',
            'imap_port' => 'required|int|min:1|max:65535',
            'imap_encryption' => 'required|in:ssl,starttls',
            'imap_username' => 'required|string|max:254',
            'imap_folder' => 'required|string|max:120',
            'processed_folder' => 'string|max:120',
            'smtp_host' => 'required|string|max:253',
            'smtp_port' => 'required|int|min:1|max:65535',
            'smtp_encryption' => 'required|in:ssl,starttls',
            'smtp_username' => 'string|max:254',
            'from_name' => 'required|string|max:120',
            'unknown_sender_policy' => 'required|in:review,create_pending',
            'poll_interval_minutes' => 'required|int|min:1|max:60',
            'max_attachment_mb' => 'required|int|min:1|max:50',
            'active' => 'bool',
            'is_default' => 'bool',
        ], [
            'name' => 'El nombre', 'address' => 'La dirección', 'imap_host' => 'El servidor IMAP', 'imap_port' => 'El puerto IMAP', 'imap_username' => 'El usuario IMAP',
            'imap_folder' => 'La carpeta', 'smtp_host' => 'El servidor SMTP', 'smtp_port' => 'El puerto SMTP', 'from_name' => 'El nombre del remitente',
            'poll_interval_minutes' => 'El intervalo', 'max_attachment_mb' => 'El tamaño máximo',
        ]);
        foreach (['imap_host', 'smtp_host'] as $h) {
            if (!preg_match('/^(?=.{3,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/i', (string)$d[$h])) {
                throw ValidationException::single($h, 'Servidor no válido (solo el nombre, sin https:// ni puerto).');
            }
        }
        if ($d['auth_type'] === 'oauth2_microsoft') {
            foreach (['oauth_tenant_id' => 'El id de inquilino (tenant)', 'oauth_client_id' => 'El id de la aplicación (client)'] as $k => $label) {
                if ($d[$k] === null || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$d[$k])) {
                    throw ValidationException::single($k, "$label debe ser un GUID como 00000000-0000-0000-0000-000000000000.");
                }
            }
        }
        if ($db->value('SELECT 1 FROM email_accounts WHERE address = ? AND id <> ?', [$d['address'], $acc['id'] ?? 0])) {
            throw ValidationException::single('address', 'Ya hay un buzón con esa dirección.');
        }
        // Secretos: solo se tocan si se escribe uno nuevo. Nunca se recortan.
        $secret = is_string($this->request->post['secret'] ?? null) ? (string)$this->request->post['secret'] : '';
        $smtpSecret = is_string($this->request->post['smtp_secret'] ?? null) ? (string)$this->request->post['smtp_secret'] : '';
        $row = $d;
        unset($row['is_default']);
        $row['smtp_username'] = $d['smtp_username'] ?? $d['imap_username'];
        $row['active'] = $d['active'] ? 1 : 0;
        $row['updated_at'] = Clock::nowDb();
        if ($secret !== '') {
            $row['imap_secret_enc'] = $this->app->crypto()->encrypt($secret);
        }
        if ($smtpSecret !== '' && $d['auth_type'] === 'password') {
            $row['smtp_secret_enc'] = $this->app->crypto()->encrypt($smtpSecret);
        }
        if ($d['active'] && $secret === '' && ($acc === null || $acc['imap_secret_enc'] === null)) {
            throw ValidationException::single('secret', $d['auth_type'] === 'password' ? 'Escribe la contraseña para poder activar el buzón.' : 'Escribe el secreto de la aplicación para poder activar el buzón.');
        }
        if ($acc !== null && ($acc['imap_host'] !== $d['imap_host'] || $acc['imap_username'] !== $d['imap_username'] || $acc['imap_folder'] !== $d['imap_folder'])) {
            // Otro buzón o carpeta: los UID anteriores no sirven
            $row['last_uid'] = null;
            $row['last_uidvalidity'] = null;
        }
        $id = $db->transaction(function () use ($db, $acc, $row, $d) {
            if ($acc === null) {
                $id = $db->insert('email_accounts', $row + ['created_at' => Clock::nowDb()]);
            } else {
                $id = (int)$acc['id'];
                $db->update('email_accounts', $row, 'id = ?', [$id]);
            }
            if ($d['is_default'] || !$db->value('SELECT 1 FROM email_accounts WHERE is_default = 1 AND id <> ?', [$id])) {
                $db->run('UPDATE email_accounts SET is_default = (id = ?)', [$id]);
            }
            return $id;
        });
        $this->audit()->log($acc === null ? 'email_account.created' : 'email_account.updated', 'email_account', $id, [
            'direccion' => $d['address'], 'autenticacion' => $d['auth_type'], 'activo' => $d['active'], 'secreto_cambiado' => $secret !== '' || $smtpSecret !== '',
        ]);
        return $this->redirect("/admin/correo/$id", 'Buzón guardado. Usa «Probar conexión» antes de dejarlo activo.');
    }

    public function test(): Response
    {
        $acc = $this->account();
        $results = [];
        try {
            $imap = $this->app->make(EmailAccounts::class)->imap($acc);
            $info = $imap->select((string)$acc['imap_folder']);
            $imap->logout();
            $results[] = ['ok' => true, 'msg' => 'IMAP correcto: carpeta «' . $acc['imap_folder'] . '» con ' . $info['exists'] . ' mensajes.'];
        } catch (\Throwable $e) {
            $results[] = ['ok' => false, 'msg' => 'IMAP: ' . Logger::redact($e->getMessage())];
        }
        try {
            $this->app->make(Mailer::class)->test($acc);
            $results[] = ['ok' => true, 'msg' => 'SMTP correcto: autenticación aceptada.'];
        } catch (\Throwable $e) {
            $results[] = ['ok' => false, 'msg' => 'SMTP: ' . Logger::redact($e->getMessage())];
        }
        $to = trim($this->request->str('test_to'));
        if ($to !== '' && $results[1]['ok']) {
            try {
                $this->app->make(Mailer::class)->send($acc, $to, 'Prueba de ' . $this->app->settings()->get('company.name', 'UNIVERS365 TECHNOLOGY'), "Correo de prueba enviado desde la plataforma de incidencias.\nNo es necesario responder.", null, [], [], true);
                $results[] = ['ok' => true, 'msg' => "Correo de prueba enviado a $to."];
            } catch (\Throwable $e) {
                $results[] = ['ok' => false, 'msg' => 'Envío de prueba: ' . Logger::redact($e->getMessage())];
            }
        }
        $this->audit()->log('email_account.tested', 'email_account', (int)$acc['id'], ['resultado' => array_column($results, 'ok')]);
        foreach ($results as $r) {
            $this->flash($r['ok'] ? 'success' : 'danger', $r['msg']);
        }
        return $this->redirect('/admin/correo/' . $acc['id']);
    }

    public function poll(): Response
    {
        $acc = $this->account();
        if (!(int)$acc['active']) {
            throw new ValidationException([], 'Activa el buzón antes de procesarlo.');
        }
        $n = $this->app->make(MailProcessor::class)->poll($acc, microtime(true) + 25);
        $acc = $this->account();
        if ($acc['last_error']) {
            $this->flash('danger', 'Error al leer el buzón: ' . $acc['last_error']);
            return $this->redirect('/admin/correo/' . $acc['id'] . '/registro');
        }
        return $this->redirect('/admin/correo/' . $acc['id'] . '/registro', $n === 1 ? 'Se ha procesado 1 correo.' : "Se han procesado $n correos.");
    }

    public function log(): Response
    {
        $acc = $this->account();
        [$page, $per, $offset] = $this->pagination(50);
        $total = (int)$this->db()->value('SELECT COUNT(*) FROM email_processing_log WHERE account_id = ?', [(int)$acc['id']]);
        $rows = $this->db()->all(
            "SELECT l.*, m.subject, m.from_address, m.ticket_id FROM email_processing_log l LEFT JOIN email_messages m ON m.id = l.email_message_id
              WHERE l.account_id = ? ORDER BY l.id DESC LIMIT $per OFFSET $offset",
            [(int)$acc['id']]
        );
        $messages = $this->db()->all(
            "SELECT id, direction, from_address, to_addresses, subject, processing_status, ticket_id, received_at, sent_at, error
               FROM email_messages WHERE account_id = ? ORDER BY id DESC LIMIT 30",
            [(int)$acc['id']]
        );
        return $this->render('admin/email_log.twig', ['a' => $acc, 'rows' => $rows, 'messages' => $messages, 'total' => $total, 'page' => $page, 'per' => $per]);
    }

    /** @return array<string,mixed> */
    private function account(): array
    {
        return $this->app->make(EmailAccounts::class)->find($this->request->paramInt('id')) ?? throw HttpException::notFound();
    }
}
