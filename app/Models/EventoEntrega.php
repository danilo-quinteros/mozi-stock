<?php

namespace App\Models;

use App\Enums\EstadoEntrega;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Cambio de estado de entrega de una venta (ESPECIFICACION_DESPACHOS.md § 6.2). Se inserta en la misma
 * transacción que el cambio y nunca se modifica ni elimina; las correcciones son eventos nuevos.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $venta_id
 * @property EstadoEntrega|null $estado_anterior
 * @property EstadoEntrega $estado_nuevo
 * @property int $usuario_id
 * @property string|null $nota
 * @property bool $es_correccion
 * @property Carbon $created_at
 * @property-read User $usuario
 */
class EventoEntrega extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'eventos_entrega';

    protected function casts(): array
    {
        return [
            'estado_anterior' => EstadoEntrega::class,
            'estado_nuevo' => EstadoEntrega::class,
            'es_correccion' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de entrega no puede modificarse.'));
        static::deleting(fn () => throw new LogicException('El historial de entrega no puede eliminarse.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
