<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Caja\Actions\CerrarTurnoCaja;
use App\Domain\Caja\Actions\GuardarCaja;
use App\Domain\Caja\LibroCaja;
use App\Domain\Identidad\Actions\GuardarUsuarioEmpresa;
use App\Domain\Ventas\Actions\VenderEnPos;
use App\Enums\Rol;
use App\Livewire\Caja\ControlCajaTopbar;
use App\Models\MovimientoCaja;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\TurnoUsuario;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Cajas compartidas entre vendedores de una misma sucursal y caja exclusiva de su sucursal
 * (revisión del 01/11/2026). Sucursal A y B, cada una con su Caja principal compartida.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->actingAs($this->admin);
    $this->sucursalA = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal A']);
    $this->sucursalB = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal B']);
    $this->cajaA = $this->sucursalA->cajaPrincipal();
    $this->cajaB = $this->sucursalB->cajaPrincipal();
    $this->producto = productoDe($this->empresa, ['nombre' => 'Aceite 1L', 'precio_consumidor' => '20.00']);
    cargarStock($this->admin, $this->sucursalA, $this->producto, '20');
    cargarStock($this->admin, $this->sucursalB, $this->producto, '20');
});

/**
 * @param  array<string, mixed>  $extra
 */
function venderConCaja(User $actor, Sucursal $sucursal, array $extra = []): Venta
{
    return app(VenderEnPos::class)->ejecutar($actor, $extra + [
        'sucursal_id' => $sucursal->id, 'cliente_id' => null,
        'lineas' => [['producto_id' => test()->producto->id, 'cantidad' => '1', 'tipo_precio' => 'consumidor', 'descuento_tipo' => 'bs', 'descuento_valor' => '0']],
        'descuento_global_tipo' => 'bs', 'descuento_global_valor' => '0', 'clave' => (string) Str::uuid(),
    ], ['metodo' => 'efectivo', 'en_cuotas' => false, 'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid()]);
}

test('dos vendedores de la misma sucursal trabajan en el mismo turno de la caja compartida sin cerrarlo', function () {
    $ana = User::factory()->vendedor($this->sucursalA)->create(['caja_id' => $this->cajaA->id]);
    $beto = User::factory()->vendedor($this->sucursalA)->create(['caja_id' => $this->cajaA->id]);

    $turno = app(AbrirTurnoCaja::class)->ejecutar($ana, $this->cajaA, '100.00');
    // Beto llega: no abre otro turno, usa el que está abierto.
    expect(fn () => app(AbrirTurnoCaja::class)->ejecutar($beto, $this->cajaA, '0'))->toThrow(ValidationException::class);

    $deAna = venderConCaja($ana, $this->sucursalA);
    $deBeto = venderConCaja($beto, $this->sucursalA);

    expect([$deAna->turno_caja_id, $deBeto->turno_caja_id])->toBe([$turno->id, $turno->id])
        ->and([$deAna->caja_id, $deBeto->caja_id])->toBe([$this->cajaA->id, $this->cajaA->id])
        ->and(MovimientoCaja::query()->where('origen_tipo', 'pago_venta')->where('turno_caja_id', $turno->id)
            ->orderBy('id')->pluck('usuario_id')->all())->toBe([$ana->id, $beto->id])
        ->and(TurnoUsuario::query()->where('turno_caja_id', $turno->id)->orderBy('user_id')->pluck('user_id')->all())->toBe([$ana->id, $beto->id])
        ->and(TurnoCaja::query()->where('caja_id', $this->cajaA->id)->where('estado', 'abierto')->count())->toBe(1);

    // Cualquiera de los dos cierra el único turno: 100 de fondo + 20 + 20.
    $cerrado = app(CerrarTurnoCaja::class)->ejecutar($beto, $turno, '140.00');
    expect($cerrado->usuario_cierre_id)->toBe($beto->id)->and((string) $cerrado->diferencia)->toBe('0.00');
});

test('una caja nunca se asigna ni se usa desde otra sucursal, aunque se manipule la solicitud', function () {
    $vendedorB = User::factory()->vendedor($this->sucursalB)->create(['caja_id' => $this->cajaB->id]);

    expect(fn () => app(GuardarUsuarioEmpresa::class)->actualizar($this->admin, $vendedorB, [
        'name' => $vendedorB->name, 'email' => $vendedorB->email, 'rol' => Rol::Vendedor,
        'sucursal_id' => $this->sucursalB->id, 'caja_id' => $this->cajaA->id, 'activo' => true,
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(AbrirTurnoCaja::class)->ejecutar($vendedorB, $this->cajaA, '0'))->toThrow(ValidationException::class);

    $turnoA = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->cajaA, '0');
    app(AbrirTurnoCaja::class)->ejecutar($vendedorB, $this->cajaB, '0');
    expect($turnoA->usuarioPuedeOperar($vendedorB))->toBeFalse();

    // Venta en la sucursal B con la caja de la sucursal A.
    expect(fn () => venderConCaja($this->admin, $this->sucursalB, ['caja_id' => $this->cajaA->id]))->toThrow(ValidationException::class);
    expect(fn () => venderConCaja($vendedorB, $this->sucursalB, ['caja_id' => $this->cajaA->id]))->toThrow(ValidationException::class);
    // Dinero de la sucursal B en el turno de la caja A.
    expect(fn () => DB::transaction(fn () => app(LibroCaja::class)->registrar($this->admin, $this->sucursalB->id, 'ingreso',
        ['efectivo' => '10.00', 'electronico' => '0'], 'egreso', 999, Carbon::now(), $this->cajaA->id, $turnoA->id)))
        ->toThrow(ValidationException::class);
    expect(Venta::query()->count())->toBe(0)->and(MovimientoCaja::query()->count())->toBe(0);

    // La base de datos lo impide aunque se salte la aplicación.
    expect(fn () => DB::table('users')->where('id', $vendedorB->id)->update(['caja_id' => $this->cajaA->id]))->toThrow(QueryException::class);
    expect(fn () => DB::table('cajas')->where('id', $this->cajaA->id)->update(['sucursal_id' => $this->sucursalB->id]))->toThrow(QueryException::class);
    expect(fn () => DB::table('turnos_caja')->insert([
        'empresa_id' => $this->empresa->id, 'sucursal_id' => $this->sucursalA->id, 'caja_id' => $this->cajaA->id,
        'usuario_apertura_id' => $this->admin->id, 'monto_apertura' => 0, 'fecha_apertura' => now(), 'estado' => 'abierto',
    ]))->toThrow(QueryException::class);
});

test('un vendedor sin caja asignada no abre ni usa la caja principal de su sucursal', function () {
    $sinCaja = User::factory()->vendedor($this->sucursalA)->create(['caja_id' => null]);

    expect(fn () => app(AbrirTurnoCaja::class)->ejecutar($sinCaja, $this->cajaA, '0'))->toThrow(ValidationException::class);

    $turno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->cajaA, '0');
    expect($turno->usuarioPuedeOperar($sinCaja))->toBeFalse()
        ->and(fn () => venderConCaja($sinCaja, $this->sucursalA))->toThrow(ValidationException::class);

    $this->actingAs($sinCaja);
    Livewire::test(ControlCajaTopbar::class)->call('abrirModalApertura')->assertSet('modalAperturaAbierto', false);
});

test('en caja individual solo opera quien abrió y la modalidad no cambia con el turno abierto', function () {
    $individual = app(GuardarCaja::class)->crear($this->admin, ['sucursal_id' => $this->sucursalA->id, 'nombre' => 'Caja 2', 'modalidad' => 'individual']);
    $ana = User::factory()->vendedor($this->sucursalA)->create(['caja_id' => $individual->id]);
    $beto = User::factory()->vendedor($this->sucursalA)->create(['caja_id' => $individual->id]);
    $turno = app(AbrirTurnoCaja::class)->ejecutar($ana, $individual, '0');

    expect(fn () => venderConCaja($beto, $this->sucursalA))->toThrow(ValidationException::class)
        ->and($turno->usuarioPuedeCerrar($beto))->toBeFalse();
    expect(venderConCaja($ana, $this->sucursalA)->turno_caja_id)->toBe($turno->id);

    expect(fn () => app(GuardarCaja::class)->actualizar($this->admin, $individual, ['nombre' => 'Caja 2', 'modalidad' => 'compartida']))
        ->toThrow(ValidationException::class);
    expect($individual->fresh()->modalidad)->toBe('individual');
});

test('no se cambia la caja de un vendedor con turno abierto y un usuario desactivado ya no opera', function () {
    $otra = app(GuardarCaja::class)->crear($this->admin, ['sucursal_id' => $this->sucursalA->id, 'nombre' => 'Caja 2']);
    $ana = User::factory()->vendedor($this->sucursalA)->create(['caja_id' => $this->cajaA->id]);
    $turno = app(AbrirTurnoCaja::class)->ejecutar($ana, $this->cajaA, '0');
    $datos = ['name' => $ana->name, 'email' => $ana->email, 'rol' => Rol::Vendedor, 'sucursal_id' => $this->sucursalA->id, 'activo' => true];

    expect(fn () => app(GuardarUsuarioEmpresa::class)->actualizar($this->admin, $ana, $datos + ['caja_id' => $otra->id]))
        ->toThrow(ValidationException::class);
    expect($ana->fresh()->caja_id)->toBe($this->cajaA->id);

    app(GuardarUsuarioEmpresa::class)->actualizar($this->admin, $ana, ['activo' => false, 'caja_id' => $this->cajaA->id] + $datos);
    expect($turno->usuarioPuedeOperar($ana->fresh()))->toBeFalse()
        ->and($turno->usuarioPuedeCerrar($this->admin))->toBeTrue();
});

test('el administrador de otra empresa no opera ni cierra un turno compartido ajeno', function () {
    $turno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->cajaA, '0');
    [, $adminB] = empresaConAdministrador();

    expect($turno->usuarioPuedeOperar($adminB))->toBeFalse()
        ->and($turno->usuarioPuedeCerrar($adminB))->toBeFalse()
        ->and(fn () => app(AbrirTurnoCaja::class)->ejecutar($adminB, $this->cajaB, '0'))->toThrow(Exception::class);
});
