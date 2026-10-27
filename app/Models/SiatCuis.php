<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * CUIS de un punto de venta. Vale solo para el ambiente y el código de sistema con que se pidió.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $siat_punto_venta_id
 * @property int $ambiente
 * @property string $codigo_sistema
 * @property string $codigo
 * @property Carbon $vence_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiatCuis extends Model
{
    use BelongsToEmpresa;

    protected $table = 'siat_cuis';

    protected function casts(): array
    {
        return [
            'ambiente' => 'integer',
            'vence_en' => 'datetime',
        ];
    }
}
