<?php

namespace App\Models;

use App\Enums\TipoArtefacto;
use App\Models\Concerns\BelongsToEmpresa;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Elemento del Lienzo con su spec validada. El código de un sandbox_web vive solo aquí y en el
 * documento aislado; nunca se envía al DOM principal.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $conversacion_id
 * @property int $mensaje_id
 * @property int|null $dataset_id
 * @property TipoArtefacto $tipo
 * @property string $titulo
 * @property array<string, mixed> $spec
 * @property array<string, mixed> $meta
 * @property int $orden
 * @property string $version
 * @property string $hash
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AgenteConversacion $conversacion
 * @property-read AgenteDataset|null $dataset
 */
class AgenteArtefacto extends Model
{
    use BelongsToEmpresa;

    protected $table = 'agente_artefactos';

    protected function casts(): array
    {
        return [
            'tipo' => TipoArtefacto::class,
            'spec' => 'array',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AgenteConversacion, $this>
     */
    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(AgenteConversacion::class, 'conversacion_id');
    }

    /**
     * @return BelongsTo<AgenteDataset, $this>
     */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(AgenteDataset::class, 'dataset_id');
    }
}
