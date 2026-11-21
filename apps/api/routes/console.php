<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('horus:matriculas-notificar')->everyMinute();

// Vencimientos del Libro de Reclamaciones (Sprint 22): una vez al día, a las 08:00 de Lima. El bloqueo caduca a
// los 30 minutos para que una ejecución abandonada (proceso muerto) no impida las siguientes durante 24 h.
// Con el módulo desactivado el comando termina con éxito sin consultar ni escribir.
Schedule::command('horus:reclamaciones-vencimientos')
    ->dailyAt('08:00')
    ->timezone('America/Lima')
    ->withoutOverlapping(30);

Schedule::command('horus:recordatorios-diarios')
    ->dailyAt('07:00')
    ->timezone('America/Lima')
    ->withoutOverlapping(30);

Schedule::command('horus:avisos-despachar')
    ->everyMinute()
    ->withoutOverlapping(5);

// Reservas de cupo (Fase 3): vence las que pasaron su plazo sin ningún pago y devuelve el cupo. Idempotente; el bloqueo caduca a
// los 10 minutos para que una ejecución abandonada no impida las siguientes.
Schedule::command('horus:reservas-expirar')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
