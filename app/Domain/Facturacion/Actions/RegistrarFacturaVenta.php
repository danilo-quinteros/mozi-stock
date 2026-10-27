<?php

namespace App\Domain\Facturacion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Facturacion\CatalogoEmpresa;
use App\Domain\Facturacion\DatosFactura;
use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Facturacion\Montos\CalculadoraFiscal;
use App\Domain\Facturacion\Montos\LineaVenta;
use App\Enums\EstadoFactura;
use App\Enums\ModoFacturacion;
use App\Jobs\Siat\EnviarFactura;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\PagoVenta;
use App\Models\Producto;
use App\Models\SiatConfiguracion;
use App\Models\SiatFactura;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSucursal;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use LogicException;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Crea la factura de una venta confirmada, dentro de la misma transacción de la venta. No llama
 * al SIN: calcula los montos fiscales y deja la factura «pendiente» para el job EnviarFactura, que
 * se despacha después del commit. Si el total no cuadra o falta configuración, la factura queda
 * «bloqueada» con el motivo y la venta sigue confirmada (no se ajusta el IVA ni se agregan descuentos).
 */
final class RegistrarFacturaVenta
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  DatosFactura|null  $datos  null: sin pedido explícito (modo automático: datos propuestos; manual: no se factura)
     */
    public function registrar(User $actor, Venta $venta, ?DatosFactura $datos): ?SiatFactura
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La factura se registra dentro de la transacción de la venta.');
        }

        $empresa = Empresa::query()->findOrFail($venta->empresa_id);
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $venta->empresa_id)->first();

        if (! $empresa->siat_habilitado || ($datos === null && $configuracion?->modo === ModoFacturacion::Manual)) {
            return null;
        }

        $datos ??= $this->datosPropuestos($venta, $configuracion);

        $factura = new SiatFactura;
        $factura->empresa_id = $venta->empresa_id;
        $factura->venta_id = $venta->id;
        $factura->venta_vigente = $venta->id;
        $factura->tipo_documento = $datos->tipoDocumento;
        $factura->numero_documento = $datos->numeroDocumento;
        $factura->complemento = $datos->complemento;
        $factura->razon_social = $datos->razonSocial;
        $factura->codigo_cliente = $datos->codigoCliente();
        $factura->codigo_excepcion = $datos->codigoExcepcion;
        $factura->metodo_pago = $datos->metodoPago;
        $factura->numero_tarjeta = $datos->numeroTarjeta;
        $factura->total = $venta->total;
        $factura->emitida_por = $actor->id;
        $this->calcular($factura, $venta, $configuracion);
        $factura->save();

        $this->auditor->registrar('siat.factura.registrada', $factura, $actor, $factura->empresa_id, despues: [
            'venta' => $venta->codigo(), 'estado' => $factura->estado->value, 'total' => $factura->total, 'motivo' => $factura->motivo,
        ]);

        if ($factura->estado === EstadoFactura::Pendiente) {
            EnviarFactura::dispatch($factura->empresa_id, $factura->id)->afterCommit();
        }

        return $factura;
    }

    /**
     * Vuelve a calcular montos, punto de venta y configuración de una factura sin enviar (bloqueada,
     * rechazada u observada). Conserva el número fiscal si ya tenía uno y descarta el XML anterior.
     */
    public function recalcular(SiatFactura $factura): void
    {
        $venta = DeEmpresa::consulta(Venta::class, $factura->empresa_id)->findOrFail($factura->venta_id);
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $factura->empresa_id)->first();

        $factura->xml = null;
        $factura->cuf = null;
        $factura->fecha_emision = null;
        $factura->leyenda = null;
        $factura->siat_cufd_id = null;
        $factura->codigo_recepcion = null;
        $factura->codigo_estado = null;
        $factura->mensajes = null;
        $factura->enviada_en = null;
        $this->calcular($factura, $venta, $configuracion);
    }

    /**
     * Montos fiscales y comprobaciones de configuración. Reúne todos los problemas en el motivo para que
     * el administrador los resuelva de una vez.
     */
    private function calcular(SiatFactura $factura, Venta $venta, ?SiatConfiguracion $configuracion): void
    {
        $empresaId = $venta->empresa_id;
        $problemas = [];
        $punto = $this->puntoDeVenta($venta);

        if ($punto === null) {
            $problemas[] = __('La sucursal de la venta no está registrada en el SIN o no tiene un punto de venta activo.');
        } elseif ($factura->siat_punto_venta_id !== null && $factura->siat_punto_venta_id !== $punto->id && $factura->numero !== null) {
            // El número fiscal es del punto original: se conserva ese punto aunque hoy hubiera otro.
            $punto = DeEmpresa::consulta(SiatPuntoVenta::class, $empresaId)->find($factura->siat_punto_venta_id) ?? $punto;
        }

        if ($configuracion?->nit === null || blank($configuracion->razon_social)) {
            $problemas[] = __('Faltan el NIT o la razón social.');
        }

        if ($configuracion?->actividad_economica === null || $configuracion->producto_sin === null) {
            $problemas[] = __('Faltan la actividad económica o el producto SIN.');
        } else {
            $problemas = array_merge($problemas, $this->problemasDeCatalogo($factura, $configuracion));
        }

        if (Decimal::comparar((string) $venta->devuelto, '0', 2) > 0) {
            $problemas[] = __('La venta tiene devoluciones: no se puede facturar por el total original.');
        }

        $items = DeEmpresa::consulta(VentaItem::class, $empresaId)->where('venta_id', $venta->id)->orderBy('orden')->get();
        $productoIds = $items->pluck('producto_id')->filter()->unique()->all();
        $productos = DeEmpresa::consulta(Producto::class, $empresaId)->whereIn('id', $productoIds)->with('unidad')->get()->keyBy('id');

        $faltanUnidades = [];
        foreach ($items as $item) {
            $prod = $productos->get($item->producto_id);
            $unidadSin = $prod?->unidad?->unidad_medida_sin ?? $configuracion?->unidad_medida;
            if ($unidadSin === null) {
                $faltanUnidades[] = $item->descripcion;
            } elseif (! CatalogoEmpresa::existe($empresaId, Catalogo::UnidadesMedida, $unidadSin)) {
                $problemas[] = __('No se encontró la unidad de medida SIN (código :codigo) del producto «:nombre» en los catálogos.', ['codigo' => $unidadSin, 'nombre' => $item->descripcion]);
            }
        }
        if ($faltanUnidades !== []) {
            $nombres = implode(', ', array_unique($faltanUnidades));
            $problemas[] = __('Falta la unidad de medida SIN en: :productos.', ['productos' => $nombres]);
        }

        $resultado = CalculadoraFiscal::calcular(
            array_values($items->map(fn (VentaItem $i): LineaVenta => new LineaVenta(Decimal::normalizar((string) $i->cantidad, 3) ?? '0',
                (string) $i->precio_unitario, (string) $i->descuento, (string) $i->total))->all()),
            (string) $venta->descuento_global,
            (string) $venta->total,
        );

        if (! $resultado->cuadra) {
            $problemas[] = (string) $resultado->motivo;
        }

        $factura->siat_punto_venta_id = $punto?->id;
        $factura->estado = $problemas === [] ? EstadoFactura::Pendiente : EstadoFactura::Bloqueada;
        $factura->motivo = $problemas === [] ? null : mb_substr(implode("\n", $problemas), 0, 1000);
        $factura->sub_total_neto = $resultado->cuadra ? $resultado->subTotalNeto : null;
        $factura->descuento_adicional = $resultado->descuentoAdicional;
        $factura->total_precio_neto = $resultado->cuadra ? $resultado->totalPrecioNeto : null;
        $factura->iva = $resultado->cuadra ? $resultado->iva : null;
        $factura->lineas = $resultado->cuadra && $configuracion !== null ? array_map(function ($linea) use ($items, $productos, $configuracion): array {
            /** @var VentaItem $item */
            $item = $items[$linea->indiceVenta];
            $prod = $productos->get($item->producto_id);
            $unidadSin = (int) ($prod?->unidad?->unidad_medida_sin ?? $configuracion->unidad_medida ?? 58);

            return [
                'venta_item_id' => $item->id,
                'codigo_producto' => $item->sku,
                'descripcion' => $item->descripcion,
                'actividad' => (string) $configuracion->actividad_economica,
                'producto_sin' => (int) $configuracion->producto_sin,
                'unidad_medida' => $unidadSin,
                'cantidad' => $linea->cantidad,
                'precio_neto_unitario' => $linea->precioNetoUnitario,
                'monto_descuento' => $linea->montoDescuento,
                'sub_total' => $linea->subTotal,
            ];
        }, $resultado->lineas) : null;
    }

    /**
     * El punto 0 de la sucursal de la venta o, si está cerrado, el activo de código más bajo.
     */
    private function puntoDeVenta(Venta $venta): ?SiatPuntoVenta
    {
        $sucursal = DeEmpresa::consulta(SiatSucursal::class, $venta->empresa_id)->where('sucursal_id', $venta->sucursal_id)->first();

        return $sucursal === null ? null : DeEmpresa::consulta(SiatPuntoVenta::class, $venta->empresa_id)
            ->where('siat_sucursal_id', $sucursal->id)->where('activo', true)->orderBy('codigo')->first();
    }

    /**
     * @return list<string>
     */
    private function problemasDeCatalogo(SiatFactura $factura, SiatConfiguracion $configuracion): array
    {
        $empresaId = $factura->empresa_id;
        $problemas = [];
        $comprobar = [
            [Catalogo::Actividades, $configuracion->actividad_economica, __('la actividad económica')],
            [Catalogo::ProductosServicios, $configuracion->producto_sin, __('el producto SIN')],
            [Catalogo::TiposDocumentoIdentidad, $factura->tipo_documento, __('el tipo de documento')],
        ];

        foreach ($comprobar as [$catalogo, $codigo, $nombre]) {
            if ($codigo !== null && ! CatalogoEmpresa::existe($empresaId, $catalogo, $codigo)) {
                $problemas[] = __('No se encontró :nombre (código :codigo) en los catálogos sincronizados del SIN.', ['nombre' => $nombre, 'codigo' => $codigo]);
            }
        }

        if ($factura->metodo_pago === null) {
            $problemas[] = __('Falta elegir el método de pago fiscal.');
        } elseif (($metodo = CatalogoEmpresa::descripcion($empresaId, Catalogo::TiposMetodoPago, $factura->metodo_pago)) === null) {
            $problemas[] = __('No se encontró el método de pago fiscal (código :codigo) en los catálogos sincronizados del SIN.', ['codigo' => $factura->metodo_pago]);
        } elseif (str_contains(mb_strtoupper($metodo), 'TARJETA') && $factura->numero_tarjeta === null) {
            $problemas[] = __('El método de pago incluye tarjeta: faltan sus primeros y últimos 4 dígitos.');
        }

        return $problemas;
    }

    private function datosPropuestos(Venta $venta, ?SiatConfiguracion $configuracion): DatosFactura
    {
        $cliente = $venta->cliente_id === null ? null : DeEmpresa::consulta(Cliente::class, $venta->empresa_id)->find($venta->cliente_id);
        $inicial = DeEmpresa::consulta(PagoVenta::class, $venta->empresa_id)->where('venta_id', $venta->id)->where('tipo', 'inicial')->first();
        $metodo = DatosFactura::metodoSugerido($venta->empresa_id, $inicial?->metodo,
            Decimal::comparar((string) $venta->saldo_pendiente, '0', 2) > 0, $configuracion?->metodo_pago_electronico);
        $sugeridos = DatosFactura::sugeridos($cliente, $metodo);

        return new DatosFactura((int) $sugeridos['tipo_documento'], $sugeridos['numero_documento'],
            $sugeridos['complemento'] === '' ? null : $sugeridos['complemento'], $sugeridos['razon_social'], $metodo,
            codigoExcepcion: $sugeridos['numero_documento'] === DatosFactura::SIN_DATOS_DOCUMENTO ? 1 : 0);
    }
}
