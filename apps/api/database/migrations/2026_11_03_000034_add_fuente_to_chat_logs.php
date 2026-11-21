<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_logs', function (Blueprint $table): void {
            // Sin inferir el motor de registros históricos.
            $table->string('fuente', 16)->nullable();
        });
    }

    public function down(): void
    {
        // La migración 000096 elimina `chat_logs` al retirar el asistente: un rollback completo pasa por aquí con la
        // tabla ya ausente y no debe fallar.
        if (! Schema::hasTable('chat_logs') || ! Schema::hasColumn('chat_logs', 'fuente')) {
            return;
        }

        Schema::table('chat_logs', function (Blueprint $table): void {
            $table->dropColumn('fuente');
        });
    }
};
