<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Fase 5.2, paso 3 — matriz a nivel servicio / HTTP del panel sobre PostgreSQL real: revisión (ETag 200 / 412),
 * publicación de curso, creación idempotente de cotizaciones en carrera, archivo y registro de imágenes y auditoría.
 *
 * Datos ficticios; solo la base desechable del perfil aislado (127.0.0.1:55433, horus_l2_test). Usan DatabaseTruncation
 * (con RefreshDatabase la segunda sesión no vería los datos). Protocolo (igual que MatriculaOperacionesConcurrencia…):
 * la sesión B toma el bloqueo de la fila; la petición real, con lock_timeout corto, debe quedar BLOQUEADA (55P03) y no
 * dejar rastro; al confirmar B se repite y decide con el estado ya confirmado.
 */
class PanelConcurrenciaPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private const TOKEN = 'token-de-servicio-del-bff-para-pruebas-0123456789';

    private const LOCK_NOT_AVAILABLE = '55P03';

    private const CHECK_VIOLATION = '23514';

    // Utilidades ---------------------------------------------------------------------------------------------------

    private function admin(): User
    {
        config(['horus-web.service_token' => self::TOKEN]);

        return $this->adminUser();
    }

    /** @param array<string, mixed> $datos */
    private function como(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/'.$ruta, $datos);
    }

    private function assertBlocked(callable $peticion): void
    {
        $this->withoutExceptionHandling();
        try {
            $peticion();
            $this->fail('La petición debía quedar bloqueada por la transacción de la otra sesión.');
        } catch (QueryException $e) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $e->getCode(), 'Debe esperar el bloqueo (55P03), no fallar por otra causa.');
        } finally {
            $this->withExceptionHandling();
        }
    }

    /** @return array<string, mixed> */
    private function fichaCurso(array $cambios = []): array
    {
        return [
            'titulo' => 'Excel Intermedio', 'resumen' => 'Aprende tablas dinámicas.', 'modalidad' => 'virtual',
            'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(),
            'confirmacion_publica' => true, ...$cambios,
        ];
    }

    // 2. Publicación de curso en paralelo -----------------------------------------------------------------------------

    public function test_publicar_y_retirar_un_curso_en_paralelo_deja_un_estado_coherente(): void
    {
        $admin = $this->admin();
        $curso = $this->makeCourse('PG52-PUB', 10);
        $revision = fn (): string => (string) $this->como($admin, 'GET', "academico/cursos/{$curso->id}/publicacion")->assertOk()->json('data.revision');

        $this->como($admin, 'PUT', "academico/cursos/{$curso->id}/publicacion", $this->fichaCurso(['_revision' => $revision()]))->assertOk();
        $publicada = $revision();
        $this->assertSame(1, AuditLog::where('action', 'curso_publicado')->count());

        // La sesión B (otra petición) tiene el curso bloqueado: el retiro espera y no deja rastro.
        $b = $this->secondSession();
        $b->beginTransaction();
        $b->select('select id from cursos where id = ? for update', [$curso->id]);

        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->como($admin, 'POST', "academico/cursos/{$curso->id}/publicacion/retirar", ['_revision' => $publicada]));
        $this->assertSame(0, AuditLog::where('action', 'curso_despublicado')->count());

        $b->commit();

        $this->como($admin, 'POST', "academico/cursos/{$curso->id}/publicacion/retirar", ['_revision' => $publicada])->assertOk();
        // Publicar con la revisión de antes del retiro: obsoleta.
        $this->como($admin, 'PUT', "academico/cursos/{$curso->id}/publicacion", $this->fichaCurso(['_revision' => $publicada]))->assertStatus(412);

        $this->assertSame(1, AuditLog::where('action', 'curso_publicado')->count());
        $this->assertSame(1, AuditLog::where('action', 'curso_despublicado')->count());
        $this->assertSame(1, DB::table('curso_publicaciones')->where('curso_id', $curso->id)->count(), 'Una sola ficha por curso.');
    }

}
