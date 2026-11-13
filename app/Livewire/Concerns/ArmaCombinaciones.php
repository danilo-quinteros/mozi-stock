<?php

namespace App\Livewire\Concerns;

use App\Domain\Catalogo\Actions\GuardarModelo;
use App\Domain\Catalogo\Variantes;
use App\Models\Color;
use App\Models\GrupoTalla;
use App\Models\Producto;
use App\Models\Talla;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Elección de tallas y colores con su cuadrícula de combinaciones (ESPECIFICACION_TALLAS_Y_COLORES.md § 4.2): SKU
 * sugerido editable, código de barras y casilla para no crear cada celda, con los SKU y códigos repetidos marcados
 * antes de guardar. La usan «Editar modelo» y «Nuevo producto» con «Este producto tiene tallas y colores». La vista es
 * `catalogo/partials/combinaciones`; toda regla vive en GuardarModelo, que vuelve a validar todo.
 */
trait ArmaCombinaciones
{
    public ?string $sku_base = '';

    public string $grupo_talla_id = '';

    /** @var list<string> */
    public array $tallas = [];

    /** @var list<string> */
    public array $colores = [];

    /**
     * Celdas de la cuadrícula por clave «talla-color». `sugerido` recuerda el último SKU sugerido para actualizarlo
     * mientras nadie lo haya editado.
     *
     * @var array<string, array{crear: bool, sku: string, codigo_barras: string, sugerido: string}>
     */
    public array $combinaciones = [];

    /**
     * Combinaciones que ya existen, por clave «talla-color» (vacío al crear).
     *
     * @return Collection<string, Producto>
     */
    abstract protected function variantesExistentes(): Collection;

    public function updatedGrupoTallaId(): void
    {
        $this->tallas = [];
        $this->sincronizar();
    }

    public function updatedTallas(): void
    {
        $this->sincronizar();
    }

    public function updatedColores(): void
    {
        $this->sincronizar();
    }

    public function updatedSkuBase(): void
    {
        $this->sincronizar();
    }

    /** Marca todas las tallas activas del grupo elegido. */
    public function todasLasTallas(): void
    {
        $ids = $this->grupoId() === null ? [] : Talla::query()->where('grupo_talla_id', $this->grupoId())->where('activa', true)
            ->orderBy('orden')->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $this->tallas = array_values($ids);
        $this->sincronizar();
    }

    public function ningunaTalla(): void
    {
        $this->tallas = [];
        $this->sincronizar();
    }

    /**
     * Celdas de la cuadrícula actual tal como las recibe GuardarModelo.
     *
     * @return array<string, array{crear: bool, sku: string, codigo_barras: string}>
     */
    protected function celdasParaGuardar(): array
    {
        $celdas = [];

        foreach ($this->cuadricula() as $clave) {
            $celda = $this->combinaciones[$clave] ?? ['crear' => true, 'sku' => '', 'codigo_barras' => ''];
            $celdas[$clave] = ['crear' => (bool) $celda['crear'], 'sku' => (string) $celda['sku'], 'codigo_barras' => (string) $celda['codigo_barras']];
        }

        return $celdas;
    }

    /**
     * Datos de la vista parcial `catalogo/partials/combinaciones`.
     *
     * @return array<string, mixed>
     */
    protected function vistaCombinaciones(int $grupoGuardadoId = 0): array
    {
        $grupoId = $this->grupoId();
        $tallasGrupo = $grupoId === null ? collect() : Talla::query()->where('grupo_talla_id', $grupoId)
            ->where(fn ($q) => $q->where('activa', true)->orWhereIn('id', $this->ids($this->tallas)))->orderBy('orden')->orderBy('id')->get();
        $colores = Color::query()->where(fn ($q) => $q->where('activo', true)->orWhereIn('id', $this->ids($this->colores)))->orderBy('nombre')->get();
        $existentes = $this->variantesExistentes();
        $claves = $this->cuadricula();

        return [
            'grupos' => GrupoTalla::query()->where(fn ($q) => $q->where('activo', true)->orWhere('id', $grupoGuardadoId))->orderBy('orden')->orderBy('nombre')->get(),
            'tallasGrupo' => $tallasGrupo,
            'coloresDisponibles' => $colores,
            'filas' => $this->ids($this->colores) === [] ? [null] : $colores->whereIn('id', $this->ids($this->colores))->values()->all(),
            'columnas' => $this->ids($this->tallas) === [] ? [null] : $tallasGrupo->whereIn('id', $this->ids($this->tallas))->values()->all(),
            'existentes' => $existentes,
            'quitadas' => $existentes->filter(fn (Producto $v, string $clave): bool => $v->activo
                && (! in_array($clave, $claves, true) || ! ($this->combinaciones[$clave]['crear'] ?? true))),
            'repetidos' => $this->repetidos($claves, $existentes),
            'resumen' => $this->resumen($claves, $existentes),
        ];
    }

    /**
     * Claves de la cuadrícula actual (tallas × colores marcados).
     *
     * @return list<string>
     */
    protected function cuadricula(): array
    {
        return array_map(fn (array $par): string => Variantes::clave($par[0], $par[1]),
            GuardarModelo::combinaciones($this->ids($this->tallas), $this->ids($this->colores)));
    }

    /**
     * Agrega las celdas nuevas con su SKU sugerido y actualiza la sugerencia de las que nadie editó. Las celdas de
     * variantes que ya existen se conservan siempre (sus valores son los guardados).
     */
    protected function sincronizar(): void
    {
        $claves = $this->cuadricula();
        $existentes = $this->variantesExistentes();
        $tallas = Talla::query()->whereIn('id', $this->ids($this->tallas))->pluck('nombre', 'id');
        $codigos = Color::query()->whereIn('id', $this->ids($this->colores))->pluck('codigo', 'id');

        foreach ($claves as $clave) {
            if ($existentes->has($clave)) {
                continue;
            }

            [$tallaId, $colorId] = Variantes::desdeClave($clave);
            $sugerido = (string) Variantes::sku($this->sku_base, $tallaId === null ? null : $tallas[$tallaId] ?? null, $colorId === null ? null : $codigos[$colorId] ?? null);
            $celda = $this->combinaciones[$clave] ?? null;

            if ($celda === null) {
                $this->combinaciones[$clave] = ['crear' => true, 'sku' => $sugerido, 'codigo_barras' => '', 'sugerido' => $sugerido];
            } elseif ($celda['sku'] === $celda['sugerido']) {
                $this->combinaciones[$clave]['sku'] = $sugerido;
                $this->combinaciones[$clave]['sugerido'] = $sugerido;
            }
        }

        // Las celdas nuevas que ya no están en la cuadrícula se olvidan.
        foreach (array_keys($this->combinaciones) as $clave) {
            if (! in_array($clave, $claves, true) && ! $existentes->has($clave)) {
                unset($this->combinaciones[$clave]);
            }
        }

        $this->resetValidation(array_map(fn (string $c): string => "combinaciones.{$c}.sku", array_keys($this->combinaciones)));
    }

    /**
     * SKU y códigos de barras que ya existen en otro producto de la empresa o se repiten en la cuadrícula (§ 8.3): la
     * vista previa los marca antes de guardar. GuardarModelo los valida igual.
     *
     * @param  list<string>  $claves
     * @param  Collection<string, Producto>  $existentes
     * @return array<string, array<string, string>> clave => campo => mensaje
     */
    private function repetidos(array $claves, Collection $existentes): array
    {
        $marcas = [];
        $propias = $existentes->filter(fn (Producto $v, string $clave): bool => in_array($clave, $claves, true))->pluck('id')->all();

        foreach (['sku' => __('Ya existe en otro producto.'), 'codigo_barras' => __('Ya está asignado a otro producto.')] as $campo => $mensaje) {
            $valores = [];

            foreach ($claves as $clave) {
                $celda = $this->combinaciones[$clave] ?? null;
                $valor = Str::lower(trim((string) ($celda[$campo] ?? '')));

                if ($celda !== null && $celda['crear'] && $valor !== '') {
                    $valores[$clave] = $valor;
                }
            }

            if ($valores === []) {
                continue;
            }

            $usados = Producto::query()->whereIn($campo, array_values(array_unique($valores)))->whereNotIn('id', $propias === [] ? [0] : $propias)
                ->pluck($campo)->map(fn ($v): string => Str::lower((string) $v))->all();
            $conteo = array_count_values($valores);

            foreach ($valores as $clave => $valor) {
                if (in_array($valor, $usados, true)) {
                    $marcas[$clave][$campo] = $mensaje;
                } elseif ($conteo[$valor] > 1) {
                    $marcas[$clave][$campo] = __('Se repite en la cuadrícula.');
                }
            }
        }

        return $marcas;
    }

    /**
     * @param  list<string>  $claves
     * @param  Collection<string, Producto>  $existentes
     * @return array{nuevas: int, reactivadas: int, quedan: int, quitadas: int}
     */
    private function resumen(array $claves, Collection $existentes): array
    {
        $resumen = ['nuevas' => 0, 'reactivadas' => 0, 'quedan' => 0, 'quitadas' => 0];

        foreach ($claves as $clave) {
            if (! ($this->combinaciones[$clave]['crear'] ?? true)) {
                continue;
            }

            $variante = $existentes->get($clave);
            $resumen[$variante === null ? 'nuevas' : ($variante->activo ? 'quedan' : 'reactivadas')]++;
        }

        foreach ($existentes as $clave => $variante) {
            if ($variante->activo && (! in_array($clave, $claves, true) || ! ($this->combinaciones[$clave]['crear'] ?? true))) {
                $resumen['quitadas']++;
            }
        }

        return $resumen;
    }

    private function grupoId(): ?int
    {
        return ctype_digit($this->grupo_talla_id) && (int) $this->grupo_talla_id > 0 ? (int) $this->grupo_talla_id : null;
    }

    /**
     * @param  array<int, mixed>  $valores
     * @return list<int>
     */
    private function ids(array $valores): array
    {
        return array_values(array_unique(array_map('intval', array_filter($valores, fn ($v): bool => is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0))));
    }
}
