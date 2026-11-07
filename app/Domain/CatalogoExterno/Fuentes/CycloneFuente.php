<?php

namespace App\Domain\CatalogoExterno\Fuentes;

use App\Domain\CatalogoExterno\Contratos\FuenteCatalogoExterno;
use App\Domain\CatalogoExterno\DTOs\ProductoExternoDto;
use App\Models\ProveedorExterno;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CycloneFuente implements FuenteCatalogoExterno
{
    private const MAX_BYTES_IMAGEN = 3145728; // 3 MB

    public function obtenerProductos(ProveedorExterno $proveedor): Generator
    {
        $baseUrl = rtrim((string) ($proveedor->base_url ?: config('services.cyclone.base_url')), '/');
        $endpoint = str_ends_with($baseUrl, '/catalogo/productos') ? $baseUrl : $baseUrl.'/catalogo/productos';
        $token = self::token($proveedor);

        $pagina = 1;
        $ultimaPagina = 1;

        do {
            // throw: false → tras agotar los reintentos devuelve la última respuesta (4xx/5xx) y se
            // informa abajo con su código; antes escapaba una RequestException sin capturar.
            $cliente = Http::timeout(20)->retry(2, 500, throw: false);

            if (filled($token)) {
                $cliente = $cliente->withToken($token);
            }

            try {
                $respuesta = $cliente->acceptJson()->get($endpoint, [
                    'page' => $pagina,
                    'per_page' => 200,
                ]);
            } catch (ConnectionException $e) {
                throw new RuntimeException("Error de conexión al conectar con Cyclone: {$e->getMessage()}", 0, $e);
            }

            if (! $respuesta->successful()) {
                throw new RuntimeException("Respuesta fallida de Cyclone (HTTP {$respuesta->status()}): ".substr($respuesta->body(), 0, 200));
            }

            $datos = $respuesta->json();
            $items = $datos['data'] ?? [];

            if (! is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    yield ProductoExternoDto::fromArray($item);
                }
            }

            $meta = $datos['meta'] ?? [];
            $ultimaPagina = (int) ($meta['last_page'] ?? $pagina);
            $pagina++;
        } while ($pagina <= $ultimaPagina);
    }

    /**
     * Token del proveedor (cifrado en la base) o, si no tiene, el del servidor (`services.cyclone.token`).
     */
    public static function token(?ProveedorExterno $proveedor): ?string
    {
        $token = $proveedor?->token ?: config('services.cyclone.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Token para descargar una foto: solo si foto_url está en el mismo host y esquema que la API del
     * proveedor. Una foto_url de otro servidor (o en http) se descarga sin credencial.
     */
    public static function tokenParaFoto(?ProveedorExterno $proveedor, string $url, ?string $token = null): ?string
    {
        $token ??= self::token($proveedor);
        $api = parse_url((string) ($proveedor?->base_url ?: config('services.cyclone.base_url')));
        $foto = parse_url($url);

        $mismoOrigen = is_array($api) && is_array($foto)
            && isset($api['host'], $foto['host'], $api['scheme'], $foto['scheme'])
            && strcasecmp($api['host'], $foto['host']) === 0
            && strcasecmp($api['scheme'], $foto['scheme']) === 0;

        return $mismoOrigen ? $token : null;
    }

    public function descargarImagen(string $url, ?string $token = null): ?string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        try {
            // Con verificación TLS: la petición lleva el token del proveedor.
            $cliente = Http::timeout(10);
            if (filled($token)) {
                $cliente = $cliente->withToken($token);
            }

            $res = $cliente->get($url);

            // Si falló y la URL incluía /storage/productos/, intentar fallback directo a /productos/
            if (! $res->successful() && str_contains($url, '/storage/productos/')) {
                $fallbackUrl = str_replace('/storage/productos/', '/productos/', $url);
                $res = $cliente->get($fallbackUrl);
            }

            if (! $res->successful()) {
                return null;
            }

            $cuerpo = $res->body();
            if (strlen($cuerpo) > self::MAX_BYTES_IMAGEN) {
                return null;
            }

            // Validar firma mínima de imagen (jpg, png, webp, gif)
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($cuerpo);
            if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                return null;
            }

            return $cuerpo;
        } catch (\Throwable) {
            return null;
        }
    }
}
