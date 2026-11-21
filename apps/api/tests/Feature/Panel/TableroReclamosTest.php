<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\Reclamacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\PreparaReclamaciones;

/**
 * Tablero «Hoy»: los reclamos con el plazo legal vencido, que vence hoy o por vencer aparecen en `acciones.reclamos` solo para
 * quien tiene `reclamaciones.ver`, y solo como cifras (sin datos personales). Usa la misma cuenta de plazos que el listado y las
 * notificaciones (`ConsultaReclamaciones::plazos()`). SQLite en memoria: no mide PostgreSQL.
 */
final class TableroReclamosTest extends PanelApiTestCase
{
    use PreparaReclamaciones;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Lunes 26/10/2026, mediodía en Lima.
        $this->prepararReclamaciones(habilitar: true);
    }

    private function resumen(User $usuario): TestResponse
    {
        return $this->actingAs($usuario)->json('GET', '/api/panel/v1/academico/resumen', [], ['X-Horus-Web-Token' => self::TOKEN]);
    }

    private function conPlazo(string $correo, string $fechaLimite, ?string $suspensionDesde = null, ?string $suspensionHasta = null): Reclamacion
    {
        $reclamacion = $this->registrar(['email' => $correo])['reclamacion'];
        // Se escribe la fecha como `Y-m-d`, igual que una columna `date` de PostgreSQL. Con el cast del modelo SQLite guardaría
        // «Y-m-d 00:00:00» y la cuenta de plazos (que compara texto) no vería «vence hoy»: una rareza de SQLite, no de producción.
        // La base exige que la suspensión lleve las dos fechas (restricción `reclamacion_suspension_coherente`).
        DB::table('reclamaciones')->where('id', $reclamacion->id)->update([
            'fecha_limite' => $fechaLimite,
            'suspension_desde' => $suspensionDesde,
            'suspension_hasta' => $suspensionHasta,
        ]);

        return $reclamacion;
    }

    public function test_cuenta_vencidas_que_vencen_hoy_y_por_vencer_e_ignora_lejanas_y_suspendidas(): void
    {
        $this->conPlazo('vencida@example.test', '2026-10-23');
        $this->conPlazo('hoy@example.test', '2026-10-26');
        $this->conPlazo('porvencer@example.test', '2026-10-28');
        $this->conPlazo('lejana@example.test', '2026-12-15');
        // Con el plazo suspendido (propuesta de solución en curso) no corre: no cuenta aunque su fecha ya pasó.
        $this->conPlazo('suspendida@example.test', '2026-10-20', '2026-10-21', '2026-10-28');

        $this->resumen($this->admin)->assertOk()
            ->assertJsonPath('data.acciones.reclamos', ['vencidas' => 1, 'vencen_hoy' => 1, 'por_vencer' => 1]);
    }

    public function test_sin_reclamos_abiertos_el_bloque_llega_con_ceros(): void
    {
        $this->resumen($this->admin)->assertOk()
            ->assertJsonPath('data.acciones.reclamos', ['vencidas' => 0, 'vencen_hoy' => 0, 'por_vencer' => 0]);
    }

    public function test_solo_llegan_cifras_nunca_datos_personales_ni_identificadores(): void
    {
        $this->conPlazo('privada.persona@example.test', '2026-10-23');

        $respuesta = $this->resumen($this->admin)->assertOk();
        $this->assertSame(['vencidas', 'vencen_hoy', 'por_vencer'], array_keys($respuesta->json('data.acciones.reclamos')));
        $respuesta->assertDontSee('privada.persona')->assertDontSee('Ficticia')->assertDontSee('12345678');
    }

    public function test_sin_reclamaciones_ver_el_bloque_no_se_envia(): void
    {
        $this->conPlazo('vencida@example.test', '2026-10-23');

        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->resumen($this->usuarioConPermisos($rol))->assertOk()->assertJsonMissingPath('data.acciones.reclamos');
        }
    }
}
