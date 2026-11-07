<?php

namespace App\Domain\SitioWeb\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\SitioWeb\CatalogoPaginas;
use App\Models\PaginaWeb;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * SEO de una página del sitio público (solo superadmin). Un campo vacío o igual al texto por
 * defecto se guarda en null, para que la página siga el valor del código si este cambia.
 * La imagen para redes va al disco privado con nombre aleatorio; la antigua se borra al confirmar.
 */
final class GuardarSeoPagina
{
    public const CAMPOS = ['titulo_seo', 'descripcion_seo', 'og_titulo', 'og_descripcion', 'canonical_url', 'mensaje_whatsapp'];

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function ejecutar(User $actor, string $clave, array $datos, ?UploadedFile $imagen = null, bool $quitarImagen = false): PaginaWeb
    {
        Gate::forUser($actor)->authorize('administrar-sitio-web');
        $pagina = CatalogoPaginas::buscar($clave);
        $porDefecto = [
            'titulo_seo' => $pagina->titulo,
            'descripcion_seo' => $pagina->descripcion,
            'canonical_url' => $pagina->url(),
            'mensaje_whatsapp' => $pagina->mensajeWhatsapp,
        ];
        $imagenNueva = null;

        if ($imagen !== null) {
            $imagenNueva = self::guardarImagen($imagen);
        }

        try {
            $fila = DB::transaction(function () use ($actor, $clave, $datos, $porDefecto, $imagenNueva, $quitarImagen): PaginaWeb {
                $fila = PaginaWeb::query()->where('clave', $clave)->lockForUpdate()->first() ?? new PaginaWeb;
                $fila->clave = $clave;
                $antes = $fila->exists ? $this->resumen($fila) : null;
                $imagenAnterior = $fila->og_imagen_path;

                foreach (self::CAMPOS as $campo) {
                    $valor = isset($datos[$campo]) ? trim((string) $datos[$campo]) : '';
                    $fila->{$campo} = $valor === '' || $valor === ($porDefecto[$campo] ?? null) ? null : $valor;
                }

                $fila->indexar = (bool) ($datos['indexar'] ?? true);

                if ($imagenNueva !== null) {
                    $fila->og_imagen_path = $imagenNueva;
                } elseif ($quitarImagen) {
                    $fila->og_imagen_path = null;
                }

                // Todo igual al código: no se guarda una fila vacía (la página no figura como «Editado»).
                $sinCambios = $fila->indexar && $fila->og_imagen_path === null
                    && collect(self::CAMPOS)->every(fn (string $campo): bool => $fila->{$campo} === null);

                if ($sinCambios) {
                    if (! $fila->exists) {
                        return $fila;
                    }

                    $this->auditor->registrar('sitio_web.seo_actualizado', $fila, $actor, null, $antes, null);
                    $fila->delete();
                } else {
                    $fila->save();
                    $this->auditor->registrar('sitio_web.seo_actualizado', $fila, $actor, null, $antes, $this->resumen($fila));
                }

                DB::afterCommit(function () use ($imagenAnterior, $fila): void {
                    if (is_string($imagenAnterior) && $imagenAnterior !== $fila->og_imagen_path) {
                        Storage::disk(PaginaWeb::DISCO)->delete($imagenAnterior);
                    }
                });

                return $fila;
            });
        } catch (Throwable $e) {
            if ($imagenNueva !== null) {
                Storage::disk(PaginaWeb::DISCO)->delete($imagenNueva);
            }

            throw $e;
        }

        return $fila;
    }

    /**
     * Vuelve la página a los valores por defecto del código (borra la personalización y su imagen).
     */
    public function restablecer(User $actor, string $clave): void
    {
        Gate::forUser($actor)->authorize('administrar-sitio-web');
        CatalogoPaginas::buscar($clave);

        DB::transaction(function () use ($actor, $clave): void {
            $fila = PaginaWeb::query()->where('clave', $clave)->lockForUpdate()->first();

            if ($fila === null) {
                return;
            }

            $imagen = $fila->og_imagen_path;
            $this->auditor->registrar('sitio_web.seo_restablecido', $fila, $actor, null, $this->resumen($fila), null);
            $fila->delete();

            DB::afterCommit(function () use ($imagen): void {
                if (is_string($imagen)) {
                    Storage::disk(PaginaWeb::DISCO)->delete($imagen);
                }
            });
        });
    }

    public static function guardarImagen(UploadedFile $imagen): string
    {
        $extension = $imagen->extension() === 'jpeg' ? 'jpg' : $imagen->extension();
        $ruta = $imagen->storeAs(PaginaWeb::CARPETA_IMAGENES, Str::random(40).'.'.$extension, PaginaWeb::DISCO);

        if ($ruta === false) {
            throw ValidationException::withMessages(['imagen' => __('No se pudo guardar la imagen.')]);
        }

        return $ruta;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(PaginaWeb $fila): array
    {
        return [
            'clave' => $fila->clave,
            ...$fila->only(self::CAMPOS),
            'indexar' => $fila->indexar,
            'imagen' => $fila->og_imagen_path !== null,
        ];
    }
}
