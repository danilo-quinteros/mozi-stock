<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Enlace entre la ficha de producto de una empresa y el producto del catálogo externo.
 * Garantiza que la empresa no duplique productos tras múltiples sincronizaciones.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $producto_id
 * @property int $proveedor_externo_id
 * @property string $externo_id
 * @property string|null $hash_aplicado
 * @property CarbonImmutable $sincronizado_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Empresa $empresa
 * @property-read Producto|null $producto Nulo fuera del contexto de su empresa (alcance por empresa).
 * @property-read ProveedorExterno $proveedor
 */
#[Fillable(['producto_id', 'proveedor_externo_id', 'externo_id', 'hash_aplicado', 'sincronizado_en'])]
class ProductoVinculoExterno extends Model
{
    use BelongsToEmpresa;

    protected $table = 'producto_vinculos_externos';

    protected function casts(): array
    {
        return [
            'sincronizado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /**
     * @return BelongsTo<ProveedorExterno, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(ProveedorExterno::class, 'proveedor_externo_id');
    }
}
