<?php

namespace App\Http\Controllers;

use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve la imagen privada de un producto. El scope de empresa en el route model
 * binding responde 404 a productos de otra empresa.
 */
class ProductoImagenController extends Controller
{
    public function __invoke(Request $request, Producto $producto): StreamedResponse
    {
        Gate::forUser($request->user())->authorize('view', $producto);

        $disco = Storage::disk(GuardarProducto::DISCO);
        abort_if($producto->imagen_path === null || ! $disco->exists($producto->imagen_path), 404);

        return $disco->response($producto->imagen_path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
