<?php

namespace App\Livewire\Ventas;

use App\Domain\Facturacion\Actions\AnularFactura;
use App\Domain\Facturacion\Actions\EmitirFactura;
use App\Domain\Facturacion\CatalogoEmpresa;
use App\Domain\Facturacion\DatosFactura;
use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Facturacion\EmisionFacturas;
use App\Domain\Facturacion\ErrorFacturacion;
use App\Enums\EstadoFactura;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Empresa;
use App\Models\PagoVenta;
use App\Models\SiatConfiguracion;
use App\Models\SiatFactura;
use App\Models\User;
use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use MoziStudios\Siat\Excepciones\SiatException;
use MoziStudios\Siat\Factura\EnlaceQr;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Factura SIAT de una venta, dentro de su detalle. Ver e imprimir: quien puede ver la venta. Emitir,
 * corregir y reintentar: gate facturacion-siat. Anular y revertir: gate configurar-facturacion (nunca
 * durante «Entrar como»). Todas las reglas viven en las acciones del dominio; aquí solo se presentan.
 */
class Factura extends Component
{
    use MuestraAvisos;

    #[Locked]
    public int $ventaId;

    /** «emitir» (venta sin factura vigente) o «corregir» (factura bloqueada, rechazada u observada). */
    #[Locked]
    public ?string $formulario = null;

    #[Locked]
    public ?int $facturaId = null;

    /** @var array{tipo_documento: string, numero_documento: string, complemento: string, razon_social: string, metodo_pago: string, tarjeta_inicio: string, tarjeta_fin: string, nit_sin_validar: bool} */
    public array $factura = [
        'tipo_documento' => '', 'numero_documento' => '', 'complemento' => '', 'razon_social' => '',
        'metodo_pago' => '', 'tarjeta_inicio' => '', 'tarjeta_fin' => '', 'nit_sin_validar' => false,
    ];

    #[Locked]
    public ?int $anulandoId = null;

    public string $motivoAnulacion = '';

    #[Locked]
    public ?int $revirtiendoId = null;

    #[Locked]
    public ?string $errorSin = null;

    public function mount(int $ventaId): void
    {
        $this->ventaId = $ventaId;
        $this->venta();
    }

    public function abrirEmision(): void
    {
        $venta = $this->venta();
        Gate::forUser($this->actor())->authorize('facturacion-siat', $this->empresa($venta));
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $venta->empresa_id)->first();
        $inicial = DeEmpresa::consulta(PagoVenta::class, $venta->empresa_id)->where('venta_id', $venta->id)->where('tipo', 'inicial')->first();
        $metodo = DatosFactura::metodoSugerido($venta->empresa_id, $inicial?->metodo,
            Decimal::comparar((string) $venta->saldo_pendiente, '0', 2) > 0, $configuracion?->metodo_pago_electronico);

        $this->abrirFormulario('emitir', null, DatosFactura::sugeridos($venta->cliente, $metodo));
    }

    public function abrirCorreccion(int $id): void
    {
        Gate::forUser($this->actor())->authorize('facturacion-siat', $this->empresa());
        $factura = $this->facturaDeLaVenta($id);

        $this->abrirFormulario('corregir', $factura->id, [
            'tipo_documento' => (string) $factura->tipo_documento,
            'numero_documento' => $factura->numero_documento,
            'complemento' => (string) $factura->complemento,
            'razon_social' => $factura->razon_social,
            'metodo_pago' => (string) $factura->metodo_pago,
            'tarjeta_inicio' => $factura->numero_tarjeta === null ? '' : substr($factura->numero_tarjeta, 0, 4),
            'tarjeta_fin' => $factura->numero_tarjeta === null ? '' : substr($factura->numero_tarjeta, -4),
            'nit_sin_validar' => $factura->codigo_excepcion === 1 && $factura->numero_documento !== DatosFactura::SIN_DATOS_DOCUMENTO,
        ]);
    }

    public function guardarFormulario(): void
    {
        if ($this->formulario === null) {
            return;
        }

        $this->validate(DatosFactura::reglas('factura.'), [], [
            'factura.tipo_documento' => __('tipo de documento'),
            'factura.numero_documento' => __('número de documento'),
            'factura.complemento' => __('complemento'),
            'factura.razon_social' => __('nombre o razón social'),
            'factura.metodo_pago' => __('método de pago fiscal'),
        ]);
        $datos = DatosFactura::desde($this->factura, 'factura.');
        $accion = app(EmitirFactura::class);

        $this->conSin(function () use ($accion, $datos): void {
            if ($this->formulario === 'emitir') {
                $factura = $accion->emitir($this->actor(), $this->venta(), $datos);
            } else {
                $factura = $accion->reintentar($this->actor(), $this->facturaDeLaVenta((int) $this->facturaId), $datos);
            }

            if ($factura->estado === EstadoFactura::Pendiente) {
                app(EmisionFacturas::class)->procesar($factura->empresa_id, $factura->id);
                $factura->refresh();
            }

            $this->cerrar();
            $this->avisar(match ($factura->estado) {
                EstadoFactura::Validada => __('Factura validada por el SIN.'),
                EstadoFactura::Bloqueada => __('La factura quedó bloqueada: revisa el motivo.'),
                EstadoFactura::Rechazada => __('El SIN rechazó la factura: revisa el motivo.'),
                default => __('Factura enviada al SIN.'),
            });
        });
    }

    /** Sin cambiar datos: pendiente → consulta o reenvía; bloqueada/rechazada/observada → recalcula. */
    public function reintentar(int $id): void
    {
        $factura = $this->facturaDeLaVenta($id);

        $this->conSin(function () use ($factura): void {
            $factura = app(EmitirFactura::class)->reintentar($this->actor(), $factura);

            if ($factura->estado === EstadoFactura::Pendiente) {
                app(EmisionFacturas::class)->procesar($factura->empresa_id, $factura->id);
                $factura->refresh();
            }

            $this->avisar(match ($factura->estado) {
                EstadoFactura::Validada => __('Factura validada por el SIN.'),
                EstadoFactura::Bloqueada => __('La factura sigue bloqueada: revisa el motivo.'),
                EstadoFactura::Rechazada => __('El SIN rechazó la factura: revisa el motivo.'),
                EstadoFactura::Observada => __('Factura observada por el SIN.'),
                default => __('Factura en proceso en el SIN.'),
            });
        });
    }

    public function abrirAnulacion(int $id): void
    {
        Gate::forUser($this->actor())->authorize('configurar-facturacion', $this->empresa());
        $this->cerrar();
        $this->anulandoId = $this->facturaDeLaVenta($id)->id;
    }

    public function anular(): void
    {
        if ($this->anulandoId === null) {
            return;
        }

        $this->validate(['motivoAnulacion' => ['required', 'integer', 'min:1']], [], ['motivoAnulacion' => __('motivo de anulación')]);
        $factura = $this->facturaDeLaVenta($this->anulandoId);

        $this->conSin(function () use ($factura): void {
            app(AnularFactura::class)->anular($this->actor(), $factura, (int) $this->motivoAnulacion);
            $this->cerrar();
            $this->avisar(__('Factura anulada en el SIN. Avisa al comprador; la venta, la caja y el stock no cambiaron.'));
        });
    }

    public function abrirReversion(int $id): void
    {
        Gate::forUser($this->actor())->authorize('configurar-facturacion', $this->empresa());
        $this->cerrar();
        $this->revirtiendoId = $this->facturaDeLaVenta($id)->id;
    }

    public function revertir(): void
    {
        if ($this->revirtiendoId === null) {
            return;
        }

        $factura = $this->facturaDeLaVenta($this->revirtiendoId);

        $this->conSin(function () use ($factura): void {
            app(AnularFactura::class)->revertir($this->actor(), $factura);
            $this->cerrar();
            $this->avisar(__('Anulación revertida: la factura vuelve a estar validada.'));
        });
    }

    public function cerrar(): void
    {
        $this->reset('formulario', 'facturaId', 'anulandoId', 'motivoAnulacion', 'revirtiendoId', 'errorSin');
        $this->resetValidation();
    }

    public function render(): View
    {
        $venta = $this->venta();
        $empresa = $this->empresa($venta);
        $actor = $this->actor();
        $facturas = SiatFactura::query()->where('venta_id', $venta->id)->orderByDesc('id')->get();
        $vigente = $facturas->firstWhere('venta_vigente', $venta->id);
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresa->id)->first();
        $modalAbierto = $this->formulario !== null || $this->anulandoId !== null;

        return view('livewire.ventas.factura', [
            'venta' => $venta,
            'habilitada' => $empresa->siat_habilitado,
            'vigente' => $vigente,
            'anteriores' => $facturas->reject(fn (SiatFactura $f) => $f->is($vigente))->values(),
            'puedeEmitir' => Gate::forUser($actor)->allows('facturacion-siat', $empresa),
            'puedeAnular' => Gate::forUser($actor)->allows('configurar-facturacion', $empresa),
            'enlace' => fn (SiatFactura $f): ?string => $f->cuf === null || $f->numero === null || $configuracion?->nit === null ? null
                : EnlaceQr::url((string) config('siat.url_qr'), $configuracion->nit, $f->cuf, $f->numero),
            'tiposDocumento' => $this->formulario === null ? collect() : CatalogoEmpresa::opciones($empresa->id, Catalogo::TiposDocumentoIdentidad),
            'metodosPago' => $this->formulario === null ? collect() : CatalogoEmpresa::opciones($empresa->id, Catalogo::TiposMetodoPago),
            'motivosAnulacion' => $modalAbierto && $this->anulandoId !== null ? CatalogoEmpresa::opciones($empresa->id, Catalogo::MotivosAnulacion) : collect(),
            'metodoPago' => fn (?int $codigo): ?string => CatalogoEmpresa::descripcion($empresa->id, Catalogo::TiposMetodoPago, $codigo),
        ]);
    }

    /**
     * @param  array{tipo_documento: string, numero_documento: string, complemento: string, razon_social: string, metodo_pago: string, tarjeta_inicio: string, tarjeta_fin: string, nit_sin_validar: bool}  $valores
     */
    private function abrirFormulario(string $formulario, ?int $facturaId, array $valores): void
    {
        $this->cerrar();
        $this->formulario = $formulario;
        $this->facturaId = $facturaId;
        $this->factura = $valores;
    }

    private function conSin(callable $proceso): void
    {
        $this->errorSin = null;

        try {
            $proceso();
        } catch (ErrorFacturacion|SiatException $error) {
            $this->errorSin = $error->getMessage();
        }
    }

    private function venta(): Venta
    {
        $venta = Venta::query()->with('cliente')->findOrFail($this->ventaId);
        Gate::forUser($this->actor())->authorize('view', $venta);

        return $venta;
    }

    private function facturaDeLaVenta(int $id): SiatFactura
    {
        return SiatFactura::query()->where('venta_id', $this->ventaId)->findOrFail($id);
    }

    private function empresa(?Venta $venta = null): Empresa
    {
        return Empresa::query()->findOrFail(($venta ?? $this->venta())->empresa_id);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
