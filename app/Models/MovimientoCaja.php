<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Libro monetario único: una fila por medio de pago y evento de origen. Todo total de
 * cobros, caja o arqueo se calcula solo desde aquí.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $sucursal_id
 * @property string $sentido
 * @property string $metodo
 * @property string $monto
 * @property string $origen_tipo
 * @property int $origen_id
 * @property Carbon $fecha_operacion
 * @property int $usuario_id
 * @property Carbon $created_at
 */
class MovimientoCaja extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'movimientos_caja';

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_operacion' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El libro de caja no se modifica.'));
        static::deleting(fn () => throw new LogicException('El libro de caja no se elimina.'));
    }
}
