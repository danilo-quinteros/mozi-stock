<?php

use App\Domain\Tenancy\Actions\RegistrarEmpresaPrueba;
use App\Enums\Rol;
use App\Enums\Rubro;
use App\Livewire\Auth\Registro;
use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\Empresa;
use App\Models\Scopes\EmpresaScope;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $cambios
 */
function registroCompleto(array $cambios = []): Testable
{
    $datos = array_merge([
        'name' => 'María Quispe',
        'email' => 'Maria@Ejemplo.com',
        'password' => 'clave-segura-123',
        'empresa' => 'Ferretería El Constructor',
        'telefono' => '+591 712-34567',
        'nit' => '',
        'rubro' => 'general',
        'plan' => 'inventario',
        'acepta' => true,
    ], $cambios);

    $prueba = Livewire::test(Registro::class);

    foreach (['name', 'email', 'password'] as $campo) {
        $prueba->set($campo, $datos[$campo]);
    }
    $prueba->call('avanzar');

    foreach (['empresa', 'telefono', 'nit', 'rubro'] as $campo) {
        $prueba->set($campo, $datos[$campo]);
    }
    $prueba->call('avanzar');

    return $prueba->set('plan', $datos['plan'])->set('acepta', $datos['acepta']);
}

function sinEscopo(string $modelo): Builder
{
    return $modelo::withoutGlobalScope(EmpresaScope::class);
}

beforeEach(function () {
    RateLimiter::clear('registro:127.0.0.1');
});

test('the sign-up page is public in SaaS mode and shows the trial and the plans', function () {
    $this->get(route('registro'))->assertOk()
        ->assertSee('Crea tu cuenta')
        ->assertSee('Paso 1 de 3')
        ->assertSee('15 días gratis')
        ->assertSee('Inicia sesión')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

test('signed-in users do not see the sign-up page', function () {
    $this->actingAs(User::factory()->create())->get(route('registro'))->assertRedirect();
});

test('the sign-up page does not exist in standalone mode', function () {
    config(['mozistock.deployment_mode' => 'standalone']);

    $this->get(route('registro'))->assertNotFound();
});

test('a visitor signs up in three steps and gets a ready company on a 15-day trial', function () {
    Notification::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 23:30:00', 'America/La_Paz'));

    registroCompleto()->call('avanzar')->assertHasNoErrors()->assertRedirect(route('verification.notice'));

    $usuario = User::query()->where('email', 'maria@ejemplo.com')->sole();
    $empresa = Empresa::query()->findOrFail($usuario->empresa_id);
    $suscripcion = sinEscopo(Suscripcion::class)->where('empresa_id', $empresa->id)->sole();

    expect($usuario->rol)->toBe(Rol::Administrador)
        ->and($usuario->email_verified_at)->toBeNull()
        ->and($empresa->nombre)->toBe('Ferretería El Constructor')
        ->and($empresa->telefono)->toBe('59171234567')
        ->and($empresa->nit)->toBeNull()
        ->and($empresa->activa)->toBeTrue()
        ->and($empresa->siat_habilitado)->toBeFalse()
        ->and(sinEscopo(Sucursal::class)->where('empresa_id', $empresa->id)->pluck('nombre')->all())->toBe(['Casa Matriz'])
        ->and(sinEscopo(Caja::class)->where('empresa_id', $empresa->id)->count())->toBe(1)
        // Fecha de La Paz: 23:30 del 6 de noviembre sigue siendo el día 6 aunque en UTC ya sea el 7.
        ->and($suscripcion->prueba_hasta->format('Y-m-d'))->toBe('2026-11-21')
        ->and($suscripcion->vencimiento->format('Y-m-d'))->toBe('2026-11-21')
        ->and($suscripcion->dia_vencimiento)->toBe(21)
        ->and((string) $suscripcion->precio_mensual)->toBe('150.00')
        ->and($suscripcion->enPrueba())->toBeTrue()
        ->and(Auditoria::query()->where('accion', 'suscripcion.prueba_iniciada')->where('usuario_id', $usuario->id)->exists())->toBeTrue()
        ->and(Auditoria::query()->where('accion', 'empresa.creada')->where('empresa_id', $empresa->id)->exists())->toBeTrue();

    $this->assertAuthenticatedAs($usuario);
    Notification::assertSentTo($usuario, VerifyEmail::class);

    // Sin confirmar el correo no entra al panel.
    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));

    CarbonImmutable::setTestNow();
});

test('the SIAT plan enables invoicing setup during the trial and uses its price', function () {
    Notification::fake();

    registroCompleto(['plan' => 'siat', 'nit' => '1234567'])->call('avanzar')->assertHasNoErrors();

    $empresa = Empresa::query()->where('nombre', 'Ferretería El Constructor')->sole();

    expect($empresa->siat_habilitado)->toBeTrue()
        ->and($empresa->nit)->toBe('1234567')
        ->and((string) sinEscopo(Suscripcion::class)->where('empresa_id', $empresa->id)->value('precio_mensual'))->toBe('300.00');
});

test('each step is validated on the server before moving on', function () {
    User::factory()->create(['email' => 'ocupado@ejemplo.com']);

    $prueba = Livewire::test(Registro::class)
        ->set('name', 'Jo')->set('email', 'no-es-correo')->set('password', '1')
        ->call('avanzar')
        ->assertHasErrors(['name', 'email', 'password'])
        ->assertSet('paso', 1);

    $prueba->set('name', 'José Mamani')->set('email', 'OCUPADO@ejemplo.com')->set('password', 'clave-segura-123')
        ->call('avanzar')->assertHasErrors(['email' => 'unique'])->assertSet('paso', 1)
        ->assertSee('Ya existe una cuenta con este correo');

    $prueba->set('email', 'jose@ejemplo.com')->call('avanzar')->assertHasNoErrors()->assertSet('paso', 2)
        // El rubro general se muestra como «Tienda».
        ->assertSeeHtml('<b>Tienda</b>')->assertDontSeeHtml('<b>General</b>')
        ->set('empresa', '')->set('telefono', 'abc')->set('nit', 'ABC')
        ->call('avanzar')->assertHasErrors(['empresa', 'telefono'])->assertSet('paso', 2)
        ->set('empresa', 'Tienda José')->set('telefono', '71234567')->set('rubro', 'general')
        ->call('avanzar')->assertHasNoErrors()->assertSet('paso', 3)
        ->set('acepta', false)
        ->call('avanzar')->assertHasErrors(['acepta' => 'accepted']);

    expect(Empresa::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(1);
});

test('the step cannot be skipped from the browser', function () {
    expect(fn () => Livewire::test(Registro::class)->set('paso', 3))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    // Saltar hacia adelante con irA tampoco se permite.
    Livewire::test(Registro::class)->call('irA', 3)->assertSet('paso', 1);
});

test('going back keeps what was typed', function () {
    registroCompleto()->call('volver')->assertSet('paso', 2)->assertSet('empresa', 'Ferretería El Constructor')
        ->call('irA', 1)->assertSet('paso', 1)->assertSet('email', 'maria@ejemplo.com');
});

test('a plan link from the pricing page arrives preselected and an unknown plan falls back', function () {
    Livewire::withQueryParams(['plan' => 'siat'])->test(Registro::class)->assertSet('plan', 'siat');
    Livewire::withQueryParams(['plan' => 'no-existe'])->test(Registro::class)->assertSet('plan', 'inventario');
});

test('bots filling the hidden field create nothing', function () {
    registroCompleto()->set('sitio_web', 'https://spam.example')->call('avanzar')->assertHasErrors('acepta');

    expect(Empresa::query()->count())->toBe(0);
});

test('sign-ups are limited per IP', function () {
    RateLimiter::hit('registro:127.0.0.1', 3600);
    foreach (range(2, Registro::MAX_POR_HORA) as $i) {
        RateLimiter::hit('registro:127.0.0.1', 3600);
    }

    registroCompleto()->call('avanzar')->assertHasErrors('acepta');

    expect(Empresa::query()->count())->toBe(0);
});

test('a repeated submission with the same email does not create a second company', function () {
    Notification::fake();
    $accion = app(RegistrarEmpresaPrueba::class);
    $empresa = ['nombre' => 'Tienda Uno', 'rubro' => Rubro::General, 'telefono' => '71234567'];
    $admin = ['name' => 'Ana Flores', 'email' => 'ana@ejemplo.com', 'password' => 'clave-segura-123'];

    $accion->ejecutar($empresa, $admin, 'inventario');

    expect(fn () => $accion->ejecutar($empresa, $admin, 'inventario'))->toThrow(ValidationException::class)
        ->and(Empresa::query()->count())->toBe(1)
        ->and(sinEscopo(Suscripcion::class)->count())->toBe(1);
});

test('a company whose trial ended stays active until the superadmin decides', function () {
    Notification::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 10:00:00', 'America/La_Paz'));
    $usuario = app(RegistrarEmpresaPrueba::class)->ejecutar(
        ['nombre' => 'Tienda Dos', 'rubro' => Rubro::General, 'telefono' => '71234567'],
        ['name' => 'Luis Rojas', 'email' => 'luis@ejemplo.com', 'password' => 'clave-segura-123'],
        'inventario',
    );
    $usuario->markEmailAsVerified();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-20 10:00:00', 'America/La_Paz'));
    $suscripcion = sinEscopo(Suscripcion::class)->where('empresa_id', $usuario->empresa_id)->sole();

    expect($suscripcion->enPrueba())->toBeFalse()
        ->and(Empresa::query()->findOrFail($usuario->empresa_id)->activa)->toBeTrue()
        ->and($usuario->fresh()->motivoBloqueo())->toBeNull();

    $this->post(route('login.store'), ['email' => 'luis@ejemplo.com', 'password' => 'clave-segura-123'])
        ->assertRedirect(route('dashboard'));

    CarbonImmutable::setTestNow();
});

test('the company sees its trial days and the superadmin sees which companies are on trial', function () {
    Notification::fake();
    $usuario = app(RegistrarEmpresaPrueba::class)->ejecutar(
        ['nombre' => 'Tienda Tres', 'rubro' => Rubro::General, 'telefono' => '71234567'],
        ['name' => 'Rosa Choque', 'email' => 'rosa@ejemplo.com', 'password' => 'clave-segura-123'],
        'inventario',
    );
    $usuario->markEmailAsVerified();

    $this->actingAs($usuario)->get(route('suscripcion'))->assertOk()
        ->assertSee('data-test="aviso-prueba"', false)
        ->assertSee('te quedan 15 días');

    $this->actingAs(User::factory()->superadmin()->create())->get(route('admin.empresas'))->assertOk()
        ->assertSee('Tienda Tres')->assertSee('Prueba gratis');
});

test('every trial button of the website leads to the sign-up page', function () {
    $html = $this->get(route('sitio.precios'))->assertOk()->getContent();

    expect($html)->toContain('href="'.route('registro').'"')
        ->toContain('href="'.route('registro', ['plan' => 'inventario']).'"')
        ->toContain('href="'.route('registro', ['plan' => 'siat']).'"');
});

test('login and sign-up share the two-column layout', function () {
    $this->get(route('login'))->assertOk()
        ->assertSee('class="acceso-info"', false)
        ->assertSee('Tu negocio en orden')
        ->assertSee('href="'.route('registro').'"', false);

    $this->get(route('registro'))->assertSee('class="acceso-info"', false)->assertSee('Prueba MoziStock en tu negocio');
});
