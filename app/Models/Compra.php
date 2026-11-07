<?php

namespace App\Models;

use App\Enums\MetodoPago;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Compra: ingresó stock en su sucursal y generó una sola salida de caja por lo pagado al
 * confirmar. Si quedó saldo (crédito o parcial), su deuda vive en CuentaPagar.
 * Inmutable; comparte el correlativo COM con su operación de inventario.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $numero
 * @property int $sucursal_id
 * @property int|null $caja_id
 * @property int|null $turno_caja_id
 * @property int|null $proveedor_id
 * @property int $usuario_id
 * @property int $operacion_inventario_id
 * @property Carbon $fecha
 * @property string|null $referencia
 * @property string $total
 * @property string $condicion
 * @property string $pagado
 * @property Carbon|null $vencimiento
 * @property MetodoPago|null $metodo
 * @property string $monto_efectivo
 * @property string $monto_electronico
 * @property bool $pago_externo
 * @property string $clave_idempotencia
 * @property Carbon $created_at
 * @property-read Sucursal $sucursal
 * @property-read Proveedor|null $proveedor
 * @property-read User $usuario
 * @property-read OperacionInventario $operacion
 * @property-read CuentaPagar|null $cuentaPagar
 */
class Compra extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'compras';

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'total' => 'decimal:2',
            'pagado' => 'decimal:2',
            'vencimiento' => 'date',
            'metodo' => MetodoPago::class,
            'pago_externo' => 'boolean',
            'monto_efectivo' => 'decimal:2',
            'monto_electronico' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Una compra registrada no se modifica.'));
        static::deleting(fn () => throw new LogicException('Una compra registrada no se elimina.'));
    }

    public function codigo(): string
    {
        return 'COM-'.str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return HasMany<CompraItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CompraItem::class)->orderBy('orden');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return BelongsTo<OperacionInventario, $this>
     */
    public function operacion(): BelongsTo
    {
        return $this->belongsTo(OperacionInventario::class, 'operacion_inventario_id');
    }

    /**
     * @return HasOne<CuentaPagar, $this>
     */
    public function cuentaPagar(): HasOne
    {
        return $this->hasOne(CuentaPagar::class);
    }
}
