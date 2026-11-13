<?php

namespace App\Http\Controllers;

use App\Domain\Catalogo\Actions\GuardarModelo;
use App\Models\Modelo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve la foto general privada de un modelo (tallas y colores § 4.5). El scope de empresa en el route model binding
 * responde 404 a modelos de otra empresa; la ruta exige además la capacidad «variantes».
 */
class ModeloImagenController extends Controller
{
    public function __invoke(Request $request, Modelo $modelo): StreamedResponse
    {
        Gate::forUser($request->user())->authorize('view', $modelo);

        $disco = Storage::disk(GuardarModelo::DISCO);
        abort_if($modelo->imagen_path === null || ! $disco->exists($modelo->imagen_path), 404);

        return $disco->response($modelo->imagen_path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
