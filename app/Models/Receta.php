<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Versión de la receta de un producto fabricado (ESPECIFICACION_PRODUCCION.md § 5): materias primas para la cantidad
 * base, en la unidad del producto. Solo GuardarReceta la crea o cambia: guardar cambios crea la versión siguiente y deja
 * de solo lectura las anteriores; «activa» se lleva en la vigente.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $producto_id
 * @property int $version
 * @property string $cantidad_base
 * @property string|null $nota
 * @property bool $vigente
 * @property bool $activa
 * @property int $usuario_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Producto $producto
 * @property-read User $usuario
 * @property-read Collection<int, RecetaItem> $items
 */
class Receta extends Model
{
    use BelongsToEmpresa;

    protected $table = 'recetas';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'cantidad_base' => 'decimal:3',
            'vigente' => 'boolean',
            'activa' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return HasMany<RecetaItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RecetaItem::class)->orderBy('orden');
    }
}
