<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Registro histórico de cambios de plan (upgrade / downgrade).
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $suscripcion_id
 * @property int $plan_anterior_id
 * @property int $plan_nuevo_id
 * @property string $tipo
 * @property int $dias_restantes
 * @property string $monto_prorrateado
 * @property string $precio_nuevo_completo
 * @property CarbonImmutable $vencimiento_conservado
 * @property int|null $usuario_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'empresa_id',
    'suscripcion_id',
    'plan_anterior_id',
    'plan_nuevo_id',
    'tipo',
    'dias_restantes',
    'monto_prorrateado',
    'precio_nuevo_completo',
    'vencimiento_conservado',
    'usuario_id',
])]
class CambioPlan extends Model
{
    use BelongsToEmpresa;

    protected $table = 'cambios_plan';

    protected function casts(): array
    {
        return [
            'dias_restantes' => 'integer',
            'monto_prorrateado' => 'decimal:2',
            'precio_nuevo_completo' => 'decimal:2',
            'vencimiento_conservado' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Suscripcion, $this>
     */
    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function planAnterior(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_anterior_id');
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function planNuevo(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_nuevo_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
