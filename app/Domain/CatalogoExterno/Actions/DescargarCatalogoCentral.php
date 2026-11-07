<?php

namespace App\Domain\CatalogoExterno\Actions;

use App\Domain\CatalogoExterno\Contratos\FuenteCatalogoExterno;
use App\Domain\CatalogoExterno\Fuentes\CycloneFuente;
use App\Domain\CatalogoExterno\InterpretePresentacion;
use App\Models\CatalogoExternoProducto;
use App\Models\ProveedorExterno;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class DescargarCatalogoCentral
{
    /**
     * @return array{
     *     procesados: int,
     *     nuevos: int,
     *     actualizados: int,
     *     descontinuados: int
     * }
     */
    public function ejecutar(ProveedorExterno $proveedor, ?FuenteCatalogoExterno $fuente = null): array
    {
        $fuente ??= $this->resolverFuente($proveedor);

        $vistosExternosIds = [];
        $nuevos = 0;
        $actualizados = 0;
        $procesados = 0;

        try {
            foreach ($fuente->obtenerProductos($proveedor) as $dto) {
                if ($dto->externoId === '' || $dto->nombre === '') {
                    continue;
                }

                $procesados++;
                $vistosExternosIds[] = $dto->externoId;

                // Interpretar presentación y normalizar nombre
                $interpretacion = InterpretePresentacion::interpretar($dto->presentacion, $dto->nombre, $dto->descripcion, $dto->unidadSiat);

                // Calcular hash de integridad del producto externo
                $payloadHash = json_encode([
                    'codigo' => $dto->codigo,
                    'nombre' => $interpretacion['nombre_limpio'],
                    'contenido' => $interpretacion['contenido'],
                    'unidad' => $interpretacion['unidad_abreviatura'],
                    'unidad_siat' => $dto->unidadSiat,
                    'tipo_venta' => $interpretacion['tipo_venta'],
                    'descripcion' => $dto->descripcion,
                    'linea' => $dto->linea,
                    'foto_url' => $dto->fotoUrl,
                    'activo' => $dto->activo,
                ], JSON_THROW_ON_ERROR);

                $hash = hash('sha256', $payloadHash);

                $existente = CatalogoExternoProducto::query()
                    ->where('proveedor_externo_id', $proveedor->id)
                    ->where('externo_id', $dto->externoId)
                    ->first();

                if ($existente === null) {
                    $productoCentral = new CatalogoExternoProducto([
                        'proveedor_externo_id' => $proveedor->id,
                        'externo_id' => $dto->externoId,
                        'codigo' => $dto->codigo,
                        'nombre_original' => $dto->nombre,
                        'nombre_limpio' => $interpretacion['nombre_limpio'],
                        'contenido' => $interpretacion['contenido'],
                        'unidad_sugerida' => $interpretacion['unidad_abreviatura'],
                        'unidad_siat_sugerida' => $dto->unidadSiat,
                        'tipo_venta' => $interpretacion['tipo_venta'],
                        'descripcion' => $dto->descripcion,
                        'linea_nombre' => $dto->linea,
                        'foto_url' => $dto->fotoUrl,
                        'activo_origen' => $dto->activo,
                        'disponible' => true,
                        'hash' => $hash,
                        'origen_updated_at' => self::fechaOrigen($dto->origenUpdatedAt),
                    ]);

                    // Descargar y cachear foto centralmente si tiene URL
                    if (filled($dto->fotoUrl)) {
                        $productoCentral->foto_cache_path = $this->guardarFotoCentral($proveedor->id, $dto->externoId, $dto->fotoUrl, $fuente, CycloneFuente::tokenParaFoto($proveedor, $dto->fotoUrl));
                    }

                    $productoCentral->save();
                    $nuevos++;
                } else {
                    $cambio = ($existente->hash !== $hash) || ! $existente->disponible;
                    $faltaFoto = filled($dto->fotoUrl) && (
                        empty($existente->foto_cache_path) ||
                        ! Storage::disk('local')->exists((string) $existente->foto_cache_path) ||
                        $existente->foto_url !== $dto->fotoUrl
                    );

                    if ($cambio || $faltaFoto) {
                        $existente->codigo = $dto->codigo;
                        $existente->nombre_original = $dto->nombre;
                        $existente->nombre_limpio = $interpretacion['nombre_limpio'];
                        $existente->contenido = $interpretacion['contenido'];
                        $existente->unidad_sugerida = $interpretacion['unidad_abreviatura'];
                        $existente->unidad_siat_sugerida = $dto->unidadSiat;
                        $existente->tipo_venta = $interpretacion['tipo_venta'];
                        $existente->descripcion = $dto->descripcion;
                        $existente->linea_nombre = $dto->linea;
                        $existente->activo_origen = $dto->activo;
                        $existente->disponible = true;
                        $existente->hash = $hash;
                        $existente->origen_updated_at = self::fechaOrigen($dto->origenUpdatedAt);

                        if (filled($dto->fotoUrl)) {
                            $necesitaDescarga = ($existente->foto_url !== $dto->fotoUrl)
                                || empty($existente->foto_cache_path)
                                || ! Storage::disk('local')->exists((string) $existente->foto_cache_path);

                            $archivoAnterior = $existente->foto_cache_path;
                            $existente->foto_url = $dto->fotoUrl;

                            if ($necesitaDescarga) {
                                $existente->foto_cache_path = $this->guardarFotoCentral($proveedor->id, $dto->externoId, $dto->fotoUrl, $fuente, CycloneFuente::tokenParaFoto($proveedor, $dto->fotoUrl), $archivoAnterior);
                            }
                        } elseif (filled($existente->foto_cache_path)) {
                            // Cyclone quitó la foto: borrar de nuestro servidor para almacenar solo 1 o ninguna
                            if (Storage::disk('local')->exists($existente->foto_cache_path)) {
                                Storage::disk('local')->delete($existente->foto_cache_path);
                            }
                            $existente->foto_cache_path = null;
                            $existente->foto_url = null;
                        }

                        $existente->save();
                        $actualizados++;
                    }
                }
            }

            // Marcar como no disponibles aquellos que ya no vinieron en la API
            $descontinuados = 0;
            if ($vistosExternosIds !== []) {
                $descontinuados = CatalogoExternoProducto::query()
                    ->where('proveedor_externo_id', $proveedor->id)
                    ->where('disponible', true)
                    ->whereNotIn('externo_id', $vistosExternosIds)
                    ->update(['disponible' => false]);
            }

            $proveedor->ultima_descarga_en = CarbonImmutable::now();
            $proveedor->ultimo_error = null;
            $proveedor->save();

            return [
                'procesados' => $procesados,
                'nuevos' => $nuevos,
                'actualizados' => $actualizados,
                'descontinuados' => $descontinuados,
            ];
        } catch (Throwable $e) {
            $proveedor->ultimo_error = Str::limit($e->getMessage(), 500);
            $proveedor->save();

            throw $e;
        }
    }

    private function resolverFuente(ProveedorExterno $proveedor): FuenteCatalogoExterno
    {
        return match ($proveedor->driver) {
            'cyclone' => app(CycloneFuente::class),
            default => app(CycloneFuente::class),
        };
    }

    /**
     * Fecha de actualización que informa el proveedor (texto de su API), interpretada igual que el cast datetime.
     */
    private static function fechaOrigen(?string $valor): ?CarbonImmutable
    {
        return filled($valor) ? CarbonImmutable::parse($valor) : null;
    }

    private function guardarFotoCentral(int $proveedorId, string $externoId, string $url, FuenteCatalogoExterno $fuente, ?string $token = null, ?string $archivoAnterior = null): ?string
    {
        $contenido = $fuente->descargarImagen($url, $token);
        if ($contenido === null) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenido);
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        $path = "catalogo_central/{$proveedorId}/{$externoId}.".$extension;

        // Si había un archivo anterior con diferente ruta/extensión, borrarlo para almacenar solo 1 imagen
        if (filled($archivoAnterior) && $archivoAnterior !== $path && Storage::disk('local')->exists($archivoAnterior)) {
            Storage::disk('local')->delete($archivoAnterior);
        }

        Storage::disk('local')->put($path, $contenido);

        return $path;
    }
}
