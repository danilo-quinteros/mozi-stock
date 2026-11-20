<?php

namespace App\Models;

use App\Domain\Identidad\PermisosUsuario;
use App\Enums\Permiso;
use App\Enums\TipoCuenta;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * empresa_id, sucursal_id, tipo, rol_id y activo no son asignables masivamente: solo los cambian
 * las acciones de dominio autorizadas. Lo que puede hacer un usuario de tipo «usuario» lo deciden su rol
 * y sus permisos extra (ESPECIFICACION_PERMISOS.md); el administrador tiene todos.
 *
 * @property int $id
 * @property int|null $empresa_id
 * @property int|null $sucursal_id
 * @property int|null $caja_id
 * @property TipoCuenta|null $tipo
 * @property int|null $rol_id rol de la empresa (solo tipo usuario)
 * @property bool $activo
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
 * @property-read Rol|null $rol
 * @property-read int|null $permisos_extra_count
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
            'tipo' => TipoCuenta::class,
            'activo' => 'boolean',
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
     * Rol de la empresa (FK compuesta: nunca de otra empresa). El administrador no tiene rol.
     *
     * @return BelongsTo<Rol, $this>
     */
    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<UsuarioPermiso, $this>
     */
    public function permisosExtra(): HasMany
    {
        return $this->hasMany(UsuarioPermiso::class);
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

    /**
     * Administradores y usuarios con el permiso por su rol o por un extra (§ 6.1), en una sola consulta. Sirve para
     * listas de asignación (p. ej. el vendedor del pedido); no mira capacidades: quien llama decide si aplica.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function conPermiso(Builder $query, Permiso $permiso): void
    {
        $query->where(fn (Builder $q) => $q->where('users.tipo', TipoCuenta::Administrador->value)
            ->orWhere(fn (Builder $q) => $q->where('users.tipo', TipoCuenta::Usuario->value)
                ->where(fn (Builder $q) => self::conClave($q, $permiso))));
    }

    /**
     * Usuarios cuyo alcance incluye la sucursal (§ 6.2): administradores, quien trabaja en todas y los asignados a ella.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function alcanzanSucursal(Builder $query, int $sucursalId): void
    {
        $query->where(fn (Builder $q) => $q->where('users.tipo', TipoCuenta::Administrador->value)
            ->orWhere('users.sucursal_id', $sucursalId)
            ->orWhere(fn (Builder $q) => $q->where('users.tipo', TipoCuenta::Usuario->value)
                ->where(fn (Builder $q) => self::conClave($q, Permiso::SucursalesTodas))));
    }

    /**
     * @param  Builder<User>  $query
     */
    private static function conClave(Builder $query, Permiso $permiso): void
    {
        $claves = $permiso->clavesQueLoDan();
        $query->whereExists(fn ($s) => $s->from('rol_permisos')->whereColumn('rol_permisos.rol_id', 'users.rol_id')->whereIn('rol_permisos.permiso', $claves))
            ->orWhereExists(fn ($s) => $s->from('usuario_permisos')->whereColumn('usuario_permisos.user_id', 'users.id')->whereIn('usuario_permisos.permiso', $claves));
    }

    public function esSuperadmin(): bool
    {
        return $this->tipo === TipoCuenta::Superadmin;
    }

    public function esAdministrador(): bool
    {
        return $this->tipo === TipoCuenta::Administrador && $this->empresa_id !== null;
    }

    /** Usuario de empresa con un rol (no administrador). */
    public function esUsuario(): bool
    {
        return $this->tipo === TipoCuenta::Usuario && $this->empresa_id !== null;
    }

    public function perteneceAEmpresa(): bool
    {
        return $this->esAdministrador() || $this->esUsuario();
    }

    /**
     * Permiso efectivo (§ 6.1): el administrador los tiene todos; el usuario, los de su rol y sus extras.
     * Un permiso cuya capacidad está apagada no cuenta. Una clave desconocida niega.
     */
    public function tienePermiso(Permiso|string $permiso): bool
    {
        $permiso = $permiso instanceof Permiso ? $permiso : Permiso::tryFrom($permiso);

        return $permiso !== null && app(PermisosUsuario::class)->tiene($this, $permiso);
    }

    /**
     * Alcance de sucursal (§ 6.2): el administrador y quien tiene «Trabajar en todas las sucursales» eligen
     * la sucursal; el resto trabaja solo en la asignada, que no puede cambiar.
     */
    public function veTodasLasSucursales(): bool
    {
        return $this->esAdministrador() || $this->tienePermiso(Permiso::SucursalesTodas);
    }

    /** Si la sucursal está dentro de su alcance. */
    public function alcanzaSucursal(?int $sucursalId): bool
    {
        if (! $this->perteneceAEmpresa() || $sucursalId === null) {
            return false;
        }

        return $this->veTodasLasSucursales() || ($this->sucursal_id !== null && (int) $this->sucursal_id === $sucursalId);
    }

    /**
     * Filtro de sucursal de un listado, reporte o CSV (P7): quien trabaja en todas usa el que pidió (null = todas);
     * el resto, siempre su sucursal asignada, diga lo que diga la petición.
     */
    public function acotarSucursal(?int $solicitada): ?int
    {
        return $this->veTodasLasSucursales() ? $solicitada : ($this->sucursal_id ?? 0);
    }

    /**
     * Supervisar cajas (§ 6.3): elige caja, abre un turno por caja, ve Caja y arqueo y cierra turnos ajenos de
     * su alcance. Sin este permiso usa solo su caja asignada.
     */
    public function supervisaCajas(): bool
    {
        return $this->tienePermiso(Permiso::CajaSupervisar);
    }

    /** Nombre para mostrar: el tipo de cuenta o, para un usuario con rol, el nombre de su rol. */
    public function nombreRol(): string
    {
        return match ($this->tipo) {
            TipoCuenta::Usuario => $this->rol->nombre ?? __('Sin rol asignado'),
            null => __('Sin rol asignado'),
            default => $this->tipo->etiqueta(),
        };
    }

    /** Clave de sesión con la sucursal que eligió en el menú lateral quien trabaja en todas. */
    public const SESION_SUCURSAL = 'sucursal_activa_id';

    public const SESION_IDIOMA = 'idioma';

    /** Idioma de los correos que recibe el usuario (null = predeterminado). */
    public function preferredLocale(): ?string
    {
        return $this->idioma;
    }

    /**
     * Sucursal con la que trabaja la sesión: la asignada (inmutable) a quien no trabaja en todas, o la que
     * fijó en el menú lateral quien sí, si sigue activa y es de su empresa. null = todas.
     */
    public function sucursalActivaId(): ?int
    {
        if (! $this->perteneceAEmpresa()) {
            return null;
        }

        if (! $this->veTodasLasSucursales()) {
            return $this->sucursal_id;
        }

        $id = session(self::SESION_SUCURSAL);

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
     * Relee el usuario y olvida sus permisos leídos en este proceso.
     *
     * @return $this
     */
    public function refresh()
    {
        app(PermisosUsuario::class)->olvidar($this->id);

        return parent::refresh();
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
