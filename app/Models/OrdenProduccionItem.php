<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Materia prima de una orden de producción: copia de la línea de la receta, cantidad teórica escalada a la cantidad
 * planificada, consumo real (al finalizar) y costo promedio guardado al aprobar (§ 9).
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $orden_id
 * @property int $producto_id
 * @property int $orden
 * @property string $cantidad_receta
 * @property string $cantidad_teorica
 * @property string|null $cantidad_real
 * @property string|null $costo_unitario
 * @property-read Producto $producto
 */
class OrdenProduccionItem extends Model
{
    use BelongsToEmpresa;

    public $timestamps = false;

    protected $table = 'orden_produccion_items';

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'cantidad_receta' => 'decimal:3',
            'cantidad_teorica' => 'decimal:3',
            'cantidad_real' => 'decimal:3',
            'costo_unitario' => 'decimal:6',
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
