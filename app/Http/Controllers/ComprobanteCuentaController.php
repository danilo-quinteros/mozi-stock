<?php

namespace App\Http\Controllers;

use App\Domain\Caja\Comprobante;
use App\Models\Cobro;
use App\Models\CuentaPagar;
use App\Models\PagoCuentaPagar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga autorizada de comprobantes de cobros y pagos, y del respaldo de una obligación
 * (§ 24.4). El scope de empresa responde 404 para IDs ajenos; la policy decide el resto.
 * Las imágenes se muestran en el navegador; los PDF se descargan.
 */
class ComprobanteCuentaController extends Controller
{
    public function cobro(Request $request, int $cobro): StreamedResponse
    {
        $registro = Cobro::query()->findOrFail($cobro);
        Gate::forUser($request->user())->authorize('view', $registro);

        return $this->servir($registro->comprobante_path, $registro->comprobante_mime, $registro->codigo());
    }

    public function pago(Request $request, int $pago): StreamedResponse
    {
        $registro = PagoCuentaPagar::query()->with('cuenta')->findOrFail($pago);
        Gate::forUser($request->user())->authorize('view', $registro->cuenta);

        return $this->servir($registro->comprobante_path, $registro->comprobante_mime, $registro->cuenta->codigo().'-pago-'.$registro->id);
    }

    public function respaldo(Request $request, int $cuenta): StreamedResponse
    {
        $registro = CuentaPagar::query()->findOrFail($cuenta);
        Gate::forUser($request->user())->authorize('view', $registro);

        return $this->servir($registro->respaldo_path, $registro->respaldo_mime, $registro->codigo().'-respaldo');
    }

    private function servir(?string $ruta, ?string $mime, string $nombre): StreamedResponse
    {
        $disco = Storage::disk(Comprobante::DISCO);
        abort_if($ruta === null || $mime === null || ! $disco->exists($ruta), 404);
        $archivo = $nombre.'.'.(Comprobante::TIPOS[$mime] ?? 'bin');
        $cabeceras = ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        return $mime === 'application/pdf'
            ? $disco->download($ruta, $archivo, $cabeceras)
            : $disco->response($ruta, $archivo, $cabeceras);
    }
}
