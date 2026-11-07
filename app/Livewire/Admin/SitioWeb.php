<?php

namespace App\Livewire\Admin;

use App\Domain\SitioWeb\Actions\GuardarAjustesSitio;
use App\Domain\SitioWeb\Actions\GuardarSeoPagina;
use App\Domain\SitioWeb\CatalogoPaginas;
use App\Domain\SitioWeb\Pagina;
use App\Domain\SitioWeb\Seo;
use App\Domain\SitioWeb\Whatsapp;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\AjusteGlobal;
use App\Models\PaginaWeb;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Superadmin › Sitio web: SEO de cada página pública (title, description, Open Graph, canonical,
 * indexación y mensaje de WhatsApp) y ajustes generales del sitio. Es el primer paso del CMS:
 * por ahora solo SEO; el contenido de las páginas sigue en Blade.
 */
#[Title('Sitio web')]
class SitioWeb extends Component
{
    use MuestraAvisos, WithFileUploads;

    private const REGLAS_IMAGEN = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=600,min_height=315'];

    #[Url(as: 'pagina', except: 'inicio')]
    public string $clave = 'inicio';

    public string $titulo_seo = '';

    public string $descripcion_seo = '';

    public string $og_titulo = '';

    public string $og_descripcion = '';

    public string $canonical_url = '';

    public string $mensaje_whatsapp = '';

    public bool $indexar = true;

    /** @var TemporaryUploadedFile|null */
    public $imagen = null;

    public bool $quitarImagen = false;

    public string $sitio_whatsapp = '';

    public string $sitio_verificacion_google = '';

    /** @var TemporaryUploadedFile|null */
    public $imagenGeneral = null;

    public bool $quitarImagenGeneral = false;

    public function mount(): void
    {
        $this->authorize('administrar-sitio-web');

        if (! CatalogoPaginas::existe($this->clave)) {
            $this->clave = 'inicio';
        }

        $this->cargar();
        $ajustes = AjusteGlobal::actual();
        $this->sitio_whatsapp = (string) $ajustes->sitio_whatsapp;
        $this->sitio_verificacion_google = (string) $ajustes->sitio_verificacion_google;
    }

    public function seleccionar(string $clave): void
    {
        if (! CatalogoPaginas::existe($clave)) {
            return;
        }

        $this->clave = $clave;
        $this->resetValidation();
        $this->cargar();
    }

    public function updatedImagen(): void
    {
        $this->validarSubida('imagen');
        $this->quitarImagen = false;
    }

    public function updatedImagenGeneral(): void
    {
        $this->validarSubida('imagenGeneral');
        $this->quitarImagenGeneral = false;
    }

    public function quitar(): void
    {
        $this->imagen = null;
        $this->quitarImagen = true;
    }

    public function quitarGeneral(): void
    {
        $this->imagenGeneral = null;
        $this->quitarImagenGeneral = true;
    }

    public function guardar(GuardarSeoPagina $accion): void
    {
        $this->authorize('administrar-sitio-web');
        $this->validate([
            'titulo_seo' => ['nullable', 'string', 'max:70'],
            'descripcion_seo' => ['nullable', 'string', 'max:170'],
            'og_titulo' => ['nullable', 'string', 'max:95'],
            'og_descripcion' => ['nullable', 'string', 'max:200'],
            'canonical_url' => ['nullable', 'url:https,http', 'max:255'],
            'mensaje_whatsapp' => ['nullable', 'string', 'max:300'],
            'indexar' => ['boolean'],
            'imagen' => self::REGLAS_IMAGEN,
        ], $this->mensajes(), [
            'titulo_seo' => __('título SEO'),
            'descripcion_seo' => __('meta descripción'),
            'og_titulo' => __('título para redes'),
            'og_descripcion' => __('descripción para redes'),
            'canonical_url' => __('URL canónica'),
            'mensaje_whatsapp' => __('mensaje de WhatsApp'),
            'imagen' => __('imagen para redes'),
        ]);

        /** @var User $actor */
        $actor = Auth::user();
        $accion->ejecutar($actor, $this->clave, [
            'titulo_seo' => $this->titulo_seo,
            'descripcion_seo' => $this->descripcion_seo,
            'og_titulo' => $this->og_titulo,
            'og_descripcion' => $this->og_descripcion,
            'canonical_url' => $this->canonical_url,
            'mensaje_whatsapp' => $this->mensaje_whatsapp,
            'indexar' => $this->indexar,
        ], $this->imagen, $this->quitarImagen);

        $this->cargar();
        $this->avisar(__('SEO de «:pagina» guardado.', ['pagina' => CatalogoPaginas::buscar($this->clave)->nombre]));
    }

    public function restablecer(GuardarSeoPagina $accion): void
    {
        /** @var User $actor */
        $actor = Auth::user();
        $accion->restablecer($actor, $this->clave);
        $this->resetValidation();
        $this->cargar();
        $this->avisar(__('Se restablecieron los valores por defecto.'));
    }

    public function guardarAjustes(GuardarAjustesSitio $accion): void
    {
        $this->authorize('administrar-sitio-web');
        $numero = Whatsapp::normalizar($this->sitio_whatsapp);
        $codigo = GuardarAjustesSitio::codigoVerificacion($this->sitio_verificacion_google);

        $this->validate(['imagenGeneral' => self::REGLAS_IMAGEN], $this->mensajes(), ['imagenGeneral' => __('imagen para redes')]);

        if ($numero !== null && ! preg_match(Whatsapp::PATRON, $numero)) {
            throw ValidationException::withMessages(['sitio_whatsapp' => __('Escribe el código de país y el número, por ejemplo 591 71234567.')]);
        }

        if ($codigo !== null && ! preg_match('/^[A-Za-z0-9_-]{10,100}$/', $codigo)) {
            throw ValidationException::withMessages(['sitio_verificacion_google' => __('Pega el código que te da Google Search Console (o la etiqueta meta completa).')]);
        }

        /** @var User $actor */
        $actor = Auth::user();
        $ajustes = $accion->ejecutar($actor, $numero, $codigo, $this->imagenGeneral, $this->quitarImagenGeneral);
        $this->sitio_whatsapp = (string) $ajustes->sitio_whatsapp;
        $this->sitio_verificacion_google = (string) $ajustes->sitio_verificacion_google;
        $this->imagenGeneral = null;
        $this->quitarImagenGeneral = false;
        $this->avisar(__('Ajustes del sitio guardados.'));
    }

    public function render(): View
    {
        $pagina = CatalogoPaginas::buscar($this->clave);
        $filas = PaginaWeb::query()->get()->keyBy('clave');
        $ajustes = AjusteGlobal::actual();
        $fila = $filas->get($this->clave);

        return view('livewire.admin.sitio-web', [
            'pagina' => $pagina,
            'fila' => $fila,
            'ajustes' => $ajustes,
            'grupos' => collect(CatalogoPaginas::todas())->groupBy(fn (Pagina $p): string => $p->grupo),
            'filas' => $filas,
            'vista' => $this->vistaPrevia($pagina, $fila, $ajustes),
        ]);
    }

    /**
     * Valores que vería Google y las redes con lo escrito en el formulario (aún sin guardar).
     *
     * @return array{titulo: string, descripcion: string, url: string, ogTitulo: string, ogDescripcion: string, imagen: string|null}
     */
    private function vistaPrevia(Pagina $pagina, ?PaginaWeb $fila, AjusteGlobal $ajustes): array
    {
        $titulo = trim($this->titulo_seo) !== '' ? trim($this->titulo_seo) : $pagina->titulo;
        $descripcion = trim($this->descripcion_seo) !== '' ? trim($this->descripcion_seo) : $pagina->descripcion;

        if ($this->imagen instanceof TemporaryUploadedFile && $this->imagen->isPreviewable()) {
            $imagen = $this->imagen->temporaryUrl();
        } elseif ($fila?->og_imagen_path !== null && ! $this->quitarImagen) {
            $imagen = Seo::urlImagen($fila->og_imagen_path);
        } elseif ($ajustes->sitio_og_imagen_path !== null) {
            $imagen = Seo::urlImagen($ajustes->sitio_og_imagen_path);
        } else {
            $imagen = asset(Seo::IMAGEN_POR_DEFECTO);
        }

        return [
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'url' => trim($this->canonical_url) !== '' ? trim($this->canonical_url) : $pagina->url(),
            'ogTitulo' => trim($this->og_titulo) !== '' ? trim($this->og_titulo) : $titulo,
            'ogDescripcion' => trim($this->og_descripcion) !== '' ? trim($this->og_descripcion) : $descripcion,
            'imagen' => $imagen,
        ];
    }

    private function cargar(): void
    {
        $fila = PaginaWeb::query()->where('clave', $this->clave)->first();
        $this->titulo_seo = (string) $fila?->titulo_seo;
        $this->descripcion_seo = (string) $fila?->descripcion_seo;
        $this->og_titulo = (string) $fila?->og_titulo;
        $this->og_descripcion = (string) $fila?->og_descripcion;
        $this->canonical_url = (string) $fila?->canonical_url;
        $this->mensaje_whatsapp = (string) $fila?->mensaje_whatsapp;
        $this->indexar = $fila === null || $fila->indexar;
        $this->imagen = null;
        $this->quitarImagen = false;
    }

    private function validarSubida(string $campo): void
    {
        try {
            $this->validate([$campo => self::REGLAS_IMAGEN], $this->mensajes(), [$campo => __('imagen para redes')]);
        } catch (ValidationException $e) {
            $this->{$campo} = null;

            throw $e;
        }
    }

    /**
     * @return array<string, string>
     */
    private function mensajes(): array
    {
        return [
            'imagen.max' => __('La imagen no puede superar 2 MB.'),
            'imagenGeneral.max' => __('La imagen no puede superar 2 MB.'),
            'imagen.dimensions' => __('La imagen debe medir al menos 600 × 315 px (ideal 1200 × 630).'),
            'imagenGeneral.dimensions' => __('La imagen debe medir al menos 600 × 315 px (ideal 1200 × 630).'),
        ];
    }
}
