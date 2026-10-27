<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Evento crítico inalterable. Solo se crea mediante App\Domain\Auditoria\Auditor.
 *
 * @property int $id
 * @property int|null $empresa_id
 * @property int|null $usuario_id
 * @property string $accion
 * @property string $entidad
 * @property int|null $entidad_id
 * @property array<string, mixed>|null $antes
 * @property array<string, mixed>|null $despues
 * @property string|null $ip
 * @property Carbon $created_at
 */
class Auditoria extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'auditoria';

    protected function casts(): array
    {
        return [
            'antes' => 'array',
            'despues' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La auditoría no puede modificarse.'));
        static::deleting(fn () => throw new LogicException('La auditoría no puede eliminarse.'));
    }
}
