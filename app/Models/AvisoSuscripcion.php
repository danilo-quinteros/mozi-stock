<?php

namespace App\Models;

use App\Enums\EstadoAviso;
use App\Enums\EventoAviso;
use App\Models\Concerns\BelongsToEmpresa;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Recordatorio D-5, D0 o D+3 de un período. El único (empresa, vencimiento, evento) impide
 * generarlo dos veces (T24). Ningún aviso cambia el acceso de la empresa (T26).
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $suscripcion_id
 * @property CarbonImmutable $vencimiento
 * @property EventoAviso $evento
 * @property CarbonImmutable $fecha_programada
 * @property string $canal
 * @property EstadoAviso $estado
 * @property list<string>|null $destinatarios
 * @property Carbon|null $enviado_en
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AvisoSuscripcion extends Model
{
    use BelongsToEmpresa;

    protected $table = 'avisos_suscripcion';

    protected function casts(): array
    {
        return [
            'vencimiento' => 'date',
            'evento' => EventoAviso::class,
            'fecha_programada' => 'date',
            'estado' => EstadoAviso::class,
            'destinatarios' => 'array',
            'enviado_en' => 'datetime',
        ];
    }
}
