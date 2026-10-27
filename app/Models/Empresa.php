<?php

namespace App\Models;

use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Tenant. activa controla el acceso y solo cambia por decisión manual del superadministrador.
 *
 * @property int $id
 * @property string $nombre
 * @property string|null $razon_social
 * @property string|null $nit
 * @property string|null $email
 * @property string|null $telefono
 * @property bool $activa
 * @property bool $siat_habilitado
 * @property Carbon|null $guia_completada_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['nombre', 'razon_social', 'nit', 'email', 'telefono'])]
class Empresa extends Model
{
    /** @use HasFactory<EmpresaFactory> */
    use HasFactory;

    protected $table = 'empresas';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activa' => true,
        'siat_habilitado' => false,
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'siat_habilitado' => 'boolean',
            'guia_completada_en' => 'datetime',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Suscripción SaaS. Desde el panel global hay que omitir el scope de empresa de forma explícita.
     *
     * @return HasOne<Suscripcion, $this>
     */
    public function suscripcion(): HasOne
    {
        return $this->hasOne(Suscripcion::class);
    }

    public function identificador(): string
    {
        return 'EMP-'.str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }
}
