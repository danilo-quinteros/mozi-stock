<?php

namespace App\Domain\SitioWeb\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\SitioWeb\Whatsapp;
use App\Models\AjusteGlobal;
use App\Models\PaginaWeb;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Ajustes generales del sitio público: número de WhatsApp de los botones, código de verificación
 * de Google Search Console e imagen para redes por defecto. Solo el superadmin.
 */
final class GuardarAjustesSitio
{
    public function __construct(private readonly Auditor $auditor) {}

    public function ejecutar(User $actor, ?string $whatsapp, ?string $verificacion, ?UploadedFile $imagen = null, bool $quitarImagen = false): AjusteGlobal
    {
        Gate::forUser($actor)->authorize('administrar-sitio-web');
        $imagenNueva = $imagen !== null ? GuardarSeoPagina::guardarImagen($imagen) : null;

        try {
            $ajustes = DB::transaction(function () use ($actor, $whatsapp, $verificacion, $imagenNueva, $quitarImagen): AjusteGlobal {
                $ajustes = AjusteGlobal::query()->orderBy('id')->lockForUpdate()->first() ?? new AjusteGlobal;
                $antes = $this->resumen($ajustes);
                $imagenAnterior = $ajustes->sitio_og_imagen_path;
                $ajustes->sitio_whatsapp = Whatsapp::normalizar($whatsapp);
                $ajustes->sitio_verificacion_google = self::codigoVerificacion($verificacion);

                if ($imagenNueva !== null) {
                    $ajustes->sitio_og_imagen_path = $imagenNueva;
                } elseif ($quitarImagen) {
                    $ajustes->sitio_og_imagen_path = null;
                }

                $ajustes->save();
                $this->auditor->registrar('sitio_web.ajustes_actualizados', $ajustes, $actor, null, $antes, $this->resumen($ajustes));

                DB::afterCommit(function () use ($imagenAnterior, $ajustes): void {
                    if (is_string($imagenAnterior) && $imagenAnterior !== $ajustes->sitio_og_imagen_path) {
                        Storage::disk(PaginaWeb::DISCO)->delete($imagenAnterior);
                    }
                });

                return $ajustes;
            });
        } catch (Throwable $e) {
            if ($imagenNueva !== null) {
                Storage::disk(PaginaWeb::DISCO)->delete($imagenNueva);
            }

            throw $e;
        }

        return $ajustes;
    }

    /**
     * Acepta el código solo o la etiqueta <meta name="google-site-verification" content="…"> completa.
     */
    public static function codigoVerificacion(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $valor, $coincidencia) === 1) {
            $valor = trim($coincidencia[1]);
        }

        return $valor === '' ? null : $valor;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(AjusteGlobal $ajustes): array
    {
        return [
            'sitio_whatsapp' => $ajustes->sitio_whatsapp,
            'sitio_verificacion_google' => $ajustes->sitio_verificacion_google,
            'sitio_imagen' => $ajustes->sitio_og_imagen_path !== null,
        ];
    }
}
