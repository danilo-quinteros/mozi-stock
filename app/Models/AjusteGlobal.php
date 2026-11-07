<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ajustes globales de MoziStudios (una sola fila): datos de cobro, motor de Agente Mozi y
 * datos del sitio web público (WhatsApp, imagen para redes y verificación de Google). Solo el
 * superadministrador los edita. Las columnas agente_* en null usan el valor de config/agente_mozi.php.
 *
 * @property int $id
 * @property string|null $receptor_nombre
 * @property string|null $correo_contacto
 * @property string|null $qr_path
 * @property string|null $agente_motor
 * @property string|null $agente_modelo
 * @property int|null $agente_mensajes_diarios
 * @property int|null $agente_tokens_diarios
 * @property string|null $sitio_whatsapp
 * @property string|null $sitio_og_imagen_path
 * @property string|null $sitio_verificacion_google
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
