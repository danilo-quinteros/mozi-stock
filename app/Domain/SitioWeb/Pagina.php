<?php

namespace App\Domain\SitioWeb;

/**
 * Página del sitio público. Ruta, vista y textos SEO por defecto viven en código; el superadmin
 * solo sobrescribe el SEO (tabla paginas_web). La clave es estable: no cambiarla sin migrar datos.
 */
final readonly class Pagina
{
    public const GRUPOS = [
        'principal' => 'Principal',
        'modulo' => 'Módulos',
        'rubro' => 'Rubros',
        'comercial' => 'Comercial y confianza',
        'legal' => 'Legal',
    ];

    public function __construct(
        public string $clave,
        public string $ruta,
        public string $nombre,
        public string $grupo,
        public string $titulo,
        public string $descripcion,
        public string $mensajeWhatsapp,
        public ?string $padre = 'inicio',
        public string $prioridad = '0.8',
        public string $frecuencia = 'monthly',
        public string $tipo = 'WebPage',
    ) {}

    public function nombreRuta(): string
    {
        return $this->clave === 'inicio' ? 'home' : 'sitio.'.$this->clave;
    }

    /** Cada clave del catálogo tiene su vista en resources/views/sitio/paginas (lo verifica el test). */
    public function vista(): string
    {
        return 'sitio.paginas.'.$this->clave;
    }

    public function url(): string
    {
        return CatalogoPaginas::urlAbsoluta($this->ruta);
    }
}
