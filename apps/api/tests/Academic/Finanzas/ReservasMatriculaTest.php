<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\ReservaMatricula;
use App\Services\ReservasMatricula;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Fase 3: reservas de cupo con vencimiento. Una reserva retiene el cupo de una matrícula pendiente mientras la persona paga; al vencer SIN
 * ningún pago en el libro, la matrícula se anula y el cupo vuelve (con actor «Sistema»). Con un pago —confirmado o por confirmar— nunca
 * vence. SQLite en memoria: no mide la concurrencia real de PostgreSQL (`lockForUpdate` se ignora aquí).
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-21 10:00:00', 'America/Lima'));
});

/** Curso con un cupo ya ocupado por la matrícula pendiente que se crea después (`cupos_disponibles` = máximo − 1). */
function rvCurso(string $codigo = 'RSV-1'): Curso
{
    return Curso::create(['codigo' => $codigo, 'nombre' => 'Curso de reservas', 'descripcion' => 'x', 'categoria' => 'tecnologia',
        'duracion_horas' => 20, 'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 10,
        'cupos_disponibles' => 9, 'estado' => 'activo']);
}

function rvMatricula(Curso $curso, string $dni, string $estado = 'pendiente'): Matricula
{
    $estudiante = Estudiante::create(['dni' => $dni, 'nombres' => 'Ana', 'apellidos' => 'Quispe '.$dni, 'estado' => 'activo']);

    return Matricula::create(['estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(), 'estado' => $estado]);
}

/** Una reserva activa que ya venció (pasaron más de 2 días). */
function rvVencida(Matricula $matricula): ReservaMatricula
{
    $reserva = app(ReservasMatricula::class)->reservar($matricula);
    test()->travel(3)->days();

    return $reserva->refresh();
}

/** Registra un pago en el libro de la matrícula (obligación de S/ 300 con una transacción en el estado indicado). */
function rvPago(Matricula $matricula, string $estado): void
{
    $obligacion = DB::table('obligaciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'matricula_id' => $matricula->id,
        'concepto' => 'Pago de prueba', 'plan' => 'completo', 'monto_total' => '300.00', 'moneda' => 'PEN', 'estado' => 'vigente',
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('cuotas_obligacion')->insert(['obligacion_pago_id' => $obligacion, 'numero' => 1, 'monto' => '300.00', 'vence' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('metodos_pago')->insertOrIgnore(['codigo' => 'yape', 'nombre' => 'Yape', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('transacciones_pago')->insert(['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $obligacion,
        'metodo_pago_id' => (int) DB::table('metodos_pago')->where('codigo', 'yape')->value('id'), 'tipo' => 'pago', 'estado' => $estado,
        'monto' => '100.00', 'fecha_pago' => now()->toDateString(), 'reversa_de_id' => null, 'created_at' => now(), 'updated_at' => now()]);
}

function rvCupos(Curso $curso): int
{
    return (int) $curso->fresh()->cupos_disponibles;
}

// --------------------------------------------------------------------------------------------------------- crear

it('reserva el cupo de una matrícula pendiente con referencia única, vencimiento de 2 días y enlace cifrado', function (): void {
    $m = rvMatricula(rvCurso(), '70000001');

    $reserva = app(ReservasMatricula::class)->reservar($m);

    expect($reserva->estado)->toBe('activa')
        ->and($reserva->referencia)->toMatch('/^HRS-[A-HJ-NP-Z2-9]{6}$/')
        ->and($reserva->expira_at->equalTo(now()->addDays(2)))->toBeTrue();

    $token = $reserva->token();
    expect($token)->toHaveLength(40)
        ->and(DB::table('reservas_matricula')->value('token_hash'))->toBe(hash('sha256', $token))
        // El enlace no se guarda en claro y nunca sale al serializar el modelo.
        ->and(DB::table('reservas_matricula')->value('token_cifrado'))->not->toContain($token)
        ->and($reserva->toArray())->not->toHaveKeys(['token_hash', 'token_cifrado']);
    expect(AuditLog::where('action', 'reserva_creada')->count())->toBe(1);
});

it('es idempotente: reservar otra vez devuelve la misma reserva con el mismo enlace', function (): void {
    $m = rvMatricula(rvCurso(), '70000002');

    $primera = app(ReservasMatricula::class)->reservar($m);
    $this->travel(1)->hours();
    $segunda = app(ReservasMatricula::class)->reservar($m);

    expect($segunda->id)->toBe($primera->id)
        ->and($segunda->expira_at->equalTo($primera->expira_at))->toBeTrue()
        ->and($segunda->token())->toBe($primera->token())
        ->and(ReservaMatricula::count())->toBe(1);
});

it('no reserva una matrícula que no está pendiente', function (string $estado): void {
    $m = rvMatricula(rvCurso(), '70000003', $estado);

    expect(fn () => app(ReservasMatricula::class)->reservar($m))->toThrow(ValidationException::class);
    expect(ReservaMatricula::count())->toBe(0);
})->with(['pagada', 'anulada']);

it('la duración nunca baja de 2 días, aunque se configure menos', function (): void {
    config(['reservas.dias' => 1]);
    expect(ReservasMatricula::dias())->toBe(2);
    config(['reservas.dias' => 0]);
    expect(ReservasMatricula::dias())->toBe(2);
    config(['reservas.dias' => 5]);
    expect(ReservasMatricula::dias())->toBe(5);
});

// ------------------------------------------------------------------------------------------------------ vencer

it('vence una reserva sin pagos: anula la matrícula, devuelve el cupo una sola vez y audita como Sistema', function (): void {
    $curso = rvCurso();
    $m = rvMatricula($curso, '70000010');
    $reserva = rvVencida($m);
    expect(rvCupos($curso))->toBe(9);

    $r = app(ReservasMatricula::class)->expirarVencidas();

    expect($r)->toMatchArray(['vencidas' => 1, 'con_pago' => 0, 'cerradas' => 0, 'errores' => 0])
        ->and($reserva->fresh()->estado)->toBe('vencida')
        ->and($reserva->fresh()->vencida_at)->not->toBeNull()
        ->and($m->fresh()->estado)->toBe('anulada')
        ->and($m->fresh()->motivo_anulacion)->toBe(ReservasMatricula::MOTIVO_VENCIMIENTO)
        ->and(rvCupos($curso))->toBe(10);

    // Sin usuario: el panel lo muestra como «Sistema».
    expect(AuditLog::where('action', 'matricula_anulada')->latest('id')->first()?->user_id)->toBeNull()
        ->and(AuditLog::where('action', 'reserva_vencida')->count())->toBe(1);
});

it('no toca una reserva que todavía no venció', function (): void {
    $curso = rvCurso();
    $m = rvMatricula($curso, '70000011');
    $reserva = app(ReservasMatricula::class)->reservar($m);
    $this->travel(47)->hours();

    $r = app(ReservasMatricula::class)->expirarVencidas();

    expect($r)->toMatchArray(['vencidas' => 0, 'con_pago' => 0, 'cerradas' => 0, 'omitidas' => 0, 'errores' => 0])
        ->and($reserva->fresh()->estado)->toBe('activa')
        ->and($m->fresh()->estado)->toBe('pendiente')
        ->and(rvCupos($curso))->toBe(9);
});

it('nunca vence una reserva que ya tiene un pago, confirmado o por confirmar', function (string $estadoPago): void {
    $curso = rvCurso();
    $m = rvMatricula($curso, '70000012');
    $reserva = rvVencida($m);
    rvPago($m, $estadoPago);

    $r = app(ReservasMatricula::class)->expirarVencidas();

    expect($r)->toMatchArray(['vencidas' => 0, 'con_pago' => 1, 'errores' => 0])
        ->and($reserva->fresh()->estado)->toBe('con_pago')
        ->and($m->fresh()->estado)->toBe('pendiente')
        ->and(rvCupos($curso))->toBe(9)
        ->and(AuditLog::where('action', 'reserva_con_pago')->count())->toBe(1);
})->with(['pendiente', 'confirmada']);

it('cierra la reserva si la matrícula ya no está pendiente, sin devolver el cupo otra vez', function (): void {
    $curso = rvCurso();
    $m = rvMatricula($curso, '70000013');
    $reserva = rvVencida($m);
    // El panel la anuló antes (y ya devolvió su cupo): el vencimiento no debe devolverlo de nuevo.
    $m->forceFill(['estado' => 'anulada'])->save();
    $curso->forceFill(['cupos_disponibles' => 10])->save();

    $r = app(ReservasMatricula::class)->expirarVencidas();

    expect($r)->toMatchArray(['vencidas' => 0, 'cerradas' => 1, 'errores' => 0])
        ->and($reserva->fresh()->estado)->toBe('cerrada')
        ->and(rvCupos($curso))->toBe(10);
});

it('repetir la ejecución no cambia nada ni devuelve el cupo dos veces', function (): void {
    $curso = rvCurso();
    $m = rvMatricula($curso, '70000014');
    rvVencida($m);

    app(ReservasMatricula::class)->expirarVencidas();
    $segunda = app(ReservasMatricula::class)->expirarVencidas();

    expect($segunda)->toMatchArray(['vencidas' => 0, 'con_pago' => 0, 'cerradas' => 0, 'omitidas' => 0, 'errores' => 0])
        ->and(rvCupos($curso))->toBe(10)
        ->and(AuditLog::where('action', 'reserva_vencida')->count())->toBe(1);
});

it('un fallo en una reserva se cuenta y no impide procesar las demás', function (): void {
    $cursoRoto = rvCurso('RSV-ROTO');
    $cursoBueno = rvCurso('RSV-OK');
    $rota = rvMatricula($cursoRoto, '70000015');
    $buena = rvMatricula($cursoBueno, '70000016');
    app(ReservasMatricula::class)->reservar($rota);
    app(ReservasMatricula::class)->reservar($buena);
    $this->travel(3)->days();

    // Solo la primera falla al guardarse (un error real a mitad de la anulación, con el cupo ya devuelto dentro de su transacción).
    $evento = 'eloquent.updating: '.Matricula::class;
    Event::listen($evento, static function (Matricula $m) use ($rota): void {
        if ($m->id === $rota->id) {
            throw new RuntimeException('Fallo simulado al anular.');
        }
    });
    try {
        $r = app(ReservasMatricula::class)->expirarVencidas();
    } finally {
        Event::forget($evento);
    }

    expect($r)->toMatchArray(['vencidas' => 1, 'errores' => 1])
        ->and($buena->fresh()->estado)->toBe('anulada')
        ->and(rvCupos($cursoBueno))->toBe(10)
        // La que falló se revirtió completa: sigue activa, su matrícula pendiente y su cupo intacto.
        ->and($rota->reserva()->first()?->estado)->toBe('activa')
        ->and($rota->fresh()->estado)->toBe('pendiente')
        ->and(rvCupos($cursoRoto))->toBe(9);
});

it('respeta el límite por ejecución', function (): void {
    foreach (['70000020', '70000021', '70000022'] as $dni) {
        app(ReservasMatricula::class)->reservar(rvMatricula(rvCurso('RSV-'.$dni), $dni));
    }
    $this->travel(3)->days();

    expect(app(ReservasMatricula::class)->expirarVencidas(2)['vencidas'])->toBe(2)
        ->and(app(ReservasMatricula::class)->contarVencidas())->toBe(1);
});

// ------------------------------------------------------------------------------------------------------ renovar

it('renovar da una ventana completa desde ahora, nunca la acorta y reanuda una reserva con pago', function (): void {
    $m = rvMatricula(rvCurso(), '70000030');
    $reserva = app(ReservasMatricula::class)->reservar($m);

    $this->travel(30)->hours();
    $renovada = app(ReservasMatricula::class)->renovar($reserva);
    expect($renovada->expira_at->equalTo(now()->addDays(2)))->toBeTrue();

    // Con una duración configurada menor no se acorta una ventana más larga.
    $ventana = $renovada->expira_at->copy();
    $this->travel(1)->hours();
    config(['reservas.dias' => 2]);
    expect(app(ReservasMatricula::class)->renovar($renovada)->expira_at->greaterThanOrEqualTo($ventana))->toBeTrue();

    // Una reserva «con pago» (su pago fue rechazado) se reanuda como activa.
    $renovada->forceFill(['estado' => 'con_pago'])->save();
    expect(app(ReservasMatricula::class)->renovar($renovada)->estado)->toBe('activa');
    expect(AuditLog::where('action', 'reserva_renovada')->count())->toBe(3);
});

it('no renueva una reserva vencida o cuya matrícula ya no está pendiente', function (): void {
    $m = rvMatricula(rvCurso(), '70000031');
    $reserva = rvVencida($m);
    app(ReservasMatricula::class)->expirarVencidas();

    expect(fn () => app(ReservasMatricula::class)->renovar($reserva->fresh()))->toThrow(ValidationException::class);

    $m2 = rvMatricula(rvCurso('RSV-2'), '70000032');
    $r2 = app(ReservasMatricula::class)->reservar($m2);
    $m2->forceFill(['estado' => 'pagada'])->save();
    expect(fn () => app(ReservasMatricula::class)->renovar($r2))->toThrow(ValidationException::class);
});

it('reactiva con una ventana y un enlace nuevos una reserva vencida cuya matrícula volvió a estar pendiente', function (): void {
    $m = rvMatricula(rvCurso(), '70000033');
    $vieja = rvVencida($m);
    $tokenViejo = $vieja->token();
    app(ReservasMatricula::class)->expirarVencidas();
    // El personal reactiva la matrícula (vuelve a pendiente) y pide reservar de nuevo.
    Matricula::query()->whereKey($m->id)->update(['estado' => 'pendiente']);

    $nueva = app(ReservasMatricula::class)->reservar($m->fresh());

    expect($nueva->id)->toBe($vieja->id)
        ->and($nueva->estado)->toBe('activa')
        ->and($nueva->vencida_at)->toBeNull()
        ->and($nueva->referencia)->toBe($vieja->referencia)
        ->and($nueva->token())->not->toBe($tokenViejo);
});

// ------------------------------------------------------------------------------------------------------ comando

it('el comando vence las reservas, informa el resultado y con --dry-run no modifica nada', function (): void {
    $curso = rvCurso();
    $m = rvMatricula($curso, '70000040');
    rvVencida($m);

    $this->artisan('horus:reservas-expirar', ['--dry-run' => true])
        ->expectsOutputToContain('1 reserva(s) vencida(s) por procesar')->assertExitCode(0);
    expect($m->fresh()->estado)->toBe('pendiente')->and(rvCupos($curso))->toBe(9);

    $this->artisan('horus:reservas-expirar')->expectsOutputToContain('1 vencida(s) y cupo devuelto')->assertExitCode(0);
    expect($m->fresh()->estado)->toBe('anulada')->and(rvCupos($curso))->toBe(10);
});

it('el comando rechaza un límite inválido', function (): void {
    $this->artisan('horus:reservas-expirar', ['--limit' => '0'])->expectsOutputToContain('entre 1 y 1000')->assertExitCode(1);
    $this->artisan('horus:reservas-expirar', ['--limit' => '5000'])->assertExitCode(1);
});

it('el comando está programado cada 15 minutos sin solaparse', function (): void {
    $evento = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'horus:reservas-expirar'));

    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('*/15 * * * *')
        ->and($evento->withoutOverlapping)->toBeTrue();
});
