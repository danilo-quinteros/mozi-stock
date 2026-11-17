<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Envasado (ESPECIFICACION_PRODUCCION.md § 7): granel pasado a presentaciones en una sucursal. Solo
 * App\Domain\Produccion\Actions\RegistrarEnvasado lo crea; no se edita ni se anula. Su número es el de la operación de
 * inventario (ENV-00001). La merma es lo usado de más: granel_real − granel_teorico y, en cada envase, real − teórico.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $numero
 * @property int $sucursal_id
 * @property int $granel_id
 * @property int|null $lote_id
 * @property int $operacion_id
 * @property string $granel_teorico
 * @property string $granel_real
 * @property string $granel_costo_unitario
 * @property string $costo_total
 * @property string|null $nota
 * @property int $usuario_id
 * @property string $clave_idempotencia
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Sucursal $sucursal
 * @property-read Producto $granel
 * @property-read Lote|null $lote
 * @property-read User $usuario
 * @property-read Collection<int, EnvasadoItem> $items
 * @property-read Collection<int, EnvasadoConsumo> $consumos
 */
class Envasado extends Model
{
    use BelongsToEmpresa;

    protected $table = 'envasados';

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'granel_teorico' => 'decimal:3',
            'granel_real' => 'decimal:3',
            'granel_costo_unitario' => 'decimal:6',
            'costo_total' => 'decimal:6',
        ];
    }

    /**
     * Número visible: el de su operación de inventario, ENV-00012.
     */
    public static function formatearCodigo(int $numero): string
    {
        return 'ENV-'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT);
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
    public function granel(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'granel_id');
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
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<EnvasadoItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EnvasadoItem::class)->orderBy('orden');
    }

    /**
     * @return HasMany<EnvasadoConsumo, $this>
     */
    public function consumos(): HasMany
    {
        return $this->hasMany(EnvasadoConsumo::class)->orderBy('orden');
    }
}
