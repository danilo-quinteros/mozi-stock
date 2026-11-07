<?php

namespace App\Livewire\Empresa;

use App\Domain\Caja\Actions\GuardarCaja;
use App\Domain\Identidad\Actions\GuardarUsuarioEmpresa;
use App\Domain\Tenancy\Actions\GuardarSucursal;
use App\Enums\Rol;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Caja;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sucursales y cajas (propuesta A aprobada el 01/11/2026): cada sucursal con sus cajas, su estado
 * y sus vendedores. Las cajas se crean y editan aquí y los vendedores se asignan desde cada caja
 * (§ 6.3); el historial de turnos queda en Caja y arqueo. Las acciones validan empresa, sucursal
 * y turnos abiertos en el servidor.
 */
#[Title('Sucursales y cajas')]
class Sucursales extends Component
{
    use MuestraAvisos;

    /** Solo el formulario de la sucursal principal, dentro del popup de la guía de configuración del Inicio. */
    #[Locked]
    public bool $embebido = false;

    public bool $formularioAbierto = false;

    #[Locked]
    public ?int $editandoId = null;

    #[Locked]
    public ?int $confirmandoEstadoId = null;

    public string $nombre = '';

    public string $codigo = '';

    public string $direccion = '';

    /** Sucursal de la caja que se crea o edita. */
    #[Locked]
    public ?int $cajaSucursalId = null;

    #[Locked]
    public ?int $editandoCajaId = null;

    public bool $formularioCajaAbierto = false;

    public string $nombreCaja = '';

    public string $codigoCaja = '';

    public string $modalidad = 'compartida';

    #[Locked]
    public ?int $confirmandoEstadoCajaId = null;

    /** Caja cuyos vendedores se están eligiendo. */
    #[Locked]
    public ?int $vendedoresCajaId = null;

    /** @var list<int|string> vendedores marcados para pasar a esa caja */
    public array $seleccion = [];

    /** Vendedor sin caja al que se le elige una. */
    #[Locked]
    public ?int $asignandoVendedorId = null;

    public string $cajaElegida = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Sucursal::class);

        if ($this->embebido) {
            $principal = Sucursal::query()->where('activa', true)->orderBy('id')->first();
            $principal === null ? $this->crear() : $this->editar($principal->id);
        }
    }

    public function crear(): void
    {
        $this->authorize('create', Sucursal::class);
        $this->resetValidation();
        $this->reset('editandoId', 'nombre', 'codigo', 'direccion');
        $this->formularioAbierto = true;
    }

    public function editar(int $id): void
    {
        // El scope de empresa responde 404 para sucursales de otra empresa.
        $sucursal = Sucursal::query()->findOrFail($id);
        $this->authorize('update', $sucursal);
        $this->resetValidation();
        $this->editandoId = $sucursal->id;
        $this->nombre = $sucursal->nombre;
        $this->codigo = $sucursal->codigo;
        $this->direccion = (string) $sucursal->direccion;
        $this->formularioAbierto = true;
    }

    public function guardar(GuardarSucursal $accion): void
    {
        $actor = $this->actor();
        $datos = $this->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'codigo' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('sucursales', 'codigo')->where('empresa_id', $actor->empresa_id)->ignore($this->editandoId)],
            'direccion' => ['nullable', 'string', 'max:255'],
        ], [
            'codigo.regex' => __('Usa solo letras, números y guiones.'),
            'codigo.unique' => __('Ya existe una sucursal con ese código en tu empresa.'),
        ]);

        if ($this->editandoId === null) {
            $sucursal = $accion->crear($actor, $datos);
            $this->avisar(__('Sucursal registrada con su Caja principal.'));
        } else {
            $sucursal = $accion->actualizar($actor, Sucursal::query()->findOrFail($this->editandoId), $datos);
            $this->avisar(__('Sucursal actualizada.'));
        }

        if ($this->embebido) {
            // El popup sigue abierto con la sucursal guardada; lo cierra la guía.
            $this->editandoId = $sucursal->id;

            return;
        }

        $this->cerrarFormulario();
    }

    public function cerrarFormulario(): void
    {
        $this->formularioAbierto = false;
        $this->reset('editandoId', 'nombre', 'codigo', 'direccion');
        $this->resetValidation();
    }

    public function confirmarEstado(int $id): void
    {
        $sucursal = Sucursal::query()->findOrFail($id);
        $this->authorize('update', $sucursal);
        $this->cerrarFormulario();
        $this->confirmandoEstadoId = $sucursal->id;
    }

    public function cambiarEstado(GuardarSucursal $accion): void
    {
        if ($this->confirmandoEstadoId === null) {
            return;
        }

        $actual = Sucursal::query()->findOrFail($this->confirmandoEstadoId);
        $sucursal = $accion->cambiarEstado($this->actor(), $actual, ! $actual->activa);
        $this->confirmandoEstadoId = null;
        $this->avisar($sucursal->activa ? __('Sucursal activada.') : __('Sucursal desactivada.'));
    }

    public function cancelarEstado(): void
    {
        $this->confirmandoEstadoId = null;
        $this->resetValidation();
    }

    public function crearCaja(int $sucursalId): void
    {
        $sucursal = Sucursal::query()->where('activa', true)->findOrFail($sucursalId);
        $this->authorize('update', $sucursal);
        $this->resetValidation();
        $this->reset('editandoCajaId', 'nombreCaja', 'codigoCaja');
        $this->cajaSucursalId = $sucursal->id;
        $this->modalidad = 'compartida';
        $this->formularioCajaAbierto = true;
    }

    public function editarCaja(int $cajaId): void
    {
        $caja = $this->cajaDeLaEmpresa($cajaId);
        $this->authorize('update', $caja->sucursal);
        $this->resetValidation();
        $this->cajaSucursalId = $caja->sucursal_id;
        $this->editandoCajaId = $caja->id;
        $this->nombreCaja = $caja->nombre;
        $this->codigoCaja = (string) $caja->codigo;
        $this->modalidad = $caja->modalidad;
        $this->formularioCajaAbierto = true;
    }

    public function cerrarFormularioCaja(): void
    {
        $this->formularioCajaAbierto = false;
        $this->reset('cajaSucursalId', 'editandoCajaId', 'nombreCaja', 'codigoCaja');
        $this->modalidad = 'compartida';
        $this->resetValidation();
    }

    public function guardarCaja(GuardarCaja $accion): void
    {
        if ($this->cajaSucursalId === null) {
            return;
        }

        $actor = $this->actor();
        $datos = $this->validate([
            'nombreCaja' => ['required', 'string', 'max:80'],
            'codigoCaja' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('cajas', 'codigo')->where('empresa_id', $actor->empresa_id)
                    ->where('sucursal_id', $this->cajaSucursalId)->ignore($this->editandoCajaId)],
            'modalidad' => ['required', 'in:compartida,individual'],
        ], [
            'codigoCaja.regex' => __('Usa solo letras, números y guiones en el código.'),
            'codigoCaja.unique' => __('Ya existe una caja con ese código en esta sucursal.'),
        ], ['nombreCaja' => __('nombre'), 'codigoCaja' => __('código')]);
        $campos = ['nombre' => $datos['nombreCaja'], 'codigo' => $datos['codigoCaja'] ?? null, 'modalidad' => $datos['modalidad']];

        if ($this->editandoCajaId === null) {
            $caja = $accion->crear($actor, $campos + ['sucursal_id' => $this->cajaSucursalId]);
            $this->avisar(__(':caja creada. Asígnale vendedores con «Vendedores».', ['caja' => $caja->nombre]));
        } else {
            $accion->actualizar($actor, $this->cajaDeLaEmpresa($this->editandoCajaId), $campos);
            $this->avisar(__('Caja actualizada.'));
        }

        $this->cerrarFormularioCaja();
    }

    public function confirmarEstadoCaja(int $cajaId): void
    {
        $caja = $this->cajaDeLaEmpresa($cajaId);
        $this->authorize('update', $caja->sucursal);
        $this->cerrarFormularioCaja();
        $this->confirmandoEstadoCajaId = $caja->id;
    }

    public function cancelarEstadoCaja(): void
    {
        $this->confirmandoEstadoCajaId = null;
        $this->resetValidation();
    }

    public function cambiarEstadoCaja(GuardarCaja $accion): void
    {
        if ($this->confirmandoEstadoCajaId === null) {
            return;
        }

        $actual = $this->cajaDeLaEmpresa($this->confirmandoEstadoCajaId);
        $caja = $accion->cambiarEstado($this->actor(), $actual, ! $actual->activa);
        $this->confirmandoEstadoCajaId = null;
        $this->avisar($caja->activa ? __('Caja activada.') : __('Caja desactivada.'));
    }

    public function elegirVendedores(int $cajaId): void
    {
        $caja = $this->cajaDeLaEmpresa($cajaId);
        $this->authorize('update', $caja->sucursal);
        $this->resetValidation();
        $this->vendedoresCajaId = $caja->id;
        $this->seleccion = [];
    }

    public function cerrarVendedores(): void
    {
        $this->reset('vendedoresCajaId', 'seleccion');
        $this->resetValidation();
    }

    public function guardarVendedores(GuardarUsuarioEmpresa $accion): void
    {
        if ($this->vendedoresCajaId === null) {
            return;
        }

        $caja = $this->cajaDeLaEmpresa($this->vendedoresCajaId);
        $ids = array_values(array_filter(array_map('intval', $this->seleccion)));
        $movidos = $ids === [] ? 0 : $accion->asignarCaja($this->actor(), $caja->id, $ids);
        $this->cerrarVendedores();
        $this->avisar($movidos === 0 ? __('Sin cambios.') : trans_choice(':n vendedor pasa a :caja.|:n vendedores pasan a :caja.', $movidos, ['n' => $movidos, 'caja' => $caja->nombre]));
    }

    public function asignar(int $vendedorId): void
    {
        $vendedor = User::query()->where('empresa_id', $this->actor()->empresa_id)
            ->where('rol', Rol::Vendedor)->findOrFail($vendedorId);
        $this->authorize('update', $vendedor);
        $this->resetValidation();
        $this->asignandoVendedorId = $vendedor->id;
        $this->cajaElegida = (string) (Caja::withoutGlobalScopes()->where('empresa_id', $vendedor->empresa_id)
            ->where('sucursal_id', $vendedor->sucursal_id)->where('activa', true)
            ->orderByDesc('es_principal')->orderBy('nombre')->value('id') ?? '');
    }

    public function cerrarAsignar(): void
    {
        $this->reset('asignandoVendedorId', 'cajaElegida');
        $this->resetValidation();
    }

    public function guardarAsignacion(GuardarUsuarioEmpresa $accion): void
    {
        if ($this->asignandoVendedorId === null) {
            return;
        }

        if (! ctype_digit($this->cajaElegida)) {
            $this->addError('caja_id', __('Elige una caja.'));

            return;
        }

        $accion->asignarCaja($this->actor(), (int) $this->cajaElegida, [$this->asignandoVendedorId]);
        $vendedor = User::query()->find($this->asignandoVendedorId);
        $this->cerrarAsignar();
        $this->avisar(__(':nombre ya tiene caja asignada.', ['nombre' => $vendedor?->name]));
    }

    public function render(): View
    {
        $actor = $this->actor();
        $sucursales = Sucursal::query()->orderByDesc('activa')->orderBy('nombre')->get();
        $cajas = Caja::withoutGlobalScopes()
            ->where('empresa_id', $actor->empresa_id)
            ->with('turnoActivo.usuarioApertura:id,name')
            ->orderByDesc('activa')->orderByDesc('es_principal')->orderBy('nombre')
            ->get();
        $vendedores = User::query()
            ->where('empresa_id', $actor->empresa_id)
            ->where('rol', Rol::Vendedor)
            ->where('activo', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sucursal_id', 'caja_id']);
        // Turno abierto que abrió cada vendedor: hasta cerrarlo no cambia de caja.
        $abrio = $cajas->pluck('turnoActivo')->filter()->keyBy('usuario_apertura_id');
        $activas = array_values($sucursales->where('activa', true)->map(fn (Sucursal $s): int => $s->id)->all());
        $cajaVendedores = $this->vendedoresCajaId === null ? null : $cajas->firstWhere('id', $this->vendedoresCajaId);
        $asignando = $this->asignandoVendedorId === null ? null : $vendedores->firstWhere('id', $this->asignandoVendedorId);

        return view('livewire.empresa.sucursales', [
            'sucursales' => $sucursales,
            'cajasPorSucursal' => $cajas->groupBy('sucursal_id'),
            'vendedoresPorCaja' => $vendedores->whereNotNull('caja_id')->groupBy('caja_id'),
            'vendedoresPorSucursal' => $vendedores->groupBy('sucursal_id'),
            'sinCaja' => $vendedores->filter(fn (User $v) => $v->caja_id === null && in_array((int) $v->sucursal_id, $activas, true))->values(),
            'abrio' => $abrio,
            'resumen' => $this->resumen($sucursales, $cajas, $vendedores, $activas),
            'confirmando' => $sucursales->firstWhere('id', $this->confirmandoEstadoId),
            'sucursalCaja' => $sucursales->firstWhere('id', $this->cajaSucursalId),
            'cajaEditada' => $cajas->firstWhere('id', $this->editandoCajaId),
            'confirmandoCaja' => $cajas->firstWhere('id', $this->confirmandoEstadoCajaId),
            'cajaVendedores' => $cajaVendedores,
            'asignando' => $asignando,
            'nombresCaja' => $cajas->pluck('nombre', 'id'),
            'cajasDe' => fn (int $sucursalId): Collection => $cajas->where('sucursal_id', $sucursalId)->where('activa', true)->values(),
        ]);
    }

    /**
     * @param  Collection<int, Sucursal>  $sucursales
     * @param  Collection<int, Caja>  $cajas
     * @param  Collection<int, User>  $vendedores
     * @param  list<int>  $activas
     * @return array{sucursales: int, cajas: int, abiertas: int, sin_caja: int}
     */
    private function resumen(Collection $sucursales, Collection $cajas, Collection $vendedores, array $activas): array
    {
        $operables = $cajas->filter(fn (Caja $c) => $c->activa && in_array((int) $c->sucursal_id, $activas, true));

        return [
            'sucursales' => count($activas),
            'cajas' => $operables->count(),
            'abiertas' => $operables->filter(fn (Caja $c) => $c->turnoActivo !== null)->count(),
            'sin_caja' => $vendedores->filter(fn (User $v) => $v->caja_id === null && in_array((int) $v->sucursal_id, $activas, true))->count(),
        ];
    }

    /**
     * Caja de la empresa del administrador; una de otra empresa responde 404.
     */
    private function cajaDeLaEmpresa(int $cajaId): Caja
    {
        return Caja::withoutGlobalScopes()->where('empresa_id', $this->actor()->empresa_id)->findOrFail($cajaId);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
