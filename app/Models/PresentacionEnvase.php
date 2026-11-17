<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Envase de una presentación (ESPECIFICACION_PRODUCCION.md § 7.1): materia prima y cantidad por unidad (1 botella,
 * 1 tapa, 1 etiqueta). Solo App\Domain\Produccion\Presentaciones lo escribe.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $presentacion_id
 * @property int $producto_id
 * @property int $orden
 * @property string $cantidad
 * @property-read Producto $producto
 */
class PresentacionEnvase extends Model
{
    use BelongsToEmpresa;

    public $timestamps = false;

    protected $table = 'presentacion_envases';

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'cantidad' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
