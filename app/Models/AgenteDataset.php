<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Datos verificables que sustentan los artefactos de un mensaje (§ 6.3). El navegador los pide por
 * su ref opaca al endpoint autenticado; solo el autor de la conversación puede leerlos.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $conversacion_id
 * @property int $mensaje_id
 * @property int $usuario_id
 * @property string $ref
 * @property list<array{field: string, label: string, type: string, nullable: bool}> $columnas
 * @property list<array<string, string|int|null>> $filas
 * @property int $total_filas
 * @property array<string, mixed> $meta
 * @property CarbonImmutable|null $expira_en
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class AgenteDataset extends Model
{
    use BelongsToEmpresa;

    protected $table = 'agente_datasets';

    protected function casts(): array
    {
        return [
            'columnas' => 'array',
            'filas' => 'array',
            'meta' => 'array',
            'expira_en' => 'datetime',
        ];
    }

    public function expirado(): bool
    {
        return $this->expira_en !== null && $this->expira_en->isPast();
    }
}
