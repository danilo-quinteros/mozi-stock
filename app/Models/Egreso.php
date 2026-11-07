<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Gasto operativo (sueldos, alquiler, servicios...) reconocido por su monto total. Lo pagado
 * al registrarlo sale de caja una sola vez; si queda saldo (parcial o pendiente), la deuda vive
 * en CuentaPagar. Las compras de stock nunca se registran como egreso. Inmutable.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $numero
 * @property int $sucursal_id
 * @property int|null $caja_id
 * @property int|null $turno_caja_id
 * @property int $categoria_egreso_id
 * @property int|null $proveedor_id
 * @property int $usuario_id
 * @property string $descripcion
 * @property string|null $metodo
 * @property string $monto
 * @property string $condicion
 * @property string $pagado
 * @property string $monto_efectivo
 * @property string $monto_electronico
 * @property Carbon|null $vencimiento
 * @property bool $pago_externo
 * @property Carbon $fecha
 * @property string $clave_idempotencia
 * @property Carbon $created_at
 * @property-read Sucursal $sucursal
 * @property-read CategoriaEgreso $categoria
 * @property-read User $usuario
 * @property-read Proveedor|null $proveedor
 * @property-read CuentaPagar|null $cuentaPagar
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
            'pagado' => 'decimal:2',
            'monto_efectivo' => 'decimal:2',
            'monto_electronico' => 'decimal:2',
            'vencimiento' => 'date',
            'pago_externo' => 'boolean',
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

    /**
     * Beneficiario del gasto; obligatorio cuando queda deuda.
     *
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * @return HasOne<CuentaPagar, $this>
     */
    public function cuentaPagar(): HasOne
    {
        return $this->hasOne(CuentaPagar::class);
    }
}
