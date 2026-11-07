<?php

namespace App\Http\Controllers;

use App\Domain\SitioWeb\CatalogoPaginas;
use App\Domain\SitioWeb\EsquemaJsonLd;
use App\Domain\SitioWeb\PlanesSitio;
use App\Domain\SitioWeb\Seo;
use App\Models\AjusteGlobal;
use App\Models\PaginaWeb;
use Illuminate\Contracts\View\View as Vista;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sitio web público (solo modo SaaS). Cada página del catálogo usa esta acción con su clave como
 * valor por defecto de la ruta; el contenido es Blade estático y el SEO sale de paginas_web.
 */
final class SitioWebController extends Controller
{
    /** Páginas que muestran precios o los publican en el JSON-LD. */
    private const CON_PLANES = ['inicio', 'precios'];

    /**
     * Raíz del dominio: el sitio en modo SaaS; en una instalación standalone no hay sitio público
     * y se mantiene el comportamiento anterior (panel o acceso).
     */
    public function inicio(): Vista|RedirectResponse
    {
        if (config('mozistock.deployment_mode') !== 'saas') {
            return redirect()->route(auth()->check() ? 'dashboard' : 'login');
        }

        return $this->pagina('inicio');
    }

    public function pagina(string $clave): Vista
    {
        $pagina = CatalogoPaginas::buscar($clave);
        $ajustes = AjusteGlobal::actual();
        $seo = Seo::para($pagina, PaginaWeb::query()->where('clave', $clave)->first(), $ajustes);
        $planes = in_array($clave, self::CON_PLANES, true) ? PlanesSitio::activos() : null;
        // El encabezado, el pie y los botones de WhatsApp de cualquier componente leen estos ajustes.
        View::share('sitioAjustes', $ajustes);

        $vista = $pagina->vista();

        if (! view()->exists($vista)) {
            abort(404);
        }

        return view($vista, [
            'pagina' => $pagina,
            'seo' => $seo,
            'ajustes' => $ajustes,
            'planes' => $planes ?? collect(),
            'esquema' => EsquemaJsonLd::grafo($pagina, $seo, $ajustes, $planes),
        ]);
    }

    public function sitemap(): Response
    {
        $personalizadas = PaginaWeb::query()->get(['clave', 'indexar', 'canonical_url', 'updated_at'])->keyBy('clave');
        $publicacion = '2026-11-06';
        $entradas = [];

        foreach (CatalogoPaginas::todas() as $pagina) {
            $fila = $personalizadas->get($pagina->clave);

            // Una página con noindex o con canonical hacia otra URL no va en el sitemap.
            if ($fila !== null && (! $fila->indexar || filled($fila->canonical_url))) {
                continue;
            }

            $entradas[] = [
                'url' => $pagina->url(),
                'fecha' => max($publicacion, $fila?->updated_at?->toDateString() ?? $publicacion),
                'frecuencia' => $pagina->frecuencia,
                'prioridad' => $pagina->prioridad,
            ];
        }

        return response()
            ->view('sitio.sitemap', ['entradas' => $entradas])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        $lineas = [
            'User-agent: *',
            // Zonas privadas de la app: igual exigen sesión; así no gastan presupuesto de rastreo.
            'Disallow: /admin',
            'Disallow: /dashboard',
            'Disallow: /livewire',
            'Disallow: /settings',
            'Disallow: /agente-mozi',
            'Disallow: /impersonar',
            '',
            'Sitemap: '.CatalogoPaginas::urlAbsoluta('sitemap.xml'),
        ];

        return response(implode("\n", $lineas)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Imagen para redes subida desde el superadmin. Es pública a propósito (la leen Facebook y
     * WhatsApp); vive en el disco privado y solo se sirve lo que está en la carpeta del sitio.
     */
    public function imagen(string $archivo): StreamedResponse
    {
        $ruta = PaginaWeb::CARPETA_IMAGENES.'/'.$archivo;
        $disco = Storage::disk(PaginaWeb::DISCO);
        abort_unless($disco->exists($ruta), 404);

        return $disco->response($ruta, $archivo, [
            'Cache-Control' => 'public, max-age=2592000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
