<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de una versión de receta: materia prima y cantidad para la cantidad base, en la unidad de la materia prima
 * (PR14). No se modifica: una versión nueva copia sus líneas.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $receta_id
 * @property int $producto_id
 * @property int $orden
 * @property string $cantidad
 * @property-read Producto $producto
 */
class RecetaItem extends Model
{
    use BelongsToEmpresa;

    public $timestamps = false;

    protected $table = 'receta_items';

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
