<?php

namespace App\Domain\SitioWeb;

use App\Models\AjusteGlobal;
use App\Models\PaginaWeb;

/**
 * SEO final de una página: valor del superadmin si existe, si no el del código. La imagen para
 * redes cae en la de la página, luego la global del superadmin y al final la incluida en public/.
 */
final readonly class Seo
{
    public const IMAGEN_POR_DEFECTO = 'img/sitio/og-mozistock.png';

    public const ANCHO_IMAGEN_POR_DEFECTO = 1200;

    public const ALTO_IMAGEN_POR_DEFECTO = 630;

    public function __construct(
        public string $titulo,
        public string $descripcion,
        public string $canonical,
        public bool $indexar,
        public string $ogTitulo,
        public string $ogDescripcion,
        public string $ogImagen,
        public bool $ogImagenPorDefecto,
        public string $mensajeWhatsapp,
    ) {}

    public static function para(Pagina $pagina, ?PaginaWeb $personalizado, AjusteGlobal $ajustes): self
    {
        $titulo = self::texto($personalizado?->titulo_seo) ?? $pagina->titulo;
        $descripcion = self::texto($personalizado?->descripcion_seo) ?? $pagina->descripcion;
        $imagen = $personalizado->og_imagen_path ?? $ajustes->sitio_og_imagen_path;

        return new self(
            titulo: $titulo,
            descripcion: $descripcion,
            canonical: self::texto($personalizado?->canonical_url) ?? $pagina->url(),
            indexar: $personalizado === null || $personalizado->indexar,
            ogTitulo: self::texto($personalizado?->og_titulo) ?? $titulo,
            ogDescripcion: self::texto($personalizado?->og_descripcion) ?? $descripcion,
            ogImagen: $imagen !== null ? self::urlImagen($imagen) : CatalogoPaginas::urlAbsoluta(self::IMAGEN_POR_DEFECTO),
            ogImagenPorDefecto: $imagen === null,
            mensajeWhatsapp: self::texto($personalizado?->mensaje_whatsapp) ?? $pagina->mensajeWhatsapp,
        );
    }

    /**
     * URL pública de una imagen subida desde el superadmin (guardada en el disco privado).
     */
    public static function urlImagen(string $ruta): string
    {
        return CatalogoPaginas::urlAbsoluta(route('sitio.imagen', ['archivo' => basename($ruta)], false));
    }

    private static function texto(?string $valor): ?string
    {
        $valor = $valor !== null ? trim($valor) : null;

        return $valor === '' ? null : $valor;
    }
}
