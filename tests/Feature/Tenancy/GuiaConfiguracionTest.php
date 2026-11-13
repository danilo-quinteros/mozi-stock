<?php

use App\Domain\Facturacion\PreparacionFacturacion;
use App\Domain\Tenancy\Actions\CrearEmpresa;
use App\Domain\Tenancy\Actions\GuardarSucursal;
use App\Domain\Tenancy\GuiaConfiguracion;
use App\Enums\Rubro;
use App\Livewire\Empresa\Configuracion;
use App\Livewire\Facturacion\Asistente;
use App\Livewire\Facturacion\Puntos;
use App\Livewire\Inicio\Guia;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSincronizacion;
use App\Models\Sucursal;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Facturacion\SiatPruebas;

/**
 * Empresa registrada por el superadmin con la acción real, como en el panel global.
 *
 * @param  array<string, mixed>  $datos
 * @return array{0: Empresa, 1: User, 2: Sucursal}
 */
function empresaRecienCreada(array $datos = []): array
{
    $empresa = app(CrearEmpresa::class)->ejecutar(User::factory()->superadmin()->create(),
        $datos + ['nombre' => 'Comercial Nueva', 'rubro' => Rubro::General, 'telefono' => '70000000'],
        ['name' => 'Administradora', 'email' => 'admin@nueva.test', 'password' => 'ClaveSegura12345']);

    return [
        $empresa,
        User::query()->where('empresa_id', $empresa->id)->sole(),
        Sucursal::withoutGlobalScopes()->where('empresa_id', $empresa->id)->sole(),
    ];
}

/**
 * @return array<string, bool>
 */
function pasosGuia(User $usuario): array
{
    return collect(GuiaConfiguracion::para($usuario)['pasos'] ?? [])->pluck('listo', 'clave')->all();
}

test('al registrar una empresa se crea «Casa Matriz» y su administrador ve la guía al entrar', function () {
    [$empresa, $admin, $matriz] = empresaRecienCreada();

    expect($matriz->nombre)->toBe('Casa Matriz')
        ->and($matriz->codigo)->toBe('CM')
        ->and($matriz->activa)->toBeTrue()
        ->and($empresa->guia_completada_en)->toBeNull()
        ->and(Auditoria::query()->where('accion', 'sucursal.creada')->where('entidad_id', $matriz->id)->exists())->toBeTrue();

    $this->actingAs($admin)->get(route('dashboard'))->assertOk()
        ->assertSee('¡Bienvenido a MoziStock!')
        ->assertSee('Configura tu negocio')
        ->assertSeeLivewire(Guia::class)
        ->assertSee('1 de 4 completados')
        ->assertDontSee('Configura tu sucursal')
        ->assertSee(route('productos.crear'))
        ->assertDontSee('Configuración de facturación SIAT');
});

test('cada paso se completa con los datos reales y la guía desaparece al terminar', function () {
    [$empresa, $admin, $matriz] = empresaRecienCreada(['telefono' => null]);
    $this->actingAs($admin);

    expect(pasosGuia($admin))->toBe(['empresa' => false, 'productos' => false, 'inventario' => false, 'venta' => false]);

    Livewire::test(Configuracion::class)->set('telefono', '76543210')->call('guardar')->assertHasNoErrors();
    $producto = productoDe($empresa);

    expect(pasosGuia($admin))->toBe(['empresa' => true, 'productos' => true, 'inventario' => false, 'venta' => false]);

    cargarStock($admin, $matriz, $producto, '10');
    $pedido = crearPedido($admin, $matriz, [['producto_id' => $producto->id, 'cantidad' => '2']]);

    // Un pedido pendiente no es una venta.
    expect(pasosGuia($admin))->toBe(['empresa' => true, 'productos' => true, 'inventario' => true, 'venta' => false]);
    $this->get(route('dashboard'))->assertOk()->assertSee('3 de 4 completados')->assertSee(route('pos'));

    confirmarVenta($admin, $pedido, ['metodo' => 'efectivo']);

    $this->get(route('dashboard'))->assertOk()->assertDontSee('Configura tu negocio')->assertSee('Hola, Administradora');
    expect($empresa->fresh()->guia_completada_en)->not->toBeNull();

    // Terminada, no vuelve aunque después cambien los datos.
    app(GuardarSucursal::class)->actualizar($admin, $matriz, ['nombre' => 'Casa Matriz', 'codigo' => 'CM', 'direccion' => null]);
    expect(GuiaConfiguracion::para($admin))->toBeNull();
});

test('los vendedores no ven la guía ni la configuración fiscal', function () {
    [$empresa, $admin, $matriz] = empresaRecienCreada();
    $empresa->forceFill(['siat_habilitado' => true])->save();
    $vendedor = User::factory()->vendedor($matriz)->create();

    expect(GuiaConfiguracion::para($vendedor))->toBeNull();
    $this->actingAs($vendedor)->get(route('dashboard'))->assertOk()
        ->assertDontSeeLivewire(Guia::class)
        ->assertDontSee('Configura tu negocio')
        ->assertDontSee('Configuración de facturación SIAT')
        ->assertDontSee(route('facturacion'));

    // Ni el componente de la guía ni los formularios embebidos le sirven, aunque los llame directo.
    Livewire::test(Guia::class)->assertForbidden();
    Livewire::test(Configuracion::class, ['embebido' => true])->assertForbidden();
    Livewire::test(Puntos::class, ['empresaId' => $empresa->id, 'embebido' => true])->assertForbidden();

    $this->actingAs($admin)->get(route('dashboard'))->assertOk()
        ->assertSee('1 de 5 completados')
        ->assertSee('Configuración de facturación SIAT');
});

test('la parte SIAT de la guía usa la misma revisión que Facturación', function () {
    SiatPruebas::configurar();
    ['empresa' => $empresa, 'admin' => $admin, 'configuracion' => $configuracion] = SiatPruebas::empresaFiscal();
    $this->actingAs($admin);

    // Token, NIT, razón social, sucursal y punto de venta ya están; faltan catálogos (paso del token) y actividad (datos fiscales).
    $siat = GuiaConfiguracion::para($admin)['siat'];
    expect(collect($siat['pasos'])->pluck('titulo')->all())->toBe(['Asociación y token', 'Datos fiscales', 'Sucursal y punto de venta', 'Verificar conexión'])
        ->and(collect($siat['pasos'])->pluck('listo')->all())->toBe([false, false, true, false])
        ->and($siat['actual'])->toBe(0)
        ->and(pasosGuia($admin)['siat'])->toBeFalse();

    // La guía embebe el asistente de Facturación: abre en «Revisar», que pide la actividad y el producto SIN
    // (los catálogos se sincronizan al validar el token, ya no son una fila de Revisar).
    $this->get(route('dashboard'))->assertOk()
        ->assertSee('Conecta tu empresa con Impuestos Nacionales')
        ->assertSee('Paso 4 · Revisar')
        ->assertSee('Actividad y producto SIN')
        ->assertDontSee(route('facturacion', ['seccion' => 'catalogos']));
    $this->get(route('facturacion', ['seccion' => 'facturas']))->assertOk()->assertSee('Catálogos del SIN sincronizados');

    foreach (PreparacionFacturacion::CATALOGOS_NECESARIOS as $catalogo) {
        $sincronizacion = new SiatSincronizacion;
        $sincronizacion->empresa_id = $empresa->id;
        $sincronizacion->catalogo = $catalogo->name;
        $sincronizacion->sincronizado_en = now();
        $sincronizacion->save();
    }
    $configuracion->forceFill(['actividad_economica' => '620100', 'producto_sin' => 83141])->save();

    // CUIS y CUFD se obtienen solos al facturar: no detienen la guía.
    expect(collect(PreparacionFacturacion::revisar($empresa))->every(fn (array $p): bool => $p['listo'] || $p['automatico']))->toBeTrue()
        ->and(pasosGuia($admin)['siat'])->toBeTrue();
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Configuración de facturación SIAT');
});

test('la guía de una empresa solo cuenta sus propios datos', function () {
    [, $adminA] = empresaRecienCreada();
    [$empresaB, $adminB] = empresaConAdministrador(['telefono' => '71111111']);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id, 'direccion' => 'Calle 1']);
    $productoB = productoDe($empresaB);
    $this->actingAs($adminB);
    cargarStock($adminB, $sucursalB, $productoB, '5');
    confirmarVenta($adminB, crearPedido($adminB, $sucursalB, [['producto_id' => $productoB->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);

    $this->actingAs($adminA);

    expect(pasosGuia($adminA))->toBe(['empresa' => true, 'productos' => false, 'inventario' => false, 'venta' => false]);
});

test('los popups usan los componentes reales y al cerrarlos la guía se actualiza', function () {
    [, $admin, $matriz] = empresaRecienCreada(['telefono' => null]);
    $this->actingAs($admin);

    $guia = Livewire::test(Guia::class)->assertSee('0 de 4 completados')
        ->call('abrir', 'empresa')->assertSet('popup', 'empresa')->assertSee('Guardar cambios');

    // Mi empresa en modo embebido: solo el formulario, mismas validaciones y la misma acción.
    Livewire::test(Configuracion::class, ['embebido' => true])->assertDontSee('Acerca del sistema')
        ->set('telefono', '76543210')->call('guardar')->assertHasNoErrors();
    $guia->call('cerrar')->assertSet('popup', null)->assertSee('1 de 4 completados');

    // La sucursal ya no es un paso: «Casa Matriz» se crea al registrar la empresa.
    $guia->call('abrir', 'sucursal')->assertSet('popup', null)->assertDontSee('Configura tu sucursal');

    expect(fn () => $guia->set('popup', 'empresa'))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('la guía embebe el asistente SIAT y el popup de sucursal solo existe con la facturación habilitada', function () {
    [$empresa, $admin, $matriz] = empresaRecienCreada();
    $this->actingAs($admin);

    Livewire::test(Guia::class)->call('abrir', 'sucursal-siat')->assertSet('popup', null)
        ->call('abrir', 'no-existe')->assertSet('popup', null)
        ->assertDontSeeLivewire(Asistente::class);

    $empresa->forceFill(['siat_habilitado' => true])->save();
    // Token y datos fiscales ya no son popups: se cargan en el asistente embebido (el mismo de Facturación).
    Livewire::test(Guia::class)
        ->assertSeeLivewire(Asistente::class)
        ->assertSee('Conecta tu empresa con Impuestos Nacionales')
        ->call('abrir', 'token')->assertSet('popup', null)
        ->call('abrir', 'datos-fiscales')->assertSet('popup', null)
        ->call('abrir', 'sucursal-siat')->assertSee('Código SIN');

    // Una sola raíz también embebido: con varias, Livewire pierde el componente al guardar dentro de la guía.
    $html = ltrim(Livewire::test(Asistente::class, ['empresaId' => $empresa->id, 'embebido' => true])->html());
    expect($html)->toStartWith('<div')
        ->and(substr_count($html, 'wire:id='))->toBe(1)
        ->and(strstr($html, '>', true))->toContain('class="stack"');

    // Facturación → Puntos en modo embebido: la sucursal principal con código 0 y su punto de venta 0.
    Livewire::test(Puntos::class, ['empresaId' => $empresa->id, 'embebido' => true])
        ->assertSet('sucursalId', $matriz->id)->assertSet('codigo', '0')
        ->set('municipio', 'Santa Cruz')->call('guardarSucursal')->assertHasNoErrors()
        ->assertSet('sucursalId', $matriz->id);

    expect(SiatPuntoVenta::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('codigo', 0)->exists())->toBeTrue()
        ->and(GuiaConfiguracion::para($admin)['siat']['pasos'][2]['listo'])->toBeTrue();
});
