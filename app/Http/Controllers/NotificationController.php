<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Response;
use App\Http\Controller;

final class NotificationController extends Controller
{
    public function index(): Response
    {
        [$page, $per, $offset] = $this->pagination(30);
        $u = $this->user();
        $total = (int)$this->db()->value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$u->id]);
        $items = $this->db()->all("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT $per OFFSET $offset", [$u->id]);
        return $this->render('notifications/index.twig', compact('items', 'total', 'page', 'per'));
    }

    public function recent(): Response
    {
        $u = $this->user();
        $unread = (int)$this->db()->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$u->id]);
        if ($this->request->bool('solo_contador')) {
            return $this->json(['unread' => $unread]);
        }
        $items = array_map(static fn ($n) => [
            'id' => (int)$n['id'],
            'title' => $n['title'],
            'body' => $n['body'],
            'read' => $n['read_at'] !== null,
            'when' => Clock::ago($n['created_at']),
        ], $this->db()->all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$u->id]));
        return $this->json(['unread' => $unread, 'items' => $items]);
    }

    public function open(): Response
    {
        $u = $this->user();
        $n = $this->db()->one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$this->request->paramInt('id'), $u->id]);
        if ($n === null) {
            throw HttpException::notFound();
        }
        $this->db()->run('UPDATE notifications SET read_at = COALESCE(read_at, ?) WHERE id = ?', [Clock::nowDb(), (int)$n['id']]);
        return $this->redirect($n['ticket_id'] !== null ? '/incidencias/' . (int)$n['ticket_id'] : '/notificaciones');
    }

    public function readAll(): Response
    {
        $this->db()->run('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Clock::nowDb(), $this->user()->id]);
        return $this->back('/notificaciones');
    }
}
