<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Cantidad devuelta de una línea de venta, su importe neto y si volvió al stock.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $devolucion_id
 * @property int $venta_item_id
 * @property int $producto_id
 * @property string $cantidad
 * @property string $importe
 * @property bool $reingresa_stock
 * @property Carbon $created_at
 * @property-read VentaItem $ventaItem
 */
class DevolucionItem extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'devolucion_items';

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'importe' => 'decimal:2',
            'reingresa_stock' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El detalle de una devolución no se modifica.'));
        static::deleting(fn () => throw new LogicException('El detalle de una devolución no se elimina.'));
    }

    /**
     * @return BelongsTo<VentaItem, $this>
     */
    public function ventaItem(): BelongsTo
    {
        return $this->belongsTo(VentaItem::class);
    }
}
