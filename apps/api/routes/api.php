<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Publico\AcademicoPublicoController;
use App\Http\Controllers\Api\Publico\CatalogoPublicoController;
use App\Http\Controllers\Api\Publico\ContactoPublicoController;
use App\Http\Controllers\Api\Publico\PagoPublicoController;
use App\Http\Controllers\Api\Publico\ReclamacionesPublicoController;
use App\Http\Controllers\Api\Webhooks\ResendWebhookController;
use App\Http\Middleware\AutenticarServicioWeb;
use App\Http\Middleware\RecepcionReclamacionesHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('api.auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register')->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::middleware('jwt.auth')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});

// Lectura pública para el BFF de apps/web (Fase 2a): servidor a servidor con X-Horus-Web-Token; nunca desde el navegador.
Route::prefix('public/v1')->name('api.publico.')->middleware([AutenticarServicioWeb::class, 'throttle:publico-web'])->group(function (): void {
    Route::get('/cursos', [CatalogoPublicoController::class, 'cursos'])->name('cursos');
    Route::get('/ediciones/{codigo}/cronograma', [AcademicoPublicoController::class, 'cronograma'])->name('ediciones.cronograma');
    Route::get('/encuestas/{identificador}', [AcademicoPublicoController::class, 'verEncuesta'])->whereUuid('identificador')->name('encuestas.show');
    Route::post('/encuestas/{identificador}', [AcademicoPublicoController::class, 'responderEncuesta'])->whereUuid('identificador')->name('encuestas.responder');
    // Fase 2c.1: Libro de Reclamaciones (solo envío; no hay consulta pública). Apagado, el POST responde 404 antes de leer el cuerpo.
    Route::get('/reclamaciones/formulario', [ReclamacionesPublicoController::class, 'formulario'])
        ->middleware('throttle:reclamaciones-bff-formulario')->name('reclamaciones.formulario');
    Route::post('/reclamaciones', [ReclamacionesPublicoController::class, 'store'])
        ->middleware([RecepcionReclamacionesHabilitada::class, 'throttle:reclamaciones-bff-intentos'])->name('reclamaciones.store');
    // «Contáctanos»: mensaje de contacto del sitio. Sin consulta pública.
    Route::post('/contactos', [ContactoPublicoController::class, 'store'])
        ->middleware('throttle:contacto-bff-intentos')->name('contactos.store');
    // Página de pago de una reserva de cupo (Fase 3): el enlace secreto de 40 caracteres es el único permiso; cualquier otro valor es 404.
    Route::get('/pagos/{token}', [PagoPublicoController::class, 'ver'])
        ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:pagos-bff')->name('pagos.ver');
});

// Webhook firmado de Resend (Svix HMAC): actualiza entregas/rebotes sin depender del BFF ni exponer PII.
Route::post('/webhooks/resend', ResendWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('api.webhooks.resend');
