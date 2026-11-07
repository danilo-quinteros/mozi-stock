<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Parte de un cobro aplicada a una venta (y a una de sus cuotas, si tiene plan) o a una
 * cuenta manual. Los reportes de dinero no suman esta tabla: usan movimientos_caja.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $cobro_id
 * @property int|null $venta_id
 * @property int|null $venta_cuota_id
 * @property int|null $cuenta_cobrar_id
 * @property string $monto
 * @property string $monto_efectivo
 * @property string $monto_electronico
 * @property Carbon $created_at
 * @property-read Cobro $cobro
 * @property-read Venta|null $venta
 * @property-read VentaCuota|null $cuota
 * @property-read CuentaCobrar|null $cuenta
 */
class CobroAplicacion extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'cobro_aplicaciones';

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'monto_efectivo' => 'decimal:2',
            'monto_electronico' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La aplicación de un cobro no se modifica.'));
        static::deleting(fn () => throw new LogicException('La aplicación de un cobro no se elimina.'));
    }

    /**
     * @return BelongsTo<Cobro, $this>
     */
    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class);
    }

    /**
     * @return BelongsTo<Venta, $this>
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /**
     * @return BelongsTo<VentaCuota, $this>
     */
    public function cuota(): BelongsTo
    {
        return $this->belongsTo(VentaCuota::class, 'venta_cuota_id');
    }

    /**
     * @return BelongsTo<CuentaCobrar, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaCobrar::class, 'cuenta_cobrar_id');
    }
}
