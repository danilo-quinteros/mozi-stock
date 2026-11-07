<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Importación de productos desde una hoja de cálculo. Avanza por pasos (columnas → valores →
 * revisión) y solo crea productos al confirmarse, una única vez.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $usuario_id
 * @property string $archivo_nombre
 * @property string|null $archivo_path
 * @property list<array{indice: int, nombre: string}>|null $hojas
 * @property int $hoja
 * @property int $fila_encabezados
 * @property array<string, int>|null $mapeo campo => índice de columna
 * @property array{categorias?: array<string, array<string, mixed>>, unidades?: array<string, array<string, mixed>>}|null $valores
 * @property int|null $unidad_defecto_id
 * @property bool|null $usar_stock
 * @property int|null $sucursal_stock_id
 * @property string $estado
 * @property int $total_filas
 * @property array<string, mixed>|null $resumen
 * @property Carbon|null $confirmada_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $usuario
 */
class ImportacionProducto extends Model
{
    use BelongsToEmpresa;

    public const COLUMNAS = 'columnas';

    public const VALORES = 'valores';

    public const REVISION = 'revision';

    public const COMPLETADA = 'completada';

    public const CANCELADA = 'cancelada';

    protected $table = 'importaciones_productos';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => self::COLUMNAS,
        'hoja' => 0,
        'fila_encabezados' => 1,
        'total_filas' => 0,
    ];

    protected function casts(): array
    {
        return [
            'hojas' => 'array',
            'mapeo' => 'array',
            'valores' => 'array',
            'usar_stock' => 'boolean',
            'resumen' => 'array',
            'confirmada_en' => 'datetime',
        ];
    }

    public function abierta(): bool
    {
        return in_array($this->estado, [self::COLUMNAS, self::VALORES, self::REVISION], true);
    }

    /**
     * @return HasMany<ImportacionProductoFila, $this>
     */
    public function filas(): HasMany
    {
        return $this->hasMany(ImportacionProductoFila::class, 'importacion_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
