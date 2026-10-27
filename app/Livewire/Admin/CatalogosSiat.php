<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\MuestraAvisos;
use App\Models\SiatCatalogo;
use App\Models\SiatSincronizacion;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Catálogos paramétricos globales del SIN (unidades, métodos de pago, monedas, etc.).
 * Son comunes para todas las empresas a nivel nacional.
 */
#[Title('Catálogos globales del SIN')]
class CatalogosSiat extends Component
{
    use MuestraAvisos;

    private const LIMITE_VISOR = 150;

    public string $viendo = '';

    public string $buscar = '';

    public function mount(): void
    {
        /** @var User */
        $user = Auth::user();
        abort_unless($user->esSuperadmin(), 403);
    }

    public function ver(string $catalogo): void
    {
        $this->viendo = self::catalogo($catalogo)?->name ?? '';
        $this->buscar = '';
    }

    public function cerrarVisor(): void
    {
        $this->reset('viendo', 'buscar');
    }

    public function render(): View
    {
        $visto = self::catalogo($this->viendo);
        $items = collect();
        $total = 0;

        if ($visto !== null) {
            $termino = trim($this->buscar);
            $consulta = SiatCatalogo::withoutGlobalScopes()
                ->where('catalogo', $visto->name)
                ->select('codigo', 'descripcion')
                ->distinct()
                ->when($termino !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('codigo', 'like', addcslashes($termino, '\\%_').'%')
                    ->orWhere('descripcion', 'like', '%'.addcslashes($termino, '\\%_').'%')));

            $total = (clone $consulta)->count();
            $items = $consulta->orderByRaw('CAST(codigo AS UNSIGNED)')->orderBy('codigo')->limit(self::LIMITE_VISOR)->get();
        }

        // Conteo de registros por catálogo a nivel global
        $conteos = SiatCatalogo::withoutGlobalScopes()
            ->selectRaw('catalogo, COUNT(DISTINCT codigo) as total')
            ->groupBy('catalogo')
            ->pluck('total', 'catalogo');

        // Última fecha de sincronización de cada catálogo paramétrico
        $sincronizaciones = SiatSincronizacion::withoutGlobalScopes()
            ->selectRaw('catalogo, MAX(sincronizado_en) as ultima')
            ->groupBy('catalogo')
            ->pluck('ultima', 'catalogo');

        return view('livewire.admin.catalogos-siat', [
            'catalogos' => Catalogo::globales(),
            'conteos' => $conteos,
            'sincronizaciones' => $sincronizaciones,
            'visto' => $visto,
            'items' => $items,
            'total' => $total,
            'limite' => self::LIMITE_VISOR,
        ]);
    }

    private static function catalogo(string $nombre): ?Catalogo
    {
        foreach (Catalogo::globales() as $catalogo) {
            if ($catalogo->name === $nombre) {
                return $catalogo;
            }
        }

        return null;
    }
}
