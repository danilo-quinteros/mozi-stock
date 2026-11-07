<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

test('every web response carries the security headers, without HSTS outside production over HTTPS', function () {
    $respuesta = $this->get('/login');

    $respuesta->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('in production over HTTPS the response also carries HSTS', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('https://localhost/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

test('on shared hosting the every-minute cron also drains the queue, and not otherwise', function () {
    $colaProgramada = fn (): bool => collect(app(Schedule::class)->events())
        ->contains(fn ($evento) => str_contains((string) $evento->command, 'queue:work --stop-when-empty'));

    expect($colaProgramada())->toBeFalse();

    config(['mozistock.cola_desde_cron' => true]);
    require base_path('routes/console.php');

    expect($colaProgramada())->toBeTrue();
    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('queue:work --stop-when-empty --max-time=50');
});
