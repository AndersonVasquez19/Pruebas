<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas de cupo con vencimiento (Fase 3, pagos asistidos). Una reserva retiene el cupo de una matrícula `pendiente` mientras la
 * persona paga: lleva una referencia única que se escribe en el pago (Yape, transferencia…) y un enlace secreto a la página de pago.
 *
 * - `matricula_id` es único: una matrícula tiene a lo sumo una reserva (se renueva, no se duplica).
 * - `referencia` es única y legible (p. ej. `HRS-7K2M9Q`, sin caracteres ambiguos).
 * - El enlace no se guarda en claro: `token_hash` (SHA-256) sirve para buscarlo y `token_cifrado` (cifrado con la clave de la
 *   aplicación) solo para que quien atiende pueda volver a mostrar el enlace. Con una copia de la base sola no se puede usar.
 * - Estados: `activa` (retiene el cupo hasta `expira_at`), `con_pago` (ya hay un pago en el libro: no vence), `vencida` (venció sin
 *   pago: la matrícula se anuló y el cupo se devolvió) y `cerrada` (la matrícula dejó de estar pendiente por otra vía).
 * - El CHECK de PostgreSQL se añade solo en ese motor (en SQLite lo valida el servicio).
 *
 * Aditiva y reversible: no toca `matriculas` ni datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservas_matricula', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('matricula_id')->unique()->constrained('matriculas')->restrictOnDelete();
            $table->string('referencia', 16)->unique();
            $table->char('token_hash', 64)->unique();
            $table->text('token_cifrado');
            $table->string('estado', 12)->default('activa');
            $table->timestamp('expira_at');
            $table->timestamp('vencida_at')->nullable();
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['estado', 'expira_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table reservas_matricula add constraint reservas_matricula_estado_valido check (estado in ('activa', 'con_pago', 'vencida', 'cerrada'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas_matricula');
    }
};
