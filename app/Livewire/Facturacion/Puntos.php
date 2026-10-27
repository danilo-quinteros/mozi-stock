<?php

namespace App\Livewire\Facturacion;

use App\Domain\Facturacion\Actions\GestionarPuntosVenta;
use App\Domain\Facturacion\Actions\GuardarSucursalSiat;
use App\Domain\Facturacion\CodigosSiat;
use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Facturacion\ErrorFacturacion;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Facturacion\Concerns\EmpresaDeFacturacion;
use App\Models\SiatCatalogo;
use App\Models\SiatCufd;
use App\Models\SiatCuis;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSucursal;
use App\Models\Sucursal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Excepciones\SiatException;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Sucursales de la empresa registradas en el padrón del SIN y sus puntos de venta, con el estado de
 * sus CUIS y CUFD. Cada punto se busca por ID dentro de la empresa autorizada: un ID ajeno da 404.
 */
class Puntos extends Component
{
    use EmpresaDeFacturacion, MuestraAvisos;

    /** Solo el registro en el SIN de la sucursal principal, dentro del popup de la guía de configuración del Inicio. */
    #[Locked]
    public bool $embebido = false;

    #[Locked]
    public ?int $sucursalId = null;

    public string $codigo = '';

    public string $municipio = '';

    public string $telefono = '';

    #[Locked]
    public ?int $registrandoEnId = null;

    public string $tipo = '';

    public string $nombre = '';

    public string $descripcion = '';

    #[Locked]
    public ?int $cerrandoId = null;

    #[Locked]
    public ?string $errorSin = null;

    public function mount(int $empresaId): void
    {
        $this->empresaId = $empresaId;
        $empresa = $this->empresa();

        if ($this->embebido) {
            $principal = DeEmpresa::consulta(Sucursal::class, $empresa->id)->where('activa', true)->orderBy('id')->value('id');

            if ($principal !== null) {
                $this->editarSucursal((int) $principal);
            }
        }
    }

    public function editarSucursal(int $id): void
    {
        $empresa = $this->empresa();
        $sucursal = DeEmpresa::consulta(Sucursal::class, $empresa->id)->findOrFail($id);
        $registro = DeEmpresa::consulta(SiatSucursal::class, $empresa->id)->where('sucursal_id', $sucursal->id)->first();
        $this->resetValidation();
        $this->sucursalId = $sucursal->id;
        $this->codigo = $registro === null ? (DeEmpresa::consulta(SiatSucursal::class, $empresa->id)->exists() ? '' : '0') : (string) $registro->codigo;
        $this->municipio = (string) $registro?->municipio;
        $this->telefono = (string) $registro?->telefono;
    }

    public function guardarSucursal(GuardarSucursalSiat $accion): void
    {
        if ($this->sucursalId === null) {
            return;
        }

        $datos = $this->validate(GuardarSucursalSiat::reglas(), [
            'telefono.regex' => __('Usa solo números, espacios y + ( ) -.'),
        ], ['codigo' => __('código SIN'), 'municipio' => __('municipio'), 'telefono' => __('teléfono')]);
        $sucursal = DeEmpresa::consulta(Sucursal::class, $this->empresa()->id)->findOrFail($this->sucursalId);
        $accion->ejecutar($this->actor(), $sucursal, $datos);
        $this->cerrarSucursal();
        $this->avisar(__('Sucursal guardada para el SIN.'));

        if ($this->embebido) {
            // El popup sigue abierto con los datos guardados; lo cierra la guía.
            $this->editarSucursal($sucursal->id);
        }
    }

    public function cerrarSucursal(): void
    {
        $this->reset('sucursalId', 'codigo', 'municipio', 'telefono');
        $this->resetValidation();
    }

    public function nuevoPunto(int $siatSucursalId): void
    {
        $this->resetValidation();
        $this->registrandoEnId = $this->siatSucursal($siatSucursalId)->id;
        $this->reset('tipo', 'nombre', 'descripcion', 'errorSin');
    }

    public function registrarPunto(GestionarPuntosVenta $accion): void
    {
        if ($this->registrandoEnId === null) {
            return;
        }

        $datos = $this->validate(GestionarPuntosVenta::reglas(), [], [
            'tipo' => __('tipo de punto de venta'), 'nombre' => __('nombre'), 'descripcion' => __('descripción'),
        ]);

        $this->conSin(function () use ($accion, $datos): void {
            $punto = $accion->registrar($this->actor(), $this->siatSucursal((int) $this->registrandoEnId), (int) $datos['tipo'], trim($datos['nombre']), trim($datos['descripcion']));
            $this->cerrarPunto();
            $this->avisar(__('Punto de venta :codigo registrado en el SIN.', ['codigo' => $punto->codigo]));
        });
    }

    public function cerrarPunto(): void
    {
        $this->reset('registrandoEnId', 'tipo', 'nombre', 'descripcion', 'errorSin');
        $this->resetValidation();
    }

    public function importar(int $siatSucursalId, GestionarPuntosVenta $accion): void
    {
        $this->conSin(function () use ($accion, $siatSucursalId): void {
            $nuevos = $accion->importar($this->actor(), $this->siatSucursal($siatSucursalId));
            $this->avisar($nuevos === 0 ? __('No hay puntos de venta nuevos en el SIN.') : __('Se trajeron :cantidad puntos de venta del SIN.', ['cantidad' => $nuevos]));
        });
    }

    public function obtenerCuis(int $puntoId, CodigosSiat $codigos): void
    {
        $this->conSin(function () use ($codigos, $puntoId): void {
            $cuis = $codigos->cuis($this->punto($puntoId));
            $this->avisar(__('CUIS vigente hasta el :fecha.', ['fecha' => $cuis->vence_en->timezone((string) config('mozistock.business_timezone'))->format('d/m/Y')]));
        });
    }

    public function renovarCufd(int $puntoId, CodigosSiat $codigos): void
    {
        $this->conSin(function () use ($codigos, $puntoId): void {
            $cufd = $codigos->renovarCufd($this->punto($puntoId));
            $this->avisar(__('CUFD vigente hasta las :hora.', ['hora' => $cufd->vence_en->timezone((string) config('mozistock.business_timezone'))->format('d/m/Y H:i')]));
        });
    }

    public function confirmarCierre(int $puntoId): void
    {
        $this->cerrandoId = $this->punto($puntoId)->id;
        $this->errorSin = null;
    }

    public function cancelarCierre(): void
    {
        $this->cerrandoId = null;
        $this->errorSin = null;
    }

    public function cerrarEnSin(GestionarPuntosVenta $accion): void
    {
        if ($this->cerrandoId === null) {
            return;
        }

        $this->conSin(function () use ($accion): void {
            $punto = $accion->cerrar($this->actor(), $this->punto((int) $this->cerrandoId));
            $this->cerrandoId = null;
            $this->avisar(__('Punto de venta :codigo cerrado en el SIN.', ['codigo' => $punto->codigo]));
        });
    }

    public function render(): View
    {
        $empresa = $this->empresa();
        $siatSucursales = DeEmpresa::consulta(SiatSucursal::class, $empresa->id)->orderBy('codigo')->get();
        $puntos = DeEmpresa::consulta(SiatPuntoVenta::class, $empresa->id)
            ->whereIn('siat_sucursal_id', $siatSucursales->pluck('id'))
            ->orderBy('codigo')->get();

        return view('livewire.facturacion.puntos', [
            'sucursales' => DeEmpresa::consulta(Sucursal::class, $empresa->id)
                ->where(fn ($q) => $q->where('activa', true)->orWhereIn('id', $siatSucursales->pluck('sucursal_id')))
                ->orderByDesc('activa')->orderBy('nombre')->get(),
            'siatSucursales' => $siatSucursales->keyBy('sucursal_id'),
            'puntos' => $puntos->groupBy('siat_sucursal_id'),
            ...$this->codigosVigentes($puntos->map(fn (SiatPuntoVenta $punto): int => $punto->id)->values()->all()),
            'tiposPunto' => $this->registrandoEnId === null ? collect() : DeEmpresa::consulta(SiatCatalogo::class, $empresa->id)
                ->where('catalogo', Catalogo::TiposPuntoVenta->name)->orderByRaw('CAST(codigo AS UNSIGNED)')->get(['codigo', 'descripcion']),
            'cerrando' => $this->cerrandoId === null ? null : DeEmpresa::consulta(SiatPuntoVenta::class, $empresa->id)->find($this->cerrandoId),
            'editando' => $this->sucursalId === null ? null : DeEmpresa::consulta(Sucursal::class, $empresa->id)->find($this->sucursalId),
            'puedeConfigurar' => $this->puedeConfigurar($empresa),
        ]);
    }

    /**
     * CUIS y CUFD vigentes de todos los puntos en dos consultas (sin N+1), solo del ambiente y
     * sistema actuales. Sin configuración SIAT válida en el servidor no hay códigos que mostrar.
     *
     * @param  array<int, int>  $puntoIds
     * @return array{cuis: array<int, SiatCuis>, cufd: array<int, SiatCufd>}
     */
    private function codigosVigentes(array $puntoIds): array
    {
        try {
            $configuracion = app(ConfiguracionSistema::class);
        } catch (DatosInvalidos) {
            return ['cuis' => [], 'cufd' => []];
        }

        $cuis = DeEmpresa::consulta(SiatCuis::class, $this->empresaId)
            ->whereIn('siat_punto_venta_id', $puntoIds)
            ->where('ambiente', $configuracion->ambiente->value)
            ->where('codigo_sistema', $configuracion->codigoSistema)
            ->where('vence_en', '>', now())
            ->orderBy('vence_en')->get()->keyBy('siat_punto_venta_id');

        $cufd = DeEmpresa::consulta(SiatCufd::class, $this->empresaId)
            ->whereIn('siat_cuis_id', $cuis->pluck('id'))
            ->where('vence_en', '>', now())
            ->orderBy('id')->get()->keyBy('siat_punto_venta_id');

        return ['cuis' => $cuis->all(), 'cufd' => $cufd->all()];
    }

    /**
     * Las respuestas del SIN se muestran en la pantalla; los errores de validación siguen su curso.
     */
    private function conSin(callable $proceso): void
    {
        $this->errorSin = null;

        try {
            $proceso();
        } catch (ErrorFacturacion|SiatException $error) {
            $this->errorSin = $error->getMessage();
        }
    }

    private function siatSucursal(int $id): SiatSucursal
    {
        return DeEmpresa::consulta(SiatSucursal::class, $this->empresa()->id)->findOrFail($id);
    }

    private function punto(int $id): SiatPuntoVenta
    {
        return DeEmpresa::consulta(SiatPuntoVenta::class, $this->empresa()->id)->findOrFail($id);
    }
}
