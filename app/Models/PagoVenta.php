<?php

namespace App\Models;

use App\Enums\MetodoPago;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Cobro aplicado a una venta (cuota inicial o abono) con su desglose por medio. Los
 * reportes de dinero no suman esta tabla: usan movimientos_caja.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $venta_id
 * @property int $sucursal_id
 * @property int $recibido_por
 * @property string $tipo
 * @property MetodoPago $metodo
 * @property string $monto
 * @property string $monto_efectivo
 * @property string $monto_electronico
 * @property string|null $nota
 * @property Carbon $fecha_cobro
 * @property string $clave_idempotencia
 * @property Carbon $created_at
 * @property-read User $receptor
 * @property-read Sucursal $sucursal
 */
class PagoVenta extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'pagos_venta';

    protected function casts(): array
    {
        return [
            'metodo' => MetodoPago::class,
            'monto' => 'decimal:2',
            'monto_efectivo' => 'decimal:2',
            'monto_electronico' => 'decimal:2',
            'fecha_cobro' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un cobro registrado no se modifica.'));
        static::deleting(fn () => throw new LogicException('Un cobro registrado no se elimina.'));
    }

    /**
     * Sucursal que recibió el dinero (puede ser distinta de la sucursal de la venta).
     *
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }
}
