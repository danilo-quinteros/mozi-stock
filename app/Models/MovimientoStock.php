<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Línea del libro histórico de stock. Se inserta en lote dentro de la transacción
 * de la operación y nunca se modifica ni elimina.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $operacion_id
 * @property int $sucursal_id
 * @property int $producto_id
 * @property string $cantidad
 * @property string $saldo_anterior
 * @property string $saldo_posterior
 * @property Carbon $created_at
 */
class MovimientoStock extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'movimientos_stock';

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'saldo_anterior' => 'decimal:3',
            'saldo_posterior' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de stock no puede modificarse.'));
        static::deleting(fn () => throw new LogicException('El historial de stock no puede eliminarse.'));
    }
}
