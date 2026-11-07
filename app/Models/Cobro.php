<?php

namespace App\Models;

use App\Enums\MetodoPago;
use App\Models\Concerns\Anulable;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Dinero recibido de un cliente después de la venta: un único registro con su desglose por
 * medio, aplicado a una o varias ventas, cuotas o cuentas manuales (cobro_aplicaciones).
 * Su entrada de dinero está en movimientos_caja con origen «cobro». Inmutable: el administrador
 * solo puede anularlo (AnularCobro), con una contrapartida en la fecha de la anulación.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $numero
 * @property int|null $cliente_id
 * @property int $sucursal_id
 * @property int $recibido_por
 * @property MetodoPago $metodo
 * @property string $monto
 * @property string $monto_efectivo
 * @property string $monto_electronico
 * @property Carbon $fecha_cobro
 * @property int|null $caja_id
 * @property int|null $turno_caja_id
 * @property bool $externo
 * @property string $modo
 * @property string|null $referencia
 * @property string|null $nota
 * @property string|null $comprobante_path
 * @property string|null $comprobante_mime
 * @property string $clave_idempotencia
 * @property Carbon $created_at
 * @property Carbon|null $anulado_at
 * @property int|null $anulado_por
 * @property string|null $motivo_anulacion
 * @property-read Cliente|null $cliente
 * @property-read Sucursal $sucursal
 * @property-read User $receptor
 */
class Cobro extends Model
{
    use Anulable, BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'cobros';

    protected function casts(): array
    {
        return [
            'metodo' => MetodoPago::class,
            'monto' => 'decimal:2',
            'monto_efectivo' => 'decimal:2',
            'monto_electronico' => 'decimal:2',
            'fecha_cobro' => 'datetime',
            'externo' => 'boolean',
            'anulado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Cobro $cobro): void {
            if (! $cobro->soloSeAnula()) {
                throw new LogicException('Un cobro registrado no se modifica; solo se anula una vez.');
            }
        });
        static::deleting(fn () => throw new LogicException('Un cobro registrado no se elimina.'));
    }

    public function codigo(): string
    {
        return 'COB-'.str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return HasMany<CobroAplicacion, $this>
     */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(CobroAplicacion::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
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
    public function receptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }
}
