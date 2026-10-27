<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Estado de la última sincronización de cada catálogo de la empresa.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $catalogo
 * @property int $cantidad
 * @property Carbon|null $sincronizado_en
 * @property Carbon|null $fallo_en
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiatSincronizacion extends Model
{
    use BelongsToEmpresa;

    protected $table = 'siat_sincronizaciones';

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'sincronizado_en' => 'datetime',
            'fallo_en' => 'datetime',
        ];
    }
}
