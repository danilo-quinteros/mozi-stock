<?php

namespace App\Livewire\Facturacion;

use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Facturacion\ErrorFacturacion;
use App\Domain\Facturacion\SincronizarCatalogos;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Facturacion\Concerns\EmpresaDeFacturacion;
use App\Models\SiatCatalogo;
use App\Models\SiatSincronizacion;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use MoziStudios\Siat\Excepciones\SiatException;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Catálogos del SIN guardados para la empresa. Se sincronizan cada día a las 03:30 y aquí a mano.
 */
class Catalogos extends Component
{
    use EmpresaDeFacturacion, MuestraAvisos;

    private const LIMITE_VISOR = 100;

    public string $viendo = '';

    public string $buscar = '';

    #[Locked]
    public ?string $errorSin = null;

    public function mount(int $empresaId): void
    {
        $this->empresaId = $empresaId;
        $this->empresa();
    }

    public function sincronizar(SincronizarCatalogos $sincronizar): void
    {
        $empresa = $this->empresa();
        $this->errorSin = null;
        // Son 17 consultas al SIN seguidas; con el SIN lento pueden superar el límite por defecto.
        set_time_limit(300);

        try {
            $resultado = $sincronizar->ejecutar($empresa);
        } catch (ErrorFacturacion|SiatException $error) {
            $this->errorSin = $error->getMessage();

            return;
        }

        if ($resultado['fallidos'] === []) {
            $this->avisar(__('Catálogos sincronizados: :cantidad.', ['cantidad' => $resultado['correctos']]));
        } else {
            $this->errorSin = __('No se sincronizaron: :catalogos. Se conserva su versión anterior.', ['catalogos' => implode(', ', $resultado['fallidos'])]);
        }
    }

    public function ver(string $catalogo): void
    {
        $this->viendo = self::catalogo($catalogo)->name ?? '';
        $this->buscar = '';
    }

    public function cerrarVisor(): void
    {
        $this->reset('viendo', 'buscar');
    }

    public function render(): View
    {
        $empresa = $this->empresa();
        $visto = self::catalogo($this->viendo);
        $items = collect();
        $total = 0;

        if ($visto !== null) {
            $termino = trim($this->buscar);
            $consulta = DeEmpresa::consulta(SiatCatalogo::class, $empresa->id)
                ->where('catalogo', $visto->name)
                ->when($termino !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('codigo', 'like', addcslashes($termino, '\\%_').'%')
                    ->orWhere('descripcion', 'like', '%'.addcslashes($termino, '\\%_').'%')));
            $total = (clone $consulta)->count();
            $items = $consulta->orderByRaw('CAST(codigo AS UNSIGNED)')->orderBy('codigo')->limit(self::LIMITE_VISOR)->get(['codigo', 'descripcion']);
        }

        return view('livewire.facturacion.catalogos', [
            'catalogos' => Catalogo::porEmpresa(),
            'estados' => DeEmpresa::consulta(SiatSincronizacion::class, $empresa->id)->get()->keyBy('catalogo'),
            'visto' => $visto,
            'items' => $items,
            'total' => $total,
            'limite' => self::LIMITE_VISOR,
        ]);
    }

    private static function catalogo(string $nombre): ?Catalogo
    {
        foreach (Catalogo::cases() as $catalogo) {
            if ($catalogo->name === $nombre) {
                return $catalogo;
            }
        }

        return null;
    }
}
