<?php

use App\Domain\Suscripciones\Actions\ConfigurarSuscripcion;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Scopes\EmpresaScope;
use App\Models\Suscripcion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

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

test('a company without the agent plan does not see Mozi in the menu nor can open or use it', function () {
    [, $admin] = empresaConPlan($this->planSiat);

    $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee('nav-agente', false);
    $this->actingAs($admin)->get(route('agente-mozi'))->assertForbidden();
    $this->actingAs($admin)->getJson(route('agente-mozi.dataset', 'ds_'.str_repeat('a', 32)))->assertForbidden();

    expect(fn () => preguntarAMozi($admin, 'Hola'))->toThrow(AuthorizationException::class);
});

test('a company on the agent plan uses Mozi, and another company without it stays blocked', function () {
    [, $conAgente] = empresaConPlan($this->planAgente);
    [, $sinAgente] = empresaConPlan($this->planSiat);

    $this->actingAs($conAgente)->get(route('dashboard'))->assertOk()->assertSee('nav-agente', false);
    $this->actingAs($conAgente)->get(route('agente-mozi'))->assertOk();
    expect(preguntarAMozi($conAgente, 'Hola')->id)->not->toBeNull();

    $this->actingAs($sinAgente)->get(route('agente-mozi'))->assertForbidden();
});

test('a company without a subscription has no agent', function () {
    [, $admin] = empresaConAdministrador();

    $this->actingAs($admin)->get(route('agente-mozi'))->assertForbidden();
});

test('changing the plan gives or removes the agent right away', function () {
    [$empresa, $admin] = empresaConPlan($this->planSiat);
    $this->actingAs($admin)->get(route('agente-mozi'))->assertForbidden();

    app(ConfigurarSuscripcion::class)->ejecutar($this->superadmin, $empresa, '599.00', '2026-11-30', null, $this->planAgente->id);
    $this->actingAs($admin)->get(route('agente-mozi'))->assertOk();

    // El superadmin quita el agente del plan: deja de estar disponible.
    $this->planAgente->forceFill(['agente_ia' => false])->save();
    $this->actingAs($admin)->get(route('agente-mozi'))->assertForbidden();
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
    $this->actingAs($admin)->get(route('agente-mozi'))->assertForbidden();

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
