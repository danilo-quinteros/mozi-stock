<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Venta confirmada: snapshot inmutable del pedido. Solo cambian saldo_pendiente (por cobros
 * registrados con su fecha real) y los acumulados devuelto y reembolsado (por devoluciones).
 * Nunca se elimina.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $pedido_id
 * @property int $numero
 * @property int $sucursal_id
 * @property int|null $cliente_id
 * @property int $vendedor_id
 * @property int $confirmado_por
 * @property int $operacion_inventario_id
 * @property Carbon $fecha
 * @property string $subtotal
 * @property string $descuento_lineas
 * @property string $descuento_global
 * @property string $total
 * @property string $cobrado_inicial
 * @property string $saldo_pendiente
 * @property string $devuelto
 * @property string $reembolsado
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Pedido $pedido
 * @property-read Sucursal $sucursal
 * @property-read Cliente|null $cliente
 * @property-read User $vendedor
 */
class Venta extends Model
{
    use BelongsToEmpresa;

    private const INMUTABLES = ['pedido_id', 'numero', 'sucursal_id', 'cliente_id', 'vendedor_id', 'confirmado_por',
        'operacion_inventario_id', 'fecha', 'subtotal', 'descuento_lineas', 'descuento_global', 'total', 'cobrado_inicial'];

    protected $table = 'ventas';

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'subtotal' => 'decimal:2',
            'descuento_lineas' => 'decimal:2',
            'descuento_global' => 'decimal:2',
            'total' => 'decimal:2',
            'cobrado_inicial' => 'decimal:2',
            'saldo_pendiente' => 'decimal:2',
            'devuelto' => 'decimal:2',
            'reembolsado' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Venta $venta): void {
            if ($venta->isDirty(self::INMUTABLES)) {
                throw new LogicException('Una venta confirmada no puede reescribirse.');
            }
        });
        static::deleting(fn () => throw new LogicException('Una venta confirmada no se elimina.'));
    }

    public function codigo(): string
    {
        return 'V-'.str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Suma de los cobros recibidos (cuota inicial y abonos), sin restar reembolsos:
     * total − devuelto = cobrado − reembolsado + saldo. Equivale a sqlCobrado().
     */
    public function cobrado(): string
    {
        return Decimal::sumar(Decimal::restar(Decimal::restar($this->total, $this->devuelto, 2), $this->saldo_pendiente, 2),
            $this->reembolsado, Decimal::ESCALA_DINERO);
    }

    /**
     * Expresión SQL de cobrado() para consultas sobre ventas con el alias indicado.
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    public static function sqlCobrado(string $alias = 'v'): string
    {
        return "({$alias}.total - {$alias}.devuelto - {$alias}.saldo_pendiente + {$alias}.reembolsado)";
    }

    /**
     * @return HasMany<VentaItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class)->orderBy('orden');
    }

    /**
     * @return HasMany<PagoVenta, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(PagoVenta::class)->orderBy('fecha_cobro');
    }

    /**
     * @return HasMany<Devolucion, $this>
     */
    public function devoluciones(): HasMany
    {
        return $this->hasMany(Devolucion::class)->orderBy('fecha');
    }

    /**
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }
}
