<?php

namespace App\Livewire\Empresa;

use App\Domain\Identidad\Actions\GuardarUsuarioEmpresa;
use App\Enums\Rol;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Caja;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Usuarios')]
class Usuarios extends Component
{
    use MuestraAvisos, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public string $pestana = 'usuarios';

    public bool $formularioAbierto = false;

    #[Locked]
    public ?int $editandoId = null;

    public string $name = '';

    public string $email = '';

    public string $rol = 'vendedor';

    public ?string $sucursal_id = null;

    public ?string $caja_id = null;

    public string $password = '';

    public string $password_confirmation = '';

    public bool $activo = true;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
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

    public function crear(): void
    {
        $this->authorize('create', User::class);
        $this->limpiarFormulario();
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
        $this->rol = $usuario->rol->value ?? Rol::Vendedor->value;
        $this->sucursal_id = $usuario->sucursal_id === null ? null : (string) $usuario->sucursal_id;
        $this->caja_id = $usuario->caja_id === null ? null : (string) $usuario->caja_id;
        $this->activo = $usuario->activo;
        $this->formularioAbierto = true;
    }

    public function guardar(GuardarUsuarioEmpresa $accion): void
    {
        $actor = $this->actor();
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editandoId)],
            'rol' => ['required', Rule::enum(Rol::class)->only(Rol::asignablesPorEmpresa())],
            'sucursal_id' => [Rule::requiredIf($this->rol === Rol::Vendedor->value), 'nullable', 'integer',
                Rule::exists('sucursales', 'id')->where('empresa_id', $actor->empresa_id)->where('activa', true)],
            'caja_id' => [Rule::requiredIf($this->rol === Rol::Vendedor->value), 'nullable', 'integer',
                Rule::exists('cajas', 'id')->where('empresa_id', $actor->empresa_id)->where('sucursal_id', (int) $this->sucursal_id)->where('activa', true)],
            'password' => [$this->editandoId === null ? 'required' : 'nullable', 'string',
                Password::min(12)->letters()->numbers(), 'confirmed'],
            'activo' => ['boolean'],
        ], [
            'sucursal_id.required' => __('El vendedor necesita una sucursal asignada.'),
            'sucursal_id.exists' => __('Selecciona una sucursal activa de tu empresa.'),
            'caja_id.required' => __('El vendedor necesita una caja asignada.'),
            'caja_id.exists' => __('Selecciona una caja activa de la sucursal elegida.'),
        ], [
            'name' => __('nombre'),
            'email' => __('correo'),
            'sucursal_id' => __('sucursal'),
            'caja_id' => __('caja'),
            'password' => __('contraseña'),
        ]);

        $datos = [
            'name' => $this->name,
            'email' => $this->email,
            'rol' => Rol::from($this->rol),
            'sucursal_id' => $this->sucursal_id === null || $this->sucursal_id === '' ? null : (int) $this->sucursal_id,
            'caja_id' => $this->caja_id === null || $this->caja_id === '' ? null : (int) $this->caja_id,
            'password' => $this->password,
            'activo' => $this->activo,
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

    public function render(): View
    {
        $empresaId = $this->actor()->empresa_id;
        $conteo = User::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->selectRaw('count(*) as activos')
            ->selectRaw('sum(case when rol = ? then 1 else 0 end) as administradores', [Rol::Administrador->value])
            ->selectRaw('sum(case when rol = ? then 1 else 0 end) as vendedores', [Rol::Vendedor->value])
            ->toBase()
            ->first();

        return view('livewire.empresa.usuarios', [
            'usuarios' => User::query()
                ->where('empresa_id', $empresaId)
                ->with(['sucursal:id,nombre', 'caja:id,nombre'])
                ->buscar(trim($this->buscar))
                ->orderByDesc('activo')
                ->orderBy('name')
                ->paginate(25),
            'conteo' => $conteo,
            'sucursales' => Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'cajasDisponibles' => ($this->sucursal_id && ctype_digit($this->sucursal_id))
                ? Caja::query()->where('sucursal_id', (int) $this->sucursal_id)->where('activa', true)->orderByDesc('es_principal')->orderBy('nombre')->get(['id', 'nombre', 'es_principal'])
                : collect(),
            'editandoPropiaCuenta' => $this->editandoId === $this->actor()->id,
        ]);
    }

    /**
     * Solo usuarios de la empresa del administrador; cualquier otro ID responde 404.
     */
    private function usuarioDeEmpresa(int $id): User
    {
        return User::query()->where('empresa_id', $this->actor()->empresa_id)->findOrFail($id);
    }

    private function limpiarFormulario(): void
    {
        $this->reset('editandoId', 'name', 'email', 'rol', 'sucursal_id', 'caja_id', 'password', 'password_confirmation', 'activo');
        $this->resetValidation();
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
