<?php

use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Periodo;
use App\Domain\Suscripciones\Actions\ConfigurarSuscripcion;
use App\Domain\Suscripciones\Actions\EnviarComprobantePago;
use App\Domain\Suscripciones\Actions\RevisarComprobantePago;
use App\Domain\Suscripciones\Consultas\ListadoSuscripciones;
use App\Enums\EstadoPagoSaas;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\PagoSaas;
use App\Models\Scopes\EmpresaScope;
use App\Models\Suscripcion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Comprobantes y renovación (§ 3.12, § 8.4, T25–T27). Decisiones del propietario
 * (23/10/2026): se conserva el día de vencimiento aunque se pague tarde, un comprobante
 * paga de 1 a 12 meses y solo se aprueba el importe exacto (sin pagos parciales).
 */

function planPara(Empresa $empresa, string $vencimiento, string $precio = '149'): Suscripcion
{
    return app(ConfigurarSuscripcion::class)->ejecutar(User::factory()->superadmin()->create(), $empresa, $precio, $vencimiento);
}

function comprobante(User $admin, int $meses = 1, ?string $clave = null, ?UploadedFile $archivo = null): PagoSaas
{
    return app(EnviarComprobantePago::class)->ejecutar($admin,
        ['meses' => $meses, 'nota' => null, 'clave' => $clave ?? (string) Str::uuid()],
        $archivo ?? imagenPng('comprobante.png'));
}

function vencimientoDe(Empresa $empresa): string
{
    return Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresa->id)->sole()->vencimiento->format('Y-m-d');
}

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', 'America/La_Paz'));
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->superadmin = User::factory()->superadmin()->create();
    $this->revisar = app(RevisarComprobantePago::class);
});

test('T25: a pending or rejected receipt never renews; only an approval does, keeping the due day', function () {
    planPara($this->empresa, '2026-10-28');
    $this->actingAs($this->admin);

    $pendiente = comprobante($this->admin);
    expect($pendiente->estado)->toBe(EstadoPagoSaas::Pendiente)
        ->and($pendiente->importe)->toBe('149.00')
        ->and($pendiente->precio_mensual)->toBe('149.00')
        ->and(vencimientoDe($this->empresa))->toBe('2026-10-28');
    Storage::disk('local')->assertExists($pendiente->archivo_path);
    expect($pendiente->archivo_path)->toStartWith("empresas/{$this->empresa->id}/suscripcion/");

    $rechazado = $this->revisar->rechazar($this->superadmin, $pendiente->id, 'La imagen no es legible');
    expect($rechazado->estado)->toBe(EstadoPagoSaas::Rechazado)
        ->and($rechazado->motivo_rechazo)->toBe('La imagen no es legible')
        ->and(vencimientoDe($this->empresa))->toBe('2026-10-28')
        ->and(fn () => $this->revisar->aprobar($this->superadmin, $pendiente->id, true))->toThrow(ValidationException::class);

    $dos = comprobante($this->admin, 2);
    expect($dos->importe)->toBe('298.00');
    $aprobado = $this->revisar->aprobar($this->superadmin, $dos->id, true);

    expect($aprobado->estado)->toBe(EstadoPagoSaas::Aprobado)
        ->and($aprobado->vencimiento_anterior?->format('Y-m-d'))->toBe('2026-10-28')
        ->and($aprobado->vencimiento_nuevo?->format('Y-m-d'))->toBe('2026-12-28')
        ->and($aprobado->revisado_por)->toBe($this->superadmin->id)
        ->and(vencimientoDe($this->empresa))->toBe('2026-12-28')
        ->and(Auditoria::query()->where('empresa_id', $this->empresa->id)->orderBy('id')->pluck('accion')->all())->toBe([
            'suscripcion.configurada', 'suscripcion.comprobante_enviado', 'suscripcion.pago_rechazado',
            'suscripcion.comprobante_enviado', 'suscripcion.pago_aprobado',
        ]);
});

test('paying late keeps the due day and the months still owed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/La_Paz'));
    planPara($this->empresa, '2026-07-28');
    $this->actingAs($this->admin);

    $this->revisar->aprobar($this->superadmin, comprobante($this->admin)->id, true);
    $suscripcion = Suscripcion::query()->sole();
    expect($suscripcion->vencimiento->format('Y-m-d'))->toBe('2026-08-28')
        ->and($suscripcion->estado()->value)->toBe('vencida');

    $this->revisar->aprobar($this->superadmin, comprobante($this->admin, 3)->id, true);
    expect(vencimientoDe($this->empresa))->toBe('2026-11-28');
});

test('a day-31 cycle falls on the last day of short months and returns to 31', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-20 10:00', 'America/La_Paz'));
    planPara($this->empresa, '2026-01-31');
    $this->actingAs($this->admin);

    $this->revisar->aprobar($this->superadmin, comprobante($this->admin)->id, true);
    expect(vencimientoDe($this->empresa))->toBe('2026-02-28');
    $this->revisar->aprobar($this->superadmin, comprobante($this->admin)->id, true);
    expect(vencimientoDe($this->empresa))->toBe('2026-03-31')
        ->and(Suscripcion::query()->sole()->dia_vencimiento)->toBe(31);
});

test('approving requires confirming the exact amount and repeating it renews only once', function () {
    planPara($this->empresa, '2026-10-28');
    $this->actingAs($this->admin);
    $pago = comprobante($this->admin);

    expect(fn () => $this->revisar->aprobar($this->superadmin, $pago->id, false))->toThrow(ValidationException::class)
        ->and(vencimientoDe($this->empresa))->toBe('2026-10-28');

    $this->revisar->aprobar($this->superadmin, $pago->id, true);
    $this->revisar->aprobar($this->superadmin, $pago->id, true);

    expect(vencimientoDe($this->empresa))->toBe('2026-11-28')
        ->and(Auditoria::query()->where('accion', 'suscripcion.pago_aprobado')->count())->toBe(1)
        ->and(fn () => $this->revisar->rechazar($this->superadmin, $pago->id, 'Tarde'))->toThrow(ValidationException::class)
        ->and(fn () => $this->revisar->rechazar($this->superadmin, comprobante($this->admin)->id, '  '))->toThrow(ValidationException::class);
});

test('one receipt under review at a time and a repeated key does not duplicate it', function () {
    planPara($this->empresa, '2026-10-28');
    $this->actingAs($this->admin);
    $clave = (string) Str::uuid();

    $primero = comprobante($this->admin, 1, $clave);
    $repetido = comprobante($this->admin, 1, $clave);

    expect($repetido->id)->toBe($primero->id)
        ->and(PagoSaas::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles("empresas/{$this->empresa->id}/suscripcion"))->toHaveCount(1)
        ->and(fn () => comprobante($this->admin))->toThrow(ValidationException::class)
        ->and(Storage::disk('local')->allFiles("empresas/{$this->empresa->id}/suscripcion"))->toHaveCount(1);

    // La base también lo impide aunque se saltara la aplicación.
    $copia = (array) DB::table('pagos_saas')->where('id', $primero->id)->first(['empresa_id', 'suscripcion_id', 'meses',
        'precio_mensual', 'importe', 'archivo_path', 'archivo_mime', 'archivo_bytes', 'estado', 'enviado_por']);
    expect(fn () => DB::table('pagos_saas')->insert($copia + ['clave_idempotencia' => (string) Str::uuid()]))
        ->toThrow(UniqueConstraintViolationException::class);

    $this->revisar->aprobar($this->superadmin, $primero->id, true);
    expect(comprobante($this->admin)->estado)->toBe(EstadoPagoSaas::Pendiente);
});

test('the price is frozen in each receipt when it is sent', function () {
    planPara($this->empresa, '2026-10-28');
    $this->actingAs($this->admin);
    $pago = comprobante($this->admin, 2);

    $this->actingAs($this->superadmin);
    app(ConfigurarSuscripcion::class)->ejecutar($this->superadmin, $this->empresa, '199,50', '2026-10-28');
    $aprobado = $this->revisar->aprobar($this->superadmin, $pago->id, true);
    expect($aprobado->importe)->toBe('298.00')->and(vencimientoDe($this->empresa))->toBe('2026-12-28');

    $this->actingAs($this->admin);
    expect(comprobante($this->admin, 2)->importe)->toBe('399.00');
});

test('invalid months, files by content, oversize files and missing plans are rejected', function () {
    $this->actingAs($this->admin);
    expect(fn () => comprobante($this->admin))->toThrow(ValidationException::class);

    $this->actingAs($this->superadmin);
    planPara($this->empresa, '2026-10-28');
    $this->actingAs($this->admin);

    foreach ([0, 13] as $meses) {
        expect(fn () => comprobante($this->admin, $meses))->toThrow(ValidationException::class);
    }

    // Texto con nombre .png: el tipo se detecta por el contenido, no por el nombre.
    $ruta = tempnam(sys_get_temp_dir(), 'cp');
    file_put_contents($ruta, '<?php echo "no soy una imagen";');
    $disfrazado = new UploadedFile($ruta, 'comprobante.png', null, null, true);
    expect(fn () => comprobante($this->admin, 1, null, $disfrazado))->toThrow(ValidationException::class)
        ->and(fn () => comprobante($this->admin, 1, null, UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf')))->toThrow(ValidationException::class)
        ->and(fn () => comprobante($this->admin, 1, 'no-es-uuid'))->toThrow(ValidationException::class)
        ->and(PagoSaas::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    expect(comprobante($this->admin, 1, null, UploadedFile::fake()->create('comprobante.pdf', 200, 'application/pdf'))->esPdf())->toBeTrue();
});

test('T27: a SaaS payment never enters the cash or reports of any company', function () {
    planPara($this->empresa, '2026-10-28');
    $this->actingAs($this->admin);
    $pago = comprobante($this->admin, 3);
    $this->revisar->aprobar($this->superadmin, $pago->id, true);

    expect(DB::table('movimientos_caja')->count())->toBe(0)
        ->and(DB::table('pagos_venta')->count())->toBe(0)
        ->and(Cobros::resumen($this->empresa->id, Periodo::hoy())['total'])->toBe('0.00')
        ->and(ListadoSuscripciones::cobrado(Periodo::mesActual()))->toBe('447.00');
});

test('T26: overdue for three days, the company keeps operating until the superadmin deactivates it', function () {
    planPara($this->empresa, '2026-10-20');
    $this->artisan('mozistock:avisos-suscripcion')->assertSuccessful();

    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Tu suscripción venció el 20/10/2026');
    $this->actingAs($this->admin)->get(route('pedidos.index'))->assertOk();

    $rechazado = comprobante($this->admin);
    $this->revisar->rechazar($this->superadmin, $rechazado->id, 'Importe distinto');
    $pendiente = comprobante($this->admin);
    expect($this->empresa->refresh()->activa)->toBeTrue();

    // Aprobar el pago de una empresa desactivada tampoco la reactiva: el acceso es manual.
    $this->empresa->activa = false;
    $this->empresa->save();
    $this->revisar->aprobar($this->superadmin, $pendiente->id, true);
    expect($this->empresa->refresh()->activa)->toBeFalse()
        ->and(vencimientoDe($this->empresa))->toBe('2026-11-20');
});

test('only the superadmin configures plans and reviews; a manual due-date change needs a reason', function () {
    $configurar = app(ConfigurarSuscripcion::class);
    expect(fn () => $configurar->ejecutar($this->admin, $this->empresa, '149', '2026-10-28'))->toThrow(AuthorizationException::class);

    $configurar->ejecutar($this->superadmin, $this->empresa, '149', '2026-10-28');
    expect(fn () => $configurar->ejecutar($this->superadmin, $this->empresa, '149', '2026-11-05'))->toThrow(ValidationException::class)
        ->and(fn () => $configurar->ejecutar($this->superadmin, $this->empresa, '0', '2026-10-28'))->toThrow(ValidationException::class)
        ->and(fn () => $configurar->ejecutar($this->superadmin, $this->empresa, '10.555', '2026-10-28'))->toThrow(ValidationException::class)
        ->and(fn () => $configurar->ejecutar($this->superadmin, $this->empresa, '149', '2026-02-30'))->toThrow(ValidationException::class);

    $corregida = $configurar->ejecutar($this->superadmin, $this->empresa, '149', '2026-11-05', 'Pago recibido en efectivo en oficina');
    expect($corregida->vencimiento->format('Y-m-d'))->toBe('2026-11-05')
        ->and($corregida->dia_vencimiento)->toBe(5);
    $auditoria = Auditoria::query()->where('accion', 'suscripcion.actualizada')->sole();
    expect($auditoria->antes)->toMatchArray(['vencimiento' => '2026-10-28'])
        ->and($auditoria->despues)->toMatchArray(['vencimiento' => '2026-11-05', 'motivo' => 'Pago recibido en efectivo en oficina']);

    $this->actingAs($this->admin);
    $pago = comprobante($this->admin);
    expect(fn () => $this->revisar->aprobar($this->admin, $pago->id, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(EnviarComprobantePago::class)->ejecutar($this->superadmin, ['meses' => 1, 'nota' => null, 'clave' => (string) Str::uuid()], imagenPng('x.png')))
        ->toThrow(AuthorizationException::class);
});
