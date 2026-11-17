<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Envase usado en un envasado: lo que pedían las unidades buenas (teórico), lo usado (real; la diferencia es merma) y
 * el costo promedio leído al registrar.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $envasado_id
 * @property int $producto_id
 * @property int $orden
 * @property string $cantidad_teorica
 * @property string $cantidad_real
 * @property string $costo_unitario
 * @property-read Producto $producto
 */
class EnvasadoConsumo extends Model
{
    use BelongsToEmpresa;

    public $timestamps = false;

    protected $table = 'envasado_consumos';

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
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
