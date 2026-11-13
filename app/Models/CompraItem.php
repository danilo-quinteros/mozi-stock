<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Línea de compra con snapshot del producto y su costo pagado.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $compra_id
 * @property int $producto_id
 * @property int|null $lote_id Solo en productos con vencimiento (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 6.5).
 * @property int $orden
 * @property string $descripcion
 * @property string $sku
 * @property string $unidad
 * @property string $cantidad
 * @property string $costo_unitario
 * @property string $subtotal
 * @property Carbon $created_at
 * @property-read Lote|null $lote
 */
class CompraItem extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'compra_items';

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'costo_unitario' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El detalle de una compra no se modifica.'));
        static::deleting(fn () => throw new LogicException('El detalle de una compra no se elimina.'));
    }

    /**
     * @return BelongsTo<Lote, $this>
     */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }
}
