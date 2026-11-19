<?php

use App\Domain\Suscripciones\Actions\ConfigurarSuscripcion;
use App\Livewire\AgenteMozi\Panel as AgenteMoziPanel;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Scopes\EmpresaScope;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * En SaaS, Agente Mozi es solo para empresas cuyo plan lo incluye (planes.agente_ia). El vencimiento
 * no lo quita (T26); un cambio a un plan menor lo quita cuando pasa la fecha ya pagada.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'America/La_Paz'));
    $this->superadmin = User::factory()->superadmin()->create();
    $this->planSiat = Plan::factory()->siat()->create(['codigo' => 'siat-prueba']);
    $this->planAgente = Plan::factory()->agenteIa()->create(['codigo' => 'agente-prueba']);
});

function empresaConPlan(Plan $plan, string $vencimiento = '2026-11-30'): array
{
    [$empresa, $admin] = empresaConAdministrador();
    app(ConfigurarSuscripcion::class)->ejecutar(test()->superadmin, $empresa, (string) $plan->precio_mensual, $vencimiento, null, $plan->id);

    return [$empresa, $admin];
}

function suscripcionDe(Empresa $empresa): Suscripcion
{
    return Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresa->id)->firstOrFail();
}

test('without the agent plan the admin sees Mozi locked in the menu and gets the change-plan notice, never the agent', function () {
    [$empresa, $admin] = empresaConPlan($this->planSiat);
    $vendedor = User::factory()->vendedor(Sucursal::factory()->create(['empresa_id' => $empresa->id]))->create();

    $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('nav-agente', false)->assertSee('data-test="nav-agente-sin-plan"', false);
    $this->actingAs($admin)->get(route('agente-mozi'))->assertOk()
        ->assertSee('data-test="plan-requerido"', false)
        ->assertSee('Agente Mozi no está incluido en tu plan')
        ->assertSee(route('suscripcion', ['planes' => 1]), false)
        ->assertDontSee('Tus charlas');
    $this->actingAs($admin)->getJson(route('agente-mozi.dataset', 'ds_'.str_repeat('a', 32)))->assertForbidden();

    // El aviso no abre nada del agente: sus acciones siguen bloqueadas, aunque se llamen directo.
    expect(fn () => preguntarAMozi($admin, 'Hola'))->toThrow(AuthorizationException::class);
    Livewire::actingAs($admin)->test(AgenteMoziPanel::class)->assertSet('sinPlan', true)->call('enviar', 'Hola', (string) Str::uuid())->assertForbidden();
    expect(fn () => Livewire::actingAs($admin)->test(AgenteMoziPanel::class)->set('sinPlan', false))->toThrow(Exception::class);

    // Los vendedores no pueden cambiar de plan: ni lo ven en el menú ni reciben el aviso.
    $this->actingAs($vendedor)->get(route('dashboard'))->assertOk()->assertDontSee('nav-agente', false);
    $this->actingAs($vendedor)->get(route('agente-mozi'))->assertForbidden();
});

test('a company on the agent plan uses Mozi, and another company without it stays blocked', function () {
    [, $conAgente] = empresaConPlan($this->planAgente);
    [, $sinAgente] = empresaConPlan($this->planSiat);

    $this->actingAs($conAgente)->get(route('dashboard'))->assertOk()->assertSee('nav-agente', false);
    $this->actingAs($conAgente)->get(route('agente-mozi'))->assertOk();
    expect(preguntarAMozi($conAgente, 'Hola')->id)->not->toBeNull();

    $this->actingAs($conAgente)->get(route('dashboard'))->assertDontSee('data-test="nav-agente-sin-plan"', false);
    $this->actingAs($sinAgente)->get(route('agente-mozi'))->assertOk()->assertSee('data-test="plan-requerido"', false);
});

test('a company without a subscription has no agent, only the notice', function () {
    [, $admin] = empresaConAdministrador();

    $this->actingAs($admin)->get(route('agente-mozi'))->assertOk()->assertSee('data-test="plan-requerido"', false);
    expect(fn () => preguntarAMozi($admin, 'Hola'))->toThrow(AuthorizationException::class);
});

test('changing the plan gives or removes the agent right away', function () {
    [$empresa, $admin] = empresaConPlan($this->planSiat);
    $this->actingAs($admin)->get(route('agente-mozi'))->assertOk()->assertSee('data-test="plan-requerido"', false);

    app(ConfigurarSuscripcion::class)->ejecutar($this->superadmin, $empresa, '599.00', '2026-11-30', null, $this->planAgente->id);
    $this->actingAs($admin)->get(route('agente-mozi'))->assertOk();

    // El superadmin quita el agente del plan: deja de estar disponible.
    $this->planAgente->forceFill(['agente_ia' => false])->save();
    $this->actingAs($admin)->get(route('agente-mozi'))->assertOk()->assertSee('data-test="plan-requerido"', false);
});

test('an expired subscription keeps the agent, a scheduled downgrade removes it after the paid date', function () {
    [$empresa, $admin] = empresaConPlan($this->planAgente, '2026-11-20');

    $suscripcion = suscripcionDe($empresa);
    $suscripcion->plan_siguiente_id = $this->planSiat->id;
    $suscripcion->save();

    // Hasta el vencimiento pagado sigue con el agente.
    $this->actingAs($admin)->get(route('agente-mozi'))->assertOk();

    // Otro día es otra petición: el servicio scoped empieza vacío (en la prueba la app se reutiliza).
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00', 'America/La_Paz'));
    app()->forgetScopedInstances();
    $this->actingAs($admin)->get(route('agente-mozi'))->assertOk()->assertSee('data-test="plan-requerido"', false);

    // Sin cambio programado, vencer no quita nada (T26).
    $suscripcion->plan_siguiente_id = null;
    $suscripcion->save();
    $this->actingAs($admin)->get(route('agente-mozi'))->assertOk();
});

test('in standalone mode there are no plans and every company user can use Mozi', function () {
    config(['mozistock.deployment_mode' => 'standalone']);
    [, $admin] = empresaConAdministrador();

    $this->actingAs($admin)->get(route('agente-mozi'))->assertOk();
});

test('the superadmin still cannot use Mozi as a company user', function () {
    $this->actingAs($this->superadmin)->get(route('agente-mozi'))->assertForbidden();
});

test('«Cambiar de plan» from the notice opens the plan picker, which shows which plans include Mozi and SIAT', function () {
    [, $admin] = empresaConPlan($this->planSiat);

    $this->actingAs($admin)->get(route('suscripcion'))->assertOk()->assertDontSee('Cambiar plan de suscripción');
    $this->actingAs($admin)->get(route('suscripcion', ['planes' => 1]))->assertOk()
        ->assertSee('Cambiar plan de suscripción')
        ->assertSee($this->planAgente->titulo)
        ->assertSeeHtmlInOrder([$this->planAgente->titulo, 'data-test="plan-incluye"', 'Agente Mozi']);
});
