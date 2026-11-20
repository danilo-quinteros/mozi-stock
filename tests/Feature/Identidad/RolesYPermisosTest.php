<?php

use App\Domain\AgenteMozi\Herramientas\RegistroHerramientas;
use App\Domain\Identidad\Actions\EliminarRol;
use App\Domain\Identidad\Actions\GuardarRol;
use App\Domain\Identidad\Actions\GuardarUsuarioEmpresa;
use App\Domain\Identidad\PermisosUsuario;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Domain\Tenancy\ResolverSucursal;
use App\Enums\Capacidad;
use App\Enums\Permiso;
use App\Enums\Rubro;
use App\Enums\TipoCuenta;
use App\Livewire\Egresos\Panel as EgresosPanel;
use App\Livewire\Empresa\RolFormulario;
use App\Livewire\Empresa\Usuarios;
use App\Livewire\Inventario\Movimientos;
use App\Livewire\Ventas\NuevaDevolucion;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\CuentaPagar;
use App\Models\Egreso;
use App\Models\OperacionInventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Roles y permisos de empresa (ESPECIFICACION_PERMISOS.md § 11). Cada test nombra el punto que cubre.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->vendedor = User::factory()->vendedor($this->matriz)->create(['name' => 'Vera Vendedora']);
    $this->encargado = User::factory()->encargado($this->matriz)->create(['name' => 'Elio Encargado']);
    $this->actingAs($this->admin);
});

/**
 * Rol de la empresa creado con la acción real (requiere al administrador autenticado).
 *
 * @param  list<Permiso>  $permisos
 */
function rolDePrueba(User $admin, string $nombre, array $permisos): Rol
{
    return app(GuardarRol::class)->crear($admin, ['nombre' => $nombre, 'permisos' => array_map(fn (Permiso $p): string => $p->value, $permisos)]);
}

test('§11.1 paridad: el administrador tiene todo el catálogo y el vendedor exactamente lo de antes', function () {
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, [Capacidad::Despachos, Capacidad::Produccion]);
    $vendedor = $this->vendedor->fresh();

    foreach (Permiso::cases() as $permiso) {
        expect($this->admin->tienePermiso($permiso))->toBeTrue('administrador: '.$permiso->value)
            ->and($vendedor->tienePermiso($permiso))->toBe(in_array($permiso, Permiso::deVendedor(), true), 'vendedor: '.$permiso->value);
    }

    foreach (['ver-reportes', 'supervisar-cajas', 'analizar-empresa', 'analizar-lotes', 'usar-produccion'] as $gate) {
        expect(Gate::forUser($this->admin)->allows($gate))->toBeTrue($gate)
            ->and(Gate::forUser($vendedor)->allows($gate))->toBeFalse($gate);
    }

    $habilidades = [
        ['viewAny', Pedido::class, true], ['create', Pedido::class, true], ['viewAny', Cliente::class, true], ['create', Cliente::class, true],
        ['viewAny', Producto::class, true], ['create', Producto::class, false], ['viewAny', Compra::class, false], ['viewAny', Egreso::class, false],
        ['viewAny', CuentaPagar::class, false], ['viewAny', Proveedor::class, false], ['viewAny', OperacionInventario::class, false],
        ['create', OperacionInventario::class, false], ['viewAny', User::class, false], ['create', Rol::class, false],
    ];

    foreach ($habilidades as [$habilidad, $clase, $vendedorPuede]) {
        expect(Gate::forUser($this->admin)->allows($habilidad, $clase))->toBeTrue("administrador {$habilidad} {$clase}")
            ->and(Gate::forUser($vendedor)->allows($habilidad, $clase))->toBe($vendedorPuede, "vendedor {$habilidad} {$clase}");
    }

    expect($this->admin->veTodasLasSucursales())->toBeTrue()
        ->and($vendedor->veTodasLasSucursales())->toBeFalse()
        ->and($vendedor->sucursalActivaId())->toBe($this->matriz->id)
        ->and($vendedor->supervisaCajas())->toBeFalse();
});

test('§11.3 sin ventas.devolver: 403 en la ruta y en Livewire; con el extra, permitido', function () {
    $producto = productoDe($this->empresa);
    cargarStock($this->admin, $this->matriz, $producto, '5');
    $venta = confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $producto->id, 'cantidad' => '2']]), ['metodo' => 'efectivo']);

    $this->actingAs($this->vendedor)->get(route('ventas.devolucion', $venta))->assertForbidden();
    Livewire::actingAs($this->vendedor)->test(NuevaDevolucion::class, ['venta' => $venta])->assertForbidden();

    darExtras($this->vendedor, Permiso::VentasDevolver);
    $this->actingAs($this->vendedor)->get(route('ventas.devolucion', $venta))->assertOk();
    Livewire::actingAs($this->vendedor)->test(NuevaDevolucion::class, ['venta' => $venta])->assertOk();
});

test('§11.4 quitar un permiso al rol surte efecto en la acción siguiente, con el componente abierto', function () {
    $this->actingAs($this->encargado)->get(route('egresos.index'))->assertOk();
    $pantalla = Livewire::actingAs($this->encargado)->test(EgresosPanel::class)->call('nuevoEgreso')->assertOk();

    $this->actingAs($this->admin);
    $rol = Rol::query()->findOrFail($this->encargado->rol_id);
    $sinEgresos = array_values(array_filter($rol->clavesPermisos(), fn (Permiso $p): bool => $p !== Permiso::EgresosGestionar));
    app(GuardarRol::class)->actualizar($this->admin, $rol, ['nombre' => $rol->nombre, 'permisos' => array_map(fn (Permiso $p): string => $p->value, $sinEgresos)]);

    // La misma pantalla, todavía abierta, ya no deja registrar egresos.
    $this->actingAs($this->encargado);
    $pantalla->call('nuevoEgreso')->assertForbidden();
    $this->actingAs($this->encargado)->get(route('egresos.index'))->assertForbidden();
    $this->actingAs($this->encargado)->get(route('egresos.exportar'))->assertForbidden();
});

test('§11.5 el administrador de A no ve, edita, duplica, elimina ni asigna roles de B', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $rolB = rolDePrueba($adminB, 'Almacenero B', [Permiso::InventarioAjustar]);

    $this->actingAs($this->admin);
    $this->get(route('usuarios.roles.editar', $rolB->id))->assertNotFound();
    $this->get(route('usuarios.index', ['pestana' => 'roles']))->assertOk()->assertDontSee('Almacenero B');
    Livewire::test(Usuarios::class)->call('duplicarRol', $rolB->id)->assertNotFound();
    Livewire::test(Usuarios::class)->call('eliminarRol', $rolB->id)->assertNotFound();

    expect(fn () => app(GuardarRol::class)->actualizar($this->admin, $rolB, ['nombre' => 'Robado', 'permisos' => []]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(EliminarRol::class)->ejecutar($this->admin, $rolB))->toThrow(AuthorizationException::class)
        ->and(fn () => app(GuardarUsuarioEmpresa::class)->actualizar($this->admin, $this->vendedor, [
            'name' => $this->vendedor->name, 'email' => $this->vendedor->email, 'tipo' => TipoCuenta::Usuario, 'rol_id' => $rolB->id,
            'sucursal_id' => $this->matriz->id, 'caja_id' => $this->vendedor->caja_id, 'activo' => true,
        ]))->toThrow(ValidationException::class);

    // La FK compuesta también lo impide aunque se salte la acción.
    expect(fn () => DB::table('users')->where('id', $this->vendedor->id)->update(['rol_id' => $rolB->id]))->toThrow(QueryException::class);
    expect($rolB->fresh()->nombre)->toBe('Almacenero B')->and($this->vendedor->fresh()->rol_id)->not->toBe($rolB->id);
});

test('§11.6 una clave de permiso inventada se rechaza en roles y extras', function () {
    expect(fn () => app(GuardarRol::class)->crear($this->admin, ['nombre' => 'Raro', 'permisos' => ['ventas.ver', 'usuarios.gestionar']]))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(GuardarUsuarioEmpresa::class)->actualizar($this->admin, $this->vendedor, [
            'name' => $this->vendedor->name, 'email' => $this->vendedor->email, 'tipo' => TipoCuenta::Usuario, 'rol_id' => $this->vendedor->rol_id,
            'sucursal_id' => $this->matriz->id, 'caja_id' => $this->vendedor->caja_id, 'activo' => true, 'permisos_extra' => ['superpoder'],
        ]))->toThrow(ValidationException::class);

    // Una petición de Livewire manipulada pierde la clave antes de llegar a la acción.
    Livewire::test(RolFormulario::class)->set('nombre', 'Raro')->set('permisos', ['ventas.ver', 'empresa.borrar'])
        ->assertSet('permisos', ['ventas.ver']);
    Livewire::test(Usuarios::class)->call('editar', $this->vendedor->id)->set('permisos_extra', ['superpoder'])
        ->assertSet('permisos_extra', [])
        ->call('guardar')->assertHasNoErrors();

    expect(Rol::query()->where('nombre', 'Raro')->exists())->toBeFalse()
        ->and(DB::table('usuario_permisos')->where('user_id', $this->vendedor->id)->exists())->toBeFalse();
});

test('§11.7 un usuario con los 26 permisos no gestiona usuarios, roles ni extras, ni entra como otro', function () {
    $rolTodo = rolDePrueba($this->admin, 'Todo', Permiso::cases());
    $poderoso = User::factory()->conRol($rolTodo, $this->matriz)->create();
    // Sin las 4 que dependen de funciones apagadas: 22 efectivos.
    expect(app(PermisosUsuario::class)->efectivos($poderoso))->toHaveCount(22);

    $this->actingAs($poderoso);
    $this->get(route('usuarios.index'))->assertForbidden();
    $this->get(route('usuarios.roles.crear'))->assertForbidden();
    $this->get(route('usuarios.roles.editar', $rolTodo->id))->assertForbidden();
    $this->get(route('usuarios.exportar'))->assertForbidden();
    $this->post(route('impersonar', $this->vendedor))->assertForbidden();
    Livewire::test(Usuarios::class)->assertForbidden();

    expect(fn () => app(GuardarRol::class)->crear($poderoso, ['nombre' => 'Escalada', 'permisos' => []]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(GuardarUsuarioEmpresa::class)->actualizar($poderoso, $this->vendedor, [
            'name' => $this->vendedor->name, 'email' => $this->vendedor->email, 'tipo' => TipoCuenta::Administrador, 'sucursal_id' => null, 'activo' => true,
        ]))->toThrow(AuthorizationException::class)
        ->and(Gate::forUser($poderoso)->allows('impersonar', $this->vendedor))->toBeFalse();
});

test('§11.8 un rol con usuarios no se elimina y la empresa conserva un administrador activo', function () {
    $rolVendedor = Rol::query()->findOrFail($this->vendedor->rol_id);

    expect(fn () => app(EliminarRol::class)->ejecutar($this->admin, $rolVendedor))->toThrow(ValidationException::class);
    Livewire::test(Usuarios::class)->set('pestana', 'roles')->call('eliminarRol', $rolVendedor->id)
        ->assertHasErrors('rol')->assertSee('Asígnale otro rol antes de eliminarlo');
    expect($rolVendedor->fresh())->not->toBeNull();

    // Sin usuarios sí se elimina, y queda auditado.
    $libre = rolDePrueba($this->admin, 'Temporal', [Permiso::VentasVer]);
    Livewire::test(Usuarios::class)->set('pestana', 'roles')->call('eliminarRol', $libre->id)->assertHasNoErrors();
    expect(Rol::query()->whereKey($libre->id)->exists())->toBeFalse()
        ->and(Auditoria::query()->where('accion', 'rol.eliminado')->where('entidad_id', $libre->id)->exists())->toBeTrue();

    // Un segundo administrador pasa a usuario con rol; el primero sigue y nadie se degrada a sí mismo.
    $otroAdmin = User::factory()->administrador($this->empresa)->create();
    app(GuardarUsuarioEmpresa::class)->actualizar($this->admin, $otroAdmin, [
        'name' => $otroAdmin->name, 'email' => $otroAdmin->email, 'tipo' => TipoCuenta::Usuario, 'rol_id' => $rolVendedor->id,
        'sucursal_id' => $this->matriz->id, 'caja_id' => $this->matriz->cajaPrincipal()->id, 'activo' => true,
    ]);
    expect($otroAdmin->fresh()->tipo)->toBe(TipoCuenta::Usuario)
        ->and(fn () => app(GuardarUsuarioEmpresa::class)->actualizar($this->admin, $this->admin, [
            'name' => $this->admin->name, 'email' => $this->admin->email, 'tipo' => TipoCuenta::Usuario, 'rol_id' => $rolVendedor->id,
            'sucursal_id' => $this->matriz->id, 'caja_id' => $this->matriz->cajaPrincipal()->id, 'activo' => true,
        ]))->toThrow(ValidationException::class)
        ->and(User::query()->where('empresa_id', $this->empresa->id)->where('tipo', TipoCuenta::Administrador)->where('activo', true)->count())->toBe(1);
});

test('§11.9 sin sucursales.todas la sucursal pedida se ignora; con el permiso puede elegir', function () {
    $resolver = app(ResolverSucursal::class);
    expect($resolver->paraOperacion($this->encargado, $this->norte->id)->id)->toBe($this->matriz->id);

    // Livewire: el filtro de sucursal queda fijo y el servidor ignora el cambio manipulado.
    Livewire::actingAs($this->encargado)->test(Movimientos::class)
        ->assertSet('sucursal', (string) $this->matriz->id)
        ->set('sucursal', (string) $this->norte->id)
        ->assertSet('sucursal', (string) $this->matriz->id)
        ->set('sucursal', '')
        ->assertSet('sucursal', (string) $this->matriz->id);
});

test('§11.9 con «Trabajar en todas las sucursales» elige la sucursal de la operación', function () {
    darExtras($this->encargado, Permiso::SucursalesTodas);

    expect(app(ResolverSucursal::class)->paraOperacion($this->encargado, $this->norte->id)->id)->toBe($this->norte->id)
        ->and($this->encargado->veTodasLasSucursales())->toBeTrue();

    Livewire::actingAs($this->encargado)->test(Movimientos::class)
        ->set('sucursal', (string) $this->norte->id)
        ->assertSet('sucursal', (string) $this->norte->id);
});

test('§11.10 con Despachos apagado el permiso guardado no habilita nada y la ruta responde 404', function () {
    darExtras($this->vendedor, Permiso::DespachosAutorizar);
    expect($this->vendedor->tienePermiso(Permiso::DespachosAutorizar))->toBeFalse();
    $this->actingAs($this->vendedor)->get(route('pedidos.despachos.exportar'))->assertNotFound();

    $this->actingAs($this->admin);
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Distribuidora, [Capacidad::Despachos]);
    expect($this->vendedor->tienePermiso(Permiso::DespachosAutorizar))->toBeTrue();
});

test('§11.11 guardar un rol con ventas.vender agrega ventas.ver en el servidor y en la pantalla', function () {
    $rol = app(GuardarRol::class)->crear($this->admin, ['nombre' => 'Cajero', 'permisos' => ['ventas.vender', 'ventas.asignar_vendedor']]);
    expect(DB::table('rol_permisos')->where('rol_id', $rol->id)->orderBy('permiso')->pluck('permiso')->all())
        ->toBe(['ventas.asignar_vendedor', 'ventas.vender', 'ventas.ver']);

    Livewire::test(RolFormulario::class)
        ->set('permisos', ['inventario.ajustar'])
        ->assertSet('permisos', ['inventario.historial', 'inventario.ajustar'])
        ->assertSee('Lo necesita «Registrar ajustes».');
});

test('§11.12 el CSV de un usuario acotado solo trae filas de su sucursal', function () {
    $producto = productoDe($this->empresa, ['nombre' => 'Arroz 1kg']);
    cargarStock($this->admin, $this->matriz, $producto, '5');
    cargarStock($this->admin, $this->norte, $producto, '7');

    $csv = $this->actingAs($this->encargado)->get(route('inventario.exportar', ['sucursal' => $this->norte->id]))->assertOk()->streamedContent();
    expect($csv)->toContain('Casa Matriz')->not->toContain('Sucursal Norte');

    $csv = $this->actingAs($this->admin)->get(route('inventario.exportar', ['sucursal' => $this->norte->id]))->assertOk()->streamedContent();
    expect($csv)->toContain('Sucursal Norte')->not->toContain('Casa Matriz');
});

test('§11.13 solo quien tiene ventas.vender y alcanza la sucursal puede ser el vendedor del pedido', function () {
    $producto = productoDe($this->empresa);
    $almacenero = User::factory()->conRol(rolDePrueba($this->admin, 'Almacenero', [Permiso::InventarioAjustar]), $this->matriz)->create();
    $vendedorNorte = User::factory()->vendedor($this->norte)->create();
    $linea = [['producto_id' => $producto->id, 'cantidad' => '1']];

    $this->actingAs($this->encargado);
    expect(fn () => crearPedido($this->encargado, $this->matriz, $linea, ['vendedor_id' => $almacenero->id]))->toThrow(ValidationException::class)
        ->and(fn () => crearPedido($this->encargado, $this->matriz, $linea, ['vendedor_id' => $vendedorNorte->id]))->toThrow(ValidationException::class)
        ->and(crearPedido($this->encargado, $this->matriz, $linea, ['vendedor_id' => $this->vendedor->id])->vendedor_id)->toBe($this->vendedor->id);

    // Sin «Elegir el vendedor del pedido» la elección se ignora: queda quien lo crea.
    $this->actingAs($this->vendedor);
    expect(crearPedido($this->vendedor, $this->matriz, $linea, ['vendedor_id' => $this->encargado->id])->vendedor_id)->toBe($this->vendedor->id);
});

test('§11.14 Agente Mozi: las herramientas de análisis exigen reportes.ver y todas las sucursales', function () {
    $registro = app(RegistroHerramientas::class);

    expect(array_keys($registro->disponibles($this->admin)))->toContain('analyze_receivables', 'query_business_data')
        ->and(array_keys($registro->disponibles($this->vendedor)))->not->toContain('analyze_receivables')->toContain('query_business_data')
        ->and(array_keys($registro->disponibles($this->encargado)))->not->toContain('analyze_receivables');

    darExtras($this->encargado, Permiso::SucursalesTodas);
    expect(array_keys($registro->disponibles($this->encargado)))->toContain('analyze_receivables');
});

test('§11.15 roles, cambios de rol y extras quedan en la auditoría', function () {
    $rol = rolDePrueba($this->admin, 'Repartidor', [Permiso::VentasVer]);
    app(GuardarRol::class)->actualizar($this->admin, $rol, ['nombre' => 'Repartidor', 'permisos' => ['ventas.ver', 'creditos.cobrar']]);

    Livewire::test(Usuarios::class)
        ->call('editar', $this->vendedor->id)
        ->set('rol_id', (string) $rol->id)
        ->set('permisos_extra', ['caja.operar'])
        ->call('guardar')
        ->assertHasNoErrors();

    $actualizado = Auditoria::query()->where('accion', 'rol.actualizado')->sole();
    $rolCambiado = Auditoria::query()->where('accion', 'usuario.rol_cambiado')->sole();
    $extras = Auditoria::query()->where('accion', 'usuario.permisos_extra')->sole();

    expect(Auditoria::query()->where('accion', 'rol.creado')->where('entidad_id', $rol->id)->exists())->toBeTrue()
        ->and($actualizado->antes['permisos'])->toBe(['ventas.ver'])
        ->and($actualizado->despues['permisos'])->toBe(['ventas.ver', 'creditos.ver', 'creditos.cobrar'])
        ->and($rolCambiado->antes['rol'])->toBe('Vendedor')
        ->and($rolCambiado->despues['rol'])->toBe('Repartidor')
        ->and($extras->despues['permisos_extra'])->toBe(['caja.operar'])
        ->and($rolCambiado->usuario_id)->toBe($this->admin->id);
});

test('pantalla Roles: fila fija de Administrador, conteos, duplicar abre la copia', function () {
    Livewire::test(Usuarios::class)
        ->set('pestana', 'roles')
        ->assertSee('Todos los permisos')->assertSee('Fijo: no se edita ni se elimina')
        ->assertSee('Vendedor')->assertSee('Encargado de sucursal')
        ->assertSee('6 permisos')->assertSee('20 permisos');

    $vendedor = Rol::query()->where('plantilla', Rol::PLANTILLA_VENDEDOR)->sole();
    Livewire::test(Usuarios::class)->call('duplicarRol', $vendedor->id)
        ->assertRedirect(route('usuarios.roles.editar', Rol::query()->where('nombre', 'Copia de Vendedor')->sole()));

    expect(Rol::query()->where('nombre', 'Copia de Vendedor')->sole()->clavesPermisos())->toBe($vendedor->clavesPermisos());
});

test('formulario de rol: crea y edita con las casillas del catálogo y oculta las de funciones apagadas', function () {
    $this->get(route('usuarios.roles.crear'))->assertOk()
        ->assertSee('Nuevo rol')->assertSee('Registrar devoluciones')
        ->assertDontSeeHtml('data-permiso="despachos.autorizar"')->assertSee('requiere la función Despachos');

    Livewire::test(RolFormulario::class)
        ->set('nombre', 'Almacenero')->set('descripcion', 'Recibe compras y ajusta stock.')
        ->set('permisos', ['compras.gestionar', 'inventario.traspasar'])
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertRedirect(route('usuarios.index', ['pestana' => 'roles']));

    $rol = Rol::query()->where('nombre', 'Almacenero')->sole();
    expect(array_map(fn (Permiso $p): string => $p->value, $rol->clavesPermisos()))
        ->toBe(['compras.gestionar', 'inventario.historial', 'inventario.traspasar']);

    // Nombre repetido (sin distinguir mayúsculas) se rechaza.
    Livewire::test(RolFormulario::class)->set('nombre', 'almacenero')->call('guardar')->assertHasErrors('nombre');

    $this->get(route('usuarios.roles.editar', $rol))->assertOk()->assertSee('Editar rol');
    Livewire::test(RolFormulario::class, ['rol' => $rol])
        ->assertSet('descripcion', 'Recibe compras y ajusta stock.')
        ->assertSet('permisos', ['compras.gestionar', 'inventario.historial', 'inventario.traspasar'])
        ->set('nombre', 'Almacén central')
        ->call('guardar')
        ->assertHasNoErrors();
    expect($rol->fresh()->nombre)->toBe('Almacén central');
});

test('formulario de usuario: permisos extra con «Del rol», requeridos y caja según el rol', function () {
    $supervisor = rolDePrueba($this->admin, 'Supervisor', [Permiso::CajaSupervisar, Permiso::ReportesVer]);

    Livewire::test(Usuarios::class)
        ->call('editar', $this->vendedor->id)
        ->assertSee('Del rol')
        // ventas.asignar_vendedor requiere ventas.vender, que ya da el rol: no se repite como extra.
        ->set('permisos_extra', ['ventas.asignar_vendedor', 'ventas.vender'])
        ->assertSet('permisos_extra', ['ventas.asignar_vendedor'])
        ->call('guardar')
        ->assertHasNoErrors();
    expect(DB::table('usuario_permisos')->where('user_id', $this->vendedor->id)->pluck('permiso')->all())->toBe(['ventas.asignar_vendedor']);

    // Un rol que no usa su caja deja la caja opcional.
    Livewire::test(Usuarios::class)
        ->call('crear')
        ->set('name', 'Sara Supervisora')->set('email', 'sara@ejemplo.test')
        ->set('rol_id', (string) $supervisor->id)
        ->set('sucursal_id', (string) $this->norte->id)
        ->set('caja_id', null)
        ->set('password', 'ClaveSegura12345')->set('password_confirmation', 'ClaveSegura12345')
        ->call('guardar')
        ->assertHasNoErrors();
    $sara = User::query()->where('email', 'sara@ejemplo.test')->sole();
    expect($sara->rol_id)->toBe($supervisor->id)->and($sara->caja_id)->toBeNull()->and($sara->supervisaCajas())->toBeTrue();

    // Con «Usar su caja» sin supervisar, la caja es obligatoria.
    Livewire::test(Usuarios::class)
        ->call('crear')
        ->set('name', 'Caja obligatoria')->set('email', 'caja@ejemplo.test')
        ->set('sucursal_id', (string) $this->norte->id)
        ->set('caja_id', null)
        ->set('password', 'ClaveSegura12345')->set('password_confirmation', 'ClaveSegura12345')
        ->call('guardar')
        ->assertHasErrors(['caja_id' => 'required']);
});

test('la tabla de usuarios muestra el rol y los extras, y permite entrar como cualquier usuario con rol', function () {
    darExtras($this->vendedor, Permiso::VentasDevolver);

    Livewire::test(Usuarios::class)
        ->assertSee('Vera Vendedora')->assertSee('Encargado de sucursal')
        ->assertSeeHtml('data-test="extras-'.$this->vendedor->id.'"')->assertSee('+1 extra')
        ->assertSeeHtml(route('impersonar', $this->encargado));

    expect(Gate::forUser($this->admin)->allows('impersonar', $this->encargado))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('impersonar', User::factory()->administrador($this->empresa)->create()))->toBeFalse();
});
