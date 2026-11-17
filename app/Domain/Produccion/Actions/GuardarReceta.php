<?php

namespace App\Domain\Produccion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\RecetaItem;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Única escritura de recetas (ESPECIFICACION_PRODUCCION.md § 5, PR4, PR5 y PR15).
 *
 * - Solo para productos de venta activos que controlan stock y no son combinaciones de tallas y colores.
 * - Cantidad base > 0 en la unidad del producto; líneas de materias primas activas, sin repetir, con cantidad > 0 en la
 *   unidad de cada una. Las unidades enteras (o los productos que se venden por unidad) no admiten decimales, como en
 *   LibroStock.
 * - Guardar crea la versión N + 1 y la deja vigente; la anterior queda de solo lectura. Las órdenes guardan su propia
 *   copia de los ingredientes, así que una versión nueva nunca cambia una orden ya emitida.
 *
 * Concurrencia e idempotencia: la transacción bloquea la ficha del producto (FOR UPDATE), así dos guardados de la misma
 * receta se ordenan. Si la vigente ya tiene exactamente el contenido enviado (doble clic, reintento) no se crea nada.
 * Si otra persona guardó una versión mientras se editaba, se rechaza en vez de pisar sus cambios.
 */
final class GuardarReceta
{
    public const MAX_LINEAS = 100;

    /** Máximo de la cantidad base y de cada línea: DECIMAL(18,3). */
    private const MAXIMO = '999999999999999';

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * $datos['version'] es la versión que se estaba editando (null al crear).
     *
     * @param  array{producto_id: int|string|null, cantidad_base: string|null, nota?: string|null, lineas: list<array{producto_id: int|string|null, cantidad: string|null}>, version?: int|string|null}  $datos
     */
    public function ejecutar(User $actor, array $datos): Receta
    {
        Gate::forUser($actor)->authorize('usar-produccion');

        $empresaId = (int) $actor->empresa_id;
        $productoId = is_numeric($datos['producto_id'] ?? null) ? (int) $datos['producto_id'] : 0;
        $producto = Producto::query()->with('unidad:id,abreviatura,admite_decimales')->find($productoId);

        if ($producto === null) {
            throw ValidationException::withMessages(['producto_id' => __('Elige un producto de tu empresa.')]);
        }

        self::exigirFabricable($producto->clase, (bool) $producto->activo, (bool) $producto->controla_stock, $producto->modelo_id, $producto->nombre);
        $base = $this->cantidadBase((string) ($datos['cantidad_base'] ?? ''), $producto);
        $nota = trim((string) ($datos['nota'] ?? ''));

        if (mb_strlen($nota) > 250) {
            throw ValidationException::withMessages(['nota' => __('La nota admite hasta 250 caracteres.')]);
        }

        $lineas = $this->lineas($datos['lineas']);
        $version = is_numeric($datos['version'] ?? null) ? (int) $datos['version'] : null;
        $nota = $nota === '' ? null : $nota;

        return DB::transaction(function () use ($actor, $empresaId, $producto, $base, $nota, $lineas, $version): Receta {
            // El estado real de la ficha, bloqueado: dos guardados de la misma receta no se cruzan.
            $ficha = DB::table('productos')->where('empresa_id', $empresaId)->where('id', $producto->id)->lockForUpdate()
                ->first(['clase', 'activo', 'controla_stock', 'modelo_id']);
            self::exigirFabricable((string) $ficha?->clase, (bool) $ficha?->activo, (bool) $ficha?->controla_stock, $ficha?->modelo_id, $producto->nombre);

            $vigente = Receta::query()->where('producto_id', $producto->id)->where('vigente', true)->with('items')->first();

            if ($vigente !== null && $this->mismoContenido($vigente, $base, $nota, $lineas)) {
                return $vigente;
            }

            if ($vigente !== null && $version !== $vigente->version) {
                throw ValidationException::withMessages(['receta' => $version === null
                    ? __(':producto ya tiene receta (versión :version). Edítala para crear una versión nueva.', ['producto' => $producto->nombre, 'version' => $vigente->version])
                    : __('La receta de :producto cambió mientras la editabas: ahora está en la versión :version. Vuelve a abrirla para no perder esos cambios.', ['producto' => $producto->nombre, 'version' => $vigente->version]),
                ]);
            }

            if ($vigente === null && $version !== null) {
                throw ValidationException::withMessages(['receta' => __('La receta que editabas ya no existe. Vuelve a abrirla.')]);
            }

            $siguiente = (int) Receta::query()->where('producto_id', $producto->id)->max('version') + 1;

            if ($vigente !== null) {
                $vigente->vigente = false;
                $vigente->save();
            }

            $receta = new Receta;
            $receta->producto_id = $producto->id;
            $receta->version = $siguiente;
            $receta->cantidad_base = $base;
            $receta->nota = $nota;
            $receta->vigente = true;
            // Desactivada sigue desactivada: guardar una versión no la reactiva.
            $receta->activa = $vigente === null || $vigente->activa;
            $receta->usuario_id = $actor->id;
            $receta->save();

            RecetaItem::query()->insert(array_map(fn (array $linea, int $indice): array => [
                'empresa_id' => $empresaId, 'receta_id' => $receta->id, 'producto_id' => $linea['producto_id'],
                'orden' => $indice + 1, 'cantidad' => $linea['cantidad'],
            ], $lineas, array_keys($lineas)));

            $this->auditor->registrar($vigente === null ? 'receta.creada' : 'receta.version', $receta, $actor, $empresaId,
                $vigente === null ? null : $this->resumen($vigente->version, (string) $vigente->cantidad_base, $vigente->nota, array_values($vigente->items->map(fn (RecetaItem $i): array => ['producto_id' => $i->producto_id, 'cantidad' => (string) $i->cantidad])->all())),
                ['producto_id' => $producto->id] + $this->resumen($receta->version, $base, $nota, $lineas));

            return $receta->load('items');
        });
    }

    /**
     * Desactiva o reactiva la receta del producto (§ 5.5): una receta desactivada no se usa para órdenes nuevas. No crea
     * versión. Repetir la misma solicitud no hace nada.
     */
    public function cambiarEstado(User $actor, Producto $producto, bool $activa): Receta
    {
        Gate::forUser($actor)->authorize('usar-produccion');

        return DB::transaction(function () use ($actor, $producto, $activa): Receta {
            DB::table('productos')->where('empresa_id', (int) $actor->empresa_id)->where('id', $producto->id)->lockForUpdate()->value('id');
            $vigente = Receta::query()->where('producto_id', $producto->id)->where('vigente', true)->first();

            abort_if($vigente === null, 404);

            if ($vigente->activa === $activa) {
                return $vigente;
            }

            $vigente->activa = $activa;
            $vigente->save();
            $this->auditor->registrar($activa ? 'receta.activada' : 'receta.desactivada', $vigente, $actor, $vigente->empresa_id,
                ['activa' => ! $activa], ['activa' => $activa, 'version' => $vigente->version]);

            return $vigente;
        });
    }

    /**
     * § 5.1 (PR4): solo se produce un producto de venta, activo, que controla stock y no es combinación de un modelo.
     * También lo usan las órdenes de producción al emitir.
     */
    public static function exigirFabricable(string $clase, bool $activo, bool $controlaStock, mixed $modeloId, string $nombre): void
    {
        $error = match (true) {
            $clase === Producto::CLASE_MATERIA_PRIMA => __(':producto es materia prima: la receta es para el producto que se fabrica.', ['producto' => $nombre]),
            $modeloId !== null => __(':producto es una combinación de tallas y colores: no lleva receta.', ['producto' => $nombre]),
            ! $activo => __(':producto está inactivo. Actívalo en Productos para producirlo.', ['producto' => $nombre]),
            ! $controlaStock => __(':producto no controla stock. Marca «Controla stock» en Productos para producirlo.', ['producto' => $nombre]),
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['producto_id' => $error]);
        }
    }

    private function cantidadBase(string $texto, Producto $producto): string
    {
        $base = Decimal::normalizar($texto, Decimal::ESCALA_CANTIDAD);

        if ($base === null || Decimal::comparar($base, '0') <= 0 || Decimal::comparar($base, self::MAXIMO) > 0) {
            throw ValidationException::withMessages(['cantidad_base' => __('Ingresa una cantidad base mayor que cero (hasta 3 decimales).')]);
        }

        if ($this->esEntera($producto->tipo_venta, (bool) $producto->unidad->admite_decimales) && ! Decimal::esEntero($base)) {
            throw ValidationException::withMessages(['cantidad_base' => __(':producto se mide en unidades enteras: escribe la cantidad base sin decimales.', ['producto' => $producto->nombre])]);
        }

        return $base;
    }

    /**
     * Normaliza y valida las líneas con una sola consulta de productos. El scope de empresa deja fuera los de otra.
     *
     * @param  mixed  $entrada
     * @return list<array{producto_id: int, cantidad: string}>
     */
    private function lineas($entrada): array
    {
        $entrada = is_array($entrada) ? array_values($entrada) : [];

        if ($entrada === []) {
            throw ValidationException::withMessages(['lineas' => __('Agrega al menos una materia prima.')]);
        }

        if (count($entrada) > self::MAX_LINEAS) {
            throw ValidationException::withMessages(['lineas' => __('Una receta admite hasta :max materias primas.', ['max' => self::MAX_LINEAS])]);
        }

        $ids = [];

        foreach ($entrada as $linea) {
            $id = is_array($linea) && is_numeric($linea['producto_id'] ?? null) ? (int) $linea['producto_id'] : 0;
            $ids[] = $id;
        }

        /** @var Collection<int, Producto> $materias */
        $materias = Producto::query()->whereIn('productos.id', array_unique($ids))
            ->join('unidades_medida as um', 'um.id', '=', 'productos.unidad_medida_id')
            ->get(['productos.id', 'productos.nombre', 'productos.contenido', 'productos.unidad_medida_id', 'productos.clase', 'productos.activo', 'productos.tipo_venta', 'um.admite_decimales', 'um.abreviatura'])
            ->keyBy('id');
        $errores = [];
        $lineas = [];
        $vistas = [];

        foreach ($entrada as $indice => $linea) {
            $materia = $materias->get($ids[$indice]);

            if ($materia === null) {
                $errores["lineas.{$indice}.producto_id"] = __('Una de las materias primas no existe.');

                continue;
            }

            if ($materia->clase !== Producto::CLASE_MATERIA_PRIMA) {
                $errores["lineas.{$indice}.producto_id"] = __(':producto no es materia prima.', ['producto' => $materia->nombre]);

                continue;
            }

            if (! $materia->activo) {
                $errores["lineas.{$indice}.producto_id"] = __(':producto está inactiva. Actívala en Materias primas o quítala de la receta.', ['producto' => $materia->nombre]);

                continue;
            }

            if (isset($vistas[$materia->id])) {
                $errores["lineas.{$indice}.producto_id"] = __(':producto está dos veces: deja una sola línea con la cantidad total.', ['producto' => $materia->nombre]);

                continue;
            }

            $vistas[$materia->id] = true;
            $cantidad = Decimal::normalizar(is_array($linea) && is_scalar($linea['cantidad'] ?? null) ? (string) $linea['cantidad'] : '', Decimal::ESCALA_CANTIDAD);

            if ($cantidad === null || Decimal::comparar($cantidad, '0') <= 0 || Decimal::comparar($cantidad, self::MAXIMO) > 0) {
                $errores["lineas.{$indice}.cantidad"] = __(':producto: la cantidad debe ser mayor que cero (hasta 3 decimales).', ['producto' => $materia->nombre]);
            } elseif ($this->esEntera($materia->tipo_venta, (bool) $materia->getAttribute('admite_decimales')) && ! Decimal::esEntero($cantidad)) {
                $errores["lineas.{$indice}.cantidad"] = __(':producto se usa en unidades enteras (:unidad). Escribe una cantidad sin decimales o usa una unidad menor.', ['producto' => $materia->nombre, 'unidad' => $materia->getAttribute('abreviatura')]);
            } else {
                $lineas[] = ['producto_id' => $materia->id, 'cantidad' => $cantidad];
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores + ['lineas' => __('Revisa las materias primas marcadas.')]);
        }

        return $lineas;
    }

    /** La misma regla que LibroStock: lo que se vende por unidad, o se mide en una unidad entera, no lleva decimales. */
    private function esEntera(?string $tipoVenta, bool $admiteDecimales): bool
    {
        return ($tipoVenta ?? 'unidad') === 'unidad' || ! $admiteDecimales;
    }

    /**
     * @param  list<array{producto_id: int, cantidad: string}>  $lineas
     */
    private function mismoContenido(Receta $vigente, string $base, ?string $nota, array $lineas): bool
    {
        if (Decimal::comparar((string) $vigente->cantidad_base, $base) !== 0 || $vigente->nota !== $nota || $vigente->items->count() !== count($lineas)) {
            return false;
        }

        foreach ($vigente->items->values() as $indice => $item) {
            if ($item->producto_id !== $lineas[$indice]['producto_id'] || Decimal::comparar((string) $item->cantidad, $lineas[$indice]['cantidad']) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{producto_id: int, cantidad: string}>  $lineas
     * @return array<string, mixed>
     */
    private function resumen(int $version, string $base, ?string $nota, array $lineas): array
    {
        return ['version' => $version, 'cantidad_base' => $base, 'nota' => $nota, 'items' => $lineas];
    }
}
