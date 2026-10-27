<?php

use App\Domain\Suscripciones\Actions\ConfigurarSuscripcion;
use App\Domain\Suscripciones\Actions\ProgramarAvisos;
use App\Enums\EstadoAviso;
use App\Enums\EventoAviso;
use App\Jobs\EnviarAvisoSuscripcion;
use App\Mail\AvisoVencimientoSuscripcion;
use App\Models\AvisoSuscripcion;
use App\Models\Empresa;
use App\Models\Scopes\EmpresaScope;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/*
 * Avisos de vencimiento (§ 3.12, § 8.5, T24). Decisión del propietario (23/10/2026): correo
 * por SMTP estándar + indicador en el panel. Una vez por empresa, período y evento.
 */

function avisosDe(Empresa $empresa): array
{
    return AvisoSuscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresa->id)
        ->orderBy('id')->get()->map(fn (AvisoSuscripcion $a) => $a->evento->value.'@'.$a->fecha_programada->format('m-d').':'.$a->estado->value)->all();
}

function diaLaPaz(string $fecha): CarbonImmutable
{
    return CarbonImmutable::parse("{$fecha} 08:00", 'America/La_Paz');
}

beforeEach(function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $this->travelTo(diaLaPaz('2026-10-01'));
    [$this->empresa, $this->admin] = empresaConAdministrador(['email' => 'Contacto@Comercial.test']);
    $this->superadmin = User::factory()->superadmin()->create();
    app(ConfigurarSuscripcion::class)->ejecutar($this->superadmin, $this->empresa, '149', '2026-10-28');
});

test('T24: D-5, D0 and D+3 are generated exactly once each per company and period', function () {
    foreach (['2026-10-20', '2026-10-22'] as $dia) {
        $this->travelTo(diaLaPaz($dia));
        $this->artisan('mozistock:avisos-suscripcion')->assertSuccessful();
    }
    expect(avisosDe($this->empresa))->toBe([]);

    $this->travelTo(diaLaPaz('2026-10-23'));
    $this->artisan('mozistock:avisos-suscripcion')->assertSuccessful();
    $this->artisan('mozistock:avisos-suscripcion')->assertSuccessful();
    expect(avisosDe($this->empresa))->toBe(['d-5@10-23:enviado']);
    Mail::assertSentCount(1);

    foreach (['2026-10-24', '2026-10-25', '2026-10-27', '2026-10-28', '2026-10-28', '2026-10-29', '2026-10-31', '2026-11-01', '2026-11-03', '2026-11-10'] as $dia) {
        $this->travelTo(diaLaPaz($dia));
        $this->artisan('mozistock:avisos-suscripcion')->assertSuccessful();
    }

    expect(avisosDe($this->empresa))->toBe(['d-5@10-23:enviado', 'd0@10-28:enviado', 'd+3@10-31:enviado']);
    Mail::assertSentCount(3);
    Mail::assertSent(AvisoVencimientoSuscripcion::class, fn (AvisoVencimientoSuscripcion $m) => $m->evento === EventoAviso::Posterior
        && $m->hasTo('contacto@comercial.test') && $m->hasTo($this->admin->email));

    // La desactivación sigue siendo manual (T26).
    expect($this->empresa->refresh()->activa)->toBeTrue();
});

test('if the server skipped days it sends the reminder whose window is still open, never two the same day', function () {
    $this->travelTo(diaLaPaz('2026-10-25'));
    app(ProgramarAvisos::class)->ejecutar();
    expect(avisosDe($this->empresa))->toBe(['d-5@10-23:enviado']);

    // Sin ejecuciones del 26 al 29: el 30 todavía está abierta la ventana de D0, no la de D-5.
    $this->travelTo(diaLaPaz('2026-10-30'));
    app(ProgramarAvisos::class)->ejecutar();
    app(ProgramarAvisos::class)->ejecutar();
    expect(avisosDe($this->empresa))->toBe(['d-5@10-23:enviado', 'd0@10-28:enviado']);

    // Pasada la ventana de D+3 ya no se envía tarde.
    $this->travelTo(diaLaPaz('2026-11-06'));
    app(ProgramarAvisos::class)->ejecutar();
    expect(avisosDe($this->empresa))->toHaveCount(2);
});

test('the email names the real days left, the plan and how to pay', function () {
    $this->travelTo(diaLaPaz('2026-10-24'));
    app(ProgramarAvisos::class)->ejecutar();

    Mail::assertSent(AvisoVencimientoSuscripcion::class, function (AvisoVencimientoSuscripcion $mail) {
        $mail->assertSeeInHtml($this->empresa->nombre)->assertSeeInHtml('Bs 149,00')->assertSeeInHtml('28/10/2026')
            ->assertSeeInHtml('Tu suscripción vence en 4 días')->assertSeeInHtml(route('suscripcion'));

        return $mail->envelope()->subject === 'Tu suscripción a MoziStock vence el 28/10/2026';
    });
});

test('a paid or corrected period, an inactive company or no recipients do not send the email', function () {
    // Programado y luego pagado antes de que el trabajo corra: queda omitido.
    $this->travelTo(diaLaPaz('2026-10-23'));
    $aviso = new AvisoSuscripcion;
    $aviso->empresa_id = $this->empresa->id;
    $aviso->suscripcion_id = Suscripcion::withoutGlobalScope(EmpresaScope::class)->sole()->id;
    $aviso->vencimiento = CarbonImmutable::parse('2026-10-28');
    $aviso->evento = EventoAviso::Previo;
    $aviso->fecha_programada = CarbonImmutable::parse('2026-10-23');
    $aviso->estado = EstadoAviso::Pendiente;
    $aviso->save();
    app(ConfigurarSuscripcion::class)->ejecutar($this->superadmin, $this->empresa, '149', '2026-11-28', 'Pago registrado');
    (new EnviarAvisoSuscripcion($aviso->id))->handle();
    (new EnviarAvisoSuscripcion($aviso->id))->handle();
    expect($aviso->refresh()->estado)->toBe(EstadoAviso::Omitido);

    [$inactiva] = empresaConAdministrador();
    $inactiva->activa = false;
    $inactiva->save();
    app(ConfigurarSuscripcion::class)->ejecutar($this->superadmin, $inactiva, '99', '2026-10-28');

    $sinCorreo = Empresa::factory()->create(['email' => null]);
    User::factory()->administrador($sinCorreo)->inactivo()->create();
    app(ConfigurarSuscripcion::class)->ejecutar($this->superadmin, $sinCorreo, '99', '2026-10-28');

    app(ProgramarAvisos::class)->ejecutar();

    expect(avisosDe($inactiva))->toBe([])
        ->and(avisosDe($sinCorreo))->toBe(['d-5@10-23:sin_destinatarios'])
        ->and($inactiva->refresh()->activa)->toBeFalse();
    Mail::assertNothingSent();
});

test('without mail configured the reminder stays in the panel as not sent', function () {
    config(['mail.default' => 'array']);
    $this->travelTo(diaLaPaz('2026-10-23'));
    $this->artisan('mozistock:avisos-suscripcion')
        ->expectsOutputToContain('Avisos nuevos en cola: 1.')
        ->expectsOutputToContain('El correo no está configurado')
        ->assertSuccessful();

    expect(avisosDe($this->empresa))->toBe(['d-5@10-23:no_enviado']);
    Mail::assertNothingSent();
});

test('recipients are only the company contact and its active administrators, never other companies or sellers', function () {
    $sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $vendedor = User::factory()->vendedor($sucursal)->create();
    $segundo = User::factory()->administrador($this->empresa)->create(['email' => 'contacto@comercial.test']);
    $inactivo = User::factory()->administrador($this->empresa)->inactivo()->create();
    [$otra, $adminOtra] = empresaConAdministrador();

    $this->travelTo(diaLaPaz('2026-10-23'));
    app(ProgramarAvisos::class)->ejecutar();

    $aviso = AvisoSuscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $this->empresa->id)->sole();
    expect($aviso->destinatarios)->toEqualCanonicalizing(['contacto@comercial.test', strtolower($this->admin->email)])
        ->and($aviso->destinatarios)->not->toContain(strtolower($vendedor->email), strtolower($inactivo->email), strtolower($adminOtra->email))
        ->and($segundo->email)->toBe('contacto@comercial.test')
        ->and(avisosDe($otra))->toBe([]);
    Mail::assertSent(AvisoVencimientoSuscripcion::class, fn ($m) => ! $m->hasTo($vendedor->email) && ! $m->hasTo($adminOtra->email));
});

test('a mail failure marks the reminder as failed without stopping the other companies', function () {
    [$otra] = empresaConAdministrador();
    app(ConfigurarSuscripcion::class)->ejecutar($this->superadmin, $otra, '99', '2026-10-28');
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    $this->travelTo(diaLaPaz('2026-10-23'));
    expect(app(ProgramarAvisos::class)->ejecutar())->toBe(2)
        ->and(avisosDe($this->empresa))->toBe(['d-5@10-23:fallido'])
        ->and(avisosDe($otra))->toBe(['d-5@10-23:fallido']);

    // Un reintento del trabajo ya fallido no reenvía.
    $aviso = AvisoSuscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $this->empresa->id)->sole();
    (new EnviarAvisoSuscripcion($aviso->id))->handle();
    expect($aviso->refresh()->estado)->toBe(EstadoAviso::Fallido)->and($aviso->error)->toBe('RuntimeException');
});

test('standalone mode has no subscription reminders', function () {
    config(['mozistock.deployment_mode' => 'standalone']);
    $this->travelTo(diaLaPaz('2026-10-23'));
    $this->artisan('mozistock:avisos-suscripcion')->assertSuccessful();

    expect(avisosDe($this->empresa))->toBe([]);
});
