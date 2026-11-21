<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ReservasMatricula;
use Illuminate\Console\Command;

/**
 * Vence las reservas de cupo (Fase 3): las reservas activas cuya fecha de vencimiento ya pasó y que no tienen ningún pago en el
 * libro anulan su matrícula pendiente y devuelven el cupo, con actor «Sistema». Si ya hay un pago —confirmado o por confirmar— la
 * reserva no vence y pasa a `con_pago`. Ver {@see ReservasMatricula::expirarVencidas()}.
 *
 * Idempotente: repetirlo no cambia nada. Un fallo en un caso se registra (solo identificador y clase de excepción) y no impide
 * procesar los demás. No envía correos ni mensajes.
 */
class ExpirarReservasMatricula extends Command
{
    protected $signature = 'horus:reservas-expirar
        {--dry-run : Cuenta las reservas vencidas sin modificar nada}
        {--limit=200 : Máximo de reservas por ejecución (1 a 1000)}';

    protected $description = 'Vence las reservas de cupo sin pago y devuelve el cupo';

    public function handle(ReservasMatricula $reservas): int
    {
        $limite = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limite === false) {
            $this->error('El límite debe estar entre 1 y 1000.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Simulación: '.$reservas->contarVencidas().' reserva(s) vencida(s) por procesar. No se modificó nada.');

            return self::SUCCESS;
        }

        $r = $reservas->expirarVencidas($limite);
        $this->info("Reservas: {$r['vencidas']} vencida(s) y cupo devuelto · {$r['con_pago']} con pago (no vencen) · {$r['cerradas']} cerrada(s) · {$r['omitidas']} omitida(s) · {$r['errores']} con error.");

        return $r['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
