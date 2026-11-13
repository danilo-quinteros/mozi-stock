<?php

namespace App\Models;

use App\Domain\Inventario\DatosLote;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Lote de un producto con vencimiento (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 6.3): número y fecha, iguales en todas
 * las sucursales. Lo crea y lo mueve solo LibroLotes; aquí solo se lee. El lote de regularización no tiene número ni
 * fecha y agrupa el stock anterior al control por lote (§ 9).
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $producto_id
 * @property string|null $numero
 * @property Carbon|null $vencimiento
 * @property bool $es_regularizacion
 * @property Carbon $created_at
 * @property-read Producto $producto
 */
class Lote extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'lotes';

    protected function casts(): array
    {
        return [
            'vencimiento' => 'date',
            'es_regularizacion' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn () => throw new LogicException('Los lotes solo se crean desde LibroLotes.'));
        static::updating(fn () => throw new LogicException('Un lote no se modifica.'));
        static::deleting(fn () => throw new LogicException('Un lote no se elimina.'));
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function vencido(): bool
    {
        return DatosLote::vencido($this->vencimiento?->format('Y-m-d'));
    }

    public function etiqueta(): string
    {
        return $this->es_regularizacion
            ? __('Sin lote (regularización)')
            : __('Lote :numero · vence :fecha', ['numero' => $this->numero, 'fecha' => DatosLote::formatear($this->vencimiento?->format('Y-m-d'))]);
    }
}
