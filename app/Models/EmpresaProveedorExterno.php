<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Vinculación de una empresa de MoziStock como revendedora de un proveedor externo.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $proveedor_externo_id
 * @property bool $activo
 * @property int|null $vinculado_por
 * @property CarbonImmutable|null $ultima_sincronizacion_en
 * @property array<string, mixed>|null $ultimo_resultado
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Empresa $empresa
 * @property-read ProveedorExterno $proveedor
 */
#[Fillable(['proveedor_externo_id', 'activo', 'vinculado_por', 'ultima_sincronizacion_en', 'ultimo_resultado'])]
class EmpresaProveedorExterno extends Model
{
    use BelongsToEmpresa;

    protected $table = 'empresa_proveedor_externo';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'ultima_sincronizacion_en' => 'datetime',
            'ultimo_resultado' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ProveedorExterno, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(ProveedorExterno::class, 'proveedor_externo_id');
    }
}
