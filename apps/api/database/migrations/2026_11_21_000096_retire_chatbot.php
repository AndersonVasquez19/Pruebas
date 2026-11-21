<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retira el asistente virtual (Horus AI) del sistema: elimina el permiso `asistente.ver` y las tablas `chat_logs`
 * y `chat_intents`. Se decidió el 21/11/2026 sustituirlo por un botón flotante de WhatsApp (ver `AGENTS.md`).
 *
 * Las migraciones históricas que crearon o alteraron esas tablas se conservan sin tocar para que una instalación
 * nueva siga siendo reproducible; esta las deja fuera al final. Solo actúa sobre esa lista cerrada, no resincroniza
 * roles y repetirla no cambia nada. Spatie elimina en cascada las asignaciones del permiso.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISOS = ['asistente.ver'];

    public function up(): void
    {
        DB::transaction(function (): void {
            Permission::query()->where('guard_name', 'web')->whereIn('name', self::PERMISOS)->get()
                ->each(fn (Permission $permiso) => $permiso->delete());

            Schema::dropIfExists('chat_logs');
            Schema::dropIfExists('chat_intents');
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No recrea nada a propósito: el asistente se retiró. Revertir no debe fallar, pero tampoco lo resucita.
    }
};
