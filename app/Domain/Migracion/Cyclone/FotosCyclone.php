<?php

namespace App\Domain\Migracion\Cyclone;

use App\Domain\CatalogoExterno\Fuentes\CycloneFuente;
use App\Models\Empresa;
use App\Models\ProveedorExterno;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fotos de los productos migrados, después de la transacción (los archivos no se pueden deshacer con ella). Usa la
 * copia que el catálogo central ya guardó de cada producto de Cyclone (catalogo_externo_productos, mismo id de origen)
 * y, si no la tiene, la descarga de su foto_url con la validación de CycloneFuente (tamaño y tipo de imagen). Cada foto
 * va al almacenamiento privado de la empresa. Solo completa productos sin imagen: repetirlo no duplica archivos.
 */
final class FotosCyclone
{
    private const DISCO = 'local';

    public function __construct(private readonly CycloneFuente $fuente) {}

    /**
     * Productos migrados que siguen sin imagen y cuya ficha del catálogo central tiene foto (copia guardada o URL). La
     * pantalla de Superadmin los copia por tandas sin necesitar el respaldo, y puede retomarlos si se cerró a medias.
     *
     * @return array<int|string, string> id de origen => «SKU nombre»
     */
    public static function pendientes(Empresa $empresa): array
    {
        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->first();

        if ($proveedor === null) {
            return [];
        }

        $empresaId = (int) $empresa->id;

        return DB::table('migracion_origenes')
            ->join('productos', function ($join) use ($empresaId): void {
                $join->on('productos.id', '=', 'migracion_origenes.destino_id')->where('productos.empresa_id', $empresaId);
            })
            ->join('catalogo_externo_productos', function ($join) use ($proveedor): void {
                $join->on('catalogo_externo_productos.externo_id', '=', 'migracion_origenes.origen_id')
                    ->where('catalogo_externo_productos.proveedor_externo_id', $proveedor->id);
            })
            ->where('migracion_origenes.empresa_id', $empresaId)
            ->where('migracion_origenes.sistema', MigrarCyclone::SISTEMA)
            ->where('migracion_origenes.entidad', 'producto')
            ->whereNull('productos.imagen_path')
            ->where(fn ($q) => $q->where('catalogo_externo_productos.foto_cache_path', '<>', '')
                ->orWhere('catalogo_externo_productos.foto_url', '<>', ''))
            ->orderBy('migracion_origenes.id')
            ->get(['migracion_origenes.origen_id', 'productos.sku', 'productos.nombre'])
            ->mapWithKeys(fn (object $fila): array => [$fila->origen_id => "{$fila->sku} {$fila->nombre}"])
            ->all();
    }

    /**
     * @param  array<int|string, string>  $fotos  id de origen del producto => etiqueta para el informe (nombre de la foto)
     * @return array{copiadas: int, sin_catalogo: list<string>, fallidas: list<string>}
     */
    public function copiar(Empresa $empresa, array $fotos): array
    {
        $resultado = ['copiadas' => 0, 'sin_catalogo' => [], 'fallidas' => []];
        $empresaId = (int) $empresa->id;
        $productos = DB::table('migracion_origenes')
            ->join('productos', function ($join) use ($empresaId): void {
                $join->on('productos.id', '=', 'migracion_origenes.destino_id')->where('productos.empresa_id', $empresaId);
            })
            ->where('migracion_origenes.empresa_id', $empresaId)
            ->where('migracion_origenes.sistema', MigrarCyclone::SISTEMA)
            ->where('migracion_origenes.entidad', 'producto')
            ->whereIn('migracion_origenes.origen_id', array_map('strval', array_keys($fotos)))
            ->whereNull('productos.imagen_path')
            ->pluck('productos.id', 'migracion_origenes.origen_id');

        if ($productos->isEmpty()) {
            return $resultado;
        }

        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->first();
        $catalogo = $proveedor === null ? collect() : DB::table('catalogo_externo_productos')
            ->where('proveedor_externo_id', $proveedor->id)
            ->whereIn('externo_id', $productos->keys()->map(fn ($id): string => (string) $id)->all())
            ->get(['externo_id', 'foto_url', 'foto_cache_path'])
            ->keyBy('externo_id');
        $disco = Storage::disk(self::DISCO);

        foreach ($productos as $origen => $productoId) {
            $etiqueta = "Producto {$origen} ({$fotos[$origen]})";
            $externo = $catalogo->get((string) $origen);

            if ($externo === null || (blank($externo->foto_cache_path) && blank($externo->foto_url))) {
                $resultado['sin_catalogo'][] = $etiqueta;

                continue;
            }

            $contenido = filled($externo->foto_cache_path) && $disco->exists($externo->foto_cache_path)
                ? $disco->get($externo->foto_cache_path)
                : null;

            if ($contenido === null && filled($externo->foto_url)) {
                $contenido = $this->fuente->descargarImagen($externo->foto_url, CycloneFuente::tokenParaFoto($proveedor, $externo->foto_url));
            }

            $extension = $contenido === null ? null : match ((new \finfo(FILEINFO_MIME_TYPE))->buffer($contenido)) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'image/jpeg' => 'jpg',
                default => null,
            };

            if ($extension === null) {
                $resultado['fallidas'][] = $etiqueta;

                continue;
            }

            $destino = "empresas/{$empresaId}/productos/".Str::random(40).".{$extension}";

            if (! $disco->put($destino, $contenido)) {
                $resultado['fallidas'][] = $etiqueta;

                continue;
            }

            $actualizado = DB::table('productos')->where('empresa_id', $empresaId)->where('id', $productoId)
                ->whereNull('imagen_path')->update(['imagen_path' => $destino, 'updated_at' => now()]);

            if ($actualizado === 0) {
                // Otra ejecución la completó mientras tanto: no dejar el archivo huérfano.
                $disco->delete($destino);

                continue;
            }

            $resultado['copiadas']++;
        }

        return $resultado;
    }
}
