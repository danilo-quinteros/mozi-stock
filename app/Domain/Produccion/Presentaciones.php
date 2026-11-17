<?php

namespace App\Domain\Produccion;

use App\Domain\Auditoria\Auditor;
use App\Models\Producto;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Presentaciones (ESPECIFICACION_PRODUCCION.md § 7.1 y § 7.6): un producto de venta que sale de envasar un granel.
 * Indica el granel, cuánto granel lleva cada unidad (en la unidad del granel) y sus envases (materias primas y cantidad
 * por unidad). Lo usa GuardarProducto cuando el formulario de producto envía la sección «Presentación».
 *
 * Reglas:
 * - la presentación es un producto de venta que controla stock y no es una combinación de tallas y colores;
 * - el granel es otro producto de venta de la empresa con stock, sin tallas y colores, que no es presentación; y la
 *   presentación no puede ser granel de otras (sin cadenas);
 * - la presentación y su granel coinciden en «Controla lote y vencimiento» (las unidades entran con el lote del granel);
 * - granel por unidad > 0, entero si el granel se mide en unidades enteras;
 * - envases: materias primas de la empresa, sin repetir, cantidad > 0 (entera en unidades enteras), hasta 20.
 * No se exige que el granel o los envases estén activos: lo exige el envasado. Así una presentación con un envase
 * desactivado se sigue pudiendo editar (precio, nombre…).
 */
final class Presentaciones
{
    public const MAX_ENVASES = 20;

    /** Máximo de una cantidad: DECIMAL(18,3). */
    private const MAXIMO = '999999999999999';

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Valida los datos de la sección y asigna granel_id y granel_por_unidad a la ficha (sin guardarla). Devuelve los
     * envases a guardar con guardar() dentro de la transacción del producto. Granel vacío = deja de ser presentación.
     *
     * @param  array{granel_id?: mixed, granel_por_unidad?: mixed, envases?: mixed}  $datos
     * @return list<array{producto_id: int, cantidad: string}>
     */
    public function preparar(int $empresaId, Producto $producto, array $datos): array
    {
        $granelId = is_scalar($datos['granel_id'] ?? null) && ctype_digit((string) $datos['granel_id']) ? (int) $datos['granel_id'] : null;

        if ($granelId === null) {
            $producto->granel_id = null;
            $producto->granel_por_unidad = null;

            return [];
        }

        $errores = [];
        $nombre = $producto->nombre ?: __('Este producto');

        if ($producto->clase !== Producto::CLASE_VENTA || $producto->modelo_id !== null || ! $producto->controla_stock) {
            throw ValidationException::withMessages(['granel_id' => __('Solo un producto de venta que controla stock y no tiene tallas y colores puede obtenerse mediante envasado.')]);
        }

        $granel = Producto::query()->with('unidad:id,abreviatura,admite_decimales')
            ->where('empresa_id', $empresaId)->where('clase', Producto::CLASE_VENTA)->where('controla_stock', true)
            ->whereNull('modelo_id')->whereNull('granel_id')->find($granelId);

        if ($granel === null || $granel->id === $producto->id) {
            throw ValidationException::withMessages(['granel_id' => __('Elige como producto de origen otro producto de venta de tu empresa que controle stock y que no se obtenga también mediante envasado.')]);
        }

        if ($producto->exists && Producto::query()->where('empresa_id', $empresaId)->where('granel_id', $producto->id)->exists()) {
            throw ValidationException::withMessages(['granel_id' => __(':producto ya es el producto de origen de otros productos envasados: no puede obtenerse también mediante envasado.', ['producto' => $nombre])]);
        }

        if ((bool) $granel->controla_vencimiento !== (bool) $producto->controla_vencimiento) {
            $errores['granel_id'] = $granel->controla_vencimiento
                ? __(':granel controla lote y vencimiento: marca también «Controla lote y vencimiento» en este producto (sus unidades entran con el lote del producto de origen).', ['granel' => $granel->nombre])
                : __(':granel no controla lote y vencimiento: desmárcalo también en este producto.', ['granel' => $granel->nombre]);
        }

        $porUnidad = Decimal::normalizar(is_scalar($datos['granel_por_unidad'] ?? null) ? (string) $datos['granel_por_unidad'] : '', Decimal::ESCALA_CANTIDAD);

        if ($porUnidad === null || Decimal::comparar($porUnidad, '0') <= 0 || Decimal::comparar($porUnidad, self::MAXIMO) > 0) {
            $errores['granel_por_unidad'] = __('Escribe la cantidad utilizada por envase, mayor que cero (hasta 3 decimales).');
        } elseif (Escalado::esEntera($granel->tipo_venta, (bool) $granel->unidad->admite_decimales) && ! Decimal::esEntero($porUnidad)) {
            $errores['granel_por_unidad'] = __(':granel se mide en unidades enteras: escribe la cantidad sin decimales.', ['granel' => $granel->nombre]);
        }

        $envases = $this->envases($empresaId, $datos['envases'] ?? [], $errores);

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $producto->granel_id = $granel->id;
        $producto->granel_por_unidad = $porUnidad;

        return $envases;
    }

    /**
     * Dentro de la transacción del producto, con la ficha ya guardada: reemplaza sus envases si cambiaron y deja el
     * cambio en la auditoría (granel y granel por unidad ya van en la auditoría de la ficha).
     *
     * @param  list<array{producto_id: int, cantidad: string}>  $envases
     */
    public function guardar(User $actor, Producto $producto, array $envases): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Presentaciones::guardar() requiere una transacción abierta.');
        }

        $empresaId = (int) $producto->empresa_id;
        $antes = DB::table('presentacion_envases')->where('empresa_id', $empresaId)->where('presentacion_id', $producto->id)
            ->orderBy('orden')->get(['producto_id', 'cantidad'])
            ->map(fn (object $f): array => ['producto_id' => (int) $f->producto_id, 'cantidad' => (string) $f->cantidad])->all();

        if ($antes === $envases) {
            return;
        }

        DB::table('presentacion_envases')->where('empresa_id', $empresaId)->where('presentacion_id', $producto->id)->delete();
        DB::table('presentacion_envases')->insert(array_map(fn (array $e, int $i): array => [
            'empresa_id' => $empresaId, 'presentacion_id' => $producto->id, 'producto_id' => $e['producto_id'], 'orden' => $i + 1, 'cantidad' => $e['cantidad'],
        ], $envases, array_keys($envases)));

        $this->auditor->registrar('producto.envases', $producto, $actor, $empresaId, ['envases' => $antes], ['envases' => $envases]);
    }

    /**
     * Composición vigente de presentaciones de un granel, para el envasado: granel por unidad y envases de cada una.
     * Dos consultas para todas.
     *
     * @param  list<int>  $presentacionIds
     * @return array<int, array{granel_por_unidad: string, envases: array<int, string>}> presentacion_id => composición
     */
    public static function composicion(int $empresaId, int $granelId, array $presentacionIds): array
    {
        if ($presentacionIds === []) {
            return [];
        }

        $porUnidad = DB::table('productos')->where('empresa_id', $empresaId)->where('granel_id', $granelId)
            ->whereIn('id', $presentacionIds)->orderBy('id')->pluck('granel_por_unidad', 'id')
            ->mapWithKeys(fn ($cantidad, $id): array => [(int) $id => (string) $cantidad])->all();

        $envases = [];
        $filas = DB::table('presentacion_envases')->where('empresa_id', $empresaId)
            ->whereIn('presentacion_id', array_keys($porUnidad))->orderBy('presentacion_id')->orderBy('orden')
            ->get(['presentacion_id', 'producto_id', 'cantidad']);

        foreach ($filas as $envase) {
            $envases[(int) $envase->presentacion_id][(int) $envase->producto_id] = (string) $envase->cantidad;
        }

        $resultado = [];

        foreach ($porUnidad as $id => $cantidad) {
            $resultado[$id] = ['granel_por_unidad' => $cantidad, 'envases' => $envases[$id] ?? []];
        }

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $errores
     *
     * @param-out array<string, mixed>  $errores
     *
     * @return list<array{producto_id: int, cantidad: string}>
     */
    private function envases(int $empresaId, mixed $entrada, array &$errores): array
    {
        $filas = [];

        foreach (is_array($entrada) ? $entrada : [] as $indice => $fila) {
            $id = is_array($fila) && is_scalar($fila['producto_id'] ?? null) ? trim((string) $fila['producto_id']) : '';
            $cantidad = is_array($fila) && is_scalar($fila['cantidad'] ?? null) ? trim((string) $fila['cantidad']) : '';

            // Una fila vacía (agregada y sin completar) no cuenta.
            if ($id === '' && $cantidad === '') {
                continue;
            }

            $filas[$indice] = ['id' => ctype_digit($id) ? (int) $id : 0, 'cantidad' => $cantidad];
        }

        if (count($filas) > self::MAX_ENVASES) {
            $errores['envases'] = __('Se admiten hasta :max materiales de envasado.', ['max' => self::MAX_ENVASES]);

            return [];
        }

        $materias = Producto::query()->with('unidad:id,abreviatura,admite_decimales')
            ->where('empresa_id', $empresaId)->where('clase', Producto::CLASE_MATERIA_PRIMA)
            ->whereIn('id', array_values(array_unique(array_column($filas, 'id'))))
            ->get(['id', 'nombre', 'contenido', 'tipo_venta', 'unidad_medida_id'])->keyBy('id');
        $envases = [];
        $vistos = [];

        foreach ($filas as $indice => $fila) {
            $materia = $materias->get($fila['id']);
            $cantidad = Decimal::normalizar($fila['cantidad'], Decimal::ESCALA_CANTIDAD);

            if ($materia === null) {
                $errores["envases.{$indice}.producto_id"] = __('Elige una materia prima de tu empresa.');
            } elseif (isset($vistos[$materia->id])) {
                $errores["envases.{$indice}.producto_id"] = __(':materia ya está en la lista.', ['materia' => $materia->nombre]);
            } elseif ($cantidad === null || Decimal::comparar($cantidad, '0') <= 0 || Decimal::comparar($cantidad, self::MAXIMO) > 0) {
                $errores["envases.{$indice}.cantidad"] = __(':materia: escribe la cantidad por unidad, mayor que cero (hasta 3 decimales).', ['materia' => $materia->nombre]);
            } elseif (Escalado::esEntera($materia->tipo_venta, (bool) $materia->unidad->admite_decimales) && ! Decimal::esEntero($cantidad)) {
                $errores["envases.{$indice}.cantidad"] = __(':materia se usa en unidades enteras (:unidad): escribe una cantidad sin decimales.', ['materia' => $materia->nombre, 'unidad' => $materia->unidad->abreviatura]);
            } else {
                $vistos[$materia->id] = true;
                $envases[] = ['producto_id' => $materia->id, 'cantidad' => $cantidad];
            }
        }

        return $envases;
    }
}
