<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Charla de un usuario con Agente Mozi. Es privada: solo su autor la ve (AgenteConversacionPolicy),
 * además del aislamiento por empresa de EmpresaScope. Eliminada (eliminada_en), queda sin contenido:
 * solo se conservan los contadores de uso de sus mensajes (EliminarConversacion).
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $usuario_id
 * @property string $titulo
 * @property CarbonImmutable $ultima_actividad_en
 * @property CarbonImmutable|null $eliminada_en
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $usuario
 */
class AgenteConversacion extends Model
{
    use BelongsToEmpresa;

    protected $table = 'agente_conversaciones';

    protected function casts(): array
    {
        return [
            'ultima_actividad_en' => 'datetime',
            'eliminada_en' => 'datetime',
        ];
    }

    public function eliminada(): bool
    {
        return $this->eliminada_en !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return HasMany<AgenteMensaje, $this>
     */
    public function mensajes(): HasMany
    {
        return $this->hasMany(AgenteMensaje::class, 'conversacion_id');
    }
}
