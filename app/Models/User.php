<?php

namespace App\Models;

use App\Enums\Rol;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * empresa_id, sucursal_id, rol y activo no son asignables masivamente: solo los cambian
 * las acciones de dominio autorizadas.
 *
 * @property int $id
 * @property int|null $empresa_id
 * @property int|null $sucursal_id
 * @property int|null $caja_id
 * @property Rol|null $rol
 * @property bool $activo
 * @property bool $puede_despachar permiso de almacén: despacha ventas de su sucursal (ESPECIFICACION_DESPACHOS.md § 6.3)
 * @property string|null $idioma
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Empresa|null $empresa
 * @property-read Sucursal|null $sucursal
 * @property-read Caja|null $caja
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activo' => true,
        'puede_despachar' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'rol' => Rol::class,
            'activo' => 'boolean',
            'puede_despachar' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Caja, $this>
     */
    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class)->withoutGlobalScopes();
    }

    /**
     * Búsqueda por nombre, correo o sucursal. Siempre combinar con un filtro de empresa.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function buscar(Builder $query, string $termino): void
    {
        if ($termino === '') {
            return;
        }

        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $patron)
            ->orWhere('email', 'like', $patron)
            ->orWhereHas('sucursal', fn (Builder $s) => $s->where('nombre', 'like', $patron)));
    }

    public function esSuperadmin(): bool
    {
        return $this->rol === Rol::Superadmin;
    }

    public function esAdministrador(): bool
    {
        return $this->rol === Rol::Administrador && $this->empresa_id !== null;
    }

    public function esVendedor(): bool
    {
        return $this->rol === Rol::Vendedor && $this->empresa_id !== null;
    }

    public function perteneceAEmpresa(): bool
    {
        return $this->esAdministrador() || $this->esVendedor();
    }

    /** Clave de sesión con la sucursal que el administrador eligió en el menú lateral. */
    public const SESION_SUCURSAL = 'sucursal_activa_id';

    public const SESION_IDIOMA = 'idioma';

    /** Idioma de los correos que recibe el usuario (null = predeterminado). */
    public function preferredLocale(): ?string
    {
        return $this->idioma;
    }

    /**
     * Sucursal con la que trabaja la sesión: la asignada al vendedor (inmutable) o la que el
     * administrador fijó en el menú lateral, si sigue activa y es de su empresa. null = todas.
     */
    public function sucursalActivaId(): ?int
    {
        if ($this->esVendedor()) {
            return $this->sucursal_id;
        }

        $id = $this->esAdministrador() ? session(self::SESION_SUCURSAL) : null;

        return is_int($id) && Sucursal::query()->whereKey($id)->where('empresa_id', $this->empresa_id)->where('activa', true)->exists()
            ? $id : null;
    }

    /**
     * Motivo por el que la cuenta no puede operar, o null si tiene acceso.
     * Se evalúa en el inicio de sesión y en cada petición posterior.
     */
    public function motivoBloqueo(): ?string
    {
        if (! $this->activo) {
            return __('Tu usuario está desactivado. Comunícate con el administrador de tu empresa.');
        }

        if ($this->empresa_id !== null && ! Empresa::query()->whereKey($this->empresa_id)->where('activa', true)->exists()) {
            return __('Tu empresa está desactivada. Comunícate con MoziStudios para reactivar el acceso.');
        }

        return null;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
