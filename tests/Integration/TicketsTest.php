<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Services\TicketAccess;
use App\Services\TicketService;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnv;

final class TicketsTest extends TestCase
{
    public function testNumeracionCorrelativaPorAnio(): void
    {
        $svc = TestEnv::app()->make(TicketService::class);
        $admin = TestEnv::actor(TestEnv::adminId());
        $a = $svc->create(['subject' => 'Primera'], $admin);
        $b = $svc->create(['subject' => 'Segunda'], $admin);
        $na = (string)TestEnv::db()->value('SELECT ticket_number FROM tickets WHERE id = ?', [$a]);
        $nb = (string)TestEnv::db()->value('SELECT ticket_number FROM tickets WHERE id = ?', [$b]);
        $this->assertMatchesRegularExpression('/^INC-\d{4}-\d{6}$/', $na);
        $this->assertSame((int)substr($na, -6) + 1, (int)substr($nb, -6));
    }

    public function testConResponsableNaceAsignada(): void
    {
        $tech = TestEnv::user('technician', 'tecnico.asignado@pruebas.test');
        $svc = TestEnv::app()->make(TicketService::class);
        $id = $svc->create(['subject' => 'Con responsable', 'owner_user_id' => $tech], TestEnv::actor(TestEnv::adminId()));
        $code = TestEnv::db()->value('SELECT s.code FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.id = ?', [$id]);
        $this->assertSame('assigned', $code);
    }

    public function testUnTecnicoNoVeLasIncidenciasDeOtro(): void
    {
        $ana = TestEnv::user('technician', 'ana@pruebas.test');
        $luis = TestEnv::user('technician', 'luis@pruebas.test');
        $svc = TestEnv::app()->make(TicketService::class);
        $deAna = $svc->create(['subject' => 'Privada de Ana', 'owner_user_id' => $ana], TestEnv::actor(TestEnv::adminId()));

        $access = TestEnv::app()->make(TicketAccess::class);
        $this->assertNotNull($access->visible(TestEnv::actor($ana), $deAna));
        $this->assertNotNull($access->visible(TestEnv::actor(TestEnv::adminId()), $deAna));
        $this->assertNull($access->visible(TestEnv::actor($luis), $deAna));
        $this->assertFalse($access->canModify(TestEnv::actor($luis), $access->visible(TestEnv::actor($ana), $deAna), 'tickets.edit'));

        try {
            $access->find(TestEnv::actor($luis), $deAna);
            $this->fail('Debería responder 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function testElColaboradorSiLaVe(): void
    {
        $ana = TestEnv::user('technician', 'ana@pruebas.test');
        $eva = TestEnv::user('technician', 'eva@pruebas.test');
        $svc = TestEnv::app()->make(TicketService::class);
        $id = $svc->create(['subject' => 'Compartida', 'owner_user_id' => $ana, 'collaborators' => [$eva]], TestEnv::actor(TestEnv::adminId()));
        $this->assertNotNull(TestEnv::app()->make(TicketAccess::class)->visible(TestEnv::actor($eva), $id));
    }

    public function testBloqueoOptimistaRechazaVersionesAntiguas(): void
    {
        $svc = TestEnv::app()->make(TicketService::class);
        $admin = TestEnv::actor(TestEnv::adminId());
        $id = $svc->create(['subject' => 'Original'], $admin);
        $t = TestEnv::db()->one('SELECT * FROM tickets WHERE id = ?', [$id]);
        $svc->update($t, ['subject' => 'Cambio 1'], $admin, (int)$t['lock_version']);
        $this->expectException(\App\Core\ValidationException::class);
        $svc->update($t, ['subject' => 'Cambio 2'], $admin, (int)$t['lock_version']);
    }
}
