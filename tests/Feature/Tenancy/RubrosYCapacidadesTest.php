<?php

use App\Domain\Catalogo\Actions\GuardarModelo;
use App\Domain\Despachos\Actions\DespacharVenta;
use App\Domain\Despachos\Actions\MarcarEntregada;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Enums\Rubro;
use App\Livewire\Admin\Empresas;
use App\Livewire\Auth\Registro;
use App\Livewire\Catalogo\Productos;
use App\Livewire\Concerns\ExigeCapacidad;
use App\Livewire\Empresa\FuncionesNegocio;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Color;
use App\Models\Empresa;
use App\Models\EventoEntrega;
use App\Models\GrupoTalla;
use App\Models\Modelo;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\UnidadMedida;
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
 * Etapa R0 de ESPECIFICACION_RUBROS_Y_CAPACIDADES.md (§ 7). La regla de apagado de despachos llegó con
 * la etapa D1, la de lotes con L3 y la de variantes con V1 (ESPECIFICACION_TALLAS_Y_COLORES.md).
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

    // Tallas y colores (V1): solo el rubro con «variantes» recibe la lista sugerida de tallas y colores.
    $conListas = in_array(Capacidad::Variantes, $rubro->capacidades(), true);
    expect(DB::table('grupos_talla')->where('empresa_id', $empresa->id)->count())->toBe($conListas ? 4 : 0)
        ->and(DB::table('tallas')->where('empresa_id', $empresa->id)->count())->toBe($conListas ? 32 : 0)
        ->and(DB::table('colores')->where('empresa_id', $empresa->id)->count())->toBe($conListas ? 10 : 0)
        ->and(DB::table('categorias')->where('empresa_id', $empresa->id)->orderBy('id')->pluck('nombre')->all())
        ->toBe($rubro->categoriasSugeridas());
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
        ->and($auditoria->despues['rubro'])->toBe('distribuidora')
        ->and(DB::table('categorias')->where('empresa_id', $empresa->id)->orderBy('id')->pluck('nombre')->all())
        ->toBe(Rubro::Distribuidora->categoriasSugeridas());
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
        // Desde V1 las cuatro funciones están disponibles: ya no se muestra «Próximamente».
        ->assertDontSee('Próximamente')
        // Sin cambios no hay nada que confirmar.
        ->call('revisar')->assertSet('revisando', false)->assertSee('No hay cambios que guardar.')
        ->set('rubro', 'ropa')->assertSet('capacidades', ['variantes'])
        ->set('capacidades', ['variantes', 'lotes_vencimientos'])
        ->call('revisar')->assertSet('revisando', true)
        ->assertSee('Revisa los cambios')
        ->assertSee('Tienda → Tienda de ropa y calzado')
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

test('turning off despachos with sales pending or dispatched is rejected with the list; once delivered it is allowed', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $producto = productoDe($empresa, ['precio_consumidor' => '10.00']);
    $this->actingAs($admin);
    cargarStock($admin, $sucursal, $producto, '10');
    $accion = app(CambiarFuncionesEmpresa::class);
    $accion->ejecutar($admin, $empresa, Rubro::Distribuidora, [Capacidad::Despachos]);
    $venta = confirmarVenta($admin, crearPedido($admin, $sucursal, [['producto_id' => $producto->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);
    $otra = confirmarVenta($admin, crearPedido($admin, $sucursal, [['producto_id' => $producto->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);
    $auditorias = Auditoria::query()->where('accion', 'empresa.funciones_cambiadas')->count();

    expect(fn () => $accion->ejecutar($admin, $empresa, Rubro::General, []))
        ->toThrow(ValidationException::class, '2 ventas todavía no se entregaron (V-00001, V-00002)');

    // Despachada todavía no llegó al cliente: sigue bloqueando. La pantalla muestra el motivo bajo las funciones.
    app(DespacharVenta::class)->ejecutar($admin, $venta);
    app(MarcarEntregada::class)->ejecutar($admin, $otra, despacharTambien: true);
    Livewire::test(FuncionesNegocio::class)
        ->set('rubro', 'general')->set('capacidades', [])
        ->call('revisar')->call('guardar')
        ->assertHasErrors(['capacidades'])
        ->assertSet('revisando', false)
        ->assertSee('la venta V-00001 todavía no se entregó');

    expect($empresa->fresh()->tieneCapacidad(Capacidad::Despachos))->toBeTrue()
        ->and(Auditoria::query()->where('accion', 'empresa.funciones_cambiadas')->count())->toBe($auditorias);

    app(MarcarEntregada::class)->ejecutar($admin, $venta);
    expect($accion->ejecutar($admin, $empresa, Rubro::General, []))->toBeTrue()
        ->and($empresa->fresh()->tieneCapacidad(Capacidad::Despachos))->toBeFalse();

    // Las ventas anteriores conservan su historial; las nuevas nacen Entregado.
    $nueva = confirmarVenta($admin, crearPedido($admin, $sucursal, [['producto_id' => $producto->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);
    expect($nueva->refresh()->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and(EventoEntrega::query()->where('venta_id', $venta->id)->count())->toBe(3);
});
test('turning variantes off and on keeps products, stock and sales and shows the models again (stage V1)', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $this->actingAs($admin);
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Central']);
    $unidad = new UnidadMedida(['nombre' => 'Unidad', 'abreviatura' => 'u', 'admite_decimales' => false]);
    $unidad->empresa_id = $empresa->id;
    $unidad->save();
    $accion = app(CambiarFuncionesEmpresa::class);
    $accion->ejecutar($admin, $empresa, Rubro::Ropa, [Capacidad::Variantes]);

    $letras = GrupoTalla::query()->where('nombre', 'Letras')->sole();
    $modelo = app(GuardarModelo::class)->crear($admin, [
        'nombre' => 'Polera Básica', 'unidad_medida_id' => $unidad->id, 'sku_base' => 'POL001', 'precio_consumidor' => '49.90',
        'stock_minimo' => '0', 'grupo_talla_id' => $letras->id,
        'tallas' => Talla::query()->where('grupo_talla_id', $letras->id)->whereIn('nombre', ['M', 'L'])->pluck('id')->all(),
        'colores' => Color::query()->where('nombre', 'Negro')->pluck('id')->all(),
    ]);
    $variante = Producto::query()->where('modelo_id', $modelo->id)->orderBy('id')->firstOrFail();
    cargarStock($admin, $sucursal, $variante, '5');
    confirmarVenta($admin, crearPedido($admin, $sucursal, [['producto_id' => $variante->id, 'cantidad' => '2']]), ['metodo' => 'efectivo']);
    $antes = instantaneaNegocio();

    // Apagar no toca productos, stock ni ventas; los modelos y las listas quedan guardados.
    expect($accion->ejecutar($admin, $empresa, Rubro::General, []))->toBeTrue()
        ->and(instantaneaNegocio())->toBe($antes)
        ->and(Modelo::query()->count())->toBe(1)
        ->and(Color::query()->count())->toBe(10);

    // Sin la capacidad, cada combinación es un producto suelto con su nombre completo y se sigue vendiendo; las
    // pantallas de modelos y de tallas y colores responden 404.
    Livewire::test(Productos::class)
        ->assertSee('Polera Básica · M · Negro')->assertSee('Polera Básica · L · Negro')
        ->assertDontSeeHtml('data-test="fila-modelo-'.$modelo->id.'"')->assertDontSee('Nuevo modelo');
    $this->get(route('productos.modelos.ver', $modelo))->assertNotFound();
    $this->get(route('configuracion.tallas-colores'))->assertNotFound();
    confirmarVenta($admin, crearPedido($admin, $sucursal, [['producto_id' => $variante->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);
    $antes = instantaneaNegocio();

    // Encender otra vez muestra los modelos de nuevo, con las mismas combinaciones, stock y ventas, sin duplicar listas.
    expect($accion->ejecutar($admin, $empresa, Rubro::Ropa, [Capacidad::Variantes]))->toBeTrue()
        ->and(instantaneaNegocio())->toBe($antes)
        ->and(stockDe($sucursal, $variante))->toBe('2.000')
        ->and(Color::query()->count())->toBe(10);

    Livewire::test(Productos::class)->assertSeeHtml('data-test="fila-modelo-'.$modelo->id.'"')->assertSee('2 combinaciones');
    $this->get(route('productos.modelos.ver', $modelo))->assertOk()->assertSee('Polera Básica · M · Negro');
});
