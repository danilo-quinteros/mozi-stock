<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Valores de SEO que el superadmin personalizó para una página del sitio público. La página en sí
 * (ruta, vista y textos por defecto) vive en App\Domain\SitioWeb\CatalogoPaginas.
 *
 * @property int $id
 * @property string $clave
 * @property string|null $titulo_seo
 * @property string|null $descripcion_seo
 * @property string|null $og_titulo
 * @property string|null $og_descripcion
 * @property string|null $og_imagen_path
 * @property string|null $canonical_url
 * @property bool $indexar
 * @property string|null $mensaje_whatsapp
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaginaWeb extends Model
{
    /** Disco privado; las imágenes se sirven por la ruta pública sitio.imagen. */
    public const DISCO = 'local';

    public const CARPETA_IMAGENES = 'sitio-web';

    protected $table = 'paginas_web';

    protected $attributes = ['indexar' => true];

    protected function casts(): array
    {
        return ['indexar' => 'boolean'];
    }
}
