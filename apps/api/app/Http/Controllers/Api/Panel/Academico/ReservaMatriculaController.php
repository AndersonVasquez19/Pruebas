<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Models\Matricula;
use App\Models\ReservaMatricula;
use App\Services\ReservasMatricula;
use App\Support\Entrada;
use App\Support\HoraLocal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Enlace de pago de una matrícula pendiente (Fase 3): crea o devuelve su reserva de cupo, con la referencia que la persona escribe en
 * el pago y el enlace secreto a la página de pago, y permite renovarla. Toda la lógica está en {@see ReservasMatricula}.
 *
 * Necesita `matriculas.crear`, también para ver el enlace: es un secreto (cualquiera que lo tenga ve el importe y la referencia).
 * Las respuestas no se cachean.
 */
final class ReservaMatriculaController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ReservasMatricula $reservas) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('permission:matriculas.crear')];
    }

    /** La reserva actual de la matrícula; 404 si todavía no tiene. */
    public function mostrar(int $matricula): JsonResponse
    {
        $modelo = Matricula::query()->findOrFail($matricula);
        $reserva = ReservaMatricula::query()->where('matricula_id', $modelo->id)->firstOrFail();

        return $this->respuesta($reserva);
    }

    /** Crea la reserva o devuelve la que ya tiene (idempotente). 422 si la matrícula no está pendiente. */
    public function crear(Request $request, int $matricula): JsonResponse
    {
        $modelo = Matricula::query()->findOrFail($matricula);

        return $this->respuesta($this->reservas->reservar($modelo, $request->user()?->id));
    }

    /** Amplía la reserva con una ventana completa desde ahora. 404 si no tiene; 422 si ya no es vigente. */
    public function renovar(Request $request, int $matricula): JsonResponse
    {
        $modelo = Matricula::query()->findOrFail($matricula);
        $reserva = ReservaMatricula::query()->where('matricula_id', $modelo->id)->firstOrFail();

        return $this->respuesta($this->reservas->renovar($reserva, $request->user()?->id));
    }

    private function respuesta(ReservaMatricula $reserva): JsonResponse
    {
        $token = $reserva->token();
        $base = Entrada::texto(config('horus-web.url', ''));

        return response()->json([
            'data' => [
                'referencia' => $reserva->referencia,
                'estado' => $reserva->estado,
                'vigente' => $reserva->estaVigente(),
                'vence_en' => HoraLocal::formato($reserva->expira_at, 'c'),
                'token' => $token,
                // Sin HORUS_WEB_URL no se puede armar un enlace absoluto: la interfaz lo arma con su propio origen a partir del token.
                'enlace' => $base !== '' ? $base.'/pagar/'.$token : null,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
