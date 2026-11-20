<?php

namespace App\Domain\Migracion\Cyclone;

use App\Domain\CatalogoExterno\Fuentes\CycloneFuente;
use App\Models\Empresa;
use App\Models\ProveedorExterno;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fotos de los productos migrados, después de la transacción (los archivos no se pueden deshacer con ella). Qué
 * productos tenían foto lo dice el respaldo (migracion_origenes.foto); la imagen se baja de la API de Cyclone por el id
 * del producto (`…/catalogo/productos/{id}/foto`), sin pasar por «Descargar catálogo Cyclone». Si el catálogo central
 * ya guardó una copia, se usa esa. Se valida tamaño y tipo de imagen (CycloneFuente) y va al almacenamiento privado de
 * la empresa. Solo completa productos sin imagen: repetirlo no duplica archivos.
 */
final class FotosCyclone
{
    private const DISCO = 'local';

    private const LOTE = 500;

    public function __construct(private readonly CycloneFuente $fuente) {}

    /**
     * Productos migrados que siguen sin imagen y que en Cyclone tenían foto. La pantalla de Superadmin los copia por
     * tandas sin necesitar el respaldo, y puede retomarlos si se cerró a medias.
     *
     * @return array<int|string, string> id de origen => «SKU nombre»
     */
    public static function pendientes(Empresa $empresa): array
    {
        return self::productosMigrados((int) $empresa->id)
            ->whereNull('productos.imagen_path')
            ->where('migracion_origenes.foto', '<>', '')
            ->orderBy('migracion_origenes.id')
            ->get(['migracion_origenes.origen_id', 'productos.sku', 'productos.nombre'])
            ->mapWithKeys(fn (object $fila): array => [$fila->origen_id => "{$fila->sku} {$fila->nombre}"])
            ->all();
    }

    /** Productos migrados sin el dato de su foto: la empresa se migró antes de que se guardara. */
    public static function sinDatoDeFoto(Empresa $empresa): int
    {
        return DB::table('migracion_origenes')
            ->where('empresa_id', (int) $empresa->id)
            ->where('sistema', MigrarCyclone::SISTEMA)
            ->where('entidad', 'producto')
            ->whereNull('foto')
            ->count();
    }

    /**
     * Completa, con el respaldo de la empresa, el nombre de la foto de los productos que se migraron sin ese dato. Solo
     * toca esas filas (ningún otro dato cambia) y devuelve cuántas completó: 0 si el respaldo no trae ninguno de sus
     * productos. Una consulta por lote de 500, no una por producto.
     *
     * @param  array<int|string, string|null>  $fotos  id de origen => nombre de la foto (null o '' = sin foto)
     */
    public static function registrarNombres(Empresa $empresa, array $fotos): int
    {
        $completadas = 0;

        foreach (array_chunk($fotos, self::LOTE, true) as $lote) {
            $casos = [];
            $valores = [];

            foreach ($lote as $origen => $foto) {
                $casos[] = 'WHEN ? THEN ?';
                array_push($valores, (string) $origen, mb_substr(trim((string) $foto), 0, 255));
            }

            $ids = array_map('strval', array_keys($lote));
            $completadas += DB::update(
                'UPDATE migracion_origenes SET foto = CASE origen_id '.implode(' ', $casos).' END'
                .' WHERE empresa_id = ? AND sistema = ? AND entidad = ? AND foto IS NULL'
                .' AND origen_id IN ('.implode(', ', array_fill(0, count($ids), '?')).')',
                [...$valores, (int) $empresa->id, MigrarCyclone::SISTEMA, 'producto', ...$ids],
            );
        }

        return $completadas;
    }

    /**
     * @param  array<int|string, string>  $fotos  id de origen del producto => etiqueta para el informe
     * @return array{copiadas: int, fallidas: list<string>}
     */
    public function copiar(Empresa $empresa, array $fotos): array
    {
        $resultado = ['copiadas' => 0, 'fallidas' => []];
        $empresaId = (int) $empresa->id;
        $productos = self::productosMigrados($empresaId)
            ->whereIn('migracion_origenes.origen_id', array_map('strval', array_keys($fotos)))
            ->whereNull('productos.imagen_path')
            ->where('migracion_origenes.foto', '<>', '')
            ->pluck('productos.id', 'migracion_origenes.origen_id');

        if ($productos->isEmpty()) {
            return $resultado;
        }

        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->first();
        // Copia que el catálogo central ya guardó de la foto (si alguna vez se descargó): evita bajarla de nuevo.
        $catalogo = $proveedor === null ? collect() : DB::table('catalogo_externo_productos')
            ->where('proveedor_externo_id', $proveedor->id)
            ->whereIn('externo_id', $productos->keys()->map(fn ($id): string => (string) $id)->all())
            ->get(['externo_id', 'foto_url', 'foto_cache_path'])
            ->keyBy('externo_id');
        $disco = Storage::disk(self::DISCO);

        foreach ($productos as $origen => $productoId) {
            $origen = (string) $origen;
            $etiqueta = "Producto {$origen} ({$fotos[$origen]})";
            $externo = $catalogo->get($origen);
            $contenido = $externo !== null && filled($externo->foto_cache_path) && $disco->exists($externo->foto_cache_path)
                ? $disco->get($externo->foto_cache_path)
                : null;

            if ($contenido === null) {
                $url = $externo !== null && filled($externo->foto_url) ? (string) $externo->foto_url : CycloneFuente::urlFoto($proveedor, $origen);
                $contenido = $this->fuente->descargarImagen($url, CycloneFuente::tokenParaFoto($proveedor, $url));
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

    /** Equivalencias de productos migrados de la empresa unidas a su producto (siempre de la misma empresa). */
    private static function productosMigrados(int $empresaId): Builder
    {
        return DB::table('migracion_origenes')
            ->join('productos', function ($join) use ($empresaId): void {
                $join->on('productos.id', '=', 'migracion_origenes.destino_id')->where('productos.empresa_id', $empresaId);
            })
            ->where('migracion_origenes.empresa_id', $empresaId)
            ->where('migracion_origenes.sistema', MigrarCyclone::SISTEMA)
            ->where('migracion_origenes.entidad', 'producto');
    }
}
