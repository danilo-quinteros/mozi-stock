<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string $abreviatura
 * @property int|null $unidad_medida_sin
 * @property bool $admite_decimales
 * @property bool $activa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['nombre', 'abreviatura', 'unidad_medida_sin', 'admite_decimales', 'activa'])]
class UnidadMedida extends Model
{
    use BelongsToEmpresa;

    /**
     * Unidades con las que nace cada empresa.
     */
    public const BASE = [
        ['nombre' => 'Unidad', 'abreviatura' => 'und.', 'admite_decimales' => false, 'unidad_medida_sin' => 58],
        ['nombre' => 'Kilogramo', 'abreviatura' => 'kg', 'admite_decimales' => true, 'unidad_medida_sin' => 2],
        ['nombre' => 'Gramo', 'abreviatura' => 'g', 'admite_decimales' => false, 'unidad_medida_sin' => 1],
        ['nombre' => 'Litro', 'abreviatura' => 'L', 'admite_decimales' => true, 'unidad_medida_sin' => 24],
        ['nombre' => 'Caja', 'abreviatura' => 'cj.', 'admite_decimales' => false, 'unidad_medida_sin' => 15],
    ];

    protected $table = 'unidades_medida';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'admite_decimales' => false,
        'activa' => true,
    ];

    protected function casts(): array
    {
        return [
            'unidad_medida_sin' => 'integer',
            'admite_decimales' => 'boolean',
            'activa' => 'boolean',
        ];
    }
}
