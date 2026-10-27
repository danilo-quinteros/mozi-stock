<?php

namespace App\Models;

use App\Enums\MetodoPago;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Devolución de una venta. importe = deuda_reducida + reembolso: primero baja el saldo del
 * crédito y solo el resto sale de caja, en la fecha real del reembolso. Inmutable.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $numero
 * @property int $venta_id
 * @property int $sucursal_id
 * @property int $usuario_id
 * @property int|null $operacion_inventario_id
 * @property Carbon $fecha
 * @property string $motivo
 * @property string $importe
 * @property string $deuda_reducida
 * @property string $reembolso
 * @property MetodoPago|null $reembolso_metodo
 * @property string $reembolso_efectivo
 * @property string $reembolso_electronico
 * @property string $clave_idempotencia
 * @property Carbon $created_at
 * @property-read Venta $venta
 * @property-read User $usuario
 */
class Devolucion extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'devoluciones';

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'importe' => 'decimal:2',
            'deuda_reducida' => 'decimal:2',
            'reembolso' => 'decimal:2',
            'reembolso_metodo' => MetodoPago::class,
            'reembolso_efectivo' => 'decimal:2',
            'reembolso_electronico' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Una devolución registrada no se modifica.'));
        static::deleting(fn () => throw new LogicException('Una devolución registrada no se elimina.'));
    }

    public function codigo(): string
    {
        return 'DEV-'.str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return HasMany<DevolucionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DevolucionItem::class);
    }

    /**
     * @return BelongsTo<Venta, $this>
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
