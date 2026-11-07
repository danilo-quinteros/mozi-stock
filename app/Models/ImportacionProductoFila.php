<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila leída del archivo: celdas en crudo y, tras validar, el resultado (estado, datos
 * normalizados, errores y advertencias). Se escribe por lotes, nunca fila por fila.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $importacion_id
 * @property int $fila
 * @property array<int|string, string> $celdas
 * @property list<int>|null $numericas
 * @property string|null $estado
 * @property array<string, mixed>|null $normalizado
 * @property list<string>|null $errores
 * @property list<string>|null $advertencias
 * @property int|null $producto_id
 */
class ImportacionProductoFila extends Model
{
    use BelongsToEmpresa;

    public const VALIDA = 'valida';

    public const ERROR = 'error';

    public const EXISTENTE = 'existente';

    public $timestamps = false;

    protected $table = 'importacion_producto_filas';

    protected function casts(): array
    {
        return [
            'celdas' => 'array',
            'numericas' => 'array',
            'normalizado' => 'array',
            'errores' => 'array',
            'advertencias' => 'array',
        ];
    }
}
