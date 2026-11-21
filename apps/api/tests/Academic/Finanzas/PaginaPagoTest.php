<?php

declare(strict_types=1);

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\ReservaMatricula;
use App\Services\ReservasMatricula;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Fase 3: página de pago de una reserva. Lectura pública por enlace secreto (BFF) y enlace de pago para el personal (panel).
 * El enlace es el único permiso de la lectura pública: nunca expone datos personales. SQLite en memoria.
 */

const PP_TOKEN_BFF = 'token-de-servicio-del-bff-para-pruebas-0123456789';

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-21 10:00:00', 'America/Lima'));
    config(['horus-web.service_token' => PP_TOKEN_BFF, 'horus-web.url' => 'https://horus.test',
        'pagos-manuales.yape.numero' => '+51 900 111 222', 'pagos-manuales.yape.titular' => 'Titular de Prueba']);
});

function ppMatricula(string $estado = 'pendiente', string $dni = '71000001'): Matricula
{
    $curso = Curso::create(['codigo' => 'PP-'.$dni, 'nombre' => 'Excel avanzado', 'descripcion' => 'x', 'categoria' => 'tecnologia',
        'duracion_horas' => 20, 'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 10,
        'cupos_disponibles' => 9, 'estado' => 'activo']);
    $e = Estudiante::create(['dni' => $dni, 'nombres' => 'María José', 'apellidos' => 'Quispe Rojas', 'email' => "maria.{$dni}@example.test",
        'telefono' => '987654321', 'estado' => 'activo']);

    return Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(), 'estado' => $estado]);
}

/** @return array{0: Matricula, 1: ReservaMatricula, 2: string} */
function ppReserva(string $dni = '71000001'): array
{
    $m = ppMatricula('pendiente', $dni);
    $reserva = app(ReservasMatricula::class)->reservar($m);

    return [$m, $reserva, $reserva->token()];
}

function ppObligacion(Matricula $m, string $total = '300.00', ?string $pago = null, string $estadoPago = 'confirmada'): void
{
    $o = DB::table('obligaciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'matricula_id' => $m->id, 'concepto' => 'Pago',
        'plan' => 'completo', 'monto_total' => $total, 'moneda' => 'PEN', 'estado' => 'vigente', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('cuotas_obligacion')->insert(['obligacion_pago_id' => $o, 'numero' => 1, 'monto' => $total, 'vence' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now()]);
    if ($pago !== null) {
        DB::table('metodos_pago')->insertOrIgnore(['codigo' => 'yape', 'nombre' => 'Yape', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('transacciones_pago')->insert(['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $o,
            'metodo_pago_id' => (int) DB::table('metodos_pago')->where('codigo', 'yape')->value('id'), 'tipo' => 'pago', 'estado' => $estadoPago,
            'monto' => $pago, 'fecha_pago' => now()->toDateString(), 'reversa_de_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
}

function ppVer(string $token, bool $conToken = true)
{
    return test()->getJson('/api/public/v1/pagos/'.$token, $conToken ? ['X-Horus-Web-Token' => PP_TOKEN_BFF] : []);
}

// ------------------------------------------------------------------------------------------- lectura pública

it('muestra la referencia, el vencimiento, el curso y los medios de cobro de una reserva activa', function (): void {
    [, $reserva, $token] = ppReserva();

    $r = ppVer($token)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $r->assertJsonPath('data.estado', 'activa')
        ->assertJsonPath('data.vigente', true)
        ->assertJsonPath('data.referencia', $reserva->referencia)
        ->assertJsonPath('data.curso', 'Excel avanzado')
        ->assertJsonPath('data.edicion', null)
        ->assertJsonPath('data.metodos.yape', ['numero' => '+51 900 111 222', 'titular' => 'Titular de Prueba']);
    // Vence en 2 días, en hora de Lima (UTC−5).
    expect($r->json('data.vence_en'))->toBe('2026-11-23T10:00:00-05:00');
});

it('nunca expone datos personales: solo el primer nombre', function (): void {
    [, , $token] = ppReserva();

    $r = ppVer($token)->assertOk();

    expect($r->json('data.estudiante'))->toBe('María');
    $r->assertDontSee('71000001')->assertDontSee('Quispe')->assertDontSee('maria.71000001@example.test')->assertDontSee('987654321');
    expect(array_keys($r->json('data')))->toBe(['estado', 'vigente', 'referencia', 'vence_en', 'estudiante', 'curso', 'edicion', 'monto', 'metodos']);
});

it('un enlace desconocido, mal formado o de otra longitud responde siempre 404', function (string $token): void {
    ppReserva();

    ppVer($token)->assertNotFound();
})->with([
    'desconocido de 40' => [str_repeat('a', 40)],
    'muy corto' => ['abc123'],
    'muy largo' => [str_repeat('a', 41)],
    'con símbolos' => [str_repeat('a', 39).'-'],
]);

it('exige el token de servicio del BFF', function (): void {
    [, , $token] = ppReserva();

    ppVer($token, conToken: false)->assertUnauthorized();
    $this->getJson('/api/public/v1/pagos/'.$token, ['X-Horus-Web-Token' => 'otro-token-otro-token-otro-token-otro!'])->assertUnauthorized();
});

it('informa el importe de su propia matrícula y lo ya pagado o por confirmar', function (): void {
    [$m, , $token] = ppReserva();
    ppObligacion($m, '300.00', '100.00', 'confirmada');

    ppVer($token)->assertOk()->assertJsonPath('data.monto', [
        'moneda' => 'PEN', 'total' => '300.00', 'pagado' => '100.00', 'por_confirmar' => '0.00', 'saldo' => '200.00',
    ]);
});

it('un pago por confirmar descuenta del saldo pero no figura como pagado', function (): void {
    [$m, , $token] = ppReserva();
    ppObligacion($m, '300.00', '120.00', 'pendiente');

    ppVer($token)->assertOk()->assertJsonPath('data.monto.pagado', '0.00')
        ->assertJsonPath('data.monto.por_confirmar', '120.00')->assertJsonPath('data.monto.saldo', '180.00');
});

it('sin obligación de pago el importe es null, y sin Yape configurado ese medio no se ofrece', function (): void {
    config(['pagos-manuales.yape.numero' => '']);
    [, , $token] = ppReserva();

    ppVer($token)->assertOk()->assertJsonPath('data.monto', null)->assertJsonPath('data.metodos.yape', null);
});

it('una reserva activa cuyo plazo ya pasó se muestra como vencida aunque el comando no la procesó', function (): void {
    [, , $token] = ppReserva();
    $this->travel(3)->days();

    ppVer($token)->assertOk()->assertJsonPath('data.estado', 'vencida')->assertJsonPath('data.vigente', false);
});

it('refleja los demás estados reales de la reserva', function (string $estado): void {
    [, $reserva, $token] = ppReserva();
    $reserva->forceFill(['estado' => $estado])->save();

    ppVer($token)->assertOk()->assertJsonPath('data.estado', $estado)->assertJsonPath('data.vigente', false);
})->with(['con_pago', 'vencida', 'cerrada']);

it('el token de una reserva no abre la de otra persona', function (): void {
    [, $a, $tokenA] = ppReserva('71000001');
    [, $b, $tokenB] = ppReserva('71000002');

    expect($tokenA)->not->toBe($tokenB);
    ppVer($tokenA)->assertJsonPath('data.referencia', $a->referencia);
    ppVer($tokenB)->assertJsonPath('data.referencia', $b->referencia);
});

// ------------------------------------------------------------------------------------------- enlace para el personal

it('el personal con matriculas.crear crea el enlace de pago y lo vuelve a consultar', function (): void {
    $m = ppMatricula();
    $secretaria = $this->usuarioConRol('secretaria');

    $creada = $this->panelComo($secretaria, 'POST', "academico/matriculas/{$m->id}/reserva")->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
    $token = $creada->json('data.token');

    expect($creada->json('data.referencia'))->toMatch('/^HRS-[A-HJ-NP-Z2-9]{6}$/')
        ->and($creada->json('data.estado'))->toBe('activa')
        ->and($creada->json('data.vigente'))->toBeTrue()
        ->and($token)->toHaveLength(40)
        ->and($creada->json('data.enlace'))->toBe('https://horus.test/pagar/'.$token);

    // Idempotente: la misma referencia y el mismo enlace.
    $otra = $this->panelComo($secretaria, 'POST', "academico/matriculas/{$m->id}/reserva")->assertOk();
    expect($otra->json('data.referencia'))->toBe($creada->json('data.referencia'))->and($otra->json('data.token'))->toBe($token);
    $this->panelComo($secretaria, 'GET', "academico/matriculas/{$m->id}/reserva")->assertOk()->assertJsonPath('data.token', $token);

    // Y ese enlace abre la página pública de pago.
    ppVer($token)->assertOk()->assertJsonPath('data.referencia', $creada->json('data.referencia'));
});

it('sin la reserva, consultarla da 404; con una matrícula que no está pendiente, crearla da 422', function (): void {
    $secretaria = $this->usuarioConRol('secretaria');
    $m = ppMatricula();
    $this->panelComo($secretaria, 'GET', "academico/matriculas/{$m->id}/reserva")->assertNotFound();

    $pagada = ppMatricula('pagada', '71000009');
    $this->panelComo($secretaria, 'POST', "academico/matriculas/{$pagada->id}/reserva")->assertUnprocessable();
    $this->panelComo($secretaria, 'POST', 'academico/matriculas/999999/reserva')->assertNotFound();
});

it('el enlace es un secreto: solo lo ve quien tiene matriculas.crear', function (): void {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    $m = ppMatricula();
    app(ReservasMatricula::class)->reservar($m);

    // Primero sin sesión: `actingAs` mantiene la sesión en las peticiones siguientes. Sin sesión no entra; tampoco una cuenta con otro
    // permiso del panel pero sin matriculas.crear.
    $this->panelComo(null, 'GET', "academico/matriculas/{$m->id}/reserva")->assertUnauthorized();
    $solo = App\Models\User::factory()->create();
    $solo->givePermissionTo(['panel.academico.acceder', 'matriculas.ver']);
    foreach (['GET' => 'reserva', 'POST' => 'reserva', 'POST ' => 'reserva/renovar'] as $metodo => $ruta) {
        $this->panelComo($solo, trim($metodo), "academico/matriculas/{$m->id}/{$ruta}")->assertForbidden();
    }
});

it('el personal renueva la reserva y reanuda una con pago', function (): void {
    $m = ppMatricula();
    $secretaria = $this->usuarioConRol('secretaria');
    $this->panelComo($secretaria, 'POST', "academico/matriculas/{$m->id}/reserva")->assertOk();

    // Sin viajar en el tiempo (la sesión del panel caduca): que la ventana se amplíe y nunca se acorte lo prueba ReservasMatriculaTest.
    $r = $this->panelComo($secretaria, 'POST', "academico/matriculas/{$m->id}/reserva/renovar")->assertOk();
    expect($r->json('data.vence_en'))->toBe('2026-11-23T10:00:00-05:00');

    $m->reserva()->first()?->forceFill(['estado' => 'con_pago'])->save();
    $this->panelComo($secretaria, 'POST', "academico/matriculas/{$m->id}/reserva/renovar")->assertOk()->assertJsonPath('data.estado', 'activa');

    // Una reserva vencida ya no se renueva: hay que generar otra.
    $m->reserva()->first()?->forceFill(['estado' => 'vencida'])->save();
    $this->panelComo($secretaria, 'POST', "academico/matriculas/{$m->id}/reserva/renovar")->assertUnprocessable();
});
