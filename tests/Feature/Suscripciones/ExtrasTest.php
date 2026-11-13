<?php

use App\Domain\Identidad\Actions\GuardarUsuarioEmpresa;
use App\Domain\Suscripciones\Actions\CambiarExtrasSuscripcion;
use App\Domain\Suscripciones\Actions\ConfigurarSuscripcion;
use App\Domain\Suscripciones\Actions\EnviarComprobantePago;
use App\Domain\Suscripciones\Actions\RevisarComprobantePago;
use App\Domain\Suscripciones\CalculadoraExtras;
use App\Domain\Tenancy\Actions\GuardarSucursal;
use App\Enums\EstadoPagoSaas;
use App\Enums\Rol;
use App\Livewire\Admin\Suscripciones;
use App\Livewire\Empresa\MiSuscripcion;
use App\Livewire\Empresa\Sucursales as PantallaSucursales;
use App\Livewire\Empresa\Usuarios as PantallaUsuarios;
use App\Models\Empresa;
use App\Models\PagoSaas;
use App\Models\Plan;
use App\Models\Scopes\EmpresaScope;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Extras de la suscripción (decisión del propietario, 13/11/2026): el plan incluye 1 sucursal y
 * 3 usuarios; sucursal extra Bs 120/mes y paquete de 5 usuarios Bs 50/mes. La mensualidad es
 * plan + extras. Agregar con el período ya pagado se paga en ese momento (proporcional hasta el
 * vencimiento, incluidos los meses adelantados) y se activa al aprobarse el comprobante.
 */

function suscripcionExtras(Empresa $empresa, string $vencimiento = '2026-10-31', array $plan = []): Suscripcion
{
    $plan = Plan::factory()->create($plan + [
        'codigo' => 'plan-'.Str::lower(Str::random(10)),
        'precio_mensual' => '299.00',
        'sucursales_incluidas' => 1,
        'usuarios_incluidos' => 3,
        'precio_sucursal_extra' => '120.00',
        'precio_paquete_usuarios' => '50.00',
        'usuarios_por_paquete' => 5,
    ]);

    return app(ConfigurarSuscripcion::class)->ejecutar(User::factory()->superadmin()->create(), $empresa, (string) $plan->precio_mensual, $vencimiento, null, $plan->id);
}

function suscripcionConExtras(Empresa $empresa): Suscripcion
{
    return Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresa->id)->with('plan')->sole();
}

function comprobanteExtras(User $admin, int $sucursales, int $paquetes, ?string $clave = null): PagoSaas
{
    return app(EnviarComprobantePago::class)->extras($admin,
        ['sucursales_extra' => $sucursales, 'paquetes_usuarios' => $paquetes, 'nota' => null, 'clave' => $clave ?? (string) Str::uuid()],
        imagenPng('extras.png'));
}

function nuevaSucursal(User $admin, string $codigo): Sucursal
{
    return app(GuardarSucursal::class)->crear($admin, ['nombre' => 'Sucursal '.$codigo, 'codigo' => $codigo, 'direccion' => null]);
}

function nuevoUsuario(User $admin, string $correo, bool $activo = true): User
{
    return app(GuardarUsuarioEmpresa::class)->crear($admin, [
        'name' => 'Persona '.$correo, 'email' => $correo.'@ejemplo.bo', 'rol' => Rol::Administrador,
        'sucursal_id' => null, 'password' => 'clave-segura-123', 'activo' => $activo,
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/La_Paz'));
    [$this->empresa, $this->admin] = empresaConAdministrador(['nombre' => 'Ferretería Illimani']);
    $this->superadmin = User::factory()->superadmin()->create();
    $this->actingAs($this->admin);
});

test('the prorated charge covers the remaining days and every month already paid in advance', function () {
    $hoy = CarbonImmutable::parse('2026-10-15', 'UTC');
    $suscripcion = suscripcionExtras($this->empresa, '2026-10-31');

    // 16 de 31 días: 120 × 16 / 31 = 61,935… → 61,94
    expect(CalculadoraExtras::diferencia($suscripcion, 1, 0, $hoy))
        ->toMatchArray(['incremento_mensual' => '120.00', 'meses_completos' => 0, 'dias_restantes' => 16, 'dias_periodo' => 31, 'importe' => '61.94']);
    expect(CalculadoraExtras::diferencia($suscripcion, 0, 1, $hoy)['importe'])->toBe('25.81');

    // 12 meses adelantados: 120 × (12 × 31 + 16) / 31 = 1.501,94
    $suscripcion->vencimiento = CarbonImmutable::parse('2027-10-31', 'UTC');
    expect(CalculadoraExtras::diferencia($suscripcion, 1, 0, $hoy))->toMatchArray(['meses_completos' => 12, 'importe' => '1501.94']);

    // Exactamente un mes antes del vencimiento: un mes completo, sin fracción.
    $suscripcion->vencimiento = CarbonImmutable::parse('2026-11-15', 'UTC');
    expect(CalculadoraExtras::diferencia($suscripcion, 1, 0, $hoy))->toMatchArray(['meses_completos' => 1, 'dias_restantes' => 0, 'importe' => '120.00']);

    // Quitar o dejar igual no cobra.
    $suscripcion->sucursales_extra = 2;
    expect(CalculadoraExtras::diferencia($suscripcion, 1, 0, $hoy)['importe'])->toBe('0.00');
});

test('the renewal charges plan plus extras and stores the breakdown', function () {
    suscripcionExtras($this->empresa);
    Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $this->empresa->id)->update(['sucursales_extra' => 2, 'paquetes_usuarios' => 1]);

    expect(suscripcionConExtras($this->empresa)->precioRenovacion())->toBe('589.00');

    $pago = app(EnviarComprobantePago::class)->ejecutar($this->admin, ['meses' => 3, 'nota' => null, 'clave' => (string) Str::uuid()], imagenPng());

    expect($pago->refresh())
        ->tipo->toBe(PagoSaas::TIPO_RENOVACION)
        ->precio_plan->toBe('299.00')
        ->sucursales_extra->toBe(2)
        ->paquetes_usuarios->toBe(1)
        ->precio_mensual->toBe('589.00')
        ->importe->toBe('1767.00');

    // La base rechaza un desglose que no suma la mensualidad.
    expect(fn () => DB::table('pagos_saas')->whereKey($pago->id)->update(['precio_plan' => '100.00']))
        ->toThrow(QueryException::class);
});

test('adding extras mid-period is paid now and only activates when MoziStudios approves', function () {
    suscripcionExtras($this->empresa);
    nuevaSucursal($this->admin, 'CEN');

    expect(fn () => nuevaSucursal($this->admin, 'NOR'))->toThrow(ValidationException::class, 'Tu plan permite 1 sucursal activa');
    expect(fn () => app(CambiarExtrasSuscripcion::class)->ejecutar($this->admin, 1, 0))->toThrow(ValidationException::class, 'debes pagar Bs 61,94');

    $clave = (string) Str::uuid();
    $pago = comprobanteExtras($this->admin, 1, 0, $clave);
    expect(comprobanteExtras($this->admin, 1, 0, $clave)->id)->toBe($pago->id);

    expect($pago->refresh())
        ->tipo->toBe(PagoSaas::TIPO_EXTRAS)
        ->meses->toBeNull()
        ->importe->toBe('61.94')
        ->precio_mensual->toBe('419.00');
    expect(suscripcionConExtras($this->empresa)->sucursales_extra)->toBe(0);

    // Mientras está en revisión no se puede cambiar nada más.
    expect(fn () => app(CambiarExtrasSuscripcion::class)->ejecutar($this->admin, 0, 0))->toThrow(ValidationException::class);

    app(RevisarComprobantePago::class)->aprobar($this->superadmin, $pago->id, true);
    app(RevisarComprobantePago::class)->aprobar($this->superadmin, $pago->id, true);

    $suscripcion = suscripcionConExtras($this->empresa);
    expect($suscripcion->sucursales_extra)->toBe(1)
        ->and($suscripcion->vencimiento->format('Y-m-d'))->toBe('2026-10-31')
        ->and($suscripcion->precioRenovacion())->toBe('419.00')
        ->and($pago->refresh()->vencimiento_nuevo->format('Y-m-d'))->toBe('2026-10-31');

    expect(nuevaSucursal($this->admin, 'NOR')->exists)->toBeTrue();
});

test('a rejected extras receipt activates nothing', function () {
    suscripcionExtras($this->empresa);
    $pago = comprobanteExtras($this->admin, 0, 2);
    expect($pago->importe)->toBe('51.61');

    app(RevisarComprobantePago::class)->rechazar($this->superadmin, $pago->id, 'El importe no llegó.');

    expect(suscripcionConExtras($this->empresa)->paquetes_usuarios)->toBe(0)
        ->and($pago->refresh()->estado)->toBe(EstadoPagoSaas::Rechazado);
});

test('removing extras is immediate, never refunds, and cannot drop below what is in use', function () {
    suscripcionExtras($this->empresa);
    Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $this->empresa->id)->update(['sucursales_extra' => 2]);
    nuevaSucursal($this->admin, 'A1');
    nuevaSucursal($this->admin, 'A2');

    expect(fn () => app(CambiarExtrasSuscripcion::class)->ejecutar($this->admin, 0, 0))
        ->toThrow(ValidationException::class, 'Tienes 2 sucursales activas');

    $suscripcion = app(CambiarExtrasSuscripcion::class)->ejecutar($this->admin, 1, 0);

    expect($suscripcion->sucursales_extra)->toBe(1)
        ->and($suscripcion->precioRenovacion())->toBe('419.00')
        ->and(PagoSaas::query()->count())->toBe(0);
});

test('during the free trial or with an overdue subscription extras apply at once without charge', function () {
    $suscripcion = suscripcionExtras($this->empresa);
    $suscripcion->prueba_hasta = CarbonImmutable::parse('2026-10-31', 'UTC');
    $suscripcion->save();

    expect(app(CambiarExtrasSuscripcion::class)->ejecutar($this->admin, 1, 1)->sucursales_extra)->toBe(1);

    [$otra, $adminOtra] = empresaConAdministrador();
    $this->actingAs($adminOtra);
    suscripcionExtras($otra, '2026-10-10');

    expect(app(CambiarExtrasSuscripcion::class)->ejecutar($adminOtra, 2, 0)->precioRenovacion())->toBe('539.00');
});

test('branch limit blocks new and reactivated branches but never touches the ones that exist', function () {
    // Sucursales creadas antes de que existieran los límites.
    $vieja1 = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $vieja2 = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    suscripcionExtras($this->empresa);

    expect(fn () => nuevaSucursal($this->admin, 'NUE'))->toThrow(ValidationException::class);

    app(GuardarSucursal::class)->cambiarEstado($this->admin, $vieja2, false);
    expect($vieja1->refresh()->activa)->toBeTrue();

    // Con 1 activa sigue lleno; con una sucursal extra se puede reactivar.
    expect(fn () => app(GuardarSucursal::class)->cambiarEstado($this->admin, $vieja2->refresh(), true))->toThrow(ValidationException::class);
    Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $this->empresa->id)->update(['sucursales_extra' => 1]);
    expect(app(GuardarSucursal::class)->cambiarEstado($this->admin, $vieja2->refresh(), true)->activa)->toBeTrue();

    // Una empresa que ya superaba el cupo puede sumar extras igual.
    expect(fn () => app(CambiarExtrasSuscripcion::class)->ejecutar($this->admin, 2, 0))->toThrow(ValidationException::class, 'debes pagar');
});

test('user limit counts active users only and blocks creating or reactivating beyond it', function () {
    suscripcionExtras($this->empresa);
    nuevoUsuario($this->admin, 'uno');
    nuevoUsuario($this->admin, 'dos');

    expect(fn () => nuevoUsuario($this->admin, 'tres'))->toThrow(ValidationException::class, 'Tu plan permite 3 usuarios activos');

    $inactivo = nuevoUsuario($this->admin, 'inactivo', activo: false);
    expect($inactivo->activo)->toBeFalse();

    expect(fn () => app(GuardarUsuarioEmpresa::class)->actualizar($this->admin, $inactivo, [
        'name' => $inactivo->name, 'email' => $inactivo->email, 'rol' => Rol::Administrador, 'sucursal_id' => null, 'activo' => true,
    ]))->toThrow(ValidationException::class);

    Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $this->empresa->id)->update(['paquetes_usuarios' => 1]);
    expect(nuevoUsuario($this->admin, 'tres')->exists)->toBeTrue();
});

test('limits only count the own company and do not apply without a plan', function () {
    suscripcionExtras($this->empresa);
    [$otra, $adminOtra] = empresaConAdministrador();

    // La otra empresa no tiene suscripción: sin límite. Sus sucursales no cuentan para esta.
    $this->actingAs($adminOtra);
    Sucursal::factory()->count(3)->create(['empresa_id' => $otra->id]);
    expect(nuevaSucursal($adminOtra, 'B4')->exists)->toBeTrue();

    $this->actingAs($this->admin);
    expect(nuevaSucursal($this->admin, 'A1')->exists)->toBeTrue();
});

test('branches and users screens show how much of the plan is in use', function () {
    // Sin suscripción no se muestra el cupo.
    Livewire::test(PantallaUsuarios::class)->assertDontSee('de tu plan');

    suscripcionExtras($this->empresa);
    Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);

    Livewire::test(PantallaUsuarios::class)
        ->assertSee('1 de 3 de tu plan')
        ->assertDontSee('Sumar usuarios');
    Livewire::test(PantallaSucursales::class)
        ->assertSee('1 de 1 de tu plan')
        ->assertSee('Usas la 1 sucursal que incluye tu plan')
        ->assertSee('Ir a Mi suscripción');

    nuevoUsuario($this->admin, 'dos');
    nuevoUsuario($this->admin, 'tres');
    Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $this->empresa->id)->update(['sucursales_extra' => 1]);

    Livewire::test(PantallaUsuarios::class)->assertSee('3 de 3 de tu plan')->assertSee('Sumar usuarios');
    Livewire::test(PantallaSucursales::class)->assertSee('1 de 2 de tu plan')->assertDontSee('Ir a Mi suscripción');
});

test('the company screen shows the bill and walks through paying for extras', function () {
    suscripcionExtras($this->empresa);

    $pantalla = Livewire::test(MiSuscripcion::class)
        ->assertSee('Lo que pagas cada mes')
        ->assertSee('Plan Empresa')
        ->assertSee('Bs 299,00')
        ->call('sumar', 'sucursales')
        ->call('sumar', 'sucursales')
        ->assertSee('Bs 539,00')
        ->assertSee('Pagar Bs 123,87 y activar')
        ->call('restar', 'sucursales')
        ->assertSee('Pagar Bs 61,94 y activar')
        ->call('pagarExtras')
        ->assertSee('Pagas hoy por tus extras')
        ->set('archivo', imagenPng('qr.png'))
        ->call('enviar')
        ->assertHasNoErrors();

    $pago = PagoSaas::query()->sole();
    expect($pago->tipo)->toBe(PagoSaas::TIPO_EXTRAS)->and($pago->importe)->toBe('61.94');
    $pantalla->assertSee('Tienes un comprobante en revisión');
});

test('the company screen saves a free change at once', function () {
    $suscripcion = suscripcionExtras($this->empresa);
    $suscripcion->prueba_hasta = CarbonImmutable::parse('2026-10-31', 'UTC');
    $suscripcion->save();

    Livewire::test(MiSuscripcion::class)
        ->call('sumar', 'usuarios')
        ->assertSee('Estás en tu prueba gratis')
        ->call('guardarExtras')
        ->assertHasNoErrors()
        ->assertSee('Bs 349,00');

    expect(suscripcionConExtras($this->empresa)->paquetes_usuarios)->toBe(1);
});

test('the superadmin sets what each plan includes and the price of extras', function () {
    $plan = Plan::factory()->create(['codigo' => 'plan-editable']);

    Livewire::actingAs($this->superadmin)->test(Suscripciones::class)
        ->set('pestana', 'planes')
        ->call('editarPlan', $plan->id)
        ->assertSet('planSucursales', '1')
        ->set('planSucursales', '')
        ->set('planUsuarios', '10')
        ->set('planPrecioSucursal', '150,50')
        ->set('planPrecioPaquete', '40')
        ->set('planUsuariosPaquete', '3')
        ->call('guardarPlan')
        ->assertHasNoErrors();

    expect($plan->refresh())
        ->sucursales_incluidas->toBeNull()
        ->usuarios_incluidos->toBe(10)
        ->precio_sucursal_extra->toBe('150.50')
        ->precio_paquete_usuarios->toBe('40.00')
        ->usuarios_por_paquete->toBe(3);
});

test('the pricing page shows what each plan includes and the extras below', function () {
    Plan::query()->update(['activo' => false]);
    Plan::factory()->create(['codigo' => 'inventario-web', 'activo' => true, 'precio_mensual' => '299.00']);

    $this->get(route('sitio.precios'))->assertOk()
        ->assertSee('Incluye', false)
        ->assertSee('Sucursal adicional')
        ->assertSee('Bs 120,00')
        ->assertSee('Plan Empresa');
});
