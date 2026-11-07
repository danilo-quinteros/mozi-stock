<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $empresa_id
 * @property int $turno_caja_id
 * @property int $user_id
 * @property Carbon $incorporado_at
 * @property Carbon|null $retirado_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TurnoCaja $turno
 * @property-read User $usuario
 */
#[Fillable(['turno_caja_id', 'user_id', 'incorporado_at', 'retirado_at'])]
class TurnoUsuario extends Model
{
    use BelongsToEmpresa;

    protected $table = 'turno_usuarios';

    protected function casts(): array
    {
        return [
            'incorporado_at' => 'datetime',
            'retirado_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TurnoCaja, $this>
     */
    public function turno(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScopes();
    }
}
