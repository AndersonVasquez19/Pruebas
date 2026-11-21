<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Matricula;
use App\Models\ReservaMatricula;
use App\Support\Entrada;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Reservas de cupo con vencimiento (Fase 3, pagos asistidos).
 *
 * Una reserva retiene el cupo de una matrícula `pendiente` mientras la persona paga: lleva una referencia única que se escribe en el
 * pago y un enlace secreto a la página de pago. No crea matrículas ni ocupa cupo por sí misma: la matrícula pendiente ya ocupa su
 * cupo (ver CLAUDE.md, «Matrículas, cursos y estudiantes»); la reserva solo le pone fecha de vencimiento.
 *
 * Al vencer SIN ningún pago en el libro, la matrícula se anula con {@see GestionMatriculas::anular()} (la misma operación del panel:
 * bloqueos en orden, cupo devuelto una sola vez y auditoría) con actor «Sistema». Si ya hay un pago en el libro —confirmado o por
 * confirmar— no vence: pasa a `con_pago`. Nunca se anula una matrícula con dinero.
 */
final class ReservasMatricula
{
    /** Motivo que queda en la matrícula anulada por vencimiento. */
    public const MOTIVO_VENCIMIENTO = 'Reserva vencida sin pago.';

    /** Sin 0/O, 1/I/L: la referencia se dicta y se copia a mano. */
    private const ALFABETO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly GestionMatriculas $matriculas) {}

    /** Días que dura una reserva: el valor configurado, con un mínimo de 2. */
    public static function dias(): int
    {
        return max(2, Entrada::entero(config('reservas.dias', 2)));
    }

    /**
     * Reserva el cupo de una matrícula pendiente. Idempotente: si ya tiene una reserva activa o con pago, la devuelve sin cambios.
     * Si la anterior venció o se cerró y la matrícula volvió a estar pendiente, la reactiva con una ventana y un enlace nuevos.
     *
     * @throws ValidationException si la matrícula no está pendiente
     */
    public function reservar(Matricula $matricula, ?int $actor = null): ReservaMatricula
    {
        return DB::transaction(function () use ($matricula, $actor): ReservaMatricula {
            $actual = Matricula::query()->lockForUpdate()->findOrFail($matricula->id);
            if ($actual->estado !== 'pendiente') {
                throw ValidationException::withMessages(['matricula' => 'Solo una matrícula pendiente puede reservar cupo.']);
            }

            $reserva = ReservaMatricula::query()->where('matricula_id', $actual->id)->lockForUpdate()->first();
            if ($reserva !== null && in_array($reserva->estado, [ReservaMatricula::ACTIVA, ReservaMatricula::CON_PAGO], true)) {
                return $reserva;
            }

            $token = Str::random(40);
            if ($reserva === null) {
                $reserva = new ReservaMatricula;
                $reserva->matricula_id = $actual->id;
                $reserva->referencia = $this->referenciaNueva();
            }
            $reserva->forceFill([
                'estado' => ReservaMatricula::ACTIVA,
                'expira_at' => now()->addDays(self::dias()),
                'vencida_at' => null,
                'token_hash' => hash('sha256', $token),
                'token_cifrado' => Crypt::encryptString($token),
                'creada_por' => $actor,
            ])->save();

            AuditLog::record('reserva_creada', ReservaMatricula::class, $reserva->id, [
                'matricula_id' => $actual->id,
                'referencia' => $reserva->referencia,
                'expira_at' => $reserva->expira_at->toIso8601String(),
            ], $actor);

            return $reserva;
        });
    }

    /**
     * Amplía la reserva: nueva ventana completa desde ahora (nunca la acorta). Sirve también para reanudar una reserva `con_pago`
     * cuyo pago fue rechazado.
     *
     * @throws ValidationException si la reserva venció o se cerró, o si la matrícula ya no está pendiente
     */
    public function renovar(ReservaMatricula $reserva, ?int $actor = null): ReservaMatricula
    {
        return DB::transaction(function () use ($reserva, $actor): ReservaMatricula {
            $actual = ReservaMatricula::query()->lockForUpdate()->findOrFail($reserva->id);
            if (! in_array($actual->estado, [ReservaMatricula::ACTIVA, ReservaMatricula::CON_PAGO], true)) {
                throw ValidationException::withMessages(['reserva' => 'La reserva ya no está vigente: genera una nueva.']);
            }
            $matricula = Matricula::query()->lockForUpdate()->find($actual->matricula_id);
            if ($matricula === null || $matricula->estado !== 'pendiente') {
                throw ValidationException::withMessages(['reserva' => 'La matrícula ya no está pendiente.']);
            }

            $nueva = now()->addDays(self::dias());
            $actual->forceFill([
                'estado' => ReservaMatricula::ACTIVA,
                'expira_at' => $nueva->greaterThan($actual->expira_at) ? $nueva : $actual->expira_at,
            ])->save();

            AuditLog::record('reserva_renovada', ReservaMatricula::class, $actual->id, [
                'matricula_id' => $actual->matricula_id,
                'referencia' => $actual->referencia,
                'expira_at' => $actual->expira_at->toIso8601String(),
            ], $actor);

            return $actual;
        });
    }

    /** Cantidad de reservas activas ya vencidas (lo que haría {@see self::expirarVencidas()}), sin modificar nada. */
    public function contarVencidas(): int
    {
        return $this->consultaVencidas()->count();
    }

    /**
     * Procesa hasta `$limite` reservas activas vencidas. Cada una en su propia transacción: un fallo se registra (solo identificador
     * y clase de excepción) y no impide procesar las demás. Repetir la ejecución no cambia nada.
     *
     * @return array{vencidas: int, con_pago: int, cerradas: int, omitidas: int, errores: int}
     */
    public function expirarVencidas(int $limite = 200): array
    {
        $cuenta = ['vencidas' => 0, 'con_pago' => 0, 'cerradas' => 0, 'omitidas' => 0, 'errores' => 0];

        $ids = $this->consultaVencidas()->orderBy('expira_at')->orderBy('id')->limit($limite)->pluck('id');
        foreach ($ids as $id) {
            try {
                $resultado = $this->procesar(Entrada::entero($id));
                $cuenta[$resultado]++;
            } catch (Throwable $e) {
                Log::warning('reserva_vencimiento_fallido', ['reserva' => $id, 'excepcion' => $e::class]);
                $cuenta['errores']++;
            }
        }

        return $cuenta;
    }

    /** @return 'vencidas'|'con_pago'|'cerradas'|'omitidas' */
    private function procesar(int $id): string
    {
        return DB::transaction(function () use ($id): string {
            // Se relee bajo bloqueo: otra ejecución o una acción del panel pudo cambiarla desde que se listó.
            $reserva = ReservaMatricula::query()->lockForUpdate()->find($id);
            if ($reserva === null || $reserva->estado !== ReservaMatricula::ACTIVA || $reserva->expira_at->isFuture()) {
                return 'omitidas';
            }

            $matricula = Matricula::withTrashed()->find($reserva->matricula_id);
            if ($matricula === null || $matricula->trashed() || $matricula->estado !== 'pendiente') {
                // La matrícula dejó de estar pendiente por otra vía (se anuló, se eliminó o se pagó): no hay cupo que devolver.
                $reserva->forceFill(['estado' => ReservaMatricula::CERRADA])->save();

                return 'cerradas';
            }

            try {
                $this->matriculas->anular($matricula, null, self::MOTIVO_VENCIMIENTO);
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 409) {
                    throw $e;
                }
                // Ya hay un pago en el libro (confirmado o por confirmar): la reserva cumplió su objetivo y no vence.
                $reserva->forceFill(['estado' => ReservaMatricula::CON_PAGO])->save();
                AuditLog::record('reserva_con_pago', ReservaMatricula::class, $reserva->id, [
                    'matricula_id' => $reserva->matricula_id,
                    'referencia' => $reserva->referencia,
                ]);

                return 'con_pago';
            }

            $reserva->forceFill(['estado' => ReservaMatricula::VENCIDA, 'vencida_at' => now()])->save();
            AuditLog::record('reserva_vencida', ReservaMatricula::class, $reserva->id, [
                'matricula_id' => $reserva->matricula_id,
                'referencia' => $reserva->referencia,
            ]);

            return 'vencidas';
        });
    }

    /** @return Builder<ReservaMatricula> */
    private function consultaVencidas(): Builder
    {
        return ReservaMatricula::query()
            ->where('estado', ReservaMatricula::ACTIVA)
            ->where('expira_at', '<=', now());
    }

    private function referenciaNueva(): string
    {
        $largo = strlen(self::ALFABETO) - 1;
        for ($intento = 0; $intento < 10; $intento++) {
            $codigo = 'HRS-';
            for ($i = 0; $i < 6; $i++) {
                $codigo .= self::ALFABETO[random_int(0, $largo)];
            }
            if (! ReservaMatricula::query()->where('referencia', $codigo)->exists()) {
                return $codigo;
            }
        }

        throw new RuntimeException('No se pudo generar una referencia de reserva única.');
    }
}
