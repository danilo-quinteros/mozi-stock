<?php

namespace App\Livewire\Empresa;

use App\Domain\Identidad\Actions\EliminarRol;
use App\Domain\Identidad\Actions\GuardarRol;
use App\Domain\Identidad\Actions\GuardarUsuarioEmpresa;
use App\Domain\Suscripciones\CupoSuscripcion;
use App\Enums\Permiso;
use App\Enums\TipoCuenta;
use App\Livewire\Concerns\EligePermisos;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Caja;
use App\Models\Rol;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Gestión → Usuarios (ESPECIFICACION_PERMISOS.md § 8): pestañas Usuarios y Roles. Solo el administrador
 * de la empresa (UserPolicy y RolPolicy); las acciones de dominio vuelven a autorizar y validar todo.
 */
#[Title('Usuarios')]
class Usuarios extends Component
{
    use EligePermisos, MuestraAvisos, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: 'usuarios')]
    public string $pestana = 'usuarios';

    public bool $formularioAbierto = false;

    #[Locked]
    public ?int $editandoId = null;

    public string $name = '';

    public string $email = '';

    /** usuario | administrador */
    public string $tipo = 'usuario';

    public ?string $rol_id = null;

    public ?string $sucursal_id = null;

    public ?string $caja_id = null;

    public string $password = '';

    public string $password_confirmation = '';

    public bool $activo = true;

    /**
     * Permisos extra marcados (claves del catálogo). Los que ya da el rol no se guardan como extra.
     *
     * @var array<int, string>
     */
    public array $permisos_extra = [];

    /** @var array<int, list<Permiso>> permisos de los roles leídos en esta petición */
    private array $permisosDeRoles = [];

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }

        if (! in_array($this->pestana, ['usuarios', 'roles'], true)) {
            $this->pestana = 'usuarios';
        }
    }

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    public function updatedSucursalId(?string $valor): void
    {
        if ($valor !== null && $valor !== '' && ctype_digit($valor)) {
            $cajas = Caja::query()
                ->where('sucursal_id', (int) $valor)
                ->where('activa', true)
                ->orderByDesc('es_principal')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'es_principal']);

            if ($cajas->count() === 1) {
                $this->caja_id = (string) $cajas->first()->id;
            } elseif ($cajas->isNotEmpty() && ! $cajas->contains('id', (int) $this->caja_id)) {
                $this->caja_id = (string) ($cajas->firstWhere('es_principal', true)->id ?? $cajas->first()->id);
            } elseif ($cajas->isEmpty()) {
                $this->caja_id = null;
            }
        } else {
            $this->caja_id = null;
        }
    }

    /** Los requeridos de un extra se marcan solos (§ 4.4); los que ya da el rol no se repiten. */
    public function updatedPermisosExtra(): void
    {
        $this->normalizarExtras();
    }

    public function updatedRolId(): void
    {
        $this->normalizarExtras();
    }

    public function crear(): void
    {
        $this->authorize('create', User::class);
        $this->limpiarFormulario();
        $this->rol_id = $this->rolPorDefecto();
        $this->formularioAbierto = true;
    }

    public function editar(int $id): void
    {
        $usuario = $this->usuarioDeEmpresa($id);
        $this->authorize('update', $usuario);
        $this->limpiarFormulario();
        $this->editandoId = $usuario->id;
        $this->name = $usuario->name;
        $this->email = $usuario->email;
        $this->tipo = $usuario->tipo === TipoCuenta::Administrador ? 'administrador' : 'usuario';
        $this->rol_id = $usuario->rol_id === null ? $this->rolPorDefecto() : (string) $usuario->rol_id;
        $this->sucursal_id = $usuario->sucursal_id === null ? null : (string) $usuario->sucursal_id;
        $this->caja_id = $usuario->caja_id === null ? null : (string) $usuario->caja_id;
        $this->activo = $usuario->activo;
        $this->permisos_extra = $usuario->permisosExtra()->pluck('permiso')->map(fn (mixed $p): string => (string) $p)->values()->all();
        $this->normalizarExtras();
        $this->formularioAbierto = true;
    }

    public function guardar(GuardarUsuarioEmpresa $accion): void
    {
        $actor = $this->actor();
        $esUsuario = $this->tipo === TipoCuenta::Usuario->value;

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editandoId)],
            'tipo' => ['required', Rule::in([TipoCuenta::Usuario->value, TipoCuenta::Administrador->value])],
            'rol_id' => [Rule::requiredIf($esUsuario), 'nullable', 'integer',
                Rule::exists('roles', 'id')->where('empresa_id', $actor->empresa_id)],
            'sucursal_id' => [Rule::requiredIf($esUsuario), 'nullable', 'integer',
                Rule::exists('sucursales', 'id')->where('empresa_id', $actor->empresa_id)->where('activa', true)],
            'caja_id' => [Rule::requiredIf($esUsuario && $this->necesitaCaja()), 'nullable', 'integer',
                Rule::exists('cajas', 'id')->where('empresa_id', $actor->empresa_id)->where('sucursal_id', (int) $this->sucursal_id)->where('activa', true)],
            'password' => [$this->editandoId === null ? 'required' : 'nullable', 'string',
                Password::min(12)->letters()->numbers(), 'confirmed'],
            'activo' => ['boolean'],
            'permisos_extra' => ['array'],
            'permisos_extra.*' => ['string', Rule::in(Permiso::valores())],
        ], [
            'rol_id.required' => __('Elige el rol del usuario.'),
            'rol_id.exists' => __('Elige un rol de tu empresa.'),
            'sucursal_id.required' => __('El usuario necesita una sucursal asignada.'),
            'sucursal_id.exists' => __('Selecciona una sucursal activa de tu empresa.'),
            'caja_id.required' => __('Su rol usa su caja: asígnale una caja.'),
            'caja_id.exists' => __('Selecciona una caja activa de la sucursal elegida.'),
            'permisos_extra.*.in' => __('Hay un permiso que no existe.'),
        ], [
            'name' => __('nombre'),
            'email' => __('correo'),
            'tipo' => __('tipo'),
            'rol_id' => __('rol'),
            'sucursal_id' => __('sucursal'),
            'caja_id' => __('caja'),
            'password' => __('contraseña'),
        ]);

        $datos = [
            'name' => $this->name,
            'email' => $this->email,
            'tipo' => TipoCuenta::from($this->tipo),
            'rol_id' => $esUsuario && filled($this->rol_id) ? (int) $this->rol_id : null,
            'sucursal_id' => $esUsuario && filled($this->sucursal_id) ? (int) $this->sucursal_id : null,
            'caja_id' => $esUsuario && filled($this->caja_id) ? (int) $this->caja_id : null,
            'password' => $this->password,
            'activo' => $this->activo,
            // La acción valida cada clave, agrega los requeridos y descarta los que ya da el rol.
            'permisos_extra' => $esUsuario ? array_values($this->permisos_extra) : [],
        ];

        if ($this->editandoId === null) {
            $accion->crear($actor, $datos);
            $this->avisar(__('Usuario creado. Comparte la contraseña inicial por un canal seguro.'));
        } else {
            $accion->actualizar($actor, $this->usuarioDeEmpresa($this->editandoId), $datos);
            $this->avisar(__('Usuario actualizado.'));
        }

        $this->cerrarFormulario();
    }

    public function cerrarFormulario(): void
    {
        $this->formularioAbierto = false;
        $this->limpiarFormulario();
    }

    /** Copia del rol para partir de ella (§ 6.5); se abre su formulario. */
    public function duplicarRol(int $id, GuardarRol $accion): void
    {
        $copia = $accion->duplicar($this->actor(), $this->rolDeEmpresa($id));
        session()->flash('aviso', __('Rol «:nombre» creado. Ajusta su nombre y sus permisos.', ['nombre' => $copia->nombre]));
        $this->redirectRoute('usuarios.roles.editar', ['rol' => $copia->id], navigate: false);
    }

    public function eliminarRol(int $id, EliminarRol $accion): void
    {
        $rol = $this->rolDeEmpresa($id);
        $accion->ejecutar($this->actor(), $rol);
        $this->avisar(__('Rol «:nombre» eliminado.', ['nombre' => $rol->nombre]));
    }

    public function render(): View
    {
        $actor = $this->actor();
        $empresaId = (int) $actor->empresa_id;
        $conteo = User::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->selectRaw('count(*) as activos')
            ->selectRaw('sum(case when tipo = ? then 1 else 0 end) as administradores', [TipoCuenta::Administrador->value])
            ->selectRaw('sum(case when tipo = ? then 1 else 0 end) as con_rol', [TipoCuenta::Usuario->value])
            ->toBase()
            ->first();

        $roles = Rol::query()
            ->where('empresa_id', $empresaId)
            ->withCount(['usuarios', 'permisos'])
            ->orderBy('nombre')
            ->get();
        $esUsuario = $this->formularioAbierto && $this->tipo === TipoCuenta::Usuario->value;

        return view('livewire.empresa.usuarios', [
            'usuarios' => $this->pestana !== 'usuarios' ? null : User::query()
                ->where('empresa_id', $empresaId)
                ->with(['sucursal:id,nombre', 'caja:id,nombre', 'rol:id,nombre', 'permisosExtra:user_id,permiso'])
                ->buscar(trim($this->buscar))
                ->orderByDesc('activo')
                ->orderBy('name')
                ->paginate(25),
            'conteo' => $conteo,
            'roles' => $roles,
            'administradores' => (int) $conteo->administradores,
            'cupo' => CupoSuscripcion::resumen($empresaId)['usuarios'],
            'sucursales' => $this->formularioAbierto ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'cajasDisponibles' => ($esUsuario && $this->sucursal_id && ctype_digit($this->sucursal_id))
                ? Caja::query()->where('sucursal_id', (int) $this->sucursal_id)->where('activa', true)->orderByDesc('es_principal')->orderBy('nombre')->get(['id', 'nombre', 'es_principal'])
                : collect(),
            'editandoPropiaCuenta' => $this->editandoId === $actor->id,
            'esUsuario' => $esUsuario,
            'necesitaCaja' => $esUsuario && $this->necesitaCaja(),
            'delRol' => $esUsuario ? $this->permisosDelRol() : [],
            'requeridos' => $esUsuario ? self::requeridosPor([...$this->permisosDelRol(), ...self::conSusRequeridos($this->permisos_extra)]) : [],
            'grupos' => $esUsuario ? self::gruposVisibles($empresaId) : [],
            'ocultos' => $esUsuario ? self::ocultosPorCapacidad($empresaId) : [],
        ]);
    }

    /**
     * Los extras marcados con sus requeridos y sin los que ya da el rol elegido.
     */
    private function normalizarExtras(): void
    {
        $delRol = $this->permisosDelRol();
        $marcados = self::conSusRequeridos($this->permisos_extra);

        $this->permisos_extra = array_values(array_map(fn (Permiso $p): string => $p->value,
            array_filter($marcados, fn (Permiso $p): bool => ! in_array($p, $delRol, true))));
    }

    /**
     * Permisos del rol elegido en el formulario (solo roles de la empresa).
     *
     * @return list<Permiso>
     */
    private function permisosDelRol(): array
    {
        if ($this->rol_id === null || ! ctype_digit($this->rol_id)) {
            return [];
        }

        return $this->permisosDeRoles[(int) $this->rol_id] ??= Rol::query()
            ->where('empresa_id', $this->actor()->empresa_id)
            ->with('permisos')
            ->find((int) $this->rol_id)
            ?->clavesPermisos() ?? [];
    }

    /** Quien usa su caja sin supervisar cajas necesita una asignada (§ 6.3). */
    private function necesitaCaja(): bool
    {
        $efectivos = Permiso::conRequeridos([...$this->permisosDelRol(), ...self::conSusRequeridos($this->permisos_extra)]);

        return in_array(Permiso::CajaOperar, $efectivos, true) && ! in_array(Permiso::CajaSupervisar, $efectivos, true);
    }

    /** Rol sugerido para un usuario nuevo: Vendedor si existe, si no el primero. */
    private function rolPorDefecto(): ?string
    {
        /** @var Collection<int, Rol> $roles */
        $roles = Rol::query()->where('empresa_id', $this->actor()->empresa_id)->orderBy('nombre')->get(['id', 'plantilla']);
        $rol = $roles->firstWhere('plantilla', Rol::PLANTILLA_VENDEDOR) ?? $roles->first();

        return $rol === null ? null : (string) $rol->id;
    }

    /**
     * Solo usuarios de la empresa del administrador; cualquier otro ID responde 404.
     */
    private function usuarioDeEmpresa(int $id): User
    {
        return User::query()->where('empresa_id', $this->actor()->empresa_id)->findOrFail($id);
    }

    /** Solo roles de la empresa del administrador; cualquier otro ID responde 404. */
    private function rolDeEmpresa(int $id): Rol
    {
        return Rol::query()->where('empresa_id', $this->actor()->empresa_id)->findOrFail($id);
    }

    private function limpiarFormulario(): void
    {
        $this->reset('editandoId', 'name', 'email', 'tipo', 'rol_id', 'sucursal_id', 'caja_id', 'password', 'password_confirmation', 'activo', 'permisos_extra');
        $this->resetValidation();
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
