<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Carbon;

/**
 * Reserva de cupo con vencimiento de una matrícula `pendiente` (Fase 3, pagos asistidos). Se crea y modifica únicamente a través de
 * {@see \App\Services\ReservasMatricula}.
 *
 * @property int $id
 * @property int $matricula_id
 * @property string $referencia
 * @property string $token_hash
 * @property string $token_cifrado
 * @property string $estado
 * @property Carbon $expira_at
 * @property Carbon|null $vencida_at
 */
class ReservaMatricula extends Model
{
    public const ACTIVA = 'activa';

    public const CON_PAGO = 'con_pago';

    public const VENCIDA = 'vencida';

    public const CERRADA = 'cerrada';

    protected $table = 'reservas_matricula';

    /** Sin asignación masiva: el servicio usa forceFill. */
    protected $fillable = [];

    /** El enlace secreto nunca sale en una respuesta ni en un volcado del modelo. @var list<string> */
    protected $hidden = ['token_hash', 'token_cifrado'];

    protected function casts(): array
    {
        return [
            'expira_at' => 'datetime',
            'vencida_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Matricula, $this> */
    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class)->withTrashed();
    }

    /** Enlace secreto en claro, para volver a mostrarlo a quien atiende. Se guarda cifrado con la clave de la aplicación. */
    public function token(): string
    {
        return Crypt::decryptString($this->token_cifrado);
    }

    /** `true` mientras retiene el cupo y todavía no venció. */
    public function estaVigente(): bool
    {
        return $this->estado === self::ACTIVA && $this->expira_at->isFuture();
    }
}
