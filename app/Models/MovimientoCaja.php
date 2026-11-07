<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Libro monetario único: una fila por medio de pago y evento de origen. Todo total de
 * cobros, caja o arqueo se calcula solo desde aquí.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $sucursal_id
 * @property int|null $caja_id
 * @property int|null $turno_caja_id
 * @property string $sentido
 * @property string $metodo
 * @property string $monto
 * @property string $origen_tipo
 * @property int $origen_id
 * @property Carbon $fecha_operacion
 * @property int $usuario_id
 * @property Carbon $created_at
 * @property-read Caja|null $caja
 * @property-read TurnoCaja|null $turno
 */
class MovimientoCaja extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'movimientos_caja';

    /**
     * @return BelongsTo<Caja, $this>
     */
    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<TurnoCaja, $this>
     */
    public function turno(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id')->withoutGlobalScopes();
    }

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
