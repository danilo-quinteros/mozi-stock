<?php

use App\Livewire\Compras\NuevaCompra;
use App\Livewire\CuentasPagar\Lista as CuentasPagarLista;
use App\Livewire\Egresos\Panel as EgresosPanel;
use App\Models\CategoriaEgreso;
use App\Models\Compra;
use App\Models\CuentaPagar;
use App\Models\Egreso;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Estándar de formularios de finanzas: el egreso dice «Proveedor» (sale de Contactos › Proveedores), egreso, compra
 * y obligación permiten «+ Nuevo proveedor» sin salir del formulario, «Nueva obligación» es un modal de la lista
 * (como «Nueva cuenta por cobrar») y las condiciones del crédito se editan en su propia sección de la página.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'America/La_Paz'));
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->categoria = new CategoriaEgreso(['nombre' => 'Servicios']);
    $this->categoria->empresa_id = $this->empresa->id;
    $this->categoria->save();
    $this->actingAs($this->admin);
});

function proveedorExistente(int $empresaId, string $nombre, ?string $documento = null): Proveedor
{
    $proveedor = new Proveedor(['nombre' => $nombre, 'documento' => $documento]);
    $proveedor->empresa_id = $empresaId;
    $proveedor->save();

    return $proveedor;
}

test('Nuevo egreso llama «Proveedor» al campo y explica de dónde sale la lista', function () {
    Livewire::test(EgresosPanel::class)->call('nuevoEgreso')
        ->assertSee('Proveedor (opcional)')
        ->assertSee('Sin proveedor')
        ->assertSee('De tu lista de proveedores.')
        ->assertSee('+ Nuevo proveedor')
        ->assertDontSee('Beneficiario')
        ->set('condicion', 'pendiente')
        ->assertSee('Proveedor')
        ->set('categoria_id', (string) $this->categoria->id)->set('descripcion', 'Luz')->set('monto', '100')
        ->set('vencimiento', '2026-10-31')->call('registrarEgreso')
        ->assertHasErrors(['proveedor_id'])
        ->assertSee('Selecciona el proveedor: es obligatorio si queda saldo por pagar.');
});

test('desde el egreso se crea un proveedor, queda elegido y el egreso se registra con él', function () {
    $pantalla = Livewire::test(EgresosPanel::class)->call('nuevoEgreso')
        ->call('abrirProveedorRapido')
        ->assertSee('Nombre o razón social *')
        ->call('guardarProveedorRapido')
        ->assertHasErrors(['proveedorNombre'])
        ->set('proveedorNombre', 'ENDE Electricidad')->set('proveedorDocumento', '1023456029')->set('proveedorTelefono', '800101010')
        ->call('guardarProveedorRapido')
        ->assertHasNoErrors()
        ->assertSet('proveedorRapido', false)
        ->assertSee('Proveedor ENDE Electricidad agregado y elegido.');

    $proveedor = Proveedor::query()->where('nombre', 'ENDE Electricidad')->sole();
    expect($pantalla->get('proveedor_id'))->toBe((string) $proveedor->id)
        ->and($proveedor->only(['empresa_id', 'documento', 'telefono']))->toBe(['empresa_id' => $this->empresa->id, 'documento' => '1023456029', 'telefono' => '800101010']);

    // Doble clic: el formulario ya está cerrado, no crea otro.
    $pantalla->call('guardarProveedorRapido');

    $pantalla->set('categoria_id', (string) $this->categoria->id)->set('descripcion', 'Luz de octubre')->set('monto', '250')
        ->set('condicion', 'pendiente')->set('vencimiento', '2026-10-31')->call('registrarEgreso')->assertHasNoErrors();

    expect(Proveedor::query()->where('nombre', 'ENDE Electricidad')->count())->toBe(1)
        ->and(Egreso::query()->sole()->proveedor_id)->toBe($proveedor->id);
});

test('el alta rápida no duplica un proveedor con el mismo nombre ni acepta un NIT repetido', function () {
    $existente = proveedorExistente($this->empresa->id, 'Distribuidora Oriente', '445566');

    Livewire::test(EgresosPanel::class)->call('nuevoEgreso')
        ->call('abrirProveedorRapido')->set('proveedorNombre', 'Distribuidora Oriente')->call('guardarProveedorRapido')
        ->assertHasNoErrors()
        ->assertSet('proveedor_id', (string) $existente->id)
        ->assertSee('Ya tenías a Distribuidora Oriente: quedó elegido.')
        ->call('abrirProveedorRapido')->set('proveedorNombre', 'Otro nombre')->set('proveedorDocumento', '445566')->call('guardarProveedorRapido')
        ->assertHasErrors(['proveedorDocumento'])
        ->assertSet('proveedorRapido', true);

    expect(Proveedor::query()->count())->toBe(1);
});

test('aislamiento y permisos: el proveedor se crea en la propia empresa y un vendedor no puede crearlo', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    proveedorExistente($empresaB->id, 'Proveedor de B');
    $this->actingAs($this->admin);

    // Mismo nombre que un proveedor de otra empresa: no lo elige, crea el propio.
    $pantalla = Livewire::test(EgresosPanel::class)->call('nuevoEgreso')
        ->call('abrirProveedorRapido')->set('proveedorNombre', 'Proveedor de B')->call('guardarProveedorRapido')
        ->assertHasNoErrors();
    $propio = Proveedor::query()->where('nombre', 'Proveedor de B')->sole();
    expect($propio->empresa_id)->toBe($this->empresa->id)
        ->and($pantalla->get('proveedor_id'))->toBe((string) $propio->id)
        ->and(Proveedor::withoutGlobalScopes()->where('nombre', 'Proveedor de B')->count())->toBe(2);

    // Un vendedor no entra a las pantallas que ofrecen el alta rápida ni puede crear proveedores.
    $vendedor = User::factory()->vendedor($this->sucursal)->create();
    $this->actingAs($vendedor);
    Livewire::test(NuevaCompra::class)->assertForbidden();
    Livewire::test(EgresosPanel::class)->assertForbidden();
    expect($vendedor->can('create', Proveedor::class))->toBeFalse();
});

test('Nueva compra permite agregar un proveedor nuevo y registrar la compra con él', function () {
    $arroz = productoDe($this->empresa, ['nombre' => 'Arroz 5kg']);

    Livewire::test(NuevaCompra::class)
        ->assertSee('De tu lista de proveedores.')
        ->assertSee('+ Nuevo proveedor')
        ->call('abrirProveedorRapido')
        ->set('proveedorNombre', 'Molinos del Sur')
        ->call('guardarProveedorRapido')
        ->assertHasNoErrors()
        ->assertSee('Molinos del Sur')
        ->call('agregarProducto', $arroz->id)->set('lineas.0.costo', '40')
        ->call('revisar')->call('confirmar')
        ->assertHasNoErrors();

    expect(Compra::query()->sole()->proveedor->nombre)->toBe('Molinos del Sur');
});

test('Nueva obligación se registra desde un modal de la lista, también con un proveedor nuevo', function () {
    Livewire::test(CuentasPagarLista::class)
        ->assertSee('Nueva obligación')
        ->call('nuevaObligacion')
        ->assertSet('formObligacion', true)
        ->assertSee('Registrar obligación')
        ->call('abrirProveedorRapido')->set('proveedorNombre', 'Banco Unión')->call('guardarProveedorRapido')
        ->set('concepto', 'Préstamo anterior')->set('importe', '1200')->set('pagado', '200')
        ->call('registrarObligacion')
        ->assertHasErrors(['vencimiento'])
        ->set('vencimiento', '2026-12-31')
        ->call('registrarObligacion')
        ->assertHasNoErrors()
        ->assertRedirect();

    $cuenta = CuentaPagar::query()->sole();
    expect($cuenta->proveedor->nombre)->toBe('Banco Unión')
        ->and($cuenta->origen)->toBe('manual')
        ->and($cuenta->saldo_pendiente)->toBe('1000.00');
});

test('el enlace antiguo de Nueva obligación abre el modal en la lista y un vendedor no entra', function () {
    $this->get(route('cuentas-pagar.crear'))->assertRedirect(route('cuentas-pagar.index', ['nueva' => 1]));
    $this->get(route('cuentas-pagar.index', ['nueva' => 1]))->assertOk()->assertSee('Ya pagado antes (Bs, opcional)');

    $this->actingAs(User::factory()->vendedor($this->sucursal)->create());
    $this->get(route('cuentas-pagar.index', ['nueva' => 1]))->assertForbidden();
});
