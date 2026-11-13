<?php

namespace App\Http\Controllers;

use App\Domain\Catalogo\Actions\GuardarModelo;
use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Enums\Capacidad;
use App\Models\Modelo;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve la imagen privada de un producto. El scope de empresa en el route model
 * binding responde 404 a productos de otra empresa.
 *
 * Tallas y colores § 4.5 (V3): una variante sin foto propia muestra la foto general de su modelo, solo con la
 * capacidad activa y siempre de la misma empresa.
 */
class ProductoImagenController extends Controller
{
    private const CABECERAS = [
        'Cache-Control' => 'private, max-age=3600',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __invoke(Request $request, Producto $producto): StreamedResponse
    {
        Gate::forUser($request->user())->authorize('view', $producto);

        $disco = Storage::disk(GuardarProducto::DISCO);

        if ($producto->imagen_path !== null && $disco->exists($producto->imagen_path)) {
            return $disco->response($producto->imagen_path, null, self::CABECERAS);
        }

        $modelo = $producto->modelo_id !== null && Gate::forUser($request->user())->allows('capacidad', Capacidad::Variantes)
            ? Modelo::query()->where('empresa_id', $producto->empresa_id)->find($producto->modelo_id, ['id', 'imagen_path'])
            : null;
        $discoModelo = Storage::disk(GuardarModelo::DISCO);
        abort_if($modelo?->imagen_path === null || ! $discoModelo->exists($modelo->imagen_path), 404);

        return $discoModelo->response($modelo->imagen_path, null, self::CABECERAS);
    }
}
