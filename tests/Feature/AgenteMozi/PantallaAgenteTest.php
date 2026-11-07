<?php

use App\Models\Sucursal;
use App\Models\User;

/*
 * Agente Mozi · Fase 1 (docs/AGENTE_MOZI_IA.md § 3): entrada propia en el menú, pantalla de chat + Lienzo
 * y menú lateral contraído al entrar sin pisar la preferencia guardada del usuario.
 */

test('administrators and sellers open Agente Mozi from the main menu', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Sucursal Norte']);
    $vendedor = User::factory()->vendedor($sucursal)->create();

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('agente-mozi'), false)
        ->assertSee('nav-agente', false);

    $this->actingAs($admin)->get(route('agente-mozi'))
        ->assertOk()
        ->assertSee('Agente Mozi')
        ->assertSee('¿Qué quieres descubrir de tu negocio hoy?')
        ->assertSee('Modo demostración: las respuestas usan datos sintéticos, no los de tu empresa.')
        ->assertSee('aria-current="page"', false);

    $this->actingAs($vendedor)->get(route('agente-mozi'))
        ->assertOk()
        ->assertSee('Sucursal Norte');
});

test('the screen asks for the collapsed sidebar without overwriting the saved preference', function () {
    [, $admin] = empresaConAdministrador();

    $this->actingAs($admin)->get(route('agente-mozi'))
        ->assertOk()
        ->assertSee('data-sidebar-auto="collapsed"', false);

    // El resto de pantallas no fuerza el estado del menú.
    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-sidebar-auto', false);
});

test('superadmins and guests cannot open Agente Mozi', function () {
    $superadmin = User::factory()->superadmin()->create();

    $this->get(route('agente-mozi'))->assertRedirect(route('login'));
    $this->actingAs($superadmin)->get(route('agente-mozi'))->assertForbidden();
});

test('when the module is disabled the screen and the menu entry disappear', function () {
    [, $admin] = empresaConAdministrador();
    config(['agente_mozi.habilitado' => false]);

    $this->actingAs($admin)->get(route('agente-mozi'))->assertForbidden();
    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('nav-agente', false);
});
