<?php

namespace App\Livewire\Empresa;

use App\Domain\Identidad\Actions\GuardarRol;
use App\Enums\Permiso;
use App\Livewire\Concerns\EligePermisos;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Formulario de rol (ESPECIFICACION_PERMISOS.md § 8): página aparte por ser largo. Solo el administrador
 * (RolPolicy). Un rol de otra empresa responde 404 por el EmpresaScope del enlace de ruta.
 */
#[Title('Rol')]
class RolFormulario extends Component
{
    use EligePermisos;

    #[Locked]
    public ?int $rolId = null;

    public string $nombre = '';

    public string $descripcion = '';

    /** @var array<int, string> Livewire puede devolver las casillas con índices sueltos. */
    public array $permisos = [];

    #[Locked]
    public int $usuarios = 0;

    public ?string $aviso = null;

    public function mount(?Rol $rol = null): void
    {
        if ($rol === null || ! $rol->exists) {
            $this->authorize('create', Rol::class);

            return;
        }

        $this->authorize('update', $rol);
        $this->rolId = $rol->id;
        $this->nombre = $rol->nombre;
        $this->descripcion = (string) ($rol->descripcion ?? '');
        $this->permisos = array_map(fn (Permiso $p): string => $p->value, $rol->clavesPermisos());
        $this->usuarios = $rol->usuarios()->count();

        if (is_string($mensaje = session('aviso'))) {
            $this->aviso = $mensaje;
        }
    }

    /** Los requeridos se marcan solos (§ 4.4). */
    public function updatedPermisos(): void
    {
        $this->permisos = array_map(fn (Permiso $p): string => $p->value, self::conSusRequeridos($this->permisos));
    }

    public function guardar(GuardarRol $accion): void
    {
        $this->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:40'],
            'descripcion' => ['nullable', 'string', 'max:160'],
            'permisos' => ['array'],
            'permisos.*' => ['string', Rule::in(Permiso::valores())],
        ], [
            'permisos.*.in' => __('Hay un permiso que no existe.'),
        ], [
            'nombre' => __('nombre'),
            'descripcion' => __('descripción'),
        ]);

        // La acción vuelve a validar el nombre y cada clave, y agrega los requeridos.
        $datos = ['nombre' => $this->nombre, 'descripcion' => $this->descripcion, 'permisos' => array_values($this->permisos)];

        if ($this->rolId === null) {
            $rol = $accion->crear($this->actor(), $datos);
            session()->flash('aviso', __('Rol «:nombre» creado. Ya puedes asignarlo a tus usuarios.', ['nombre' => $rol->nombre]));
        } else {
            $rol = $accion->actualizar($this->actor(), $this->rolDeEmpresa(), $datos);
            session()->flash('aviso', __('Rol «:nombre» actualizado. Sus usuarios ya tienen los permisos nuevos.', ['nombre' => $rol->nombre]));
        }

        $this->redirectRoute('usuarios.index', ['pestana' => 'roles'], navigate: false);
    }

    public function render(): View
    {
        $empresaId = (int) $this->actor()->empresa_id;

        return view('livewire.empresa.rol-formulario', [
            'grupos' => self::gruposVisibles($empresaId),
            'ocultos' => self::ocultosPorCapacidad($empresaId),
            'requeridos' => self::requeridosPor(self::conSusRequeridos($this->permisos)),
        ])->title($this->rolId === null ? __('Nuevo rol') : __('Editar rol'));
    }

    private function rolDeEmpresa(): Rol
    {
        return Rol::query()->where('empresa_id', $this->actor()->empresa_id)->findOrFail($this->rolId);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
