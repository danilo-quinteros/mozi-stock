<?php

namespace App\Http\Controllers;

use App\Domain\Suscripciones\Actions\EnviarComprobantePago;
use App\Models\PagoSaas;
use App\Models\Scopes\EmpresaScope;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga autorizada del comprobante privado. La empresa solo alcanza los suyos por el
 * scope (404 para IDs ajenos); el superadministrador omite el scope de forma explícita.
 * Las imágenes se muestran en el navegador; los PDF se descargan.
 */
class ComprobantePagoController extends Controller
{
    public function __invoke(Request $request, int $pago): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $consulta = $actor->esSuperadmin() ? PagoSaas::withoutGlobalScope(EmpresaScope::class) : PagoSaas::query();
        $registro = $consulta->findOrFail($pago);
        Gate::forUser($actor)->authorize('view', $registro);

        $disco = Storage::disk(EnviarComprobantePago::DISCO);
        abort_unless($disco->exists($registro->archivo_path), 404);
        $nombre = $registro->codigo().'.'.(EnviarComprobantePago::TIPOS[$registro->archivo_mime] ?? 'bin');
        $cabeceras = ['Content-Type' => $registro->archivo_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        return $registro->esPdf()
            ? $disco->download($registro->archivo_path, $nombre, $cabeceras)
            : $disco->response($registro->archivo_path, $nombre, $cabeceras);
    }
}
