<?php

namespace App\Livewire\Produccion;

use App\Domain\Inventario\DatosLote;
use App\Domain\Inventario\LibroLotes;
use App\Domain\Produccion\Actions\RegistrarEnvasado;
use App\Domain\Produccion\Consultas\DisponibilidadMaterias;
use App\Domain\Produccion\Escalado;
use App\Domain\Produccion\Presentaciones;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use stdClass;

/**
 * Nuevo envasado (ESPECIFICACION_PRODUCCION.md § 7.2): sucursal, granel, lote del granel (por defecto el vigente que
 * vence primero), unidades buenas por presentación y lo usado de granel y envases, precargado con lo necesario y
 * editable (lo usado de más es la merma). Lo usado sigue a lo necesario mientras el usuario no lo escriba a mano. La
 * disponibilidad solo informa: toda la regla vive en RegistrarEnvasado.
 */
#[Title('Nuevo envasado')]
class EnvasadoFormulario extends Component
{
    /** Clave de idempotencia de este envasado: un doble clic o reintento devuelve el mismo. */
    #[Locked]
    public string $clave = '';

    public ?string $sucursal_id = null;

    public ?string $granel_id = null;

    public ?string $lote_id = null;

    /** @var array<int|string, string> presentacion_id => unidades buenas */
    public array $unidades = [];

    public string $granel_real = '';

    /** @var array<int|string, string> producto_id del envase => cantidad usada */
    public array $envases = [];

    /** @var array<string, bool> cantidades usadas escritas a mano ('granel', 'envase-{id}'): ya no siguen a lo necesario */
    public array $editados = [];

    public string $nota = '';

    public function mount(): void
    {
        $this->authorize('usar-produccion');
        $this->clave = (string) Str::uuid();
        $sucursal = $this->actor()->sucursalActivaId() ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
        $this->sucursal_id = $sucursal === null ? null : (string) $sucursal;
        $granel = request()->query('granel');

        if (is_string($granel) && ctype_digit($granel)) {
            $this->granel_id = $granel;
            $this->lote_id = $this->loteSugerido();
        }
    }

    public function updatedSucursalId(): void
    {
        $this->lote_id = $this->loteSugerido();
        $this->resetErrorBag(['sucursal_id', 'lote_id', 'lineas']);
    }

    public function updatedGranelId(): void
    {
        $this->unidades = [];
        $this->envases = [];
        $this->granel_real = '';
        $this->editados = [];
        $this->lote_id = $this->loteSugerido();
        $this->resetErrorBag();
    }

    public function updatedUnidades(): void
    {
        $this->sincronizar();
    }

    public function updatedGranelReal(): void
    {
        $this->editados['granel'] = true;
    }

    public function updatedEnvases(mixed $valor, int|string $clave): void
    {
        $this->editados['envase-'.$clave] = true;
    }

    public function guardar(RegistrarEnvasado $accion): void
    {
        $this->resetErrorBag();
        $envasado = $accion->ejecutar($this->actor(), [
            'sucursal_id' => $this->sucursal_id,
            'granel_id' => $this->granel_id,
            'lote_id' => $this->lote_id,
            'unidades' => $this->unidades,
            'granel_real' => $this->granel_real,
            'envases' => $this->envases,
            'nota' => $this->nota,
            'clave' => $this->clave,
        ]);
        session()->flash('aviso', __('Envasado :codigo registrado: las presentaciones entraron al stock.', ['codigo' => $envasado->codigo()]));
        $this->redirectRoute('produccion.envasados.ver', ['envasado' => $envasado->id], navigate: false);
    }

    public function render(): View
    {
        $preparacion = $this->preparacion();
        $sucursalId = $this->sucursalId();
        $lotes = $preparacion === null ? [] : $this->lotes($preparacion['granel'], $sucursalId);

        return view('livewire.produccion.envasado-formulario', [
            'sucursales' => Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'graneles' => $this->graneles(),
            'preparacion' => $preparacion,
            'conLote' => $preparacion !== null && $this->conLote($preparacion['granel']),
            'lotes' => $lotes,
            'faltantes' => $preparacion === null || $sucursalId === null ? [] : $this->faltantes($preparacion['granel'], $sucursalId, $lotes),
        ]);
    }

    /**
     * Lo usado que no se escribió a mano sigue a lo necesario para las unidades buenas.
     */
    private function sincronizar(): void
    {
        $preparacion = $this->preparacion();

        if ($preparacion === null) {
            return;
        }

        $teoricos = $preparacion['teoricos'];

        if (! ($this->editados['granel'] ?? false)) {
            $this->granel_real = Decimal::comparar($teoricos['granel'], '0') > 0 ? Decimal::simple($teoricos['granel']) : '';
        }

        $envases = [];

        foreach ($teoricos['envases_total'] as $id => $teorico) {
            $envases[$id] = ($this->editados['envase-'.$id] ?? false) && isset($this->envases[$id]) ? $this->envases[$id] : Decimal::simple($teorico);
        }

        $this->envases = $envases;
    }

    /**
     * Granel elegido, sus presentaciones activas con su composición, los envases y lo necesario para las unidades
     * escritas (con las mismas reglas de redondeo que RegistrarEnvasado).
     *
     * @return array{granel: Producto, presentaciones: Collection<int, Producto>, composicion: array<int, array{granel_por_unidad: string, envases: array<int, string>}>, materias: Collection<int, Producto>, teoricos: array{granel: string, granel_por_presentacion: array<int, string>, envases: array<int, array<int, string>>, envases_total: array<int, string>}}|null
     */
    private function preparacion(): ?array
    {
        $granel = $this->granel_id !== null && ctype_digit($this->granel_id)
            ? Producto::query()->with('unidad:id,abreviatura,admite_decimales')->where('clase', Producto::CLASE_VENTA)->whereNull('granel_id')->find((int) $this->granel_id)
            : null;

        if ($granel === null) {
            return null;
        }

        $empresaId = (int) $this->actor()->empresa_id;
        $presentaciones = Producto::query()->with('unidad:id,abreviatura,admite_decimales')
            ->where('granel_id', $granel->id)->where('clase', Producto::CLASE_VENTA)->where('activo', true)
            ->where('controla_stock', true)->whereNull('modelo_id')->orderBy('nombre')->get()->keyBy('id');
        $composicion = Presentaciones::composicion($empresaId, $granel->id, array_values($presentaciones->map(fn (Producto $p): int => $p->id)->all()));
        $ids = array_values(array_unique(array_merge([], ...array_map(fn (array $c): array => array_keys($c['envases']), array_values($composicion)))));
        $materias = Producto::query()->with('unidad:id,abreviatura,admite_decimales')
            ->where('clase', Producto::CLASE_MATERIA_PRIMA)->whereIn('id', $ids)->orderBy('nombre')->get()->keyBy('id');
        $unidades = [];

        foreach ($presentaciones as $id => $presentacion) {
            $cantidad = Decimal::normalizar(trim((string) ($this->unidades[$id] ?? '')), Decimal::ESCALA_CANTIDAD);

            if ($cantidad !== null && Decimal::comparar($cantidad, '0') > 0) {
                $unidades[$id] = $cantidad;
            }
        }

        $enteras = [$granel->id => Escalado::esEntera($granel->tipo_venta, (bool) $granel->unidad->admite_decimales)]
            + $materias->map(fn (Producto $m): bool => Escalado::esEntera($m->tipo_venta, (bool) $m->unidad->admite_decimales))->all();

        return [
            'granel' => $granel,
            'presentaciones' => $presentaciones,
            'composicion' => $composicion,
            'materias' => $materias,
            'teoricos' => RegistrarEnvasado::teoricos($granel->id, $unidades, $composicion, $enteras),
        ];
    }

    /**
     * Graneles que se pueden envasar: productos de venta activos con al menos una presentación activa. Una consulta.
     *
     * @return Collection<int, Producto>
     */
    private function graneles(): Collection
    {
        return Producto::query()
            ->where('clase', Producto::CLASE_VENTA)->where('activo', true)->where('controla_stock', true)
            ->whereNull('modelo_id')->whereNull('granel_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('productos as p')
                ->whereColumn('p.granel_id', 'productos.id')->whereColumn('p.empresa_id', 'productos.empresa_id')
                ->where('p.activo', true)->where('p.controla_stock', true))
            ->with('unidad:id,abreviatura')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'contenido', 'sku', 'unidad_medida_id']);
    }

    private function conLote(Producto $granel): bool
    {
        return $granel->controla_vencimiento && app(LibroLotes::class)->activo((int) $this->actor()->empresa_id);
    }

    /**
     * Lotes del granel con stock en la sucursal que se pueden envasar: los vigentes por vencimiento (FEFO) y al final
     * el de regularización (stock sin lote). Los vencidos no se ofrecen.
     *
     * @return list<array{id: int, numero: string|null, vencimiento: string|null, cantidad: string}>
     */
    private function lotes(Producto $granel, ?int $sucursalId): array
    {
        if ($sucursalId === null || ! $this->conLote($granel)) {
            return [];
        }

        return array_values(DB::table('stock_lotes as s')
            ->join('lotes as l', fn ($j) => $j->on('l.id', '=', 's.lote_id')->whereColumn('l.empresa_id', 's.empresa_id'))
            ->where('s.empresa_id', (int) $this->actor()->empresa_id)
            ->where('s.sucursal_id', $sucursalId)
            ->where('s.producto_id', $granel->id)
            ->where('s.cantidad', '>', 0)
            ->where(fn ($q) => $q->where('l.es_regularizacion', true)->orWhere('l.vencimiento', '>=', DatosLote::hoy()))
            ->orderBy('l.es_regularizacion')->orderBy('l.vencimiento')->orderBy('l.id')
            ->get(['l.id', 'l.numero', 'l.vencimiento', 'l.es_regularizacion', 's.cantidad'])
            ->map(fn (stdClass $l): array => [
                'id' => (int) $l->id,
                'numero' => (bool) $l->es_regularizacion ? null : (string) $l->numero,
                'vencimiento' => $l->vencimiento === null ? null : substr((string) $l->vencimiento, 0, 10),
                'cantidad' => (string) $l->cantidad,
            ])->all());
    }

    /** Lote propuesto al elegir granel o sucursal: el primero de la lista (vigente que vence primero). */
    private function loteSugerido(): ?string
    {
        $preparacion = $this->preparacion();
        $lotes = $preparacion === null ? [] : $this->lotes($preparacion['granel'], $this->sucursalId());

        return $lotes === [] ? null : (string) $lotes[0]['id'];
    }

    /**
     * Aviso de lo que no alcanza para lo usado: el granel en el lote elegido (o en la sucursal, sin lote) y los envases
     * en la sucursal sin contar lo vencido. Solo informa.
     *
     * @param  list<array{id: int, numero: string|null, vencimiento: string|null, cantidad: string}>  $lotes
     * @return array<int, array{disponible: string, falta: string}> producto_id => faltante
     */
    private function faltantes(Producto $granel, int $sucursalId, array $lotes): array
    {
        $usados = [];
        $granelReal = Decimal::normalizar(trim($this->granel_real), Decimal::ESCALA_CANTIDAD);

        foreach ($this->envases as $id => $valor) {
            $cantidad = Decimal::normalizar(trim((string) $valor), Decimal::ESCALA_CANTIDAD);

            if ($cantidad !== null && Decimal::comparar($cantidad, '0') > 0 && is_numeric($id)) {
                $usados[(int) $id] = $cantidad;
            }
        }

        $conLote = $this->conLote($granel);

        if (! $conLote && $granelReal !== null && Decimal::comparar($granelReal, '0') > 0) {
            $usados[$granel->id] = $granelReal;
        }

        $faltantes = app(DisponibilidadMaterias::class)->faltantes((int) $this->actor()->empresa_id, $sucursalId, $usados);

        if ($conLote && $granelReal !== null && Decimal::comparar($granelReal, '0') > 0) {
            $lote = collect($lotes)->firstWhere('id', (int) $this->lote_id);
            $disponible = $lote['cantidad'] ?? '0';

            if (Decimal::comparar($disponible, $granelReal) < 0) {
                $faltantes[$granel->id] = ['disponible' => $disponible, 'falta' => Decimal::restar($granelReal, $disponible)];
            }
        }

        return $faltantes;
    }

    private function sucursalId(): ?int
    {
        return $this->sucursal_id !== null && ctype_digit($this->sucursal_id) ? (int) $this->sucursal_id : null;
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
