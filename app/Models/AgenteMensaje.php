<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mensaje de una conversación: del usuario (con la clave de idempotencia del envío) o del agente
 * (con respuesta_de_id). Un mensaje «fallido» del agente conserva la pregunta para reintentar.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $conversacion_id
 * @property string $rol
 * @property string $texto
 * @property string $estado
 * @property string|null $clave
 * @property int|null $respuesta_de_id
 * @property string|null $proveedor
 * @property string|null $modelo
 * @property int|null $tokens_entrada
 * @property int|null $tokens_salida
 * @property int|null $latencia_ms
 * @property int|null $herramientas_usadas
 * @property string|null $referencia
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AgenteConversacion $conversacion
 */
class AgenteMensaje extends Model
{
    use BelongsToEmpresa;

    public const ROL_USUARIO = 'usuario';

    public const ROL_AGENTE = 'agente';

    public const COMPLETADO = 'completado';

    public const FALLIDO = 'fallido';

    protected $table = 'agente_mensajes';

    public function esDelAgente(): bool
    {
        return $this->rol === self::ROL_AGENTE;
    }

    /**
     * @return BelongsTo<AgenteConversacion, $this>
     */
    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(AgenteConversacion::class, 'conversacion_id');
    }

    /**
     * @return HasMany<AgenteArtefacto, $this>
     */
    public function artefactos(): HasMany
    {
        return $this->hasMany(AgenteArtefacto::class, 'mensaje_id')->orderBy('orden');
    }
}
