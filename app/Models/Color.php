<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Color de la empresa (§ 3.2): nombre, código corto para el SKU (NEG, AZM) y muestra opcional. Un color en uso no se
 * borra: se desactiva. Se escribe solo con GuardarColor, que también renombra las variantes.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string $codigo
 * @property string|null $muestra_hex
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['nombre', 'codigo', 'muestra_hex'])]
class Color extends Model
{
    use BelongsToEmpresa;

    protected $table = 'colores';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }
}
