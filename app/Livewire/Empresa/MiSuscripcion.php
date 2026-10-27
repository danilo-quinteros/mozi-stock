<?php

namespace App\Livewire\Empresa;

use App\Domain\Suscripciones\Actions\CambiarPlanSuscripcion;
use App\Domain\Suscripciones\Actions\EnviarComprobantePago;
use App\Domain\Suscripciones\CalculadoraProrrateo;
use App\Domain\Suscripciones\CalendarioSuscripcion;
use App\Enums\EstadoPagoSaas;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\AjusteGlobal;
use App\Models\AvisoSuscripcion;
use App\Models\PagoSaas;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Mi suscripción (suscripcion.html V4): plan, próximo vencimiento, QR de MoziStudios y
 * envío del comprobante en la misma pantalla.
 * Permite cambiar de plan con prorrateo:
 * - Upgrade: paga la diferencia proporcional por los días restantes. En la próxima
 *   renovación pagará el precio completo. Vencimiento original se conserva.
 * - Downgrade: conserva los beneficios actuales hasta el vencimiento y aplica el precio
 *   reducido en la siguiente renovación. Vencimiento original se conserva.
 */
#[Title('Mi suscripción')]
class MiSuscripcion extends Component
{
    use MuestraAvisos, WithFileUploads, WithPagination;

    private const REGLAS_ARCHIVO = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.EnviarComprobantePago::MAX_KB];

    // Claves de traducción: se pasan por __() al validar.
    private const MENSAJES = [
        'archivo.required' => 'Adjunta la imagen o el PDF del comprobante.',
        'archivo.mimes' => 'Adjunta una imagen JPG, PNG o WebP, o un PDF.',
        'archivo.max' => 'El comprobante no puede superar 5 MB.',
    ];

    /** Al día, las opciones de pago se despliegan a pedido; por vencer o vencida, siempre. */
    public bool $formulario = false;

    // Modal para cambiar o actualizar de plan
    public bool $modalPlanesAbierto = false;

    public ?int $planSeleccionadoId = null;

    #[Locked]
    public string $clave = '';

    public string $meses = '1';

    public string $nota = '';

    /** @var TemporaryUploadedFile|null */
    public $archivo = null;

    public function mount(): void
    {
        $this->authorize('create', PagoSaas::class);
        $this->clave = (string) Str::uuid();
    }

    public function abrir(): void
    {
        $this->authorize('create', PagoSaas::class);
        $this->resetValidation();
        $this->reset('meses', 'nota', 'archivo');
        $this->clave = (string) Str::uuid();
        $this->formulario = true;
    }

    public function abrirModalPlanes(): void
    {
        $this->authorize('cambiarPlan', Suscripcion::class);
        $this->resetValidation();
        $this->planSeleccionadoId = null;
        $this->modalPlanesAbierto = true;
    }

    public function cerrarModalPlanes(): void
    {
        $this->modalPlanesAbierto = false;
        $this->planSeleccionadoId = null;
    }

    public function seleccionarPlan(int $planId): void
    {
        $this->authorize('cambiarPlan', Suscripcion::class);
        $this->planSeleccionadoId = $planId;
    }

    public function confirmarCambioPlan(CambiarPlanSuscripcion $accion): void
    {
        $this->authorize('cambiarPlan', Suscripcion::class);

        if ($this->planSeleccionadoId === null) {
            return;
        }

        $cambio = $accion->ejecutar($this->actor(), $this->planSeleccionadoId);
        $this->cerrarModalPlanes();

        if ($cambio->tipo === 'upgrade') {
            $this->avisar(__('Plan actualizado con éxito. Monto proporcional por los :dias días restantes: :monto. En tu próxima renovación pagarás el precio completo (:precio).', [
                'dias' => $cambio->dias_restantes,
                'monto' => Decimal::bs((string) $cambio->monto_prorrateado),
                'precio' => Decimal::bs((string) $cambio->precio_nuevo_completo),
            ]));
        } else {
            $this->avisar(__('Cambio de plan programado. Conservas los beneficios actuales hasta el :vencimiento. En tu próxima renovación pagarás el precio reducido de :precio.', [
                'vencimiento' => $cambio->vencimiento_conservado->format('d/m/Y'),
                'precio' => Decimal::bs((string) $cambio->precio_nuevo_completo),
            ]));
        }
    }

    public function cancelarCambioPlan(CambiarPlanSuscripcion $accion): void
    {
        $this->authorize('cambiarPlan', Suscripcion::class);
        $accion->cancelarCambioProgramado($this->actor());
        $this->avisar(__('Cambio de plan programado cancelado. Permaneces en tu plan actual.'));
    }

    public function updatedArchivo(): void
    {
        try {
            $this->validate(['archivo' => self::REGLAS_ARCHIVO], array_map(__(...), self::MENSAJES));
        } catch (ValidationException $e) {
            $this->archivo = null;

            throw $e;
        }
    }

    public function enviar(EnviarComprobantePago $accion): void
    {
        $this->validate([
            'meses' => ['required', 'integer', 'between:1,'.CalendarioSuscripcion::MAX_MESES],
            'nota' => ['nullable', 'string', 'max:300'],
            'archivo' => self::REGLAS_ARCHIVO,
        ], array_map(__(...), self::MENSAJES), ['meses' => __('meses'), 'nota' => __('nota')]);

        // Límite de envíos por empresa (subida de archivos, § 6).
        $limite = 'comprobante-saas:'.$this->actor()->empresa_id;

        if (RateLimiter::tooManyAttempts($limite, 10)) {
            throw ValidationException::withMessages(['archivo' => __('Demasiados envíos seguidos. Intenta de nuevo en una hora.')]);
        }

        RateLimiter::hit($limite, 3600);

        /** @var TemporaryUploadedFile $archivo */
        $archivo = $this->archivo;
        $pago = $accion->ejecutar($this->actor(), ['meses' => $this->meses, 'nota' => $this->nota, 'clave' => $this->clave], $archivo);

        $this->formulario = false;
        $this->reset('meses', 'nota', 'archivo');
        $this->clave = (string) Str::uuid();
        $this->resetPage();
        $this->avisar(__('Comprobante :codigo enviado por :importe. Tu vencimiento cambiará cuando MoziStudios lo apruebe.', ['codigo' => $pago->codigo(), 'importe' => Decimal::bs($pago->importe)]));
    }

    public function render(): View
    {
        $suscripcion = Suscripcion::query()->with(['plan', 'planSiguiente'])->first();
        $hoy = CalendarioSuscripcion::hoy();

        if ($suscripcion !== null) {
            $suscripcion->aplicarPlanSiguienteSiVencio($hoy);
        }

        $mesesElegidos = (int) $this->meses >= 1 && (int) $this->meses <= CalendarioSuscripcion::MAX_MESES ? (int) $this->meses : 1;
        $precioRenovacion = $suscripcion ? $suscripcion->precioRenovacion() : '0.00';

        $planSeleccionado = $this->planSeleccionadoId ? Plan::query()->find($this->planSeleccionadoId) : null;
        $calculoProrrateo = ($suscripcion && $planSeleccionado)
            ? CalculadoraProrrateo::calcular($suscripcion, $planSeleccionado, $hoy)
            : null;

        return view('livewire.empresa.mi-suscripcion', [
            'suscripcion' => $suscripcion,
            'hoy' => $hoy,
            'ajustes' => AjusteGlobal::actual(),
            'enRevision' => PagoSaas::query()->where('estado', EstadoPagoSaas::Pendiente)->first(),
            'pagos' => PagoSaas::query()->latest('id')->paginate(12),
            'avisos' => $suscripcion === null ? collect()
                : AvisoSuscripcion::query()->where('vencimiento', $suscripcion->vencimiento->format('Y-m-d'))->get()->keyBy(fn (AvisoSuscripcion $a) => $a->evento->value),
            'mesesElegidos' => $mesesElegidos,
            'precioRenovacion' => $precioRenovacion,
            'vencimientoTras' => $suscripcion?->vencimientoTras($mesesElegidos),
            'planesDisponibles' => Plan::query()->where('activo', true)->orderBy('orden')->get(),
            'planSeleccionado' => $planSeleccionado,
            'calculoProrrateo' => $calculoProrrateo,
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
