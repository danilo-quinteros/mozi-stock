<?php

namespace App\Domain\CatalogoExterno\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\CatalogoExterno\Fuentes\CycloneFuente;
use App\Models\CatalogoExternoProducto;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\EmpresaProveedorExterno;
use App\Models\Producto;
use App\Models\ProductoVinculoExterno;
use App\Models\ProveedorExterno;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SincronizarEmpresa
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Sincroniza el catálogo central descargado hacia el catálogo local de UNA empresa revendedora.
     * Puede sincronizar todo el catálogo, solo un subconjunto de IDs externos seleccionados,
     * o solo aquellos productos que ya estén vinculados en la empresa.
     *
     * @param  array<int|string>|null  $soloExternoIds
     * @return array{
     *     creados: int,
     *     actualizados: int,
     *     sin_cambios: int
     * }
     */
    public function ejecutar(
        Empresa $empresa,
        ProveedorExterno $proveedor,
        ?User $actor = null,
        ?array $soloExternoIds = null,
        bool $soloActualizarVinculados = false
    ): array {
        $vinculoEmpresa = EmpresaProveedorExterno::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('proveedor_externo_id', $proveedor->id)
            ->first();

        if ($vinculoEmpresa === null || ! $vinculoEmpresa->activo) {
            return ['creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0];
        }

        // Obtener productos centrales disponibles del proveedor
        $externosQuery = CatalogoExternoProducto::query()
            ->where('proveedor_externo_id', $proveedor->id)
            ->where('disponible', true);

        if ($soloExternoIds !== null) {
            $externosQuery->whereIn('externo_id', array_map('strval', $soloExternoIds));
        }

        $externos = $externosQuery->get();

        if ($externos->isEmpty()) {
            return ['creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0];
        }

        // Precargar vínculos existentes de la empresa con este proveedor
        $vinculosExistentes = ProductoVinculoExterno::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('proveedor_externo_id', $proveedor->id)
            ->get()
            ->keyBy('externo_id');

        if ($soloActualizarVinculados) {
            $externos = $externos->filter(fn (CatalogoExternoProducto $ext) => $vinculosExistentes->has($ext->externo_id));

            if ($externos->isEmpty()) {
                return ['creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0];
            }
        }

        // Precargar categorías y unidades de la empresa para evitar N+1
        $categorias = Categoria::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->get()
            ->keyBy(fn (Categoria $c) => mb_strtolower($c->nombre));

        $unidades = UnidadMedida::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->get()
            ->keyBy(fn (UnidadMedida $u) => mb_strtolower($u->abreviatura));

        $unidadDefectoId = $this->obtenerOcrearUnidad($empresa->id, 'und.', $unidades);

        // Precargar SKUs ocupados en la empresa para evitar colisiones
        $skusOcupados = Producto::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->whereNotNull('sku')
            ->pluck('sku')
            ->map(fn ($s) => Str::upper((string) $s))
            ->flip()
            ->all();

        $creados = 0;
        $actualizados = 0;
        $sinCambios = 0;

        DB::transaction(function () use (
            $empresa,
            $proveedor,
            $externos,
            $vinculosExistentes,
            &$categorias,
            &$unidades,
            $unidadDefectoId,
            &$skusOcupados,
            &$creados,
            &$actualizados,
            &$sinCambios
        ): void {
            foreach ($externos as $ext) {
                $vinculo = $vinculosExistentes->get($ext->externo_id);

                // Resolver unidad de medida de la empresa
                $unidadId = $this->obtenerOcrearUnidad($empresa->id, $ext->unidad_sugerida, $unidades, $ext->unidad_siat_sugerida) ?: $unidadDefectoId;

                // Resolver categoría de la empresa (si tiene línea de producción)
                $categoriaId = null;
                if (filled($ext->linea_nombre)) {
                    $categoriaId = $this->obtenerOcrearCategoria($empresa->id, $ext->linea_nombre, $categorias);
                }

                if ($vinculo === null) {
                    // ==========================================
                    // CASO 1: Producto nuevo para esta empresa
                    // ==========================================
                    $producto = new Producto;
                    $producto->empresa_id = $empresa->id;
                    $producto->nombre = $ext->nombre_limpio;
                    $producto->contenido = $ext->contenido;
                    $producto->tipo_venta = $ext->tipo_venta ?: 'unidad';
                    $producto->categoria_id = $categoriaId;
                    $producto->unidad_medida_id = $unidadId;
                    $producto->descripcion = $ext->descripcion;

                    // Decisión 1: Precios en cero inicialmente, estado activo
                    $producto->precio_consumidor = '0.00';
                    $producto->precio_minorista = null;
                    $producto->precio_mayorista = null;
                    $producto->stock_minimo = '0.000';
                    $producto->activo = true;

                    // Asignar SKU si no está ocupado
                    $skuCandidato = filled($ext->codigo) ? Str::upper(trim($ext->codigo)) : null;
                    if ($skuCandidato !== null && ! isset($skusOcupados[$skuCandidato])) {
                        $producto->sku = $skuCandidato;
                        $skusOcupados[$skuCandidato] = true;
                    } else {
                        $producto->sku = null;
                    }

                    // Copiar imagen privada al tenant si existe
                    $imagenPath = $this->copiarImagenATenant($empresa->id, $ext, $proveedor->token);
                    if ($imagenPath !== null) {
                        $producto->imagen_path = $imagenPath;
                    }

                    $producto->save();

                    // Crear el vínculo de identificación
                    $nuevoVinculo = new ProductoVinculoExterno([
                        'producto_id' => $producto->id,
                        'proveedor_externo_id' => $proveedor->id,
                        'externo_id' => $ext->externo_id,
                        'hash_aplicado' => $ext->hash,
                        'sincronizado_en' => now(),
                    ]);
                    $nuevoVinculo->empresa_id = $empresa->id;
                    $nuevoVinculo->save();

                    $creados++;
                } else {
                    // ==========================================
                    // CASO 2: Producto ya vinculado previamente
                    // ==========================================
                    $producto = Producto::withoutGlobalScopes()
                        ->where('empresa_id', $empresa->id)
                        ->where('id', $vinculo->producto_id)
                        ->first();

                    $reactivado = false;
                    if ($producto !== null && ! $producto->activo) {
                        $producto->activo = true;
                        $reactivado = true;
                    }

                    if ($vinculo->hash_aplicado === $ext->hash && ! $reactivado) {
                        $sinCambios++;

                        continue;
                    }

                    // Decisión 2: Si Cyclone cambia, en el sistema cambia
                    // NUNCA modifica precios, existencias ni stock_minimo
                    if ($producto !== null) {
                        $producto->nombre = $ext->nombre_limpio;
                        $producto->contenido = $ext->contenido;
                        $producto->tipo_venta = $ext->tipo_venta ?: 'unidad';
                        $producto->unidad_medida_id = $unidadId;
                        if ($categoriaId !== null) {
                            $producto->categoria_id = $categoriaId;
                        }
                        if (filled($ext->descripcion)) {
                            $producto->descripcion = $ext->descripcion;
                        }

                        // Gestión de imagen: solo 1 imagen por producto en el servidor
                        if (empty($ext->foto_url) && filled($producto->imagen_path)) {
                            // Cyclone quitó la foto: eliminarla del servidor del tenant
                            if (Storage::disk('local')->exists($producto->imagen_path)) {
                                Storage::disk('local')->delete($producto->imagen_path);
                            }
                            $producto->imagen_path = null;
                        } elseif (filled($ext->foto_url)) {
                            if (empty($producto->imagen_path) || ! Storage::disk('local')->exists((string) $producto->imagen_path)) {
                                // No tenía imagen previa: copiarla al tenant
                                $imagenPath = $this->copiarImagenATenant($empresa->id, $ext, $proveedor->token);
                                if ($imagenPath !== null) {
                                    $producto->imagen_path = $imagenPath;
                                }
                            } else {
                                // Ya tenía imagen: actualizarla y borrar la anterior del servidor para mantener solo 1
                                $imagenAnterior = $producto->imagen_path;
                                $nuevaImagenPath = $this->copiarImagenATenant($empresa->id, $ext, $proveedor->token);
                                if ($nuevaImagenPath !== null && $nuevaImagenPath !== $imagenAnterior) {
                                    if (filled($imagenAnterior) && Storage::disk('local')->exists($imagenAnterior)) {
                                        Storage::disk('local')->delete($imagenAnterior);
                                    }
                                    $producto->imagen_path = $nuevaImagenPath;
                                }
                            }
                        }

                        $producto->save();
                    }

                    $vinculo->hash_aplicado = $ext->hash;
                    $vinculo->sincronizado_en = now();
                    $vinculo->save();

                    $actualizados++;
                }
            }
        });

        $resultado = [
            'creados' => $creados,
            'actualizados' => $actualizados,
            'sin_cambios' => $sinCambios,
        ];

        $vinculoEmpresa->ultima_sincronizacion_en = now();
        $vinculoEmpresa->ultimo_resultado = $resultado;
        $vinculoEmpresa->save();

        $this->auditor->registrar(
            'catalogo_externo.sincronizado',
            $vinculoEmpresa,
            $actor,
            $empresa->id,
            null,
            $resultado + ['proveedor' => $proveedor->codigo]
        );

        return $resultado;
    }

    /**
     * @param  Collection<string, UnidadMedida>  $unidades  unidades de la empresa por abreviatura en minúsculas
     */
    private function obtenerOcrearUnidad(int $empresaId, string $abreviatura, Collection $unidades, ?string $unidadSiat = null): int
    {
        $abrev = mb_strtolower(trim($abreviatura));
        $codigoSinSugerido = UnidadMedida::resolverCodigoSin($unidadSiat, $abreviatura);

        if (isset($unidades[$abrev])) {
            $existente = $unidades[$abrev];
            if ($codigoSinSugerido !== null && ($existente->unidad_medida_sin === null || $this->esCodigoSinLegacyDesalineado($existente->abreviatura, $existente->unidad_medida_sin))) {
                $existente->unidad_medida_sin = $codigoSinSugerido;
                $existente->save();
            }

            return $existente->id;
        }

        // Buscar definición en BASE
        $definicion = collect(UnidadMedida::BASE)->first(
            fn ($item) => mb_strtolower($item['abreviatura']) === $abrev
        ) ?? ['nombre' => ucfirst($abreviatura), 'abreviatura' => $abreviatura, 'admite_decimales' => in_array($abrev, ['kg', 'l', 'm']), 'unidad_medida_sin' => $codigoSinSugerido ?? 57];

        $unidad = new UnidadMedida([
            'nombre' => $definicion['nombre'],
            'abreviatura' => $definicion['abreviatura'],
            'admite_decimales' => (bool) $definicion['admite_decimales'],
            'unidad_medida_sin' => $codigoSinSugerido ?? $definicion['unidad_medida_sin'],
            'activa' => true,
        ]);
        $unidad->empresa_id = $empresaId;
        $unidad->save();

        $unidades[$abrev] = $unidad;

        return $unidad->id;
    }

    private function esCodigoSinLegacyDesalineado(string $abreviatura, ?int $codigoActual): bool
    {
        $abrev = mb_strtolower(trim($abreviatura));

        return match ($abrev) {
            'l', 'lt', 'lts' => $codigoActual === 24, // 24 era Kilovatio hora en vez de 28 Litro
            'kg', 'kgs' => $codigoActual === 2,      // 2 era Balde en vez de 22 Kilogramo
            'g', 'gr', 'grs' => $codigoActual === 1, // 1 era Bobinas en vez de 17 Gramo
            'ml', 'mls' => $codigoActual === 68,     // 68 era Meses en vez de 34 Mililitro
            'cj', 'cj.' => $codigoActual === 15,     // 15 era Fardo en vez de 6 Caja
            'und', 'und.' => $codigoActual === 58,   // 58 era Servicios en vez de 57 Bienes
            default => false,
        };
    }

    /**
     * @param  Collection<string, Categoria>  $categorias  categorías de la empresa por nombre en minúsculas
     */
    private function obtenerOcrearCategoria(int $empresaId, string $nombreLinea, Collection $categorias): int
    {
        $clave = mb_strtolower(trim($nombreLinea));
        if (isset($categorias[$clave])) {
            return $categorias[$clave]->id;
        }

        $categoria = new Categoria([
            'nombre' => Str::limit(trim($nombreLinea), 80, ''),
            'activa' => true,
        ]);
        $categoria->empresa_id = $empresaId;
        $categoria->save();

        $categorias[$clave] = $categoria;

        return $categoria->id;
    }

    private function copiarImagenATenant(int $empresaId, CatalogoExternoProducto $ext, ?string $token = null): ?string
    {
        $disco = Storage::disk('local');

        // 1. Si tenemos la imagen cacheada en el catálogo central, usarla
        if (filled($ext->foto_cache_path) && $disco->exists($ext->foto_cache_path)) {
            $extension = pathinfo($ext->foto_cache_path, PATHINFO_EXTENSION) ?: 'jpg';
            $nombreArchivo = Str::random(40).'.'.$extension;
            $destino = "empresas/{$empresaId}/productos/{$nombreArchivo}";

            $contenido = $disco->get($ext->foto_cache_path);
            if ($contenido !== null && $disco->put($destino, $contenido)) {
                return $destino;
            }
        }

        // 2. Si no estaba cacheada pero hay URL remota, descargarla y cachearla bajo demanda
        if (filled($ext->foto_url)) {
            $fuente = app(CycloneFuente::class);
            $contenido = $fuente->descargarImagen($ext->foto_url, CycloneFuente::tokenParaFoto($ext->proveedor, $ext->foto_url, $token));

            if ($contenido !== null) {
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenido);
                $extension = match ($mime) {
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                    default => 'jpg',
                };

                // Guardar en central
                $centralPath = "catalogo_central/{$ext->proveedor_externo_id}/{$ext->externo_id}.{$extension}";
                $disco->put($centralPath, $contenido);
                $ext->foto_cache_path = $centralPath;
                $ext->saveQuietly();

                // Copiar al tenant
                $nombreArchivo = Str::random(40).'.'.$extension;
                $destino = "empresas/{$empresaId}/productos/{$nombreArchivo}";
                if ($disco->put($destino, $contenido)) {
                    return $destino;
                }
            }
        }

        return null;
    }

    /**
     * Retorna los productos vinculados a los externoIds indicados que tengan existencias > 0 en alguna sucursal.
     *
     * @param  array<int|string>  $externoIds
     * @return array<int, array{
     *     producto_id: int,
     *     externo_id: string,
     *     nombre: string,
     *     stock_total: string,
     *     unidad: string,
     *     sucursales: array<string, string>
     * }>
     */
    public function obtenerDetalleStock(Empresa $empresa, ProveedorExterno $proveedor, array $externoIds): array
    {
        if (empty($externoIds)) {
            return [];
        }

        $vinculos = ProductoVinculoExterno::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('proveedor_externo_id', $proveedor->id)
            ->whereIn('externo_id', array_map('strval', $externoIds))
            ->with(['producto.unidad'])
            ->get();

        // Una sola consulta de existencias para todos los productos (antes, una por producto).
        $stockPorProducto = DB::table('stock_niveles as sn')
            ->join('sucursales as s', fn ($join) => $join->on('s.id', '=', 'sn.sucursal_id')->on('s.empresa_id', '=', 'sn.empresa_id'))
            ->where('sn.empresa_id', $empresa->id)
            ->whereIn('sn.producto_id', $vinculos->pluck('producto_id')->all())
            ->where('sn.cantidad', '>', 0)
            ->orderBy('s.nombre')
            ->get(['sn.producto_id', 's.nombre as sucursal', 'sn.cantidad'])
            ->groupBy('producto_id');

        $resultado = [];

        foreach ($vinculos as $vinculo) {
            // El producto se lee con el alcance de la empresa: sin contexto de empresa (un job) no se carga.
            $producto = $vinculo->producto;
            $stockPorSucursal = $stockPorProducto->get($vinculo->producto_id);
            if ($producto === null || $stockPorSucursal === null) {
                continue;
            }

            $totalStock = '0';
            $sucursales = [];
            foreach ($stockPorSucursal as $fila) {
                $totalStock = Decimal::sumar($totalStock, (string) $fila->cantidad);
                $sucursales[(string) $fila->sucursal] = (string) $fila->cantidad;
            }

            $resultado[] = [
                'producto_id' => $producto->id,
                'externo_id' => $vinculo->externo_id,
                'nombre' => $producto->nombre,
                'stock_total' => $totalStock,
                'unidad' => $producto->unidad->abreviatura,
                'sucursales' => $sucursales,
            ];
        }

        return $resultado;
    }

    /**
     * Da de baja uno o más productos de Cyclone en el catálogo de la empresa.
     * - Si tiene existencias > 0 o movimientos/historial comercial: se desactiva (activo = false)
     *   para no aparecer en nuevas ventas ni pedidos, preservando el inventario y la trazabilidad.
     * - Si tiene 0 existencias y 0 historial: se elimina limpiamente el vínculo y el producto.
     *
     * @param  array<int|string>  $externoIds
     * @return array{desactivados: int, eliminados: int, total: int}
     */
    public function dejarDeVender(Empresa $empresa, ProveedorExterno $proveedor, array $externoIds, ?User $actor = null): array
    {
        if (empty($externoIds)) {
            return ['desactivados' => 0, 'eliminados' => 0, 'total' => 0];
        }

        $vinculos = ProductoVinculoExterno::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('proveedor_externo_id', $proveedor->id)
            ->whereIn('externo_id', array_map('strval', $externoIds))
            ->get();

        $desactivados = 0;
        $eliminados = 0;

        DB::transaction(function () use ($empresa, $vinculos, &$desactivados, &$eliminados): void {
            foreach ($vinculos as $vinculo) {
                $producto = Producto::withoutGlobalScopes()
                    ->where('empresa_id', $empresa->id)
                    ->where('id', $vinculo->producto_id)
                    ->first();

                if ($producto === null) {
                    $vinculo->delete();
                    $eliminados++;

                    continue;
                }

                $tieneStock = DB::table('stock_niveles')
                    ->where('empresa_id', $empresa->id)
                    ->where('producto_id', $producto->id)
                    ->where('cantidad', '>', 0)
                    ->exists();

                $tieneHistorial = $tieneStock
                    || DB::table('movimientos_stock')->where('empresa_id', $empresa->id)->where('producto_id', $producto->id)->exists()
                    || DB::table('venta_items')->where('empresa_id', $empresa->id)->where('producto_id', $producto->id)->exists()
                    || DB::table('pedido_items')->where('empresa_id', $empresa->id)->where('producto_id', $producto->id)->exists()
                    || DB::table('compra_items')->where('empresa_id', $empresa->id)->where('producto_id', $producto->id)->exists();

                if ($tieneHistorial) {
                    $producto->activo = false;
                    $producto->save();
                    $desactivados++;
                } else {
                    $vinculo->delete();
                    DB::table('stock_niveles')->where('empresa_id', $empresa->id)->where('producto_id', $producto->id)->delete();
                    if (filled($producto->imagen_path) && Storage::disk('local')->exists($producto->imagen_path)) {
                        Storage::disk('local')->delete($producto->imagen_path);
                    }
                    $producto->delete();
                    $eliminados++;
                }
            }
        });

        $resultado = [
            'desactivados' => $desactivados,
            'eliminados' => $eliminados,
            'total' => $desactivados + $eliminados,
        ];

        $this->auditor->registrar(
            'catalogo_externo.dejar_vender',
            $empresa,
            $actor,
            $empresa->id,
            null,
            $resultado + ['proveedor' => $proveedor->codigo, 'externo_ids' => $externoIds]
        );

        return $resultado;
    }
}
