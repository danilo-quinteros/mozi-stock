<?php

namespace App\Http\Controllers;

use App\Models\AjusteGlobal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * QR de cobro de MoziStudios, guardado en el disco privado. Lo ven el superadministrador
 * y los administradores de empresa (que pagan la suscripción); los vendedores no.
 */
class QrCobroController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor->esSuperadmin() || $actor->esAdministrador(), 403);

        $ruta = AjusteGlobal::actual()->qr_path;
        $disco = Storage::disk(AjusteGlobal::DISCO);
        abort_if($ruta === null || ! $disco->exists($ruta), 404);

        return $disco->response($ruta, null, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
