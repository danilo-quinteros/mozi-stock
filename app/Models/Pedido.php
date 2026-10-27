<?php

namespace App\Models;

use App\Enums\EstadoPedido;
use App\Enums\MetodoPago;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Cotización o pedido. Mientras está pendiente no mueve stock ni dinero; solo
 * App\Domain\Ventas\Actions\ConfirmarVenta lo convierte en venta.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $numero
 * @property int $sucursal_id
 * @property int|null $cliente_id
 * @property int $vendedor_id
 * @property int $registrado_por
 * @property EstadoPedido $estado
 * @property string $subtotal
 * @property string $descuento_lineas
 * @property string $descuento_global_tipo
 * @property string $descuento_global_valor
 * @property string $descuento_global
 * @property string $total
 * @property string|null $nota
 * @property MetodoPago|null $pago_metodo
 * @property bool $pago_en_cuotas
 * @property string|null $pago_cuota_inicial
 * @property string|null $pago_efectivo
 * @property string|null $pago_electronico
 * @property string $clave_idempotencia
 * @property Carbon|null $cancelado_en
 * @property int|null $cancelado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Sucursal $sucursal
 * @property-read Cliente|null $cliente
 * @property-read User $vendedor
 * @property-read Venta|null $venta
 */
class Pedido extends Model
{
    use BelongsToEmpresa;

    protected $table = 'pedidos';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'pendiente',
        'descuento_global_tipo' => 'bs',
        'descuento_global_valor' => '0.00',
        'pago_en_cuotas' => false,
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoPedido::class,
            'pago_metodo' => MetodoPago::class,
            'pago_en_cuotas' => 'boolean',
            'subtotal' => 'decimal:2',
            'descuento_lineas' => 'decimal:2',
            'descuento_global_valor' => 'decimal:2',
            'descuento_global' => 'decimal:2',
            'total' => 'decimal:2',
            'pago_cuota_inicial' => 'decimal:2',
            'pago_efectivo' => 'decimal:2',
            'pago_electronico' => 'decimal:2',
            'cancelado_en' => 'datetime',
        ];
    }

    public function codigo(): string
    {
        return 'PED-'.str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }

    public function estaPendiente(): bool
    {
        return $this->estado === EstadoPedido::Pendiente;
    }

    /**
     * @return HasMany<PedidoItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class)->orderBy('orden');
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

    /**
     * @return HasOne<Venta, $this>
     */
    public function venta(): HasOne
    {
        return $this->hasOne(Venta::class);
    }
}
