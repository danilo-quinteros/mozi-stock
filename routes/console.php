<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Avisos de vencimiento D-5, D0 y D+3 (§ 3.12). Requiere el cron de `schedule:run` y un
// trabajador de cola (`queue:work`) en el servidor.
Schedule::command('mozistock:avisos-suscripcion')
    ->dailyAt('08:00')
    ->timezone((string) config('mozistock.business_timezone'))
    ->withoutOverlapping();

// Facturación SIAT: CUFD (vigencia de 24 h) renovado cuando le quedan menos de 2 horas, y catálogos
// del SIN una vez al día. Solo empresas activas, habilitadas y con token vigente.
Schedule::command('mozistock:siat cufd')->hourly()->withoutOverlapping();
// Facturas que siguen pendientes después de los reintentos del job (SIN caído, trabajador detenido).
Schedule::command('mozistock:siat facturas')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('mozistock:siat catalogos')
    ->dailyAt('03:30')
    ->timezone((string) config('mozistock.business_timezone'))
    ->withoutOverlapping();
