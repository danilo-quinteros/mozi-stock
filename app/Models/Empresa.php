<?php

namespace App\Models;

use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
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
 * @property Rubro $rubro
 * @property int $minutos_alerta_cocina pedidos En cocina con más minutos se resaltan (cocina § 4.4)
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
        // Igual que el valor por defecto de la columna: sin rubro elegido, la empresa es General.
        'rubro' => 'general',
        'minutos_alerta_cocina' => 15,
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'siat_habilitado' => 'boolean',
            'rubro' => Rubro::class,
            'minutos_alerta_cocina' => 'integer',
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

    /**
     * @return HasMany<EmpresaProveedorExterno, $this>
     */
    public function proveedoresExternos(): HasMany
    {
        return $this->hasMany(EmpresaProveedorExterno::class);
    }

    /**
     * Filas de capacidades (activas y apagadas). Para saber si una función está encendida usar
     * tieneCapacidad(), que lee una sola vez por petición.
     *
     * @return HasMany<EmpresaCapacidad, $this>
     */
    public function capacidades(): HasMany
    {
        return $this->hasMany(EmpresaCapacidad::class)->withoutGlobalScopes();
    }

    /** Si la función está encendida. Una consulta por empresa y petición, compartida entre instancias. */
    public function tieneCapacidad(Capacidad $capacidad): bool
    {
        return app(CapacidadesEmpresa::class)->tiene($this->id, $capacidad);
    }

    /**
     * @return list<Capacidad>
     */
    public function capacidadesActivas(): array
    {
        return app(CapacidadesEmpresa::class)->activas($this->id);
    }

    public function identificador(): string
    {
        return 'EMP-'.str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }
}
