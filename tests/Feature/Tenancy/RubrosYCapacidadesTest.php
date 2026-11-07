<?php

use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Livewire\Admin\Empresas;
use App\Livewire\Auth\Registro;
use App\Livewire\Concerns\ExigeCapacidad;
use App\Livewire\Empresa\FuncionesNegocio;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Livewire;

/**
 * Etapa R0 de ESPECIFICACION_RUBROS_Y_CAPACIDADES.md (§ 7). Los casos que dependen de los módulos
 * (despachos, lotes, variantes) quedan como «todo» para sus etapas: hoy ninguna capacidad tiene datos.
 */

/** Componente mínimo de un módulo por rubro, para probar el rechazo en mount y en acciones. */
class ComponenteConDespachos extends Component
{
    use ExigeCapacidad;

    public int $acciones = 0;

    public function mount(): void
    {
        $this->exigirCapacidad(Capacidad::Despachos);
    }

    public function despachar(): void
    {
        $this->exigirCapacidad(Capacidad::Despachos);
        $this->acciones++;
    }

    public function render(): string
    {
        return '<div>despachos</div>';
    }
}

/**
 * Registro público completo con el rubro indicado.
 */
function registrarNegocio(string $rubro, string $email): void
{
    Livewire::test(Registro::class)
        ->set('name', 'Persona de Prueba')->set('email', $email)->set('password', 'clave-segura-123')
        ->call('avanzar')
        ->set('empresa', 'Negocio '.$rubro)->set('telefono', '71234567')->set('rubro', $rubro)
        ->call('avanzar')->assertHasNoErrors()->assertSet('paso', 3)
        ->set('plan', 'inventario')->set('acepta', true)
        ->call('avanzar')->assertHasNoErrors();
}

/**
 * @return list<string>
 */
function capacidadesEnBase(Empresa $empresa): array
{
    return DB::table('empresa_capacidades')->where('empresa_id', $empresa->id)->where('activa', true)
        ->orderBy('capacidad')->pluck('capacidad')->all();
}

/**
 * Filas de las tablas de dinero y stock, para comprobar que cambiar funciones no las toca.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function instantaneaNegocio(): array
{
    $tablas = ['productos', 'stock_niveles', 'operaciones_inventario', 'movimientos_stock', 'pedidos', 'ventas',
        'venta_items', 'pagos_venta', 'movimientos_caja', 'cuentas_cobrar', 'cobros', 'devoluciones'];

    return collect($tablas)->mapWithKeys(fn (string $tabla): array => [
        $tabla => DB::table($tabla)->orderBy('id')->get()->map(fn (object $fila): array => (array) $fila)->all(),
    ])->all();
}

beforeEach(function () {
    RateLimiter::clear('registro:127.0.0.1');
});

test('the public sign-up stores each rubro with exactly its capabilities', function (Rubro $rubro) {
    Notification::fake();

    registrarNegocio($rubro->value, 'negocio-'.$rubro->value.'@ejemplo.com');

    $usuario = User::query()->where('email', 'negocio-'.$rubro->value.'@ejemplo.com')->sole();
    $empresa = Empresa::query()->findOrFail($usuario->empresa_id);
    $auditoria = Auditoria::query()->where('accion', 'empresa.creada')->where('empresa_id', $empresa->id)->sole();
    $esperadas = array_map(fn (Capacidad $c): string => $c->value, $rubro->capacidades());
    sort($esperadas);

    expect($empresa->rubro)->toBe($rubro)
        ->and(capacidadesEnBase($empresa))->toBe($esperadas)
        ->and(DB::table('empresa_capacidades')->where('empresa_id', $empresa->id)->pluck('cambiada_por')->unique()->filter()->all())
        ->toBe($esperadas === [] ? [] : [$usuario->id])
        ->and($auditoria->despues['rubro'])->toBe($rubro->value)
        ->and($auditoria->despues['capacidades'])->toBe(array_map(fn (Capacidad $c): string => $c->value, $rubro->capacidades()));

    foreach (Capacidad::cases() as $capacidad) {
        expect($empresa->tieneCapacidad($capacidad))->toBe(in_array($capacidad, $rubro->capacidades(), true));
    }
})->with(fn () => array_map(fn (Rubro $r): array => [$r], Rubro::cases()));

test('the «Tu negocio» step does not continue without a valid rubro', function () {
    $prueba = Livewire::test(Registro::class)
        ->set('name', 'Persona de Prueba')->set('email', 'sin-rubro@ejemplo.com')->set('password', 'clave-segura-123')
        ->call('avanzar')
        ->set('empresa', 'Tienda sin rubro')->set('telefono', '71234567')
        ->assertSee('¿Qué tipo de negocio tienes?')
        ->assertSee('Tienda de ropa y calzado')
        ->assertSee('Farmacias, boticas y veterinarias con medicamentos.')
        ->assertSet('rubro', '')
        ->call('avanzar')->assertHasErrors(['rubro' => 'required'])->assertSet('paso', 2)
        ->assertSee('Elige qué tipo de negocio tienes.');

    $prueba->set('rubro', 'panaderia')->call('avanzar')->assertHasErrors(['rubro'])->assertSet('paso', 2);
    $prueba->set('rubro', 'farmacia')->call('avanzar')->assertHasNoErrors()->assertSet('paso', 3);

    expect(Empresa::query()->count())->toBe(0);
});

test('the superadmin must choose the rubro and the company is born with its capabilities', function () {
    $superadmin = User::factory()->superadmin()->create();

    $pantalla = Livewire::actingAs($superadmin)->test(Empresas::class)
        ->call('crear')
        ->set('nombre', 'Distribuidora Andina')
        ->set('admin_nombre', 'Administradora')->set('admin_email', 'admin@andina.test')
        ->set('admin_password', 'ClaveSegura12345')->set('admin_password_confirmation', 'ClaveSegura12345')
        ->call('guardar')
        ->assertHasErrors(['rubro' => 'required']);

    expect(Empresa::query()->count())->toBe(0);

    $pantalla->set('rubro', 'distribuidora')->call('guardar')->assertHasNoErrors();

    $empresa = Empresa::query()->sole();
    $auditoria = Auditoria::query()->where('accion', 'empresa.creada')->sole();

    expect($empresa->rubro)->toBe(Rubro::Distribuidora)
        ->and(capacidadesEnBase($empresa))->toBe(['despachos'])
        ->and(DB::table('empresa_capacidades')->where('empresa_id', $empresa->id)->value('cambiada_por'))->toBe($superadmin->id)
        ->and($auditoria->usuario_id)->toBe($superadmin->id)
        ->and($auditoria->despues['rubro'])->toBe('distribuidora');
});

test('companies that already existed are General, without capabilities and with the same behavior', function () {
    // Así quedan las filas que ya existían: la columna nueva toma su valor por defecto.
    $id = DB::table('empresas')->insertGetId(['nombre' => 'Empresa antigua', 'activa' => true, 'siat_habilitado' => false, 'created_at' => now(), 'updated_at' => now()]);
    $empresa = Empresa::query()->findOrFail($id);
    $admin = User::factory()->administrador($empresa)->create();

    expect(DB::table('empresas')->where('id', $id)->value('rubro'))->toBe('general')
        ->and($empresa->rubro)->toBe(Rubro::General)
        ->and($empresa->capacidadesActivas())->toBe([])
        ->and(DB::table('empresa_capacidades')->count())->toBe(0);

    foreach (Capacidad::cases() as $capacidad) {
        expect(Gate::forUser($admin)->allows('capacidad', $capacidad))->toBeFalse();
    }

    // La configuración se abre igual que antes y muestra General sin funciones.
    Livewire::actingAs($admin)->test(FuncionesNegocio::class)
        ->assertSet('rubro', 'general')->assertSet('capacidades', []);
});

test('the database rejects unknown rubros and capabilities', function () {
    [$empresa] = empresaConAdministrador();

    expect(fn () => DB::table('empresas')->where('id', $empresa->id)->update(['rubro' => 'panaderia']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('empresa_capacidades')->insert(['empresa_id' => $empresa->id, 'capacidad' => 'mesas', 'activa' => true]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('empresa_capacidades')->insert([
            ['empresa_id' => $empresa->id, 'capacidad' => 'cocina', 'activa' => true],
            ['empresa_id' => $empresa->id, 'capacidad' => 'cocina', 'activa' => false],
        ]))->toThrow(QueryException::class);
});

test('tieneCapacidad reads the capabilities of a company with a single query per request', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $this->actingAs($admin);
    app(CambiarFuncionesEmpresa::class)->ejecutar($admin, $empresa, Rubro::Ropa, [Capacidad::Variantes, Capacidad::LotesVencimientos]);
    app()->forgetScopedInstances();

    $consultas = 0;
    DB::listen(function ($consulta) use (&$consultas): void {
        if (str_contains($consulta->sql, 'empresa_capacidades')) {
            $consultas++;
        }
    });

    $otraInstancia = Empresa::query()->findOrFail($empresa->id);

    expect($empresa->tieneCapacidad(Capacidad::Variantes))->toBeTrue()
        ->and($empresa->tieneCapacidad(Capacidad::LotesVencimientos))->toBeTrue()
        ->and($otraInstancia->tieneCapacidad(Capacidad::Despachos))->toBeFalse()
        ->and($otraInstancia->tieneCapacidad(Capacidad::Cocina))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('capacidad', 'variantes'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('capacidad', Capacidad::Despachos))->toBeFalse()
        ->and($empresa->capacidadesActivas())->toBe([Capacidad::Variantes, Capacidad::LotesVencimientos])
        ->and($consultas)->toBe(1);
});

test('a seller cannot change the rubro or the functions, not even calling the action', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($sucursal)->create();

    $this->actingAs($vendedor)->get(route('configuracion'))->assertForbidden();
    Livewire::actingAs($vendedor)->test(FuncionesNegocio::class)->assertForbidden();

    expect(fn () => app(CambiarFuncionesEmpresa::class)->ejecutar($vendedor, $empresa, Rubro::Farmacia, [Capacidad::LotesVencimientos]))
        ->toThrow(AuthorizationException::class)
        ->and($empresa->fresh()->rubro)->toBe(Rubro::General)
        ->and(DB::table('empresa_capacidades')->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'empresa.funciones_cambiadas')->count())->toBe(0);
});

test('company A can neither read nor change the functions of company B', function () {
    [$empresaA, $adminA] = empresaConAdministrador();
    [$empresaB, $adminB] = empresaConAdministrador();
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $empresaB, Rubro::Distribuidora, [Capacidad::Despachos]);

    // A solo ve lo suyo, aunque B tenga despachos.
    expect(Gate::forUser($adminA)->allows('capacidad', Capacidad::Despachos))->toBeFalse()
        ->and(Gate::forUser($adminB)->allows('capacidad', Capacidad::Despachos))->toBeTrue();

    Livewire::actingAs($adminA)->test(FuncionesNegocio::class)
        ->assertSet('rubro', 'general')->assertSet('capacidades', []);

    // Tampoco puede cambiar las de B llamando a la acción con la empresa B.
    expect(fn () => app(CambiarFuncionesEmpresa::class)->ejecutar($adminA, $empresaB, Rubro::General, []))
        ->toThrow(AuthorizationException::class)
        ->and($empresaB->fresh()->rubro)->toBe(Rubro::Distribuidora)
        ->and(capacidadesEnBase($empresaB))->toBe(['despachos']);

    // Lo que A guarda queda solo en A.
    Livewire::actingAs($adminA)->test(FuncionesNegocio::class)
        ->set('rubro', 'farmacia')->call('revisar')->call('guardar')->assertHasNoErrors();

    expect(capacidadesEnBase($empresaA))->toBe(['lotes_vencimientos'])
        ->and(capacidadesEnBase($empresaB))->toBe(['despachos'])
        ->and($empresaB->fresh()->rubro)->toBe(Rubro::Distribuidora);
});

test('with a capability off, its routes and Livewire actions answer 404', function () {
    Route::middleware(['web', 'auth', 'capacidad:despachos'])->get('/prueba-capacidad/despachos', fn () => 'ok');
    Route::middleware(['web', 'auth', 'capacidad:despachos'])->get('/prueba-capacidad/despachos/exportar', fn () => 'csv');

    [$empresa, $admin] = empresaConAdministrador();
    [, $adminOtra] = empresaConAdministrador();

    $this->actingAs($admin)->get('/prueba-capacidad/despachos')->assertNotFound();
    $this->actingAs($admin)->get('/prueba-capacidad/despachos/exportar')->assertNotFound();
    Livewire::actingAs($admin)->test(ComponenteConDespachos::class)->assertNotFound();

    app(CambiarFuncionesEmpresa::class)->ejecutar($admin, $empresa, Rubro::Distribuidora, [Capacidad::Despachos]);

    $this->actingAs($admin)->get('/prueba-capacidad/despachos')->assertOk()->assertSee('ok');
    $this->actingAs($admin)->get('/prueba-capacidad/despachos/exportar')->assertOk();
    $componente = Livewire::actingAs($admin)->test(ComponenteConDespachos::class)->call('despachar')->assertSet('acciones', 1);

    // Se apaga con el componente ya abierto: la acción siguiente se rechaza en el servidor.
    app(CambiarFuncionesEmpresa::class)->ejecutar($admin, $empresa, Rubro::General, []);
    $componente->call('despachar')->assertNotFound();

    // Otra empresa, el superadmin y un invitado tampoco llegan; una capacidad inexistente nunca.
    $this->actingAs($adminOtra)->get('/prueba-capacidad/despachos')->assertNotFound();
    $this->actingAs(User::factory()->superadmin()->create())->get('/prueba-capacidad/despachos')->assertNotFound();
    expect(Gate::forUser($admin)->allows('capacidad', 'mesas'))->toBeFalse();
});

test('cocina and despachos can never be active at the same time', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $accion = app(CambiarFuncionesEmpresa::class);

    expect(fn () => $accion->ejecutar($admin, $empresa, Rubro::Restaurante, [Capacidad::Cocina, Capacidad::Despachos]))
        ->toThrow(ValidationException::class);

    $accion->ejecutar($admin, $empresa, Rubro::Distribuidora, [Capacidad::Despachos]);

    // Encender cocina sin apagar despachos (o al revés) se rechaza y no cambia nada.
    expect(fn () => $accion->ejecutar($admin, $empresa, Rubro::Distribuidora, [Capacidad::Despachos, Capacidad::Cocina]))
        ->toThrow(ValidationException::class)
        ->and(capacidadesEnBase($empresa))->toBe(['despachos']);

    // La pantalla bloquea la casilla y avisa; una petición manipulada con las dos se rechaza.
    Livewire::actingAs($admin)->test(FuncionesNegocio::class)
        ->assertSee('No se puede usar junto con «Despachos». Desmárcala primero.')
        ->set('capacidades', ['despachos', 'cocina'])
        ->call('revisar')
        ->assertHasErrors(['capacidades'])
        ->assertSet('revisando', false)
        ->assertSee('no pueden estar activas a la vez');

    // Cambiar de distribuidora a restaurante sí se permite: el rubro reemplaza las casillas.
    Livewire::actingAs($admin)->test(FuncionesNegocio::class)
        ->set('rubro', 'restaurante')->assertSet('capacidades', ['cocina'])
        ->call('revisar')->call('guardar')->assertHasNoErrors();

    expect(capacidadesEnBase($empresa))->toBe(['cocina'])
        ->and($empresa->fresh()->rubro)->toBe(Rubro::Restaurante);
});

test('the administrator changes the rubro after reviewing a summary, and it stays in the audit', function () {
    [$empresa, $admin] = empresaConAdministrador();

    $pantalla = Livewire::actingAs($admin)->test(FuncionesNegocio::class)
        ->assertSee('Funciones del negocio')
        ->assertSee('Próximamente')
        // Sin cambios no hay nada que confirmar.
        ->call('revisar')->assertSet('revisando', false)->assertSee('No hay cambios que guardar.')
        ->set('rubro', 'ropa')->assertSet('capacidades', ['variantes'])
        ->set('capacidades', ['variantes', 'lotes_vencimientos'])
        ->call('revisar')->assertSet('revisando', true)
        ->assertSee('Revisa los cambios')
        ->assertSee('General → Tienda de ropa y calzado')
        ->assertSee('Tallas y colores, Lotes y vencimientos')
        ->assertSee('El stock, las ventas, los pagos, la caja y los créditos no cambian.');

    // Hasta confirmar no se guarda nada.
    expect($empresa->fresh()->rubro)->toBe(Rubro::General)
        ->and(DB::table('empresa_capacidades')->count())->toBe(0);

    $pantalla->call('guardar')->assertHasNoErrors()->assertSet('revisando', false)
        ->assertSee('Funciones del negocio guardadas.');

    $auditoria = Auditoria::query()->where('accion', 'empresa.funciones_cambiadas')->sole();

    expect($empresa->fresh()->rubro)->toBe(Rubro::Ropa)
        ->and(capacidadesEnBase($empresa))->toBe(['lotes_vencimientos', 'variantes'])
        ->and($auditoria->usuario_id)->toBe($admin->id)
        ->and($auditoria->empresa_id)->toBe($empresa->id)
        ->and($auditoria->entidad)->toBe('Empresa')
        ->and($auditoria->entidad_id)->toBe($empresa->id)
        ->and($auditoria->created_at)->not->toBeNull()
        ->and($auditoria->antes)->toBe(['rubro' => 'general', 'capacidades' => []])
        ->and($auditoria->despues)->toBe([
            'rubro' => 'ropa', 'capacidades' => ['variantes', 'lotes_vencimientos'],
            'activadas' => ['variantes', 'lotes_vencimientos'], 'desactivadas' => [],
        ]);

    // Apagar una función conserva su fila (quién y cuándo) y deja otro registro con el antes y el después.
    $pantalla->set('capacidades', ['variantes'])->call('revisar')->assertSee('Se desactiva')->call('guardar');

    expect(capacidadesEnBase($empresa))->toBe(['variantes'])
        ->and(DB::table('empresa_capacidades')->where('empresa_id', $empresa->id)->where('capacidad', 'lotes_vencimientos')->value('activa'))->toBe(0)
        ->and(Auditoria::query()->where('accion', 'empresa.funciones_cambiadas')->latest('id')->first()->despues['desactivadas'])->toBe(['lotes_vencimientos']);
});

test('a double submission of the functions form makes a single change and a single audit record', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $accion = app(CambiarFuncionesEmpresa::class);

    // Dos envíos con el mismo estado deseado (doble clic o reintento): el segundo no encuentra nada que hacer.
    expect($accion->ejecutar($admin, $empresa, Rubro::Farmacia, [Capacidad::LotesVencimientos]))->toBeTrue()
        ->and($accion->ejecutar($admin, $empresa, Rubro::Farmacia, [Capacidad::LotesVencimientos]))->toBeFalse();

    // Desde la pantalla: confirmar dos veces el mismo resumen.
    Livewire::actingAs($admin)->test(FuncionesNegocio::class)
        ->set('rubro', 'distribuidora')
        ->call('revisar')
        ->call('guardar')
        ->call('guardar')
        ->assertHasNoErrors();

    expect(Auditoria::query()->where('accion', 'empresa.funciones_cambiadas')->count())->toBe(2)
        ->and(DB::table('empresa_capacidades')->where('empresa_id', $empresa->id)->count())->toBe(2)
        ->and(capacidadesEnBase($empresa))->toBe(['despachos'])
        ->and($empresa->fresh()->rubro)->toBe(Rubro::Distribuidora);
});

test('changing the rubro and the functions never touches stock, sales, payments, cash or credits', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $producto = productoDe($empresa, ['precio_consumidor' => '200.00']);
    $this->actingAs($admin);
    cargarStock($admin, $sucursal, $producto, '10');
    $cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $cliente->save();
    // Venta al contado, venta a crédito con cuota inicial y un pedido pendiente.
    confirmarVenta($admin, crearPedido($admin, $sucursal, [['producto_id' => $producto->id, 'cantidad' => '2']]), ['metodo' => 'efectivo']);
    confirmarVenta($admin, crearPedido($admin, $sucursal, [['producto_id' => $producto->id, 'cantidad' => '1']], ['cliente_id' => $cliente->id]),
        ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '50']);
    crearPedido($admin, $sucursal, [['producto_id' => $producto->id, 'cantidad' => '1']]);

    $antes = instantaneaNegocio();
    $accion = app(CambiarFuncionesEmpresa::class);

    // Encender, apagar y volver a encender cada función, y pasar por todos los rubros.
    $accion->ejecutar($admin, $empresa, Rubro::Ropa, [Capacidad::Variantes]);
    $accion->ejecutar($admin, $empresa, Rubro::Farmacia, [Capacidad::LotesVencimientos, Capacidad::Despachos]);
    $accion->ejecutar($admin, $empresa, Rubro::General, []);
    $accion->ejecutar($admin, $empresa, Rubro::Restaurante, [Capacidad::Cocina, Capacidad::Variantes]);
    $accion->ejecutar($admin, $empresa, Rubro::Ropa, [Capacidad::Variantes]);

    expect(instantaneaNegocio())->toBe($antes)
        ->and($antes['ventas'])->toHaveCount(2)
        ->and($antes['pagos_venta'])->not->toBeEmpty()
        ->and(stockDe($sucursal, $producto))->toBe('7.000');
});

test('turning off despachos with sales pending delivery is rejected with the list (stage D1)')->todo();
test('turning off lotes_vencimientos keeps stock_niveles and zeroes lot balances with their movement (stage L3)')->todo();
test('turning variantes off and on keeps products, stock and sales and shows the models again (stage V1)')->todo();
