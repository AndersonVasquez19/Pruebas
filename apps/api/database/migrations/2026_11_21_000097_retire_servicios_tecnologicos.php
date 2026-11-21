<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retira el área «Tecnologías» (servicios tecnológicos y cotizaciones) del sistema, que pasa a centrarse en educación y atención
 * al cliente (21/11/2026, ver `AGENTS.md`). Elimina las tablas del catálogo de servicios y de las solicitudes de cotización y el
 * permiso `servicios.publicar`.
 *
 * Seguridad: si alguna de las tablas tuviera filas, la migración se detiene sin cambiar nada. No se borran datos por accidente;
 * quien las necesite debe respaldarlas y vaciarlas a propósito.
 *
 * Las migraciones históricas que crearon o alteraron estas tablas se conservan sin tocar para que una instalación nueva siga
 * siendo reproducible; ésta las deja fuera al final. Solo actúa sobre esa lista cerrada, no resincroniza roles y repetirla no
 * cambia nada. Spatie elimina en cascada las asignaciones del permiso.
 */
return new class extends Migration
{
    /** Hijas primero: ninguna tabla se elimina mientras otra tenga una clave foránea hacia ella. */
    private const TABLAS = [
        'servicio_publicacion_elementos',
        'servicio_publicaciones',
        'cotizacion_historial',
        'solicitudes_cotizacion',
        'servicios_tecnologicos',
    ];

    /** @var list<string> */
    private const PERMISOS = ['servicios.publicar'];

    public function up(): void
    {
        $conDatos = [];
        foreach (self::TABLAS as $tabla) {
            if (Schema::hasTable($tabla) && DB::table($tabla)->exists()) {
                $conDatos[] = $tabla;
            }
        }
        if ($conDatos !== []) {
            throw new RuntimeException('No se eliminan las tablas de Tecnología porque tienen datos: '.implode(', ', $conDatos).'. Respáldalas y vacíalas antes.');
        }

        DB::transaction(function (): void {
            Permission::query()->where('guard_name', 'web')->whereIn('name', self::PERMISOS)->get()
                ->each(fn (Permission $permiso) => $permiso->delete());

            foreach (self::TABLAS as $tabla) {
                Schema::dropIfExists($tabla);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No recrea nada a propósito: Tecnología se retiró y las tablas estaban vacías (la guarda de up() lo exige). Revertir no
        // debe fallar —las pruebas y `migrate:rollback` retroceden cadenas enteras—, pero tampoco resucita el área.
    }
};
