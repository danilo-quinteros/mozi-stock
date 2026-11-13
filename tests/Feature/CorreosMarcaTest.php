<?php

use App\Enums\EventoAviso;
use App\Mail\AvisoVencimientoSuscripcion;
use App\Models\AjusteGlobal;
use App\Models\Empresa;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Markdown;

// Los correos llevan el logo MoziStock (no el de Laravel) y el tema oscuro corporativo.

test('los correos de cuenta usan el logo y el tema oscuro de MoziStock', function () {
    $usuario = User::factory()->unverified()->create();
    $mensaje = (new VerifyEmail)->toMail($usuario);

    $html = (string) app(Markdown::class)->render($mensaje->markdown, $mensaje->data());

    expect($html)
        ->toContain('img/marca/logo-mozistock-correo.png')
        ->toContain('content="dark"')
        ->toContain('background-color: #151411')
        ->toContain('MoziStock · by MoziStudios')
        ->not->toContain('laravel.com/img');
});

test('el aviso de suscripción usa el logo y el tema oscuro de MoziStock', function () {
    $suscripcion = new Suscripcion;
    $suscripcion->vencimiento = now()->addDays(3)->toImmutable();
    $suscripcion->precio_mensual = '149.00';

    $correo = new AvisoVencimientoSuscripcion(
        new Empresa(['nombre' => 'Empresa Correo']), $suscripcion, EventoAviso::cases()[0], false,
        new AjusteGlobal(['receptor_nombre' => 'MoziStudios']),
    );

    $correo->assertSeeInHtml('img/marca/logo-mozistock-correo.png')
        ->assertSeeInHtml('background-color:#151411')
        ->assertSeeInHtml('Empresa Correo')
        ->assertSeeInHtml('Bs 149,00');
});
