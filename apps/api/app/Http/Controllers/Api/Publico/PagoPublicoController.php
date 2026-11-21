<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Publico;

use App\Http\Controllers\Controller;
use App\Models\ObligacionPago;
use App\Models\ReservaMatricula;
use App\Services\GestionPagos;
use App\Support\Entrada;
use App\Support\HoraLocal;
use Illuminate\Http\JsonResponse;

/**
 * Página de pago de una reserva de cupo (Fase 3): la persona abre su enlace secreto y ve cuánto debe, cómo pagar y la referencia que
 * debe escribir en el pago. Solo lectura, para el BFF de apps/web (token de servicio).
 *
 * El enlace es el único "permiso": un token desconocido, mal formado o de otra reserva responde siempre el mismo 404 (no se revela si
 * existió). Nunca expone datos personales: solo el primer nombre, el curso y los importes de su propia matrícula.
 */
final class PagoPublicoController extends Controller
{
    public function ver(string $token): JsonResponse
    {
        abort_unless(preg_match('/\A[A-Za-z0-9]{40}\z/', $token) === 1, 404);

        $reserva = ReservaMatricula::query()
            ->where('token_hash', hash('sha256', $token))
            ->with(['matricula.estudiante:id,nombres', 'matricula.curso:id,nombre', 'matricula.convocatoria:id,codigo'])
            ->first();
        abort_if($reserva === null, 404);

        $matricula = $reserva->matricula;
        abort_if($matricula === null, 404);
        $obligacion = ObligacionPago::query()->where('matricula_id', $reserva->matricula_id)->where('estado', 'vigente')->first();

        // Una activa cuyo plazo ya pasó se muestra como vencida aunque el comando todavía no la haya procesado.
        $estado = $reserva->estado === ReservaMatricula::ACTIVA && $reserva->expira_at->isPast() ? ReservaMatricula::VENCIDA : $reserva->estado;

        return response()->json([
            'data' => [
                'estado' => $estado,
                'vigente' => $estado === ReservaMatricula::ACTIVA,
                'referencia' => $reserva->referencia,
                'vence_en' => HoraLocal::formato($reserva->expira_at, 'c'),
                'estudiante' => $this->primerNombre((string) $matricula->estudiante?->nombres),
                'curso' => $matricula->curso?->nombre,
                'edicion' => $matricula->convocatoria?->codigo,
                'monto' => $obligacion !== null ? $this->monto($obligacion) : null,
                'metodos' => ['yape' => $this->yape()],
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @return array{moneda: string, total: string, pagado: string, por_confirmar: string, saldo: string} */
    private function monto(ObligacionPago $obligacion): array
    {
        $s = app(GestionPagos::class)->saldos($obligacion);

        return [
            'moneda' => 'PEN',
            'total' => GestionPagos::formato($s['total']),
            'pagado' => GestionPagos::formato($s['confirmado']),
            'por_confirmar' => GestionPagos::formato($s['pendiente']),
            'saldo' => GestionPagos::formato($s['saldo']),
        ];
    }

    /** @return array{numero: string, titular: string}|null `null` si no está configurado: ese medio no se ofrece. */
    private function yape(): ?array
    {
        $numero = trim(Entrada::texto(config('pagos-manuales.yape.numero', '')));
        if ($numero === '') {
            return null;
        }

        return ['numero' => $numero, 'titular' => trim(Entrada::texto(config('pagos-manuales.yape.titular', '')))];
    }

    private function primerNombre(string $nombres): string
    {
        $nombre = trim(explode(' ', trim($nombres))[0]);

        return $nombre !== '' ? $nombre : 'Estudiante';
    }
}
