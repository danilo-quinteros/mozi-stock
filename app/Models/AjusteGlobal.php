<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Datos de cobro de MoziStudios (una sola fila). Solo el superadministrador los edita.
 *
 * @property int $id
 * @property string|null $receptor_nombre
 * @property string|null $correo_contacto
 * @property string|null $qr_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['receptor_nombre', 'correo_contacto'])]
class AjusteGlobal extends Model
{
    public const DISCO = 'local';

    protected $table = 'ajustes_globales';

    public static function actual(): self
    {
        return self::query()->orderBy('id')->first() ?? new self;
    }
}
