<?php

namespace App\Domain\Produccion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Inventario\DatosLote;
use App\Domain\Inventario\LibroLotes;
use App\Domain\Inventario\LibroStock;
use App\Domain\Produccion\CostoPromedio;
use App\Domain\Produccion\Escalado;
use App\Domain\Produccion\Presentaciones;
use App\Domain\Tenancy\ResolverSucursal;
use App\Enums\TipoOperacionInventario;
use App\Models\Envasado;
use App\Models\Producto;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Envasado (ESPECIFICACION_PRODUCCION.md § 7 y § 9), en una sola transacción y una operación de inventario ENV:
 *
 * 1. El granel sale del lote elegido (vigente o sin lote; nunca uno vencido) por lo usado (real).
 * 2. Los envases salen por FEFO entre lotes vigentes por lo usado (real).
 * 3. Las presentaciones entran por sus unidades buenas, con el mismo número de lote y vencimiento del granel.
 * 4. Costo: cada material (granel y envases) cuesta lo usado × su costo promedio; se reparte entre las presentaciones en
 *    proporción a lo que cada una pedía de ese material, así la merma se reparte en proporción. Cada presentación
 *    recalcula su costo promedio.
 *
 * Lo teórico sale de la composición de cada presentación (granel por unidad y envases) × sus unidades; las cantidades en
 * unidades enteras se redondean hacia arriba, como en las órdenes (M5). Lo usado no puede ser menor que lo teórico: la
 * diferencia es la merma. Orden de bloqueo (§ 11): todas las fichas de una vez, por id (CostoPromedio::bloquear),
 * después saldos y lotes en LibroStock. La clave del formulario hace idempotente el registro.
 */
final class RegistrarEnvasado
{
    public const MAX_PRESENTACIONES = 50;

    /** Máximo de una cantidad: DECIMAL(18,3). */
    private const MAXIMO = '999999999999999';

    public function __construct(
        private readonly LibroStock $stock,
        private readonly LibroLotes $lotes,
        private readonly CostoPromedio $costos,
        private readonly ResolverSucursal $sucursales,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{sucursal_id: mixed, granel_id: mixed, lote_id?: mixed, unidades: mixed, granel_real: mixed, envases?: mixed, nota?: mixed, clave: mixed}  $datos
     *                                                                                                                                                                 unidades: presentacion_id => unidades buenas; envases: producto_id => cantidad usada
     */
    public function ejecutar(User $actor, array $datos): Envasado
    {
        Gate::forUser($actor)->authorize('usar-produccion');

        $empresaId = (int) $actor->empresa_id;
        $clave = is_string($datos['clave'] ?? null) ? $datos['clave'] : '';

        if (! Str::isUuid($clave)) {
            throw ValidationException::withMessages(['unidades' => __('El envasado no tiene una clave válida. Recarga la página.')]);
        }

        if (($previo = $this->existente($clave)) !== null) {
            return $previo;
        }

        $sucursal = $this->sucursales->paraOperacion($actor, is_numeric($datos['sucursal_id'] ?? null) ? (int) $datos['sucursal_id'] : null);
        $granel = $this->granel($datos['granel_id'] ?? null);
        $presentaciones = $this->presentaciones($empresaId, $granel);
        $unidades = $this->unidades($datos['unidades'] ?? null, $presentaciones, $granel);
        $composicion = Presentaciones::composicion($empresaId, $granel->id, array_keys($unidades));
        $materias = $this->materias($composicion);
        $enteras = [$granel->id => Escalado::esEntera($granel->tipo_venta, (bool) $granel->unidad->admite_decimales)]
            + $materias->map(fn (Producto $m): bool => Escalado::esEntera($m->tipo_venta, (bool) $m->unidad->admite_decimales))->all();
        $teoricos = self::teoricos($granel->id, $unidades, $composicion, $enteras);
        $reales = $this->reales($datos, $granel, $teoricos, $materias, $enteras);
        $lote = $this->lote($empresaId, $granel, $datos['lote_id'] ?? null);
        $nota = $this->nota($datos['nota'] ?? null);
        $nombreGranel = $this->nombre($granel);

        $cambios = [['sucursal_id' => $sucursal->id, 'producto_id' => $granel->id, 'cantidad' => '-'.$reales['granel'], 'nombre' => $nombreGranel]
            + ($lote === null ? [] : ['lote_id' => $lote['id']])];

        foreach ($reales['envases'] as $materiaId => $real) {
            $cambios[] = ['sucursal_id' => $sucursal->id, 'producto_id' => $materiaId, 'cantidad' => '-'.$real, 'nombre' => $this->nombre($materias[$materiaId])];
        }

        foreach ($unidades as $presentacionId => $cantidad) {
            $cambios[] = ['sucursal_id' => $sucursal->id, 'producto_id' => $presentacionId, 'cantidad' => $cantidad, 'nombre' => $this->nombre($presentaciones[$presentacionId])]
                + ($lote === null || $lote['numero'] === null ? [] : ['lote' => ['numero' => $lote['numero'], 'vencimiento' => (string) $lote['vencimiento']]]);
        }

        // Filas de saldo y lotes nuevos de las presentaciones, antes y fuera de la transacción (como LibroStock::registrar).
        $this->stock->asegurarNiveles($empresaId, $cambios);

        try {
            return DB::transaction(function () use ($actor, $empresaId, $sucursal, $granel, $unidades, $composicion, $teoricos, $reales, $lote, $nota, $clave, $cambios, $materias): Envasado {
                $bloqueados = $this->costos->bloquear($empresaId, [$granel->id, ...array_keys($materias->all()), ...array_keys($unidades)], todas: true);

                // Con las fichas bloqueadas nadie cambia la composición: debe ser la que se validó.
                if (Presentaciones::composicion($empresaId, $granel->id, array_keys($unidades)) !== $composicion) {
                    throw ValidationException::withMessages(['unidades' => __('La composición de una presentación cambió mientras registrabas el envasado. Vuelve a abrir la pantalla.')]);
                }

                $operacion = $this->stock->aplicar($actor, [
                    'tipo' => TipoOperacionInventario::Envasado,
                    'motivo' => null,
                    'sucursal_id' => $sucursal->id,
                    'sucursal_destino_id' => null,
                    'nota' => $lote === null || $lote['numero'] === null
                        ? __('Envasado de :granel', ['granel' => $granel->nombre])
                        : __('Envasado de :granel · lote :lote', ['granel' => $granel->nombre, 'lote' => $lote['numero']]),
                    'clave' => $clave,
                ], $cambios);

                $materiales = [['id' => $granel->id, 'real' => $reales['granel'], 'costo' => $bloqueados[$granel->id] ?? '0', 'teoricos' => $teoricos['granel_por_presentacion']]];

                foreach ($teoricos['envases'] as $materiaId => $porPresentacion) {
                    $materiales[] = ['id' => $materiaId, 'real' => $reales['envases'][$materiaId], 'costo' => $bloqueados[$materiaId] ?? '0', 'teoricos' => $porPresentacion];
                }

                $reparto = self::repartir($unidades, $materiales);
                $promedios = $this->costos->ingresar($empresaId, array_intersect_key($bloqueados, $unidades), array_map(
                    fn (int $id, string $cantidad): array => ['producto_id' => $id, 'cantidad' => $cantidad, 'importe' => $reparto['items'][$id]['costo_total']],
                    array_keys($unidades), $unidades,
                ));

                $envasado = new Envasado;
                $envasado->numero = $operacion->numero;
                $envasado->sucursal_id = $sucursal->id;
                $envasado->granel_id = $granel->id;
                $envasado->lote_id = $lote['id'] ?? null;
                $envasado->operacion_id = $operacion->id;
                $envasado->granel_teorico = $teoricos['granel'];
                $envasado->granel_real = $reales['granel'];
                $envasado->granel_costo_unitario = $bloqueados[$granel->id] ?? '0';
                $envasado->costo_total = $reparto['total'];
                $envasado->nota = $nota;
                $envasado->usuario_id = $actor->id;
                $envasado->clave_idempotencia = $clave;
                $envasado->save();

                DB::table('envasado_items')->insert(array_map(fn (int $id, string $cantidad, int $orden): array => [
                    'empresa_id' => $empresaId, 'envasado_id' => $envasado->id, 'producto_id' => $id, 'orden' => $orden + 1, 'unidades' => $cantidad,
                    'granel_por_unidad' => $composicion[$id]['granel_por_unidad'],
                    'costo_unitario' => $reparto['items'][$id]['costo_unitario'], 'costo_total' => $reparto['items'][$id]['costo_total'],
                ], array_keys($unidades), $unidades, array_keys(array_values($unidades))));

                if ($teoricos['envases'] !== []) {
                    $ids = array_keys($teoricos['envases']);
                    DB::table('envasado_consumos')->insert(array_map(fn (int $id, int $orden): array => [
                        'empresa_id' => $empresaId, 'envasado_id' => $envasado->id, 'producto_id' => $id, 'orden' => $orden + 1,
                        'cantidad_teorica' => $teoricos['envases_total'][$id], 'cantidad_real' => $reales['envases'][$id], 'costo_unitario' => $bloqueados[$id] ?? '0',
                    ], $ids, array_keys($ids)));
                }

                $this->auditor->registrar('envasado.registrado', $envasado, $actor, $empresaId, null, [
                    'codigo' => $envasado->codigo(), 'sucursal_id' => $sucursal->id, 'granel_id' => $granel->id, 'lote' => $lote['numero'] ?? null,
                    'granel' => ['teorico' => $teoricos['granel'], 'real' => $reales['granel']],
                    'envases' => array_map(fn (int $id): array => ['producto_id' => $id, 'teorico' => $teoricos['envases_total'][$id], 'real' => $reales['envases'][$id]], array_keys($teoricos['envases'])),
                    'presentaciones' => array_map(fn (int $id, string $cantidad): array => ['producto_id' => $id, 'unidades' => $cantidad,
                        'costo_unitario' => $reparto['items'][$id]['costo_unitario'], 'costo_promedio' => $promedios[$id]['despues'] ?? null], array_keys($unidades), $unidades),
                    'costo_total' => $reparto['total'],
                ]);

                return $envasado;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            // Dos envíos simultáneos con la misma clave: el segundo devuelve el primero.
            return $this->existente($clave) ?? throw $e;
        }
    }

    /**
     * Cantidades teóricas: granel = Σ unidades × granel por unidad; cada envase = Σ unidades × su cantidad por unidad.
     * Con el detalle por presentación, para repartir el costo. Las cantidades enteras se redondean hacia arriba (M5).
     *
     * @param  array<int, string>  $unidades  presentacion_id => unidades
     * @param  array<int, array{granel_por_unidad: string, envases: array<int, string>}>  $composicion
     * @param  array<int, bool>  $enteras  producto_id => se mide en unidades enteras (granel y envases)
     * @return array{granel: string, granel_por_presentacion: array<int, string>, envases: array<int, array<int, string>>, envases_total: array<int, string>}
     */
    public static function teoricos(int $granelId, array $unidades, array $composicion, array $enteras): array
    {
        $resultado = ['granel' => '0', 'granel_por_presentacion' => [], 'envases' => [], 'envases_total' => []];

        foreach ($unidades as $presentacionId => $cantidad) {
            $partes = $composicion[$presentacionId] ?? ['granel_por_unidad' => '0', 'envases' => []];
            $granel = Escalado::teorica($cantidad, '1', $partes['granel_por_unidad'], $enteras[$granelId] ?? false);
            $resultado['granel_por_presentacion'][$presentacionId] = $granel;
            $resultado['granel'] = Decimal::sumar($resultado['granel'], $granel);

            foreach ($partes['envases'] as $materiaId => $porUnidad) {
                $envase = Escalado::teorica($cantidad, '1', $porUnidad, $enteras[$materiaId] ?? false);
                $resultado['envases'][$materiaId][$presentacionId] = $envase;
                $resultado['envases_total'][$materiaId] = Decimal::sumar($resultado['envases_total'][$materiaId] ?? '0', $envase);
            }
        }

        return $resultado;
    }

    /**
     * Reparte el costo de cada material (lo usado × su costo) entre las presentaciones, en proporción a lo que cada una
     * pedía de ese material. Costos de cada presentación con 6 decimales; la última absorbe el redondeo para que la suma
     * sea exactamente el total.
     *
     * @param  array<int, string>  $unidades  presentacion_id => unidades
     * @param  list<array{id: int, real: string, costo: string, teoricos: array<int, string>}>  $materiales
     * @return array{total: string, items: array<int, array{costo_total: string, costo_unitario: string}>}
     */
    public static function repartir(array $unidades, array $materiales): array
    {
        $exactos = [];
        $total = '0';

        foreach ($materiales as $material) {
            $importe = Decimal::multiplicar($material['real'], $material['costo'], 9);
            $total = Decimal::sumar($total, $importe, 9);
            $pedido = array_reduce($material['teoricos'], fn (string $suma, string $t): string => Decimal::sumar($suma, $t), '0');

            if (Decimal::comparar($pedido, '0') <= 0) {
                continue;
            }

            foreach ($material['teoricos'] as $presentacionId => $teorico) {
                $parte = Decimal::dividir(Decimal::multiplicar($importe, $teorico, 12), $pedido, 12);
                $exactos[$presentacionId] = Decimal::sumar($exactos[$presentacionId] ?? '0', $parte, 12);
            }
        }

        $total = Decimal::redondear($total, 6);
        $ids = array_keys($unidades);
        $ultimo = end($ids);
        $acumulado = '0';
        $items = [];

        foreach ($unidades as $presentacionId => $cantidad) {
            $costo = $presentacionId === $ultimo
                ? Decimal::restar($total, $acumulado, 6)
                : Decimal::redondear($exactos[$presentacionId] ?? '0', 6);

            if (Decimal::comparar($costo, '0', 6) < 0) {
                $costo = '0.000000';
            }

            $acumulado = Decimal::sumar($acumulado, $costo, 6);
            $items[$presentacionId] = ['costo_total' => $costo, 'costo_unitario' => Decimal::redondear(Decimal::dividir($costo, $cantidad, 8), 6)];
        }

        return ['total' => $total, 'items' => $items];
    }

    /**
     * @phpstan-impure
     */
    private function existente(string $clave): ?Envasado
    {
        return Envasado::query()->where('clave_idempotencia', $clave)->first();
    }

    private function granel(mixed $valor): Producto
    {
        $id = is_numeric($valor) ? (int) $valor : 0;
        $granel = Producto::query()->with('unidad:id,abreviatura,admite_decimales')->where('clase', Producto::CLASE_VENTA)->find($id);

        if ($granel === null) {
            throw ValidationException::withMessages(['granel_id' => __('Elige el granel que vas a envasar.')]);
        }

        if (! $granel->activo || ! $granel->controla_stock) {
            throw ValidationException::withMessages(['granel_id' => __(':granel está desactivado o no controla stock: no se puede envasar.', ['granel' => $granel->nombre])]);
        }

        return $granel;
    }

    /**
     * Presentaciones activas del granel, con su unidad.
     *
     * @return Collection<int, Producto>
     */
    private function presentaciones(int $empresaId, Producto $granel): Collection
    {
        $presentaciones = Producto::query()->with('unidad:id,abreviatura,admite_decimales')
            ->where('empresa_id', $empresaId)->where('granel_id', $granel->id)->where('clase', Producto::CLASE_VENTA)
            ->where('activo', true)->where('controla_stock', true)->whereNull('modelo_id')
            ->orderBy('nombre')->get()->keyBy('id');

        if ($presentaciones->isEmpty()) {
            throw ValidationException::withMessages(['granel_id' => __(':granel no tiene presentaciones activas. Indica el granel en la ficha de cada presentación.', ['granel' => $granel->nombre])]);
        }

        return $presentaciones;
    }

    /**
     * Unidades buenas por presentación (> 0; vacío o 0 = no se envasa). Enteras si la presentación se vende por unidad.
     *
     * @param  Collection<int, Producto>  $presentaciones
     * @return array<int, string> presentacion_id => unidades
     */
    private function unidades(mixed $entrada, Collection $presentaciones, Producto $granel): array
    {
        $unidades = [];
        $errores = [];

        foreach (is_array($entrada) ? $entrada : [] as $id => $valor) {
            $texto = is_scalar($valor) ? trim((string) $valor) : '';

            if ($texto === '' || ! is_numeric($id)) {
                continue;
            }

            $presentacion = $presentaciones->get((int) $id);
            $cantidad = Decimal::normalizar($texto, Decimal::ESCALA_CANTIDAD);

            if ($presentacion === null) {
                $errores['unidades'] = __('Una de las presentaciones no es de :granel o está desactivada. Vuelve a abrir la pantalla.', ['granel' => $granel->nombre]);
            } elseif ($cantidad === null || Decimal::comparar($cantidad, '0') < 0 || Decimal::comparar($cantidad, self::MAXIMO) > 0) {
                $errores["unidades.{$presentacion->id}"] = __(':presentacion: escribe las unidades buenas (0 o más).', ['presentacion' => $presentacion->nombre]);
            } elseif (Escalado::esEntera($presentacion->tipo_venta, (bool) $presentacion->unidad->admite_decimales) && ! Decimal::esEntero($cantidad)) {
                $errores["unidades.{$presentacion->id}"] = __(':presentacion se cuenta en unidades enteras: escribe la cantidad sin decimales.', ['presentacion' => $presentacion->nombre]);
            } elseif ((bool) $presentacion->controla_vencimiento !== (bool) $granel->controla_vencimiento && Decimal::comparar($cantidad, '0') > 0) {
                $errores["unidades.{$presentacion->id}"] = __(':presentacion y su granel no coinciden en «Controla lote y vencimiento»: corrige la ficha de la presentación.', ['presentacion' => $presentacion->nombre]);
            } elseif (Decimal::comparar($cantidad, '0') > 0) {
                $unidades[$presentacion->id] = $cantidad;
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        if ($unidades === []) {
            throw ValidationException::withMessages(['unidades' => __('Escribe las unidades buenas de al menos una presentación.')]);
        }

        if (count($unidades) > self::MAX_PRESENTACIONES) {
            throw ValidationException::withMessages(['unidades' => __('Un envasado admite hasta :max presentaciones.', ['max' => self::MAX_PRESENTACIONES])]);
        }

        ksort($unidades);

        return $unidades;
    }

    /**
     * Envases de las presentaciones elegidas, activos.
     *
     * @param  array<int, array{granel_por_unidad: string, envases: array<int, string>}>  $composicion
     * @return Collection<int, Producto>
     */
    private function materias(array $composicion): Collection
    {
        $ids = array_values(array_unique(array_merge(...array_map(fn (array $c): array => array_keys($c['envases']), array_values($composicion)))));
        $materias = $ids === [] ? collect() : Producto::query()->with('unidad:id,abreviatura,admite_decimales')
            ->where('clase', Producto::CLASE_MATERIA_PRIMA)->whereIn('id', $ids)->orderBy('id')->get()->keyBy('id');
        $inactivas = $materias->filter(fn (Producto $m): bool => ! $m->activo)->map(fn (Producto $m): string => $m->nombre)->values()->all();

        if ($inactivas !== []) {
            throw ValidationException::withMessages(['unidades' => __('Las presentaciones usan envases desactivados (:materias). Actívalos en Materias primas.', ['materias' => implode(', ', $inactivas)])]);
        }

        return $materias;
    }

    /**
     * Lo usado del granel y de cada envase: obligatorio, no menor que lo teórico (la diferencia es merma) y entero en
     * unidades enteras.
     *
     * @param  array<string, mixed>  $datos
     * @param  array{granel: string, envases_total: array<int, string>}  $teoricos
     * @param  Collection<int, Producto>  $materias
     * @param  array<int, bool>  $enteras
     * @return array{granel: string, envases: array<int, string>}
     */
    private function reales(array $datos, Producto $granel, array $teoricos, Collection $materias, array $enteras): array
    {
        $errores = [];
        $leer = function (mixed $valor, string $campo, string $nombre, string $unidad, string $teorico, bool $entera) use (&$errores): ?string {
            $real = Decimal::normalizar(is_scalar($valor) ? trim((string) $valor) : '', Decimal::ESCALA_CANTIDAD);

            if ($real === null || Decimal::comparar($real, self::MAXIMO) > 0) {
                $errores[$campo] = __(':material: escribe lo que se usó (hasta 3 decimales).', ['material' => $nombre]);
            } elseif (Decimal::comparar($real, $teorico) < 0) {
                $errores[$campo] = __(':material: lo usado no puede ser menor que lo necesario (:cantidad :unidad). Lo que se usa de más es la merma.', [
                    'material' => $nombre, 'cantidad' => Decimal::formatear($teorico), 'unidad' => $unidad,
                ]);
            } elseif ($entera && ! Decimal::esEntero($real)) {
                $errores[$campo] = __(':material se usa en unidades enteras (:unidad): escribe una cantidad sin decimales.', ['material' => $nombre, 'unidad' => $unidad]);
            } else {
                return $real;
            }

            return null;
        };

        $granelReal = $leer($datos['granel_real'] ?? null, 'granel_real', $granel->nombre, $granel->unidad->abreviatura, $teoricos['granel'], $enteras[$granel->id] ?? false);
        $envases = [];
        $entrada = is_array($datos['envases'] ?? null) ? $datos['envases'] : [];

        foreach ($teoricos['envases_total'] as $materiaId => $teorico) {
            $materia = $materias[$materiaId];
            $real = $leer($entrada[$materiaId] ?? null, "envases.{$materiaId}", $materia->nombre, $materia->unidad->abreviatura, $teorico, $enteras[$materiaId] ?? false);

            if ($real !== null) {
                $envases[$materiaId] = $real;
            }
        }

        if ($errores !== [] || $granelReal === null) {
            throw ValidationException::withMessages($errores);
        }

        return ['granel' => $granelReal, 'envases' => $envases];
    }

    /**
     * Lote del granel (§ 7.2): obligatorio si el granel controla vencimiento; de ese granel, nunca vencido. El lote sin
     * número (regularización) se acepta: las presentaciones entran también sin lote. LibroStock comprueba el stock del
     * lote en la sucursal.
     *
     * @return array{id: int, numero: string|null, vencimiento: string|null}|null
     */
    private function lote(int $empresaId, Producto $granel, mixed $valor): ?array
    {
        if (! $granel->controla_vencimiento || ! $this->lotes->activo($empresaId)) {
            return null;
        }

        $lote = is_numeric($valor) ? DB::table('lotes')->where('empresa_id', $empresaId)->where('producto_id', $granel->id)
            ->where('id', (int) $valor)->first(['id', 'numero', 'vencimiento', 'es_regularizacion']) : null;

        if ($lote === null) {
            throw ValidationException::withMessages(['lote_id' => __('Elige el lote de :granel que vas a envasar.', ['granel' => $granel->nombre])]);
        }

        $vencimiento = $lote->vencimiento === null ? null : substr((string) $lote->vencimiento, 0, 10);

        if (! $lote->es_regularizacion && DatosLote::vencido($vencimiento)) {
            throw ValidationException::withMessages(['lote_id' => __('El lote :lote de :granel está vencido: no se puede envasar.', ['lote' => $lote->numero, 'granel' => $granel->nombre])]);
        }

        return ['id' => (int) $lote->id, 'numero' => $lote->es_regularizacion ? null : (string) $lote->numero, 'vencimiento' => $vencimiento];
    }

    private function nota(mixed $valor): ?string
    {
        $nota = is_scalar($valor) ? trim((string) $valor) : '';

        if (mb_strlen($nota) > 250) {
            throw ValidationException::withMessages(['nota' => __('La nota admite hasta 250 caracteres.')]);
        }

        return $nota === '' ? null : $nota;
    }

    private function nombre(Producto $producto): string
    {
        return $producto->nombre;
    }
}
