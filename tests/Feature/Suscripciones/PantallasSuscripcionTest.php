<?php

use App\Domain\Suscripciones\Actions\ConfigurarSuscripcion;
use App\Domain\Suscripciones\Actions\EnviarComprobantePago;
use App\Enums\EstadoPagoSaas;
use App\Livewire\Admin\AjustesGlobales;
use App\Livewire\Admin\Comprobantes;
use App\Livewire\Admin\Empresas;
use App\Livewire\Admin\Suscripciones;
use App\Livewire\Empresa\MiSuscripcion;
use App\Models\AjusteGlobal;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\PagoSaas;
use App\Models\Scopes\EmpresaScope;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Pantallas de suscripción (suscripcion.html, admin-suscripciones.html, comprobantes.html,
 * ajustes-globales.html) y aislamiento entre empresas en rutas, descargas y CSV (T02/T03).
 */

function enviarDe(User $admin, int $meses = 1): PagoSaas
{
    return app(EnviarComprobantePago::class)->ejecutar($admin, ['meses' => $meses, 'nota' => null, 'clave' => (string) Str::uuid()], imagenPng('pago.png'));
}

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00', 'America/La_Paz'));
    $this->superadmin = User::factory()->superadmin()->create();
    [$this->empresa, $this->admin] = empresaConAdministrador(['nombre' => 'Comercial Santa Cruz']);
    [$this->otra, $this->adminOtra] = empresaConAdministrador(['nombre' => 'Mercado Norte']);
    $configurar = app(ConfigurarSuscripcion::class);
    $configurar->ejecutar($this->superadmin, $this->empresa, '149', '2026-10-28');
    $configurar->ejecutar($this->superadmin, $this->otra, '199', '2026-12-01');
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $this->vendedor = User::factory()->vendedor($this->sucursal)->create();
});

test('the administrator sees the plan and sends a receipt that stays under review', function () {
    Livewire::actingAs($this->admin)->test(MiSuscripcion::class)
        ->assertSee('Bs 149,00')->assertSee('28/10/2026')->assertSee('Tu mensualidad vence en 4 días')->assertSee('Próxima a vencer')
        ->assertSee('data-status="pronto"', false)->assertDontSee('demoSubscriptionStatus')
        ->assertSee('MoziStudios aún no cargó su QR')->assertDontSee('Mercado Norte')->assertDontSee('Bs 199,00')
        ->call('abrir')
        ->set('meses', '3')->assertSee('Bs 447,00')->assertSee('28/01/2027')
        ->set('archivo', imagenPng('pago.png'))
        ->set('nota', 'Operación 123')
        ->call('enviar')
        ->assertHasNoErrors()
        ->assertSee('Comprobante CP-')->assertSee('en revisión')->assertSee('En revisión');

    $pago = PagoSaas::query()->sole();
    expect($pago->meses)->toBe(3)->and($pago->importe)->toBe('447.00')->and($pago->nota)->toBe('Operación 123')
        ->and(Suscripcion::query()->sole()->vencimiento->format('Y-m-d'))->toBe('2026-10-28');
});

test('submitting twice with the same key registers one receipt', function () {
    $componente = Livewire::actingAs($this->admin)->test(MiSuscripcion::class)->call('abrir')
        ->set('archivo', imagenPng('pago.png'));
    $clave = $componente->get('clave');
    $componente->call('enviar')->assertHasNoErrors();

    // Reintento del mismo formulario (misma clave) antes de recargar.
    app(EnviarComprobantePago::class)->ejecutar($this->admin, ['meses' => 1, 'nota' => null, 'clave' => $clave], imagenPng('pago.png'));

    expect(PagoSaas::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles("empresas/{$this->empresa->id}/suscripcion"))->toHaveCount(1);
});

test('sellers, the superadmin and standalone mode cannot reach My subscription', function () {
    $this->actingAs($this->vendedor)->get(route('suscripcion'))->assertForbidden();
    $this->actingAs($this->vendedor)->get(route('suscripcion.qr'))->assertForbidden();
    $this->actingAs($this->superadmin)->get(route('suscripcion'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('suscripcion'))->assertOk()->assertSee('Mi suscripción');

    config(['mozistock.deployment_mode' => 'standalone']);
    $this->actingAs($this->admin)->get(route('suscripcion'))->assertNotFound();
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertDontSee('Mi suscripción');
});

test('T02: receipts are only downloadable by their company administrator and the superadmin', function () {
    $this->actingAs($this->admin);
    $pago = enviarDe($this->admin);
    $this->actingAs($this->adminOtra);
    $ajeno = enviarDe($this->adminOtra);

    $this->actingAs($this->admin)->get(route('suscripcion.comprobante', $pago->id))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->actingAs($this->admin)->get(route('suscripcion.comprobante', $ajeno->id))->assertNotFound();
    $this->actingAs($this->adminOtra)->get(route('suscripcion.comprobante', $pago->id))->assertNotFound();
    $this->actingAs($this->vendedor)->get(route('suscripcion.comprobante', $pago->id))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.comprobantes.archivo', $pago->id))->assertForbidden();
    $this->actingAs($this->superadmin)->get(route('admin.comprobantes.archivo', $ajeno->id))->assertOk();

    Livewire::actingAs($this->admin)->test(MiSuscripcion::class)->assertSee($pago->codigo())->assertDontSee($ajeno->codigo());
});

test('the superadmin reviews receipts: approval needs the amount check, rejection needs a reason', function () {
    $this->actingAs($this->admin);
    $pago = enviarDe($this->admin, 2);

    $pantalla = Livewire::actingAs($this->superadmin)->test(Comprobantes::class)
        ->assertSee('Comercial Santa Cruz')->assertSee('Bs 298,00')->assertSee('En revisión')
        ->call('revisar', $pago->id)
        ->assertSee('28/10/2026 → 28/12/2026')
        ->call('aprobar')->assertHasErrors('verificado')
        ->call('rechazar')->assertSet('rechazando', true)
        ->call('rechazar')->assertHasErrors('motivo');
    expect($pago->refresh()->estado)->toBe(EstadoPagoSaas::Pendiente);

    $pantalla->call('cerrar')->call('revisar', $pago->id)->set('verificado', true)->call('aprobar')->assertHasNoErrors()
        ->assertSee($pago->codigo().' aprobado: el nuevo vencimiento es el 28/12/2026.');
    expect($pago->refresh()->estado)->toBe(EstadoPagoSaas::Aprobado)
        ->and(Suscripcion::query()->withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $this->empresa->id)->sole()->vencimiento->format('Y-m-d'))->toBe('2026-12-28');

    $this->actingAs($this->adminOtra);
    $otro = enviarDe($this->adminOtra);
    Livewire::actingAs($this->superadmin)->test(Comprobantes::class)
        ->call('revisar', $otro->id)->call('rechazar')->set('motivo', 'El importe no coincide')->call('rechazar')->assertHasNoErrors();
    expect($otro->refresh()->estado)->toBe(EstadoPagoSaas::Rechazado);

    Livewire::actingAs($this->adminOtra)->test(MiSuscripcion::class)->assertSee('Rechazado')->assertSee('El importe no coincide')
        ->assertSee('Ver opciones de pago');
});

test('company users never reach the global screens or their CSV', function () {
    foreach ([$this->admin, $this->vendedor] as $usuario) {
        foreach (['admin.suscripciones', 'admin.comprobantes', 'admin.ajustes', 'admin.suscripciones.exportar', 'admin.comprobantes.exportar'] as $ruta) {
            $this->actingAs($usuario)->get(route($ruta))->assertForbidden();
        }
    }

    config(['mozistock.deployment_mode' => 'standalone']);
    $this->actingAs($this->superadmin)->get(route('admin.suscripciones'))->assertNotFound();
});

test('the subscriptions screen and its CSV share filters and show only plan data', function () {
    $sinPlan = Empresa::factory()->create(['nombre' => 'Servicios Andinos']);
    $this->actingAs($this->admin);
    $pago = enviarDe($this->admin);

    Livewire::actingAs($this->superadmin)->test(Suscripciones::class)
        ->assertSee('Comercial Santa Cruz')->assertSee('Mercado Norte')->assertSee('Servicios Andinos')
        ->assertSee('Por vencer')->assertSee('Al día')->assertSee('Comprobante en revisión')
        ->set('estado', 'por_vencer')->assertSee('Comercial Santa Cruz')->assertDontSee('Mercado Norte')
        ->set('estado', 'sin_plan')->assertSee('Servicios Andinos')->assertDontSee('Comercial Santa Cruz')
        ->set('estado', '')->set('pestana', 'recordatorios')->assertSee('Todavía no se generaron recordatorios');

    $csv = $this->actingAs($this->superadmin)->get(route('admin.suscripciones.exportar', ['estado' => 'por_vencer']))->assertOk()->streamedContent();
    expect($csv)->toContain('Comercial Santa Cruz')->toContain('149.00')->toContain('28/10/2026')->toContain('Por vencer')
        ->not->toContain('Mercado Norte')->not->toContain($sinPlan->nombre);

    $todos = $this->actingAs($this->superadmin)->get(route('admin.comprobantes.exportar'))->assertOk()->streamedContent();
    expect($todos)->toContain($pago->codigo())->toContain('Comercial Santa Cruz')->toContain('En revisión');
});

test('the superadmin sets price and first due date when creating a company and needs a reason to move it', function () {
    Livewire::actingAs($this->superadmin)->test(Empresas::class)
        ->call('crear')
        ->set('nombre', 'Tienda Los Pinos')->set('email', 'pinos@ejemplo.test')->set('rubro', 'general')
        ->set('precio_mensual', '129,50')->set('vencimiento', '2026-11-21')
        ->set('admin_nombre', 'Admin Pinos')->set('admin_email', 'admin@pinos.test')
        ->set('admin_password', 'ClaveSegura12345')->set('admin_password_confirmation', 'ClaveSegura12345')
        ->call('guardar')->assertHasNoErrors()
        ->assertSee('Bs 129,50')->assertSee('21/11/2026');

    $pinos = Empresa::query()->where('nombre', 'Tienda Los Pinos')->sole();
    $plan = Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $pinos->id)->sole();
    expect($plan->precio_mensual)->toBe('129.50')->and($plan->dia_vencimiento)->toBe(21);

    $pantalla = Livewire::actingAs($this->superadmin)->withQueryParams(['editar' => $pinos->id])->test(Empresas::class)
        ->assertSet('formularioAbierto', true)->assertSet('vencimiento', '2026-11-21')
        ->set('vencimiento', '2026-11-30')->assertSee('Motivo del cambio de vencimiento')
        ->call('guardar')->assertHasErrors('motivo_vencimiento');
    expect($plan->refresh()->vencimiento->format('Y-m-d'))->toBe('2026-11-21');

    $pantalla->set('motivo_vencimiento', 'Acuerdo comercial')->call('guardar')->assertHasNoErrors();
    expect($plan->refresh()->vencimiento->format('Y-m-d'))->toBe('2026-11-30')->and($plan->dia_vencimiento)->toBe(30)
        ->and(Auditoria::query()->where('accion', 'suscripcion.actualizada')->sole()->despues['motivo'])->toBe('Acuerdo comercial');

    // Una empresa sin plan puede crearse igual; precio sin fecha no.
    Livewire::actingAs($this->superadmin)->test(Empresas::class)->call('crear')
        ->set('nombre', 'Sin fecha')->set('precio_mensual', '100')
        ->set('admin_nombre', 'A')->set('admin_email', 'a@sinfecha.test')
        ->set('admin_password', 'ClaveSegura12345')->set('admin_password_confirmation', 'ClaveSegura12345')
        ->call('guardar')->assertHasErrors('vencimiento');
    expect(Empresa::query()->where('nombre', 'Sin fecha')->exists())->toBeFalse();
});

test('the private QR is uploaded by the superadmin and shown only to company administrators', function () {
    Livewire::actingAs($this->superadmin)->test(AjustesGlobales::class)
        ->assertSee('Sin configurar · solo panel')
        ->set('receptor_nombre', 'MoziStudios')->set('correo_contacto', 'Soporte@MoziStudios.test')
        ->set('qr', imagenPng('qr.png'))
        ->call('guardar')->assertHasNoErrors()->assertSee('Configuración guardada.');

    $ajustes = AjusteGlobal::actual();
    expect($ajustes->correo_contacto)->toBe('soporte@mozistudios.test')->and($ajustes->qr_path)->toStartWith('mozistudios/qr-');
    Storage::disk('local')->assertExists((string) $ajustes->qr_path);

    $this->actingAs($this->admin)->get(route('suscripcion.qr'))->assertOk();
    $this->actingAs($this->superadmin)->get(route('admin.ajustes.qr'))->assertOk();
    $this->actingAs($this->vendedor)->get(route('suscripcion.qr'))->assertForbidden();
    Livewire::actingAs($this->admin)->test(MiSuscripcion::class)->assertDontSee('MoziStudios aún no cargó su QR')->assertSee('soporte@mozistudios.test');

    Livewire::actingAs($this->superadmin)->test(AjustesGlobales::class)->call('quitar')->call('guardar');
    expect(AjusteGlobal::actual()->qr_path)->toBeNull();
    Storage::disk('local')->assertMissing((string) $ajustes->qr_path);
});

test('the dashboard and menu warn administrators, not sellers, about the due date', function () {
    guiaTerminada($this->empresa);
    guiaTerminada($this->otra);
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('Tu suscripción vence en 4 días')->assertSee('Ir a Mi suscripción');
    $this->actingAs($this->vendedor)->get(route('dashboard'))->assertOk()
        ->assertDontSee('Tu suscripción vence')->assertDontSee('Mi suscripción');
    $this->actingAs($this->adminOtra)->get(route('dashboard'))->assertOk()->assertDontSee('Tu suscripción vence');

    $this->actingAs($this->superadmin)->get(route('admin.panel'))->assertOk()
        ->assertSee('Comercial Santa Cruz')->assertSee('Bs 149,00')->assertSee('Por vencer');
});

test('uploading a receipt rejects non-image files on the screen and is rate limited', function () {
    Livewire::actingAs($this->admin)->test(MiSuscripcion::class)->call('abrir')
        ->set('archivo', UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'))
        ->assertHasErrors('archivo')->assertSet('archivo', null);

    RateLimiter::increment('comprobante-saas:'.$this->empresa->id, 3600, 10);
    Livewire::actingAs($this->admin)->test(MiSuscripcion::class)->call('abrir')
        ->set('archivo', imagenPng('pago.png'))->call('enviar')->assertHasErrors('archivo');
    expect(PagoSaas::query()->count())->toBe(0);
});

test('the subscription screen shows the design of its real state, without a state selector', function () {
    // Al día: las opciones de pago se despliegan a pedido.
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'America/La_Paz'));
    Livewire::actingAs($this->adminOtra)->test(MiSuscripcion::class)
        ->assertSee('data-status="vigente"', false)->assertSee('Todo está en orden')->assertSee('26 días restantes')
        ->assertSee('Ver opciones de pago')->assertDontSee('Enviar comprobante para revisión')
        ->call('abrir')->assertSee('Enviar comprobante para revisión')->assertSee('Bs 199,00');

    // Vencida hace 8 días: el acceso sigue habilitado y el pago se muestra directamente.
    Livewire::actingAs($this->admin)->test(MiSuscripcion::class)
        ->assertSee('data-status="vencida"', false)->assertSee('Tienes un pago pendiente')->assertSee('8 días de atraso')
        ->assertSee('Venció el')->assertSee('Habilitado')->assertSee('Enviar comprobante para revisión');
});
