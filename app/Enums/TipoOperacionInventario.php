<?php

namespace App\Enums;

enum TipoOperacionInventario: string
{
    case Entrada = 'entrada';
    case Salida = 'salida';
    case Ajuste = 'ajuste';
    case Traspaso = 'traspaso';
    // Salida automática al confirmar una venta: comparte el correlativo V con la venta.
    case Venta = 'venta';
    // Entrada automática de una compra pagada: comparte el correlativo COM con la compra.
    case Compra = 'compra';
    // Reingreso de productos devueltos de una venta: comparte el correlativo DEV.
    case Devolucion = 'devolucion';
    // Órdenes de producción (ESPECIFICACION_PRODUCCION.md § 6.4): consumo de materias primas al aprobar, cierre al
    // finalizar y devolución al anular una orden aprobada. Serie PRD.
    case Produccion = 'produccion';
    // Envasado (ESPECIFICACION_PRODUCCION.md § 7): el granel sale de un lote, los envases por FEFO y las presentaciones
    // entran con el lote del granel. Serie ENV; su número es también el del envasado.
    case Envasado = 'envasado';

    /**
     * Serie de los ajustes que solo mueven saldos por lote sin cambiar el total (regularización, inicio y fin del
     * control por lote): no aparecen en el historial de existencias, así que no dejan huecos en la serie AJU.
     */
    public const SERIE_LOTES = 'LOT';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Entrada => __('Entrada'),
            self::Salida => __('Salida'),
            self::Ajuste => __('Ajuste'),
            self::Traspaso => __('Traspaso'),
            self::Venta => __('Venta'),
            self::Compra => __('Compra'),
            self::Devolucion => __('Devolución'),
            self::Produccion => __('Producción'),
            self::Envasado => __('Envasado'),
        };
    }

    public function serie(): string
    {
        return match ($this) {
            self::Traspaso => 'TRA',
            self::Venta => 'V',
            self::Compra => 'COM',
            self::Devolucion => 'DEV',
            self::Produccion => 'PRD',
            self::Envasado => 'ENV',
            default => 'AJU',
        };
    }

    /**
     * Tipos que un usuario registra manualmente desde Ajustes y traspasos.
     *
     * @return list<self>
     */
    public static function manuales(): array
    {
        return [self::Entrada, self::Salida, self::Ajuste, self::Traspaso];
    }

    /**
     * Motivos contextuales de V3. El ajuste no usa motivo: se explica con dirección y nota.
     *
     * @return array<string, string>
     */
    public function motivos(): array
    {
        return match ($this) {
            self::Entrada => [
                'devolucion' => __('Devolución'),
                'reposicion_manual' => __('Reposición manual'),
                'compra_externa' => __('Compra externa (solo stock)'),
                // También la usa la importación de productos al cargar el stock del archivo.
                'inventario_inicial' => __('Inventario inicial'),
            ],
            self::Salida => [
                'merma' => __('Merma'),
                'producto_danado' => __('Producto dañado'),
                'devolucion_proveedor' => __('Devolución a proveedor'),
                // Lotes y vencimientos § 5.4: la baja de lo vencido; en automático sale primero lo vencido.
                'vencido' => __('Vencido'),
            ],
            // Los pone el sistema según el paso de la orden; no se eligen en Ajustes (no es un tipo manual).
            self::Produccion => [
                'consumo' => __('Consumo de orden'),
                'cierre' => __('Cierre de orden'),
                'anulacion' => __('Anulación de orden'),
            ],
            default => [],
        };
    }

    /**
     * Motivos que pone el sistema en operaciones de ajuste que solo mueven saldos por lote (lotes § 9 y rubros
     * § 5.2): no se eligen en la pantalla.
     *
     * @return array<string, string>
     */
    public static function motivosLotes(): array
    {
        return [
            'regularizacion_lote' => __('Regularización de lote'),
            'inicio_control_lotes' => __('Inicio del control por lote'),
            'fin_control_lotes' => __('Fin del control por lote'),
        ];
    }

    public static function etiquetaMotivo(?string $motivo): ?string
    {
        foreach (self::cases() as $tipo) {
            if (isset($tipo->motivos()[$motivo ?? ''])) {
                return $tipo->motivos()[$motivo];
            }
        }

        return self::motivosLotes()[$motivo ?? ''] ?? null;
    }
}
