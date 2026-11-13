<?php

use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Models\Sucursal;

/*
 * Despachos en el menú (pedido del propietario, 13/11/2026): con la función Despachos (rubro
 * distribuidora) aparece un acceso directo arriba de «Ventas y finanzas» que abre la pestaña
 * Despachos de Pedidos. Sin la función no aparece.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $this->actingAs($this->admin);
});

test('with Despachos the menu shows it above Ventas y finanzas and marks it active on its tab', function () {
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Distribuidora, [Capacidad::Despachos]);

    $html = $this->get(route('pedidos.index'))->assertOk()->getContent();
    $menu = substr($html, (int) strpos($html, 'nav-groups'));

    expect($menu)->toContain('data-test="nav-despachos"')
        ->and(strpos($menu, 'data-test="nav-despachos"'))->toBeLessThan(strpos($menu, 'Ventas y finanzas'))
        ->and($menu)->toContain('href="'.e(route('pedidos.index', ['pestana' => 'despachos'])).'"');

    // En la lista de pedidos, Despachos no está activo; en su pestaña, sí (y Pedidos deja de estarlo).
    expect($menu)->not->toMatch('/class="nav-item active"[^>]*data-test="nav-despachos"/');

    $enDespachos = $this->get(route('pedidos.index', ['pestana' => 'despachos']))->assertOk()->getContent();
    expect($enDespachos)->toMatch('/class="nav-item active"[^>]*data-test="nav-despachos"/')
        ->and($enDespachos)->not->toMatch('/class="nav-item active"[^>]*title="Pedidos"/');
});

test('without Despachos the menu does not show it', function () {
    $this->get(route('pedidos.index'))->assertOk()->assertDontSee('data-test="nav-despachos"', false);
});
