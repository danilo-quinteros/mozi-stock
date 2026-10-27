<?php

namespace App\Models;

use App\Enums\TipoPrecio;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Línea de venta con snapshot de producto y precio: no cambia si cambia el catálogo.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $venta_id
 * @property int $producto_id
 * @property int $orden
 * @property string $descripcion
 * @property string $sku
 * @property string $unidad
 * @property string $cantidad
 * @property TipoPrecio $tipo_precio
 * @property string $precio_unitario
 * @property string $descuento_tipo
 * @property string $descuento_valor
 * @property string $descuento
 * @property string $subtotal
 * @property string $total
 * @property Carbon $created_at
 */
class VentaItem extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'venta_items';

    protected function casts(): array
    {
        return [
            'tipo_precio' => TipoPrecio::class,
            'cantidad' => 'decimal:3',
            'precio_unitario' => 'decimal:2',
            'descuento_valor' => 'decimal:2',
            'descuento' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El detalle de una venta no se modifica.'));
        static::deleting(fn () => throw new LogicException('El detalle de una venta no se elimina.'));
    }
}
