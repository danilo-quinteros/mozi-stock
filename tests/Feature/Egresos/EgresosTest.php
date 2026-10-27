<?php

use App\Domain\Egresos\Actions\RegistrarEgreso;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Periodo;
use App\Domain\Tenancy\Actions\CrearEmpresa;
use App\Livewire\Caja\Arqueo;
use App\Livewire\Egresos\Panel;
use App\Models\CategoriaEgreso;
use App\Models\Egreso;
use App\Models\MovimientoCaja;
use App\Models\Scopes\EmpresaScope;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Egresos operativos (§ 3.8): una salida real de caja por egreso, en la fecha en que se
 * registra, con categoría, sucursal y medio. Solo administradores.
 */

function gastar(User $admin, array $datos): Egreso
{
    return app(RegistrarEgreso::class)->ejecutar($admin, $datos + [
        'descripcion' => 'Internet', 'metodo' => 'efectivo', 'monto' => '150', 'clave' => (string) Str::uuid(),
    ]);
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->servicios = new CategoriaEgreso(['nombre' => 'Servicios', 'descripcion' => 'Luz, internet, agua']);
    $this->servicios->empresa_id = $this->empresa->id;
    $this->servicios->save();
    $this->actingAs($this->admin);
});

test('an expense is one cash outflow on the day it is registered', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 23:30', 'America/La_Paz'));
    $egreso = gastar($this->admin, ['sucursal_id' => $this->sucursal->id, 'categoria_id' => $this->servicios->id, 'metodo' => 'electronico']);

    expect($egreso->codigo())->toBe('EGR-00001');
    $caja = MovimientoCaja::query()->sole();
    expect($caja->sentido)->toBe('egreso')->and($caja->metodo)->toBe('electronico')->and($caja->monto)->toBe('150.00')
        ->and($caja->origen_tipo)->toBe('egreso')->and($caja->origen_id)->toBe($egreso->id);

    // 23:30 en La Paz sigue siendo el 22, aunque en UTC ya sea el 23.
    expect(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-22'))['egresos_electronico'])->toBe('150.00')
        ->and(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-23'))['egresos'])->toBe('0.00');
});

test('invalid amounts, inactive or foreign categories and repeated keys never create outflows', function () {
    $base = ['sucursal_id' => $this->sucursal->id, 'categoria_id' => $this->servicios->id];

    foreach (['0', '-5', 'abc', '1.005'] as $monto) {
        expect(fn () => gastar($this->admin, $base + ['monto' => $monto]))->toThrow(ValidationException::class);
    }

    expect(fn () => gastar($this->admin, $base + ['descripcion' => '   ']))->toThrow(ValidationException::class)
        ->and(fn () => gastar($this->admin, $base + ['metodo' => 'mixto']))->toThrow(ValidationException::class);

    $inactiva = new CategoriaEgreso(['nombre' => 'Antigua']);
    $inactiva->activa = false;
    $inactiva->save();
    expect(fn () => gastar($this->admin, ['sucursal_id' => $this->sucursal->id, 'categoria_id' => $inactiva->id]))->toThrow(ValidationException::class);

    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $categoriaB = new CategoriaEgreso(['nombre' => 'Servicios']);
    $categoriaB->save();
    $this->actingAs($this->admin);
    expect(fn () => gastar($this->admin, ['sucursal_id' => $this->sucursal->id, 'categoria_id' => $categoriaB->id]))->toThrow(ValidationException::class);

    $clave = (string) Str::uuid();
    gastar($this->admin, $base + ['clave' => $clave]);
    gastar($this->admin, $base + ['clave' => $clave]);

    expect(Egreso::query()->count())->toBe(1)->and(MovimientoCaja::query()->count())->toBe(1);
});

test('sellers cannot register or see expenses', function () {
    $vendedor = User::factory()->vendedor($this->sucursal)->create();
    $this->actingAs($vendedor);

    expect(fn () => gastar($vendedor, ['sucursal_id' => $this->sucursal->id, 'categoria_id' => $this->servicios->id]))
        ->toThrow(AuthorizationException::class);
    $this->get(route('egresos.index'))->assertForbidden();
    $this->get(route('egresos.exportar'))->assertForbidden();
});

test('the expenses screen registers once, shows the cash count and keeps companies apart', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'America/La_Paz'));
    $pantalla = Livewire::test(Panel::class)->call('nuevoEgreso')
        ->set('categoria_id', (string) $this->servicios->id)
        ->set('descripcion', 'Luz de octubre')
        ->set('monto', '230,50');
    $pantalla->call('registrarEgreso')->assertSee('Luz de octubre')->assertSee('Bs 230,50');
    // Doble envío con la misma pantalla (misma clave): no se repite.
    $pantalla->set('formEgreso', true)->call('registrarEgreso');

    expect(Egreso::query()->count())->toBe(1);

    Livewire::test(Arqueo::class)->assertSee('Salidas del día')->assertSee('Egreso · EGR-00001')
        ->assertSee('Servicios · Luz de octubre')->assertSee('− Bs 230,50');
    $arqueo = $this->get(route('caja.exportar', ['fecha' => '2026-10-22']))->streamedContent();
    expect($arqueo)->toContain('Salida;Egreso;EGR-00001')->toContain(';230.50;0.00;230.50');

    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    Livewire::test(Panel::class)->assertDontSee('Luz de octubre');
    expect($this->get(route('egresos.exportar'))->streamedContent())->not->toContain('Luz de octubre');
});

test('expense categories are unique per company and new companies start with the base ones', function () {
    Livewire::test(Panel::class)->set('pestana', 'categorias')->call('nuevaCategoria')
        ->set('categoriaNombre', 'Servicios')->call('guardarCategoria')->assertHasErrors('categoriaNombre')
        ->set('categoriaNombre', 'Publicidad')->call('guardarCategoria')->assertHasNoErrors()->assertSee('Publicidad');

    $superadmin = User::factory()->superadmin()->create();
    $this->actingAs($superadmin);
    $empresa = app(CrearEmpresa::class)->ejecutar($superadmin, ['nombre' => 'Nueva SRL'], [
        'name' => 'Admin Nueva', 'email' => 'admin@nueva.test', 'password' => 'clave-segura-123',
    ]);

    expect(CategoriaEgreso::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresa->id)->orderBy('id')->pluck('nombre')->all())
        ->toBe(['Sueldos', 'Alquiler', 'Servicios', 'Transporte', 'Otros']);
});
