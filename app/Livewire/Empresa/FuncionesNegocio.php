<?php

namespace App\Livewire\Empresa;

use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Configuración → Funciones del negocio (ESPECIFICACION_RUBROS_Y_CAPACIDADES.md § 4). Solo el
 * administrador de la empresa. Elegir un rubro marca sus casillas; antes de guardar se muestra un
 * resumen de lo que se activa y se desactiva. Toda la regla vive en CambiarFuncionesEmpresa.
 */
class FuncionesNegocio extends Component
{
    use MuestraAvisos;

    public string $rubro = '';

    /** @var list<string> */
    public array $capacidades = [];

    /** «Avisar pedidos con demora a partir de N minutos» (cocina § 4.4). Solo se guarda con cocina marcada. */
    public string $minutosAlertaCocina = '15';

    /** Lo escrito para apagar «Lotes y vencimientos» con productos marcados (rubros § 5.2). */
    public string $confirmacionLotes = '';

    /** Resumen abierto. Solo lo cambia el servidor: el guardado exige haber pasado por revisar(). */
    #[Locked]
    public bool $revisando = false;

    public function mount(): void
    {
        $empresa = $this->empresa();
        $this->authorize('cambiarFunciones', $empresa);
        $this->cargar($empresa);
    }

    /** Cambiar el rubro marca exactamente las casillas de ese rubro. */
    public function updatedRubro(): void
    {
        $rubro = Rubro::tryFrom($this->rubro);

        if ($rubro !== null) {
            $this->capacidades = $this->valores($rubro->capacidades());
        }

        $this->resetValidation();
    }

    public function updatedCapacidades(): void
    {
        $this->resetValidation('capacidades');
    }

    public function revisar(CambiarFuncionesEmpresa $accion): void
    {
        $this->authorize('cambiarFunciones', $this->empresa());
        [$rubro, $capacidades, $minutos] = $this->validados();
        $accion->validarCompatibles($capacidades);

        $empresa = $this->empresa();

        if ($empresa->rubro === $rubro && $this->valores($empresa->capacidadesActivas()) === $this->valores($capacidades)
            && ($minutos === null || $minutos === $empresa->minutos_alerta_cocina)) {
            $this->avisar(__('No hay cambios que guardar.'), 'info');

            return;
        }

        $this->confirmacionLotes = '';
        $this->revisando = true;
    }

    public function cancelar(): void
    {
        $this->revisando = false;
        $this->confirmacionLotes = '';
    }

    public function guardar(CambiarFuncionesEmpresa $accion): void
    {
        if (! $this->revisando) {
            return;
        }

        [$rubro, $capacidades, $minutos] = $this->validados();
        $empresa = $this->empresa();

        // Rubros § 5.2: la palabra se pide en el mismo resumen, que queda abierto hasta escribirla bien. La acción
        // lo vuelve a comprobar en su transacción.
        $productosConLote = $this->apagaLotes($empresa, $capacidades) ? $accion->productosConLote($empresa->id) : 0;

        if ($productosConLote > 0 && mb_strtoupper(trim($this->confirmacionLotes)) !== CambiarFuncionesEmpresa::CONFIRMAR_LOTES) {
            $this->addError('confirmacionLotes', __('Escribe :palabra para continuar.', ['palabra' => CambiarFuncionesEmpresa::CONFIRMAR_LOTES]));

            return;
        }

        // Si una regla de apagado lo rechaza (por ejemplo, despachos con ventas sin entregar), se cierra
        // el resumen para que el motivo se vea debajo de las funciones.
        $this->revisando = false;
        $cambio = $accion->ejecutar($this->actor(), $empresa, $rubro, $capacidades, $minutos, $this->confirmacionLotes);
        $this->confirmacionLotes = '';

        $this->cargar($this->empresa());
        $this->avisar($cambio ? __('Funciones del negocio guardadas.') : __('Las funciones ya estaban así; no se cambió nada.'));
    }

    public function render(): View
    {
        $empresa = $this->empresa();
        $actuales = $this->valores($empresa->capacidadesActivas());
        $marcadas = array_values(array_filter(Capacidad::cases(), fn (Capacidad $c): bool => in_array($c->value, $this->capacidades, true)));
        $productosConLote = $this->revisando && $this->apagaLotes($empresa, $marcadas) ? app(CambiarFuncionesEmpresa::class)->productosConLote($empresa->id) : 0;

        return view('livewire.empresa.funciones-negocio', [
            'rubros' => Rubro::cases(),
            'funciones' => Capacidad::cases(),
            'bloqueadas' => $this->bloqueadas($marcadas),
            'rubroActual' => $empresa->rubro,
            'minutosActuales' => $empresa->minutos_alerta_cocina,
            'activan' => array_values(array_filter($marcadas, fn (Capacidad $c): bool => ! in_array($c->value, $actuales, true))),
            'desactivan' => array_values(array_filter(Capacidad::cases(), fn (Capacidad $c): bool => in_array($c->value, $actuales, true) && ! in_array($c->value, $this->capacidades, true))),
            'productosConLote' => $productosConLote,
            'mensajeLotes' => $productosConLote > 0 ? CambiarFuncionesEmpresa::mensajeLotes($productosConLote) : null,
            'palabraLotes' => CambiarFuncionesEmpresa::CONFIRMAR_LOTES,
        ]);
    }

    /**
     * @param  list<Capacidad>  $capacidades  las que quedan encendidas
     */
    private function apagaLotes(Empresa $empresa, array $capacidades): bool
    {
        return $empresa->tieneCapacidad(Capacidad::LotesVencimientos) && ! in_array(Capacidad::LotesVencimientos, $capacidades, true);
    }

    /**
     * Casillas que no se pueden marcar porque ya está marcada otra incompatible (cocina ↔ despachos).
     * Si por algún motivo llegan las dos marcadas, ninguna se bloquea para poder desmarcar una.
     *
     * @param  list<Capacidad>  $marcadas
     * @return array<string, Capacidad> casilla bloqueada => la marcada que lo impide
     */
    private function bloqueadas(array $marcadas): array
    {
        $bloqueadas = [];

        foreach ($marcadas as $marcada) {
            foreach ($marcada->incompatibles() as $otra) {
                if (! in_array($otra, $marcadas, true)) {
                    $bloqueadas[$otra->value] = $marcada;
                }
            }
        }

        return $bloqueadas;
    }

    private function cargar(Empresa $empresa): void
    {
        $this->rubro = $empresa->rubro->value;
        $this->capacidades = $this->valores($empresa->capacidadesActivas());
        $this->minutosAlertaCocina = (string) $empresa->minutos_alerta_cocina;
    }

    /**
     * @return array{0: Rubro, 1: list<Capacidad>, 2: int|null} el límite de demora solo con cocina marcada
     */
    private function validados(): array
    {
        $conCocina = in_array(Capacidad::Cocina->value, $this->capacidades, true);
        $limites = ['min' => CambiarFuncionesEmpresa::MINUTOS_ALERTA_MIN, 'max' => CambiarFuncionesEmpresa::MINUTOS_ALERTA_MAX];
        $this->minutosAlertaCocina = trim($this->minutosAlertaCocina);

        $this->validate([
            'rubro' => ['required', 'string', Rule::enum(Rubro::class)],
            'capacidades' => ['array'],
            'capacidades.*' => ['string', 'distinct', Rule::enum(Capacidad::class)],
            'minutosAlertaCocina' => $conCocina
                ? ['required', 'integer', 'between:'.$limites['min'].','.$limites['max']]
                : ['nullable'],
        ], [
            'rubro.required' => __('Elige qué tipo de negocio tienes.'),
            'rubro.enum' => __('Elige qué tipo de negocio tienes.'),
            'capacidades.*.enum' => __('Elige solo funciones de la lista.'),
            'capacidades.*.distinct' => __('Elige solo funciones de la lista.'),
            'capacidades.*.string' => __('Elige solo funciones de la lista.'),
            'minutosAlertaCocina.required' => __('Escribe los minutos, entre :min y :max.', $limites),
            'minutosAlertaCocina.integer' => __('Escribe los minutos, entre :min y :max.', $limites),
            'minutosAlertaCocina.between' => __('Escribe los minutos, entre :min y :max.', $limites),
        ]);

        return [
            Rubro::from($this->rubro),
            array_values(array_filter(Capacidad::cases(), fn (Capacidad $c): bool => in_array($c->value, $this->capacidades, true))),
            $conCocina ? (int) $this->minutosAlertaCocina : null,
        ];
    }

    /**
     * @param  list<Capacidad>  $capacidades
     * @return list<string>
     */
    private function valores(array $capacidades): array
    {
        return array_map(fn (Capacidad $c): string => $c->value, $capacidades);
    }

    /**
     * Siempre la empresa del usuario autenticado; no se recibe ningún ID del navegador.
     */
    private function empresa(): Empresa
    {
        return Empresa::query()->findOrFail($this->actor()->empresa_id);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
