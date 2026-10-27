<?php

use App\Domain\Ventas\ValidadorPago;
use App\Models\User;
use Illuminate\Validation\ValidationException;

test('el usuario cambia el idioma de la interfaz y la preferencia se conserva', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()->assertSee('lang="es"', false)->assertSee('Cerrar sesión');

    $this->actingAs($user)->from(route('dashboard'))
        ->post(route('idioma'), ['idioma' => 'en'])
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->idioma)->toBe('en');

    // Sesión nueva: el idioma sale del perfil del usuario, no de la sesión anterior.
    $this->flushSession();
    $this->actingAs($user->fresh())->get(route('dashboard'))
        ->assertOk()->assertSee('lang="en"', false)->assertSee('Log out')->assertDontSee('Cerrar sesión');

    $this->actingAs($user)->post(route('idioma'), ['idioma' => 'fr'])->assertSessionHasErrors('idioma');
    expect($user->fresh()->idioma)->toBe('en');
});

test('Pedidos y ventas se muestra en inglés, incluidas las validaciones de pago', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $admin->forceFill(['idioma' => 'en'])->save();
    $this->actingAs($admin);

    $this->get(route('pedidos.index'))->assertOk()
        ->assertSee('Orders')->assertSee('Order list')->assertDontSee('Lista de pedidos');
    $this->get(route('pedidos.crear'))->assertOk()
        ->assertSee('Order details')->assertSee('Confirm sale')->assertDontSee('Confirmar venta');

    app()->setLocale('en');
    expect(fn () => ValidadorPago::inicial('100.00', 'mixto', false, null, '30', '20'))
        ->toThrow(ValidationException::class, 'Cash + electronic must add up to Bs 100,00 (order total).');
});
