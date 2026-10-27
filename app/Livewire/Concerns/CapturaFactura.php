<?php

namespace App\Livewire\Concerns;

use App\Domain\Facturacion\CatalogoEmpresa;
use App\Domain\Facturacion\DatosFactura;
use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Facturacion\Montos\CalculadoraFiscal;
use App\Enums\ModoFacturacion;
use App\Models\Empresa;
use App\Models\SiatCatalogo;
use App\Models\SiatConfiguracion;
use Illuminate\Support\Collection;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Datos fiscales del comprador al confirmar una venta (pedidos y POS). Solo aplica si
 * MoziStudios habilitó SIAT para la empresa; la emisión la decide ConfirmarVenta.
 */
trait CapturaFactura
{
    /** Facturación SIAT (modo manual: el vendedor elige; automático: siempre). */
    public bool $emitirFactura = false;

    /** @var array{tipo_documento: string, numero_documento: string, complemento: string, razon_social: string, metodo_pago: string, tarjeta_inicio: string, tarjeta_fin: string, nit_sin_validar: bool} */
    public array $factura = [
        'tipo_documento' => '', 'numero_documento' => '', 'complemento' => '', 'razon_social' => '',
        'metodo_pago' => '', 'tarjeta_inicio' => '', 'tarjeta_fin' => '', 'nit_sin_validar' => false,
    ];

    /** Configuración fiscal si la empresa factura con SIAT (habilitada por MoziStudios); si no, null. */
    private function configuracionFiscal(): ?SiatConfiguracion
    {
        $empresaId = (int) $this->actor()->empresa_id;

        if (! (bool) Empresa::query()->whereKey($empresaId)->value('siat_habilitado')) {
            return null;
        }

        return DeEmpresa::consulta(SiatConfiguracion::class, $empresaId)->first() ?? new SiatConfiguracion;
    }

    /**
     * Datos fiscales para ConfirmarVenta. Null: sin factura (modo manual sin marcar, o empresa sin SIAT).
     * Sin catálogos sincronizados no se exige el formulario: la factura queda bloqueada con el motivo y la
     * venta se confirma igual.
     */
    private function datosFactura(): ?DatosFactura
    {
        $configuracion = $this->configuracionFiscal();

        if ($configuracion === null || ($configuracion->modo === ModoFacturacion::Manual && ! $this->emitirFactura)) {
            return null;
        }

        $empresaId = (int) $this->actor()->empresa_id;

        if (CatalogoEmpresa::opciones($empresaId, Catalogo::TiposMetodoPago)->isEmpty()
            || CatalogoEmpresa::opciones($empresaId, Catalogo::TiposDocumentoIdentidad)->isEmpty()) {
            return new DatosFactura(DatosFactura::TIPO_NIT, DatosFactura::SIN_DATOS_DOCUMENTO, null,
                DatosFactura::SIN_DATOS_RAZON_SOCIAL, null, codigoExcepcion: 1);
        }

        $this->validate(DatosFactura::reglas('factura.'), [], [
            'factura.tipo_documento' => __('tipo de documento'),
            'factura.numero_documento' => __('número de documento'),
            'factura.complemento' => __('complemento'),
            'factura.razon_social' => __('nombre o razón social'),
            'factura.metodo_pago' => __('método de pago fiscal'),
        ]);

        return DatosFactura::desde($this->factura, 'factura.');
    }

    /**
     * @return array{modo: ModoFacturacion, tiposDocumento: Collection<int, SiatCatalogo>, metodosPago: Collection<int, SiatCatalogo>, vecinos: array{0: string, 1: string}|null}|null
     */
    private function contextoFiscal(string $total): ?array
    {
        $configuracion = $this->configuracionFiscal();

        if ($configuracion === null) {
            return null;
        }

        $empresaId = (int) $this->actor()->empresa_id;

        return [
            'modo' => $configuracion->modo,
            'tiposDocumento' => CatalogoEmpresa::opciones($empresaId, Catalogo::TiposDocumentoIdentidad),
            'metodosPago' => CatalogoEmpresa::opciones($empresaId, Catalogo::TiposMetodoPago),
            'vecinos' => CalculadoraFiscal::totalesVecinos($total),
        ];
    }
}
