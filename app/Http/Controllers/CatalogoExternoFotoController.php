<?php

namespace App\Http\Controllers;

use App\Domain\CatalogoExterno\Fuentes\CycloneFuente;
use App\Models\CatalogoExternoProducto;
use App\Models\EmpresaProveedorExterno;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve la imagen del catálogo central externo de forma segura y autorizada.
 * Si la imagen ya fue descargada y cacheada localmente, se envía directamente desde disco.
 * Si aún no está en caché pero tiene URL del proveedor, se descarga bajo demanda.
 */
class CatalogoExternoFotoController extends Controller
{
    public function __invoke(Request $request, CatalogoExternoProducto $productoExterno): StreamedResponse
    {
        $user = $request->user();
        abort_if(! $user, 401);

        // Superadmin o empresa revendedora activa del proveedor
        if (! $user->esSuperadmin()) {
            abort_if(empty($user->empresa_id), 403);

            $esRevendedora = EmpresaProveedorExterno::withoutGlobalScopes()
                ->where('empresa_id', $user->empresa_id)
                ->where('proveedor_externo_id', $productoExterno->proveedor_externo_id)
                ->where('activo', true)
                ->exists();

            abort_if(! $esRevendedora, 403);
        }

        $disco = Storage::disk('local');

        // 1. Si ya está en caché local, servir directamente
        if (filled($productoExterno->foto_cache_path) && $disco->exists($productoExterno->foto_cache_path)) {
            return $disco->response($productoExterno->foto_cache_path, null, [
                'Cache-Control' => 'private, max-age=86400',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // 2. Si tiene URL externa pero no estaba en caché, intentar descargar y cachear bajo demanda
        if (filled($productoExterno->foto_url)) {
            $fuente = app(CycloneFuente::class);
            $token = CycloneFuente::tokenParaFoto($productoExterno->proveedor, $productoExterno->foto_url);
            $contenido = $fuente->descargarImagen($productoExterno->foto_url, $token);

            if ($contenido !== null) {
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenido);
                $ext = match ($mime) {
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                    default => 'jpg',
                };

                $path = "catalogo_central/{$productoExterno->proveedor_externo_id}/{$productoExterno->externo_id}.{$ext}";
                $disco->put($path, $contenido);
                $productoExterno->foto_cache_path = $path;
                $productoExterno->saveQuietly();

                return $disco->response($path, null, [
                    'Cache-Control' => 'private, max-age=86400',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        abort(404, 'Imagen no disponible');
    }
}
