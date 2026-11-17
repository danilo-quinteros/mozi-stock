<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Presentación obtenida en un envasado: unidades buenas, copia del granel por unidad y su costo (§ 9).
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $envasado_id
 * @property int $producto_id
 * @property int $orden
 * @property string $unidades
 * @property string $granel_por_unidad
 * @property string $costo_unitario
 * @property string $costo_total
 * @property-read Producto $producto
 */
class EnvasadoItem extends Model
{
    use BelongsToEmpresa;

    public $timestamps = false;

    protected $table = 'envasado_items';

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'unidades' => 'decimal:3',
            'granel_por_unidad' => 'decimal:3',
            'costo_unitario' => 'decimal:6',
            'costo_total' => 'decimal:6',
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
