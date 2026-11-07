<?php

namespace App\Livewire\Egresos;

use App\Domain\Egresos\Actions\GuardarCategoriaEgreso;
use App\Domain\Egresos\Actions\RegistrarEgreso;
use App\Domain\Egresos\Consultas\ListadoEgresos;
use App\Domain\Reportes\Periodo;
use App\Livewire\Concerns\EligeCaja;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Concerns\UsaSucursalActiva;
use App\Models\CategoriaEgreso;
use App\Models\Egreso;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Egresos operativos y sus categorías (egresos.html V3 y Cuentas por pagar § 12). Un egreso
 * pagado es una salida real con la fecha y hora actuales; con saldo (pendiente o parcial) solo
 * sale lo pagado y el resto abre su cuenta por pagar. Las compras se registran en Compras.
 */
#[Title('Egresos')]
class Panel extends Component
{
    use EligeCaja, MuestraAvisos, UsaSucursalActiva, WithPagination;

    #[Url(except: 'movimientos')]
    public string $pestana = 'movimientos';

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: '')]
    public string $sucursal = '';

    /** Egreso a abrir desde un enlace (p. ej. el origen de una cuenta por pagar). */
    #[Url(as: 'egreso', except: '')]
    public string $abrir = '';

    public bool $formEgreso = false;

    #[Locked]
    public string $clave = '';

    public ?string $categoria_id = null;

    public string $descripcion = '';

    public ?string $sucursal_id = null;

    public ?string $proveedor_id = null;

    public string $condicion = 'pagado';

    public string $pagado = '';

    public string $vencimiento = '';

    public string $metodo = 'efectivo';

    public string $monto = '';

    public string $efectivo = '';

    public string $electronico = '';

    #[Locked]
    public ?int $viendoId = null;

    public bool $formCategoria = false;

    #[Locked]
    public ?int $categoriaId = null;

    public string $categoriaNombre = '';

    public string $categoriaDescripcion = '';

    public bool $categoriaActiva = true;

    public function mount(): void
    {
        $this->authorize('viewAny', Egreso::class);
        $this->aplicarSucursalActiva();

        if (! in_array($this->pestana, ['movimientos', 'categorias'], true)) {
            $this->pestana = 'movimientos';
        }

        if (ctype_digit($this->abrir)) {
            $this->ver((int) $this->abrir);
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'sucursal', 'pestana'], true)) {
            $this->resetPage();
        }
    }

    public function nuevoEgreso(): void
    {
        $this->authorize('create', Egreso::class);
        $this->resetValidation();
        $this->reset('categoria_id', 'descripcion', 'proveedor_id', 'condicion', 'pagado', 'vencimiento', 'metodo', 'monto', 'efectivo', 'electronico');
        $this->clave = (string) Str::uuid();
        $primera = $this->actor()->sucursalActivaId() ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
        $this->sucursal_id = $primera === null ? null : (string) $primera;
        $this->updatedSucursalId();
        $this->formEgreso = true;
    }

    public function registrarEgreso(RegistrarEgreso $accion): void
    {
        $this->resetValidation();
        $egreso = $accion->ejecutar($this->actor(), $this->datosCaja() + [
            'sucursal_id' => blank($this->sucursal_id) ? null : (int) $this->sucursal_id,
            'categoria_id' => blank($this->categoria_id) ? null : (int) $this->categoria_id,
            'descripcion' => $this->descripcion,
            'proveedor_id' => blank($this->proveedor_id) ? null : (int) $this->proveedor_id,
            'condicion' => $this->condicion,
            'pagado' => $this->pagado,
            'vencimiento' => $this->vencimiento === '' ? null : $this->vencimiento,
            'metodo' => $this->condicion === 'pendiente' ? null : $this->metodo,
            'monto' => $this->monto,
            'efectivo' => $this->efectivo,
            'electronico' => $this->electronico,
            'clave' => $this->clave,
        ]);

        $this->formEgreso = false;
        $this->resetPage();
        $this->avisar($egreso->condicion === 'pagado'
            ? __(':codigo registrado: salida de :importe.', ['codigo' => $egreso->codigo(), 'importe' => Decimal::bs($egreso->monto)])
            : __(':codigo registrado: pagado :pagado y :saldo en cuentas por pagar.', ['codigo' => $egreso->codigo(),
                'pagado' => Decimal::bs($egreso->pagado), 'saldo' => Decimal::bs(Decimal::restar($egreso->monto, $egreso->pagado, 2))]));
    }

    public function ver(int $id): void
    {
        // El scope de empresa responde 404 para egresos de otra empresa.
        $this->viendoId = Egreso::query()->findOrFail($id)->id;
    }

    public function nuevaCategoria(): void
    {
        $this->authorize('create', CategoriaEgreso::class);
        $this->resetValidation();
        $this->reset('categoriaId', 'categoriaNombre', 'categoriaDescripcion', 'categoriaActiva');
        $this->formCategoria = true;
    }

    public function editarCategoria(int $id): void
    {
        $categoria = CategoriaEgreso::query()->findOrFail($id);
        $this->authorize('update', $categoria);
        $this->resetValidation();
        $this->categoriaId = $categoria->id;
        $this->categoriaNombre = $categoria->nombre;
        $this->categoriaDescripcion = (string) $categoria->descripcion;
        $this->categoriaActiva = $categoria->activa;
        $this->formCategoria = true;
    }

    public function guardarCategoria(GuardarCategoriaEgreso $accion): void
    {
        $this->validate([
            'categoriaNombre' => ['required', 'string', 'max:80',
                Rule::unique('categorias_egreso', 'nombre')->where('empresa_id', $this->actor()->empresa_id)->ignore($this->categoriaId)],
            'categoriaDescripcion' => ['nullable', 'string', 'max:150'],
        ], [], ['categoriaNombre' => __('nombre'), 'categoriaDescripcion' => __('descripción')]);

        if ($this->categoriaId === null) {
            $accion->crear($this->actor(), $this->categoriaNombre, $this->categoriaDescripcion);
            $this->avisar(__('Categoría registrada.'));
        } else {
            $accion->actualizar($this->actor(), CategoriaEgreso::query()->findOrFail($this->categoriaId),
                $this->categoriaNombre, $this->categoriaDescripcion, $this->categoriaActiva);
            $this->avisar(__('Categoría actualizada.'));
        }

        $this->formCategoria = false;
    }

    public function cerrar(): void
    {
        $this->formEgreso = false;
        $this->formCategoria = false;
        $this->viendoId = null;
        $this->resetValidation();
    }

    public function render(): View
    {
        $empresaId = (int) $this->actor()->empresa_id;
        $categorias = CategoriaEgreso::query()->withCount('egresos')->orderByDesc('activa')->orderBy('nombre')->get();

        return view('livewire.egresos.panel', [
            'egresos' => ListadoEgresos::consulta($empresaId, $this->buscar, ctype_digit($this->sucursal) ? (int) $this->sucursal : null)->paginate(25),
            'delMes' => ListadoEgresos::total($empresaId, Periodo::mesActual()),
            'deHoy' => ListadoEgresos::total($empresaId, Periodo::hoy()),
            'categorias' => $categorias,
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'activa']),
            'proveedores' => $this->formEgreso ? Proveedor::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'viendo' => $this->viendoId === null ? null
                : Egreso::query()->with(['categoria:id,nombre', 'sucursal:id,nombre', 'usuario:id,name', 'proveedor:id,nombre', 'cuentaPagar'])->find($this->viendoId),
            'mixto' => Decimal::sumar(
                Decimal::normalizar($this->efectivo === '' ? '0' : $this->efectivo, 2) ?? '0',
                Decimal::normalizar($this->electronico === '' ? '0' : $this->electronico, 2) ?? '0',
                Decimal::ESCALA_DINERO,
            ),
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
