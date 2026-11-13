<?php

namespace App\Models;

use App\Domain\AgenteMozi\AccesoPorPlan;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Plan de suscripción SaaS de MoziStudios.
 *
 * @property int $id
 * @property string $codigo
 * @property string $titulo
 * @property string $descripcion
 * @property string $precio_mensual
 * @property bool $siat_habilitado
 * @property bool $agente_ia
 * @property bool $activo
 * @property int $orden
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['codigo', 'titulo', 'descripcion', 'precio_mensual', 'siat_habilitado', 'agente_ia', 'activo', 'orden'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $table = 'planes';

    protected static function booted(): void
    {
        // Si el superadmin marca o desmarca el agente en un plan, se vuelve a leer para todas las empresas.
        static::saved(fn () => app(AccesoPorPlan::class)->olvidar());
    }

    protected function casts(): array
    {
        return [
            'precio_mensual' => 'decimal:2',
            'siat_habilitado' => 'boolean',
            'agente_ia' => 'boolean',
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @return HasMany<Suscripcion, $this>
     */
    public function suscripciones(): HasMany
    {
        return $this->hasMany(Suscripcion::class);
    }

    public function esSiat(): bool
    {
        return $this->siat_habilitado;
    }
}
