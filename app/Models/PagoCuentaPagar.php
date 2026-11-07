<?php

namespace App\Models;

use App\Enums\MetodoPago;
use App\Models\Concerns\Anulable;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Pago posterior de una sola cuenta por pagar. No crea egresos ni compras: su salida de
 * dinero está en movimientos_caja con origen «pago_cxp». Inmutable: el administrador solo puede
 * anularlo (AnularPagoCuenta), con una contrapartida en la fecha de la anulación.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $cuenta_pagar_id
 * @property int $sucursal_id
 * @property int $pagado_por
 * @property MetodoPago $metodo
 * @property string $monto
 * @property string $monto_efectivo
 * @property string $monto_electronico
 * @property Carbon $fecha_pago
 * @property int|null $caja_id
 * @property int|null $turno_caja_id
 * @property bool $externo
 * @property string|null $referencia
 * @property string|null $nota
 * @property string|null $comprobante_path
 * @property string|null $comprobante_mime
 * @property string $clave_idempotencia
 * @property Carbon $created_at
 * @property Carbon|null $anulado_at
 * @property int|null $anulado_por
 * @property string|null $motivo_anulacion
 * @property-read CuentaPagar $cuenta
 * @property-read Sucursal $sucursal
 * @property-read User $pagador
 */
class PagoCuentaPagar extends Model
{
    use Anulable, BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'pagos_cuenta_pagar';

    protected function casts(): array
    {
        return [
            'metodo' => MetodoPago::class,
            'monto' => 'decimal:2',
            'monto_efectivo' => 'decimal:2',
            'monto_electronico' => 'decimal:2',
            'fecha_pago' => 'datetime',
            'externo' => 'boolean',
            'anulado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PagoCuentaPagar $pago): void {
            if (! $pago->soloSeAnula()) {
                throw new LogicException('Un pago registrado no se modifica; solo se anula una vez.');
            }
        });
        static::deleting(fn () => throw new LogicException('Un pago registrado no se elimina.'));
    }

    /**
     * @return BelongsTo<CuentaPagar, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaPagar::class, 'cuenta_pagar_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pagador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pagado_por');
    }
}
