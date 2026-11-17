<?php

namespace App\Models;

use App\Enums\EstadoOrdenProduccion;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Orden de producción (ESPECIFICACION_PRODUCCION.md § 6). Solo las acciones de App\Domain\Produccion\Actions la crean
 * o cambian de estado; created_at es la fecha de emisión. Guarda su copia de la receta (cantidad base y líneas), así
 * que una versión nueva de la receta no la altera.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $numero
 * @property int $sucursal_id
 * @property int $producto_id
 * @property int $receta_id
 * @property EstadoOrdenProduccion $estado
 * @property string $cantidad_planificada
 * @property string $cantidad_base
 * @property string|null $cantidad_obtenida
 * @property Carbon $fecha_programada
 * @property string|null $nota
 * @property int $emitida_por
 * @property Carbon|null $aprobada_en
 * @property int|null $aprobada_por
 * @property Carbon|null $finalizada_en
 * @property int|null $finalizada_por
 * @property Carbon|null $anulada_en
 * @property int|null $anulada_por
 * @property string|null $motivo_anulacion
 * @property int|null $operacion_consumo_id
 * @property int|null $operacion_cierre_id
 * @property int|null $operacion_anulacion_id
 * @property int|null $lote_id
 * @property string|null $costo_total
 * @property string|null $costo_unitario
 * @property string $clave_idempotencia
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Sucursal $sucursal
 * @property-read Producto $producto
 * @property-read Receta $receta
 * @property-read Lote|null $lote
 * @property-read User $emisor
 * @property-read User|null $aprobador
 * @property-read User|null $finalizador
 * @property-read User|null $anulador
 * @property-read Collection<int, OrdenProduccionItem> $items
 */
class OrdenProduccion extends Model
{
    use BelongsToEmpresa;

    public const SERIE = 'OP';

    protected $table = 'ordenes_produccion';

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'estado' => EstadoOrdenProduccion::class,
            'cantidad_planificada' => 'decimal:3',
            'cantidad_base' => 'decimal:3',
            'cantidad_obtenida' => 'decimal:3',
            'fecha_programada' => 'date',
            'aprobada_en' => 'datetime',
            'finalizada_en' => 'datetime',
            'anulada_en' => 'datetime',
            'costo_total' => 'decimal:6',
            'costo_unitario' => 'decimal:6',
        ];
    }

    /**
     * Número visible y número del lote del producto obtenido: OP-0015.
     */
    public static function formatearCodigo(int $numero): string
    {
        return self::SERIE.'-'.str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
    }

    public function codigo(): string
    {
        return self::formatearCodigo($this->numero);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * @return BelongsTo<Receta, $this>
     */
    public function receta(): BelongsTo
    {
        return $this->belongsTo(Receta::class);
    }

    /**
     * @return BelongsTo<Lote, $this>
     */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function emisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitida_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobada_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function finalizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalizada_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function anulador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    /**
     * @return HasMany<OrdenProduccionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrdenProduccionItem::class, 'orden_id')->orderBy('orden');
    }
}
