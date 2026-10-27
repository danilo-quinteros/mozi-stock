<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Saldo materializado por producto y sucursal. Solo lo cambian las acciones de
 * inventario, junto con su movimiento en movimientos_stock.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $sucursal_id
 * @property int $producto_id
 * @property string $cantidad
 * @property string|null $stock_minimo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StockNivel extends Model
{
    use BelongsToEmpresa;

    protected $table = 'stock_niveles';

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'stock_minimo' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
