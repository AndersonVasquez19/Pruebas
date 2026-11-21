<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Publico;

use App\Http\Controllers\Controller;
use App\Services\CatalogoPublicoCursos;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo público de cursos en JSON para el BFF de apps/web (solo lectura): nada de precio, cupos ni datos
 * administrativos. Un fallo técnico responde 503 con un código genérico, sin SQL ni contenido.
 */
final class CatalogoPublicoController extends Controller
{
    private const MODALIDADES = ['presencial', 'semipresencial', 'virtual', 'hibrido'];

    public function cursos(Request $request, CatalogoPublicoCursos $catalogo): JsonResponse
    {
        $datos = $request->validate(['modalidad' => ['sometimes', 'string', 'in:'.implode(',', self::MODALIDADES)]]);
        $modalidad = $datos['modalidad'] ?? null;
        $habilitado = (bool) config('catalogo-publico.enabled', false);
        $cursos = [];
        $total = 0;
        if ($habilitado) {
            try {
                if (! Schema::hasTable('curso_publicaciones')) {
                    Log::warning('catalogo_web_esquema_incompleto');

                    return $this->noDisponible();
                }
                foreach ($catalogo->paraWeb()['cursos'] as $ficha) {
                    if (trim($ficha['titulo']) === '' || mb_strlen($ficha['titulo']) > 120
                        || trim($ficha['resumen']) === '' || mb_strlen($ficha['resumen']) > 500
                        || ! in_array($ficha['modalidad'], self::MODALIDADES, true)
                        || mb_strlen($ficha['horario'] ?? '') > 200 || mb_strlen($ficha['inscripcion'] ?? '') > 400) {
                        Log::warning('catalogo_web_ficha_invalida');

                        continue;
                    }
                    if ($modalidad !== null && $ficha['modalidad'] !== $modalidad) {
                        continue;
                    }
                    $total++;
                    if (count($cursos) < 50) {
                        $cursos[] = $ficha;
                    }
                }
            } catch (QueryException) {
                Log::warning('catalogo_web_lectura_fallida');

                return $this->noDisponible();
            }
        }

        return response()->json(['habilitado' => $habilitado, 'modalidad' => $modalidad, 'total' => $total, 'cursos' => $cursos]);
    }

    private function noDisponible(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'catalogo_no_disponible', 'message' => 'El catálogo no está disponible.']], 503);
    }
}
