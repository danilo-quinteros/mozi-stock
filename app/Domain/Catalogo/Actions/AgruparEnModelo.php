<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Catalogo\Variantes;
use App\Models\Color;
use App\Models\Modelo;
use App\Models\Producto;
use App\Models\Talla;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Agrupa productos sueltos en un modelo, existente o nuevo (ESPECIFICACION_TALLAS_Y_COLORES.md § 9, opción B).
 *
 * Cada producto conserva su id, su stock en todas las sucursales, su historial, su SKU y su código de barras: solo se le
 * asignan modelo, talla y color. Cambian, y la vista previa lo muestra antes: el nombre (formato de variante; las
 * ventas pasadas guardan el suyo), la categoría (la del modelo) y el precio, que nunca cambia en silencio: si los tres
 * precios coinciden con los del modelo queda en «precio del modelo»; si alguno difiere, queda como precio propio con
 * los mismos importes.
 *
 * Reglas: solo productos sueltos de la empresa y sin vínculo con un catálogo externo (la sincronización les cambiaría
 * el nombre), con la misma unidad de medida que el modelo, tallas del grupo del modelo y tallas y colores activos, sin
 * combinaciones repetidas (también lo garantiza el índice único `clave_variante`) y con al menos una combinación activa.
 * Todo en una transacción: el modelo con FOR UPDATE y después los productos (mismo orden que GuardarModelo), con
 * auditoría. Repetir la petición no duplica nada: los productos ya agrupados dejan de ser sueltos y se rechazan.
 */
final class AgruparEnModelo
{
    /** Productos por operación: la pantalla es una tabla que se revisa fila por fila. */
    public const MAXIMO = 100;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Variantes $variantes,
        private readonly GuardarModelo $guardarModelo,
    ) {}

    /**
     * Qué cambiaría en cada producto, sin guardar nada. Lanza los mismos errores que ejecutar().
     *
     * @param  list<int>  $productoIds
     * @param  array<string, mixed>  $datosModelo  datos de GuardarModelo para un modelo nuevo (si $modeloId es null)
     * @param  array<int|string, array{talla_id?: int|string|null, color_id?: int|string|null}>  $asignaciones  por producto
     * @return list<array{id: int, nombre_antes: string, nombre_despues: string, nombre_visible: string, categoria_antes: int|null, categoria_despues: int|null, precio_consumidor: string, precio_propio: bool, activo: bool, talla_id: int|null, color_id: int|null}>
     */
    public function vistaPrevia(User $actor, array $productoIds, ?int $modeloId, array $datosModelo, array $asignaciones): array
    {
        $this->autorizar($actor, $modeloId);
        $empresaId = (int) $actor->empresa_id;
        $modelo = $modeloId === null ? $this->modeloNuevo($empresaId, $datosModelo) : $this->modeloExistente($empresaId, $modeloId, false);

        return $this->planificar($empresaId, $modelo, $this->productos($empresaId, $productoIds, false), $asignaciones, false);
    }

    /**
     * @param  list<int>  $productoIds
     * @param  array<string, mixed>  $datosModelo
     * @param  array<int|string, array{talla_id?: int|string|null, color_id?: int|string|null}>  $asignaciones
     */
    public function ejecutar(User $actor, array $productoIds, ?int $modeloId, array $datosModelo, array $asignaciones): Modelo
    {
        $this->autorizar($actor, $modeloId);
        $empresaId = (int) $actor->empresa_id;

        return DB::transaction(function () use ($actor, $empresaId, $productoIds, $modeloId, $datosModelo, $asignaciones): Modelo {
            $modelo = $modeloId === null ? $this->modeloNuevo($empresaId, $datosModelo) : $this->modeloExistente($empresaId, $modeloId, true);
            $productos = $this->productos($empresaId, $productoIds, true);
            $plan = $this->planificar($empresaId, $modelo, $productos, $asignaciones, true);
            $nuevo = ! $modelo->exists;

            if ($nuevo) {
                try {
                    $modelo->save();
                } catch (QueryException $e) {
                    throw $this->duplicado($e, 'nombre', __('Ya existe un modelo con ese nombre.'));
                }
            }

            $ahora = now();
            $antes = [];

            foreach ($plan as $fila) {
                $producto = $productos->get($fila['id']);
                $antes[$fila['id']] = $producto->only(['nombre', 'categoria_id', 'precio_consumidor', 'precio_minorista', 'precio_mayorista']);

                // Una sentencia por producto (máximo MAXIMO), cada una con su talla y color; el índice único de
                // clave_variante rechaza una combinación repetida por una carrera con otra petición.
                $this->escribir(fn () => DB::table('productos')->where('empresa_id', $empresaId)->where('id', $fila['id'])->whereNull('modelo_id')
                    ->update([
                        'modelo_id' => $modelo->id, 'talla_id' => $fila['talla_id'], 'color_id' => $fila['color_id'],
                        'nombre' => $fila['nombre_despues'], 'categoria_id' => $modelo->categoria_id,
                        'precio_propio' => $fila['precio_propio'], 'updated_at' => $ahora,
                    ]));
            }

            $this->auditor->registrar('modelo.agrupado', $modelo, $actor, $empresaId, ['productos' => $antes], [
                'modelo' => $modelo->nombre,
                'modelo_nuevo' => $nuevo,
                'productos' => collect($plan)->mapWithKeys(fn (array $f): array => [$f['id'] => [
                    'nombre' => $f['nombre_despues'], 'talla_id' => $f['talla_id'], 'color_id' => $f['color_id'], 'precio_propio' => $f['precio_propio'],
                ]])->all(),
            ]);

            return $modelo;
        });
    }

    private function autorizar(User $actor, ?int $modeloId): void
    {
        Gate::forUser($actor)->authorize('create', Modelo::class);
        $this->variantes->exigir((int) $actor->empresa_id);

        if ($modeloId !== null) {
            // El scope y el filtro de empresa dan 404 a modelos de otra empresa.
            $modelo = Modelo::query()->where('empresa_id', $actor->empresa_id)->find($modeloId);
            abort_if($modelo === null, 404);
            Gate::forUser($actor)->authorize('update', $modelo);
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function modeloNuevo(int $empresaId, array $datos): Modelo
    {
        $modelo = new Modelo;
        $modelo->empresa_id = $empresaId;

        foreach ($this->guardarModelo->validarModelo($empresaId, $modelo, $datos) as $campo => $valor) {
            $modelo->setAttribute($campo, $valor);
        }

        $modelo->activo = true;

        return $modelo;
    }

    private function modeloExistente(int $empresaId, int $modeloId, bool $bloquear): Modelo
    {
        $modelo = Modelo::query()->where('empresa_id', $empresaId)->whereKey($modeloId)->when($bloquear, fn ($q) => $q->lockForUpdate())->firstOrFail();

        if (! $modelo->activo) {
            throw ValidationException::withMessages(['modelo_id' => __('Elige un modelo activo.')]);
        }

        return $modelo;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Producto>
     */
    private function productos(int $empresaId, array $ids, bool $bloquear): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            throw ValidationException::withMessages(['productos' => __('Marca al menos un producto suelto.')]);
        }

        if (count($ids) > self::MAXIMO) {
            throw ValidationException::withMessages(['productos' => __('Agrupa hasta :max productos por vez.', ['max' => self::MAXIMO])]);
        }

        $productos = Producto::query()->where('empresa_id', $empresaId)->whereIn('id', $ids)->orderBy('id')
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->withExists('vinculoExterno')
            ->get()->keyBy('id');

        // Ids de otra empresa o inexistentes: no se revela cuál.
        abort_if($productos->count() !== count($ids), 404);

        return $productos;
    }

    /**
     * @param  Collection<int, Producto>  $productos
     * @param  array<int|string, array{talla_id?: int|string|null, color_id?: int|string|null}>  $asignaciones
     * @return list<array{id: int, nombre_antes: string, nombre_despues: string, nombre_visible: string, categoria_antes: int|null, categoria_despues: int|null, precio_consumidor: string, precio_propio: bool, activo: bool, talla_id: int|null, color_id: int|null}>
     */
    private function planificar(int $empresaId, Modelo $modelo, Collection $productos, array $asignaciones, bool $bloquear): array
    {
        $errores = [];
        $pedidos = [];

        foreach ($productos as $producto) {
            $asignacion = $asignaciones[$producto->id] ?? [];
            $pedidos[$producto->id] = [$this->id($asignacion['talla_id'] ?? null), $this->id($asignacion['color_id'] ?? null)];

            if ($producto->modelo_id !== null) {
                $errores["asignaciones.{$producto->id}"] = __(':producto ya pertenece a un modelo.', ['producto' => $producto->nombre]);
            } elseif ((bool) $producto->getAttribute('vinculo_externo_exists')) {
                $errores["asignaciones.{$producto->id}"] = __(':producto se sincroniza con un catálogo externo: no se puede agrupar.', ['producto' => $producto->nombre]);
            } elseif ((int) $producto->unidad_medida_id !== (int) $modelo->unidad_medida_id) {
                $errores["asignaciones.{$producto->id}"] = __('Tiene otra unidad de medida: todos deben usar la del modelo.');
            }
        }

        $tallaIds = array_values(array_unique(array_filter(array_column($pedidos, 0))));
        $colorIds = array_values(array_unique(array_filter(array_column($pedidos, 1))));
        $tallas = $tallaIds === [] ? collect() : Talla::query()->where('empresa_id', $empresaId)->whereIn('id', $tallaIds)->get()->keyBy('id');
        $colores = $colorIds === [] ? collect() : Color::query()->where('empresa_id', $empresaId)->whereIn('id', $colorIds)->get()->keyBy('id');

        // Combinaciones que el modelo ya tiene, activas o no (el índice único las cuenta todas).
        $existentes = $modelo->exists
            ? DB::table('productos')->where('empresa_id', $empresaId)->where('modelo_id', $modelo->id)->when($bloquear, fn ($q) => $q->lockForUpdate())
                ->get(['talla_id', 'color_id', 'activo'])
            : collect();
        $usadas = $existentes->mapWithKeys(fn (stdClass $v): array => [Variantes::clave($v->talla_id === null ? null : (int) $v->talla_id, $v->color_id === null ? null : (int) $v->color_id) => true])->all();
        $claves = [];

        foreach ($pedidos as $id => [$tallaId, $colorId]) {
            $talla = $tallaId === null ? null : $tallas->get($tallaId);
            $color = $colorId === null ? null : $colores->get($colorId);

            if ($tallaId !== null && $talla === null) {
                $errores["asignaciones.{$id}.talla_id"] = __('Elige una talla de tu empresa.');
            } elseif ($talla !== null && ($modelo->grupo_talla_id === null || (int) $talla->grupo_talla_id !== (int) $modelo->grupo_talla_id)) {
                $errores["asignaciones.{$id}.talla_id"] = __('Elige una talla del grupo del modelo.');
            } elseif ($talla !== null && ! $talla->activa) {
                $errores["asignaciones.{$id}.talla_id"] = __('La talla :talla está desactivada: no se usa en combinaciones nuevas.', ['talla' => $talla->nombre]);
            }

            if ($colorId !== null && $color === null) {
                $errores["asignaciones.{$id}.color_id"] = __('Elige un color de tu empresa.');
            } elseif ($color !== null && ! $color->activo) {
                $errores["asignaciones.{$id}.color_id"] = __('El color :color está desactivado: no se usa en combinaciones nuevas.', ['color' => $color->nombre]);
            }

            $clave = Variantes::clave($tallaId, $colorId);

            if (isset($usadas[$clave])) {
                $errores["asignaciones.{$id}"] ??= __('El modelo ya tiene esa talla y color.');
            } elseif (isset($claves[$clave])) {
                $errores["asignaciones.{$id}"] ??= __('Otro producto marcado tiene la misma talla y color.');
                $errores["asignaciones.{$claves[$clave]}"] ??= __('Otro producto marcado tiene la misma talla y color.');
            }

            $claves[$clave] ??= $id;
        }

        $activas = $existentes->where('activo', true)->count() + $productos->where('activo', true)->count();

        if ($errores === [] && $activas === 0) {
            $errores['productos'] = __('El modelo debe tener al menos una combinación activa: marca algún producto activo.');
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $plan = [];

        foreach ($productos as $producto) {
            [$tallaId, $colorId] = $pedidos[$producto->id];
            $nombre = Variantes::nombre($modelo->nombre, $tallaId === null ? null : $tallas[$tallaId]->nombre, $colorId === null ? null : $colores[$colorId]->nombre);
            // Como se verá: el contenido del producto («350 g»), si tiene, se conserva y se sigue mostrando.
            $visible = new Producto;
            $visible->setRawAttributes(['nombre' => $nombre, 'contenido' => $producto->getAttributes()['contenido'] ?? null, 'unidad_medida_id' => $producto->unidad_medida_id]);
            $plan[] = [
                'id' => $producto->id,
                'nombre_antes' => $producto->nombre,
                'nombre_despues' => $nombre,
                'nombre_visible' => $visible->nombre,
                'categoria_antes' => $producto->categoria_id,
                'categoria_despues' => $modelo->categoria_id,
                'precio_consumidor' => (string) $producto->precio_consumidor,
                'precio_propio' => ! $this->mismoPrecio($producto, $modelo),
                'activo' => (bool) $producto->activo,
                'talla_id' => $tallaId,
                'color_id' => $colorId,
            ];
        }

        return $plan;
    }

    /** Los tres precios coinciden con los del modelo (vacío = vacío). */
    private function mismoPrecio(Producto $producto, Modelo $modelo): bool
    {
        foreach (['precio_consumidor', 'precio_minorista', 'precio_mayorista'] as $campo) {
            $a = $producto->getAttribute($campo);
            $b = $modelo->getAttribute($campo);

            if ($a === null || $b === null ? $a !== $b : Decimal::comparar((string) $a, (string) $b, Decimal::ESCALA_DINERO) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function id(mixed $valor): ?int
    {
        return is_scalar($valor) && ctype_digit((string) $valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    private function escribir(callable $escritura): void
    {
        try {
            $escritura();
        } catch (QueryException $e) {
            throw $this->duplicado($e, 'productos', __('Otra persona guardó al mismo tiempo una combinación igual en el modelo. Revisa los datos y vuelve a intentar.'));
        }
    }

    private function duplicado(QueryException $e, string $campo, string $mensaje): Throwable
    {
        return ($e->errorInfo[1] ?? null) === 1062 ? ValidationException::withMessages([$campo => $mensaje]) : $e;
    }
}
