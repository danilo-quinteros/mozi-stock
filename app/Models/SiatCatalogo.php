<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de un catálogo del SIN sincronizado para la empresa. `catalogo` es el nombre del caso
 * de MoziStudios\Siat\Sincronizacion\Catalogo; las filas se reemplazan en cada sincronización.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $catalogo
 * @property string $codigo
 * @property string $descripcion
 * @property array<string, string>|null $atributos
 */
class SiatCatalogo extends Model
{
    use BelongsToEmpresa;

    public $timestamps = false;

    protected $table = 'siat_catalogos';

    protected function casts(): array
    {
        return [
            'atributos' => 'array',
        ];
    }
}
