<?php

namespace App\Models;

use App\Enums\TipoOperacionInventario;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Cabecera inalterable de un ajuste o traspaso. Junto con movimientos_stock es la
 * auditoría del inventario: usuario, sucursal, nota, saldos antes y después.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $serie
 * @property int $numero
 * @property TipoOperacionInventario $tipo
 * @property string|null $motivo
 * @property int $sucursal_id
 * @property int|null $sucursal_destino_id
 * @property string|null $nota
 * @property int $usuario_id
 * @property int $total_lineas
 * @property string $clave_idempotencia
 * @property Carbon $created_at
 * @property-read Sucursal $sucursal
 * @property-read Sucursal|null $sucursalDestino
 */
class OperacionInventario extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'operaciones_inventario';

    protected function casts(): array
    {
        return [
            'tipo' => TipoOperacionInventario::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Una operación de inventario confirmada no se modifica.'));
        static::deleting(fn () => throw new LogicException('Una operación de inventario confirmada no se elimina.'));
    }

    public function codigo(): string
    {
        return $this->serie.'-'.str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return HasMany<MovimientoStock, $this>
     */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoStock::class, 'operacion_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursalDestino(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_destino_id');
    }
}
