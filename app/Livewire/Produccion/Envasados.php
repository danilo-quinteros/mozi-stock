<?php

namespace App\Livewire\Produccion;

use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Envasado;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Producción → Envasado (ESPECIFICACION_PRODUCCION.md § 7): historial de envasados, del más nuevo al más antiguo, con
 * búsqueda por número (ENV-00012 o 12) o por granel. Solo el administrador con la capacidad (ruta + gate
 * usar-produccion). El scope de empresa filtra los envasados y la búsqueda del granel.
 */
#[Title('Envasado')]
class Envasados extends Component
{
    use MuestraAvisos, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public function mount(): void
    {
        $this->authorize('usar-produccion');

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $texto = trim($this->buscar);
        $numero = preg_match('/^(?:ENV-?)?0*(\d{1,9})$/i', $texto, $partes) === 1 ? (int) $partes[1] : null;
        $parecido = '%'.addcslashes($texto, '%_\\').'%';

        $envasados = Envasado::query()
            ->with([
                'granel' => fn ($q) => $q->select(['id', 'empresa_id', 'nombre', 'contenido', 'sku', 'unidad_medida_id'])->with('unidad:id,abreviatura'),
                'sucursal:id,nombre', 'lote:id,numero,vencimiento,es_regularizacion',
                'items' => fn ($q) => $q->select(['id', 'envasado_id', 'producto_id', 'orden', 'unidades'])
                    ->with(['producto' => fn ($p) => $p->select(['id', 'empresa_id', 'nombre', 'contenido', 'unidad_medida_id'])->with('unidad:id,abreviatura')]),
            ])
            ->when($texto !== '', fn ($q) => $q->where(fn ($w) => $w
                ->when($numero !== null, fn ($n) => $n->orWhere('numero', $numero))
                ->orWhereHas('granel', fn ($g) => $g->where('nombre', 'like', $parecido)->orWhere('sku', 'like', $parecido))))
            ->orderByDesc('id')
            ->paginate(30);

        return view('livewire.produccion.envasados', ['envasados' => $envasados]);
    }
}
