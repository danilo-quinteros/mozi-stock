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
        };
    }

    public function serie(): string
    {
        return match ($this) {
            self::Traspaso => 'TRA',
            self::Venta => 'V',
            self::Compra => 'COM',
            self::Devolucion => 'DEV',
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
            ],
            default => [],
        };
    }

    public static function etiquetaMotivo(?string $motivo): ?string
    {
        foreach (self::cases() as $tipo) {
            if (isset($tipo->motivos()[$motivo ?? ''])) {
                return $tipo->motivos()[$motivo];
            }
        }

        return null;
    }
}
