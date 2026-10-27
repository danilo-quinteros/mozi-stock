<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Gasto operativo pagado (sueldos, alquiler, servicios...). Su salida de caja se registra
 * una sola vez; las compras de stock nunca se registran como egreso. Inmutable.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $numero
 * @property int $sucursal_id
 * @property int $categoria_egreso_id
 * @property int $usuario_id
 * @property string $descripcion
 * @property string $metodo
 * @property string $monto
 * @property Carbon $fecha
 * @property string $clave_idempotencia
 * @property Carbon $created_at
 * @property-read Sucursal $sucursal
 * @property-read CategoriaEgreso $categoria
 * @property-read User $usuario
 */
class Egreso extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'egresos';

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un egreso registrado no se modifica.'));
        static::deleting(fn () => throw new LogicException('Un egreso registrado no se elimina.'));
    }

    public function codigo(): string
    {
        return 'EGR-'.str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<CategoriaEgreso, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaEgreso::class, 'categoria_egreso_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
