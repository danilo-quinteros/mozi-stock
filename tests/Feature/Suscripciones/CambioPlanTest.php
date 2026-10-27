<?php

use App\Domain\Suscripciones\Actions\ConfigurarSuscripcion;
use App\Domain\Suscripciones\Actions\EnviarComprobantePago;
use App\Domain\Suscripciones\Actions\RevisarComprobantePago;
use App\Livewire\Admin\Suscripciones;
use App\Livewire\Empresa\MiSuscripcion;
use App\Models\CambioPlan;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Scopes\EmpresaScope;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/La_Paz'));

    $this->superadmin = User::factory()->superadmin()->create();

    // Obtener los planes sembrados por la migración o crearlos si no existen
    $this->planInventario = Plan::firstOrCreate(
        ['codigo' => 'inventario'],
        [
            'titulo' => 'Acceso al sistema de inventarios',
            'descripcion' => 'Acceso completo al sistema de gestión de inventarios, pedidos y ventas.',
            'precio_mensual' => '150.00',
            'siat_habilitado' => false,
            'activo' => true,
            'orden' => 1,
        ]
    );

    $this->planSiat = Plan::firstOrCreate(
        ['codigo' => 'siat'],
        [
            'titulo' => 'Inventarios + Facturación SIAT',
            'descripcion' => 'Acceso completo al sistema de inventarios más facturación electrónica SIAT.',
            'precio_mensual' => '300.00',
            'siat_habilitado' => true,
            'activo' => true,
            'orden' => 2,
        ]
    );

    [$this->empresa, $this->admin] = empresaConAdministrador(['nombre' => 'Ferretería El Cóndor']);
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $this->vendedor = User::factory()->vendedor($this->sucursal)->create();

    // Asignar suscripción con vencimiento a fin de mes (31 de octubre)
    app(ConfigurarSuscripcion::class)->ejecutar(
        $this->superadmin,
        $this->empresa,
        '150.00',
        '2026-10-31',
        null,
        $this->planInventario->id
    );
});

test('the superadmin can edit the title, description, and price of a plan', function () {
    Livewire::actingAs($this->superadmin)->test(Suscripciones::class)
        ->set('pestana', 'planes')
        ->assertSee('Acceso al sistema de inventarios')
        ->assertSee('Bs 150,00')
        ->call('editarPlan', $this->planInventario->id)
        ->assertSet('planTitulo', 'Acceso al sistema de inventarios')
        ->assertSet('planPrecio', '150')
        ->set('planTitulo', 'Plan Inventarios Pro')
        ->set('planDescripcion', 'Inventarios avanzados y compras.')
        ->set('planPrecio', '180.00')
        ->call('guardarPlan')
        ->assertHasNoErrors()
        ->assertSee('Plan Inventarios Pro');

    $this->planInventario->refresh();
    expect($this->planInventario->titulo)->toBe('Plan Inventarios Pro')
        ->and($this->planInventario->descripcion)->toBe('Inventarios avanzados y compras.')
        ->and($this->planInventario->precio_mensual)->toBe('180.00');
});

test('the superadmin can create a third subscription plan', function () {
    Livewire::actingAs($this->superadmin)->test(Suscripciones::class)
        ->set('pestana', 'planes')
        ->call('crearPlan')
        ->assertSet('modalPlanAbierto', true)
        ->set('planTitulo', 'Plan Enterprise Multi-Sucursal')
        ->set('planDescripcion', 'Inventarios, Facturación SIAT y sucursales ilimitadas.')
        ->set('planPrecio', '500.00')
        ->set('planSiat', true)
        ->call('guardarPlan')
        ->assertHasNoErrors()
        ->assertSee('Plan Enterprise Multi-Sucursal');

    $tercerPlan = Plan::query()->where('codigo', 'like', '%enterprise%')->first();
    expect($tercerPlan)->not->toBeNull()
        ->and($tercerPlan->titulo)->toBe('Plan Enterprise Multi-Sucursal')
        ->and($tercerPlan->precio_mensual)->toBe('500.00')
        ->and($tercerPlan->siat_habilitado)->toBeTrue();
});

test('the company administrator can upgrade plan with proration, paying only the remaining difference', function () {
    // Hoy es 15 de octubre; el ciclo vence el 31 de octubre (quedan 16 días de un ciclo de 31 días)
    // Diferencia: 300 - 150 = 150. Prorrateo = (150 * 16) / 31 = 77.42
    Livewire::actingAs($this->admin)->test(MiSuscripcion::class)
        ->assertSee('Acceso al sistema de inventarios')
        ->call('abrirModalPlanes')
        ->assertSet('modalPlanesAbierto', true)
        ->call('seleccionarPlan', $this->planSiat->id)
        ->assertSee('Subir de plan')
        ->assertSee('Bs 77,42') // Monto proporcional por los días restantes
        ->assertSee('únicamente por los días restantes')
        ->assertSee('Bs 300,00') // En la próxima renovación pagará el precio completo
        ->assertSee('31/10/2026') // Vencimiento original se mantiene
        ->call('confirmarCambioPlan')
        ->assertHasNoErrors()
        ->assertSee('Plan actualizado con éxito');

    $suscripcion = Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $this->empresa->id)->first();
    expect($suscripcion->plan_id)->toBe($this->planSiat->id)
        ->and($suscripcion->precio_mensual)->toBe('300.00') // Nuevo precio para la siguiente renovación
        ->and($suscripcion->vencimiento->format('Y-m-d'))->toBe('2026-10-31') // Fecha original intacta
        ->and($suscripcion->plan_siguiente_id)->toBeNull();

    // Beneficios SIAT habilitados de inmediato
    $this->empresa->refresh();
    expect($this->empresa->siat_habilitado)->toBeTrue();

    // Registro histórico del cambio
    $cambio = CambioPlan::query()->latest('id')->first();
    expect($cambio->tipo)->toBe('upgrade')
        ->and($cambio->monto_prorrateado)->toBe('77.42')
        ->and($cambio->dias_restantes)->toBe(16)
        ->and($cambio->precio_nuevo_completo)->toBe('300.00')
        ->and($cambio->vencimiento_conservado->format('Y-m-d'))->toBe('2026-10-31');
});

test('the company administrator can downgrade, retaining current benefits until expiration and applying reduced price on renewal', function () {
    // Colocar a la empresa en Plan SIAT primero
    $suscripcion = Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $this->empresa->id)->first();
    $suscripcion->plan_id = $this->planSiat->id;
    $suscripcion->precio_mensual = '300.00';
    $suscripcion->save();
    $this->empresa->forceFill(['siat_habilitado' => true])->save();

    Livewire::actingAs($this->admin)->test(MiSuscripcion::class)
        ->call('abrirModalPlanes')
        ->call('seleccionarPlan', $this->planInventario->id)
        ->assertSee('Cambiar a plan: Acceso al sistema de inventarios (Downgrade)')
        ->assertSee('Conservas todos los beneficios de tu plan actual hasta su vencimiento')
        ->assertSee('Bs 150,00') // Precio reducido en la siguiente renovación
        ->assertSee('31/10/2026') // Vencimiento original conservado
        ->call('confirmarCambioPlan')
        ->assertHasNoErrors()
        ->assertSee('Cambio de plan programado');

    $suscripcion->refresh();
    // No cambia el plan actual hoy:
    expect($suscripcion->plan_id)->toBe($this->planSiat->id)
        ->and($suscripcion->plan_siguiente_id)->toBe($this->planInventario->id)
        ->and($suscripcion->vencimiento->format('Y-m-d'))->toBe('2026-10-31');

    // La empresa CONSERVA sus beneficios actuales (SIAT) hasta el vencimiento:
    $this->empresa->refresh();
    expect($this->empresa->siat_habilitado)->toBeTrue();

    // En la pantalla se informa del cambio programado y el precio de la siguiente renovación es Bs 150:
    Livewire::actingAs($this->admin)->test(MiSuscripcion::class)
        ->assertSee('Cambio de plan programado para tu próxima renovación')
        ->assertSee('Acceso al sistema de inventarios')
        ->assertSee('Bs 150,00');

    // Al renovar (enviar comprobante y aprobar), se cobra el precio reducido Bs 150 y se aplica el nuevo plan:
    $pago = app(EnviarComprobantePago::class)->ejecutar(
        $this->admin,
        ['meses' => 1, 'nota' => null, 'clave' => (string) Str::uuid()],
        imagenPng('recibo.png')
    );
    expect($pago->precio_mensual)->toBe('150.00')
        ->and($pago->importe)->toBe('150.00');

    // Superadmin aprueba la renovación
    app(RevisarComprobantePago::class)->aprobar($this->superadmin, $pago->id, true);

    $suscripcion->refresh();
    $this->empresa->refresh();

    // Ahora sí se aplicó el nuevo plan con precio reducido y se actualizaron los beneficios
    expect($suscripcion->plan_id)->toBe($this->planInventario->id)
        ->and($suscripcion->precio_mensual)->toBe('150.00')
        ->and($suscripcion->plan_siguiente_id)->toBeNull()
        ->and($this->empresa->siat_habilitado)->toBeFalse();
});

test('a scheduled downgrade can be cancelled before expiration', function () {
    $suscripcion = Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $this->empresa->id)->first();
    $suscripcion->plan_id = $this->planSiat->id;
    $suscripcion->plan_siguiente_id = $this->planInventario->id;
    $suscripcion->save();

    Livewire::actingAs($this->admin)->test(MiSuscripcion::class)
        ->assertSee('Cambio de plan programado')
        ->call('cancelarCambioPlan')
        ->assertHasNoErrors()
        ->assertSee('Cambio de plan programado cancelado');

    $suscripcion->refresh();
    expect($suscripcion->plan_siguiente_id)->toBeNull()
        ->and($suscripcion->plan_id)->toBe($this->planSiat->id);
});

test('sellers cannot change the company subscription plan', function () {
    Livewire::actingAs($this->vendedor)->test(MiSuscripcion::class)
        ->assertForbidden();
});

test('multi-tenant isolation prevents Empresa A from modifying Empresa B plans', function () {
    [$otraEmpresa, $otroAdmin] = empresaConAdministrador(['nombre' => 'Supermercado Central']);
    app(ConfigurarSuscripcion::class)->ejecutar(
        $this->superadmin,
        $otraEmpresa,
        '150.00',
        '2026-10-31',
        null,
        $this->planInventario->id
    );

    // El admin de otra empresa cambia su plan:
    Livewire::actingAs($otroAdmin)->test(MiSuscripcion::class)
        ->call('abrirModalPlanes')
        ->call('seleccionarPlan', $this->planSiat->id)
        ->call('confirmarCambioPlan');

    // La empresa original sigue inalterada:
    $suscripcionOriginal = Suscripcion::withoutGlobalScope(EmpresaScope::class)
        ->where('empresa_id', $this->empresa->id)->first();
    expect($suscripcionOriginal->plan_id)->toBe($this->planInventario->id);
});
