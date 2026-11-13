<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Talla de un grupo (§ 3.1). Una talla en uso no se borra: se desactiva y deja de ofrecerse para combinaciones
 * nuevas. Se escribe solo con GuardarTalla, que también renombra las variantes.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $grupo_talla_id
 * @property string $nombre
 * @property int $orden
 * @property bool $activa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GrupoTalla $grupo
 */
#[Fillable(['nombre'])]
class Talla extends Model
{
    use BelongsToEmpresa;

    protected $table = 'tallas';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'orden' => 0,
        'activa' => true,
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'activa' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<GrupoTalla, $this>
     */
    public function grupo(): BelongsTo
    {
        return $this->belongsTo(GrupoTalla::class, 'grupo_talla_id');
    }
}
