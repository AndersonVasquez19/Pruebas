<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Identificación opaca del visitante en los formularios públicos que llegan por el BFF de apps/web
 * (contacto, reclamaciones, preinscripciones). La calcula el servidor de Next como HMAC de la IP y la envía en
 * `X-Horus-Visitante`: nunca llega la IP en claro. Sirve para agrupar los límites de velocidad por visitante.
 */
final class VisitantePublico
{
    public const CABECERA = 'X-Horus-Visitante';

    /** Valor del HMAC (64 caracteres hexadecimales); sin cabecera válida se agrupa como «anonimo». */
    public static function identificar(Request $request): string
    {
        $valor = $request->header(self::CABECERA);

        return is_string($valor) && preg_match('/\A[a-f0-9]{64}\z/D', $valor) === 1 ? $valor : 'anonimo';
    }
}
