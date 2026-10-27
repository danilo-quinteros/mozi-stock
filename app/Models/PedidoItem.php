<?php

namespace App\Models;

use App\Enums\TipoPrecio;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $empresa_id
 * @property int $pedido_id
 * @property int $producto_id
 * @property int $orden
 * @property string $cantidad
 * @property TipoPrecio $tipo_precio
 * @property string $precio_unitario
 * @property string $descuento_tipo
 * @property string $descuento_valor
 * @property string $descuento
 * @property string $subtotal
 * @property string $total
 * @property-read Producto $producto
 */
class PedidoItem extends Model
{
    use BelongsToEmpresa;

    protected $table = 'pedido_items';

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

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
