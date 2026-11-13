<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Catalogo\Importacion\ValidadorImportacion;
use App\Domain\Inventario\LibroLotes;
use App\Domain\Inventario\LibroStock;
use App\Domain\Tenancy\ResolverSucursal;
use App\Enums\TipoOperacionInventario;
use App\Models\ImportacionProducto;
use App\Models\ImportacionProductoFila;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Crea, en una sola transacción, las categorías y unidades nuevas, los productos de las filas
 * válidas y (si el usuario lo pidió) el stock inicial como entradas «Inventario inicial» de
 * LibroStock en la sucursal elegida. Vuelve a validar justo antes: si el resultado cambió
 * desde que el usuario lo revisó, no confirma. La fila de la importación se bloquea y solo
 * se confirma una vez: un doble clic o un reintento devuelve el resultado ya registrado.
 *
 * Lotes y vencimientos (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 7): con la capacidad activa, los productos marcados
 * nacen con «Controla lote y vencimiento» y su stock inicial entra al lote de la fila o, sin él, al de regularización.
 */
final class ConfirmarImportacionProductos
{
    private const LOTE = 500;

    public function __construct(
        private readonly ValidadorImportacion $validador,
        private readonly GuardarCategoria $categorias,
        private readonly GuardarUnidadMedida $unidades,
        private readonly LibroStock $libro,
        private readonly ResolverSucursal $resolverSucursal,
        private readonly Auditor $auditor,
        private readonly LibroLotes $lotes,
    ) {}

    /**
     * @param  string  $firma  firma del resumen que el usuario revisó
     * @return array<string, mixed> resultado de la importación
     */
    public function ejecutar(User $actor, ImportacionProducto $importacion, bool $omitirErrores, string $firma): array
    {
        Gate::forUser($actor)->authorize('create', Producto::class);

        if ($importacion->empresa_id !== (int) $actor->empresa_id) {
            abort(404);
        }

        if ($importacion->estado === ImportacionProducto::COMPLETADA) {
            return $importacion->resumen['resultado'] ?? [];
        }

        if ($importacion->estado !== ImportacionProducto::REVISION) {
            throw ValidationException::withMessages(['confirmar' => __('Revisa la importación antes de confirmarla.')]);
        }

        $resumen = $this->revalidar($importacion);

        if (! hash_equals($resumen['firma'], $firma)) {
            throw ValidationException::withMessages(['confirmar' => __('Mientras revisabas cambiaron datos del catálogo. Revisa el resultado actualizado y vuelve a confirmar.')]);
        }

        if ($resumen['validas'] === 0) {
            throw ValidationException::withMessages(['confirmar' => __('No hay filas listas para importar.')]);
        }

        if ($resumen['errores'] > 0 && ! $omitirErrores) {
            throw ValidationException::withMessages(['confirmar' => __('Hay filas con errores: elige importar solo las correctas o corrige el archivo.')]);
        }

        $sucursal = $importacion->usar_stock === true && $resumen['con_stock'] > 0
            ? $this->resolverSucursal->paraOperacion($actor, $importacion->sucursal_stock_id)
            : null;

        try {
            [$resultado, $ruta] = DB::transaction(fn (): array => $this->aplicar($actor, $importacion->id, $resumen, $sucursal), attempts: 3);
        } catch (UniqueConstraintViolationException) {
            // Otro usuario registró el mismo código, categoría o unidad mientras tanto.
            $this->revalidar($importacion);

            throw ValidationException::withMessages(['confirmar' => __('Mientras revisabas se registraron productos, categorías o unidades con los mismos datos. Revisa el resultado actualizado y vuelve a confirmar.')]);
        }

        if ($ruta !== null) {
            Storage::disk(PrepararImportacionProductos::DISCO)->delete($ruta);
        }

        $importacion->refresh();

        return $resultado;
    }

    /**
     * @return array{total: int, validas: int, errores: int, existentes: int, advertencias: int, con_stock: int, categorias_nuevas: int, unidades_nuevas: int, firma: string}
     */
    private function revalidar(ImportacionProducto $importacion): array
    {
        $resumen = $this->validador->validar($importacion);
        $importacion->resumen = $resumen;
        $importacion->save();

        return $resumen;
    }

    /**
     * @param  array<string, mixed>  $resumen
     * @return array{0: array<string, mixed>, 1: string|null} resultado y archivo a borrar
     */
    private function aplicar(User $actor, int $importacionId, array $resumen, ?Sucursal $sucursal): array
    {
        $importacion = ImportacionProducto::query()->whereKey($importacionId)->lockForUpdate()->firstOrFail();

        if ($importacion->estado === ImportacionProducto::COMPLETADA) {
            return [$importacion->resumen['resultado'] ?? [], null];
        }

        if ($importacion->estado !== ImportacionProducto::REVISION) {
            throw ValidationException::withMessages(['confirmar' => __('Revisa la importación antes de confirmarla.')]);
        }

        $empresaId = $importacion->empresa_id;
        $valores = $importacion->valores ?? [];
        $filas = ImportacionProductoFila::query()
            ->where('importacion_id', $importacion->id)
            ->where('estado', ImportacionProductoFila::VALIDA)
            ->orderBy('fila')
            ->get(['id', 'fila', 'normalizado']);

        $categoriasNuevas = [];
        $unidadesNuevas = [];

        foreach ($filas as $fila) {
            $datos = (array) $fila->normalizado;

            if (isset($datos['categoria']['crear']) && ! isset($categoriasNuevas[$datos['categoria']['crear']])) {
                $valor = $valores['categorias'][$datos['categoria']['crear']] ?? throw $this->valoresIncompletos();
                $categoriasNuevas[$datos['categoria']['crear']] = $this->categorias->crear($actor, (string) ($valor['nombre_nuevo'] ?? $valor['nombre']))->id;
            }

            if (isset($datos['unidad']['crear']) && ! isset($unidadesNuevas[$datos['unidad']['crear']])) {
                $valor = $valores['unidades'][$datos['unidad']['crear']] ?? throw $this->valoresIncompletos();
                $unidadesNuevas[$datos['unidad']['crear']] = $this->unidades->crear($actor, [
                    'nombre' => (string) ($valor['nombre_nuevo'] ?? $valor['nombre']),
                    'abreviatura' => (string) $valor['abreviatura'],
                    'admite_decimales' => (bool) $valor['decimales'],
                ])->id;
            }
        }

        $ahora = Carbon::now();
        $productos = [];
        // La capacidad se lee de nuevo aquí: si se apagó entre la revisión y la confirmación, nadie queda marcado.
        $conLotes = $this->lotes->activo($empresaId);

        foreach ($filas as $fila) {
            $datos = (array) $fila->normalizado;
            $productos[] = [
                'empresa_id' => $empresaId,
                'categoria_id' => isset($datos['categoria']['crear']) ? $categoriasNuevas[$datos['categoria']['crear']] : ($datos['categoria']['id'] ?? null),
                'unidad_medida_id' => isset($datos['unidad']['crear']) ? $unidadesNuevas[$datos['unidad']['crear']] : $datos['unidad']['id'],
                'tipo_venta' => $datos['tipo_venta'],
                'sku' => $datos['sku'],
                'codigo_barras' => $datos['codigo_barras'],
                'nombre' => $datos['nombre'],
                // Filas revisadas antes de la marca no la traen.
                'marca' => $datos['marca'] ?? null,
                'contenido' => $datos['contenido'],
                'descripcion' => $datos['descripcion'],
                'precio_consumidor' => $datos['precio_consumidor'],
                'precio_minorista' => $datos['precio_minorista'],
                'precio_mayorista' => $datos['precio_mayorista'],
                'stock_minimo' => $datos['stock_minimo'],
                // Filas revisadas antes de K3 no traen la marca: controlan stock, como todo producto existente.
                'controla_stock' => (bool) ($datos['controla_stock'] ?? true),
                'controla_vencimiento' => $conLotes && (bool) ($datos['controla_stock'] ?? true) && (bool) ($datos['controla_vencimiento'] ?? false),
                'activo' => true,
                'importacion_id' => $importacion->id,
                'importacion_fila' => $fila->fila,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        foreach (array_chunk($productos, self::LOTE) as $lote) {
            DB::table('productos')->insert($lote);
        }

        $ids = DB::table('productos')->where('empresa_id', $empresaId)->where('importacion_id', $importacion->id)->pluck('id', 'importacion_fila');

        DB::update(
            'UPDATE importacion_producto_filas f JOIN productos p ON p.importacion_id = f.importacion_id AND p.importacion_fila = f.fila
             SET f.producto_id = p.id WHERE f.importacion_id = ? AND p.empresa_id = ?',
            [$importacion->id, $empresaId],
        );

        $stock = $sucursal === null ? null : $this->cargarStock($actor, $importacion, $sucursal, $filas, $ids->all());
        $resultado = [
            'creados' => $ids->count(),
            'omitidas_error' => $resumen['errores'],
            'omitidas_existentes' => $resumen['existentes'],
            'categorias_creadas' => count($categoriasNuevas),
            'unidades_creadas' => count($unidadesNuevas),
            'stock' => $stock,
        ];

        $ruta = $importacion->archivo_path;
        $importacion->estado = ImportacionProducto::COMPLETADA;
        $importacion->confirmada_en = $ahora;
        $importacion->archivo_path = null;
        $importacion->resumen = $resumen + ['resultado' => $resultado];
        $importacion->save();

        $this->auditor->registrar('productos.importados', $importacion, $actor, $empresaId, null, ['archivo' => $importacion->archivo_nombre] + $resultado);

        return [$resultado, $ruta];
    }

    private function valoresIncompletos(): ValidationException
    {
        return ValidationException::withMessages(['confirmar' => __('Falta indicar qué hacer con una categoría o unidad. Vuelve al paso «Categorías y unidades».')]);
    }

    /**
     * Entradas «Inventario inicial» de hasta LibroStock::MAX_LINEAS productos cada una, con
     * clave de idempotencia derivada de la importación.
     *
     * @param  Collection<int, ImportacionProductoFila>  $filas
     * @param  array<int, int>  $ids  fila => producto_id
     * @return array{sucursal: string, productos: int, operaciones: list<string>}|null
     */
    private function cargarStock(User $actor, ImportacionProducto $importacion, Sucursal $sucursal, $filas, array $ids): ?array
    {
        $cambios = [];

        foreach ($filas as $fila) {
            $datos = (array) $fila->normalizado;

            if ($datos['stock'] !== null) {
                $cambio = [
                    'sucursal_id' => $sucursal->id,
                    'producto_id' => $ids[$fila->fila],
                    'cantidad' => (string) $datos['stock'],
                    'nombre' => (string) $datos['nombre'],
                ];

                // Con lote, a ese lote; sin él, LibroLotes lo lleva al de regularización. Sin marca, se ignora.
                if (is_array($datos['lote'] ?? null)) {
                    $cambio['lote'] = ['numero' => (string) $datos['lote']['numero'], 'vencimiento' => (string) $datos['lote']['vencimiento']];
                }

                $cambios[] = $cambio;
            }
        }

        if ($cambios === []) {
            return null;
        }

        // El historial ya muestra el motivo «Inventario inicial» delante de la nota.
        $nota = mb_substr(__('Importación de productos n.º :numero (:archivo)', [
            'numero' => $importacion->id, 'archivo' => $importacion->archivo_nombre,
        ]), 0, 250);
        $operaciones = [];

        foreach (array_chunk($cambios, LibroStock::MAX_LINEAS) as $parte => $lote) {
            $this->libro->asegurarNiveles($importacion->empresa_id, $lote);
            $operaciones[] = $this->libro->aplicar($actor, [
                'tipo' => TipoOperacionInventario::Entrada,
                'motivo' => 'inventario_inicial',
                'sucursal_id' => $sucursal->id,
                'sucursal_destino_id' => null,
                'nota' => $nota,
                'clave' => Uuid::uuid5(Uuid::NAMESPACE_URL, "mozistock:importacion-productos:{$importacion->id}:{$parte}")->toString(),
            ], $lote)->codigo();
        }

        return ['sucursal' => $sucursal->nombre, 'productos' => count($cambios), 'operaciones' => $operaciones];
    }
}
