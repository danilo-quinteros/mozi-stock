<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Achica las fotos que se suben (productos y modelos) con GD, que viene con PHP: sin dependencias nuevas.
 *
 * - El lado mayor queda en 1600 px como máximo (sobra para la ficha, el POS y el catálogo).
 * - Se corrige la orientación con que guarda la cámara del celular (EXIF), que GD no aplica solo.
 * - Se guarda en WebP calidad 82, o en JPEG calidad 85 si el GD del servidor no tiene WebP. Una foto de celular de
 *   5–8 MB queda en unos cientos de KB sin diferencia visible en pantalla.
 * - Al reescribirla se descartan los metadatos de la cámara (incluida la ubicación GPS).
 *
 * Si no se puede (servidor sin GD, formato que GD no lee como un WebP animado, demasiados píxeles para la memoria) o
 * la foto ya es chica y el resultado no pesa menos, se guarda el archivo original: subir una foto nunca falla por esto.
 */
final class OptimizadorImagen
{
    public const LADO_MAXIMO = 1600;

    private const CALIDAD_WEBP = 82;

    private const CALIDAD_JPEG = 85;

    /** Más píxeles que esto (≈ 50 MP) no se descomprimen: en GD ocuparían cientos de MB. */
    private const MAX_PIXELES = 50_000_000;

    /** Bytes por píxel que usa GD en color verdadero, con margen. */
    private const BYTES_POR_PIXEL = 5;

    /**
     * Guarda la imagen subida en la carpeta del disco, con nombre aleatorio, optimizada si se puede.
     *
     * @return string|false ruta en el disco, o false si no se pudo escribir
     */
    public function guardar(UploadedFile $archivo, string $carpeta, string $disco): string|false
    {
        $optimizada = $this->optimizar((string) $archivo->getRealPath());

        if ($optimizada === null) {
            return $archivo->storeAs($carpeta, Str::random(40).'.'.$archivo->extension(), $disco);
        }

        $ruta = $carpeta.'/'.Str::random(40).'.'.$optimizada['extension'];

        return Storage::disk($disco)->put($ruta, $optimizada['contenido']) ? $ruta : false;
    }

    /**
     * @return array{contenido: string, extension: string, ancho: int, alto: int}|null null = conservar el original
     */
    public function optimizar(string $ruta): ?array
    {
        if (! self::disponible()) {
            Log::notice('GD no está disponible en PHP: la imagen se guarda sin optimizar.');

            return null;
        }

        try {
            return $this->procesar($ruta);
        } catch (Throwable $e) {
            Log::warning('No se pudo optimizar una imagen: se guarda la original.', ['motivo' => $e->getMessage()]);

            return null;
        }
    }

    public static function disponible(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagecopyresampled');
    }

    /**
     * @return array{contenido: string, extension: string, ancho: int, alto: int}|null
     */
    private function procesar(string $ruta): ?array
    {
        $info = @getimagesize($ruta);
        $tamano = @filesize($ruta);

        if ($info === false || $tamano === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return null;
        }

        [$ancho, $alto] = $info;

        if ($ancho < 1 || $alto < 1 || $ancho * $alto > self::MAX_PIXELES || ! $this->hayMemoria($ancho * $alto)) {
            return null;
        }

        $original = @imagecreatefromstring((string) file_get_contents($ruta));

        if (! $original instanceof GdImage) {
            return null;
        }

        $orientacion = $info[2] === IMAGETYPE_JPEG ? $this->orientacionExif($ruta) : 1;
        $imagen = $this->orientar($original, $orientacion);
        unset($original);

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $escala = min(1, self::LADO_MAXIMO / max($ancho, $alto));
        $nuevoAncho = max(1, (int) round($ancho * $escala));
        $nuevoAlto = max(1, (int) round($alto * $escala));
        $webp = function_exists('imagewebp') && (bool) (gd_info()['WebP Support'] ?? false);

        // Siempre a color verdadero: WebP no admite imágenes de paleta (PNG de 8 bits).
        $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);

        if ($webp) {
            imagealphablending($destino, false);
            imagesavealpha($destino, true);
            imagefill($destino, 0, 0, (int) imagecolorallocatealpha($destino, 255, 255, 255, 127));
        } else {
            // JPEG no tiene transparencia: el fondo transparente de un PNG queda blanco.
            imagefill($destino, 0, 0, (int) imagecolorallocate($destino, 255, 255, 255));
        }

        imagecopyresampled($destino, $imagen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
        unset($imagen);

        ob_start();
        $escrita = $webp ? imagewebp($destino, null, self::CALIDAD_WEBP) : imagejpeg($destino, null, self::CALIDAD_JPEG);
        $contenido = (string) ob_get_clean();

        if (! $escrita || $contenido === '') {
            return null;
        }

        // Ya era chica y bien orientada: si no se gana peso, mejor la original.
        if ($escala >= 1 && $orientacion === 1 && strlen($contenido) >= $tamano) {
            return null;
        }

        return ['contenido' => $contenido, 'extension' => $webp ? 'webp' : 'jpg', 'ancho' => $nuevoAncho, 'alto' => $nuevoAlto];
    }

    /**
     * Gira o refleja según la orientación EXIF (1–8) para que la foto se vea como en el celular.
     */
    private function orientar(GdImage $imagen, int $orientacion): GdImage
    {
        if (in_array($orientacion, [3, 4], true)) {
            $imagen = imagerotate($imagen, 180, 0) ?: $imagen;
        } elseif (in_array($orientacion, [5, 6], true)) {
            $imagen = imagerotate($imagen, 270, 0) ?: $imagen;
        } elseif (in_array($orientacion, [7, 8], true)) {
            $imagen = imagerotate($imagen, 90, 0) ?: $imagen;
        }

        if (in_array($orientacion, [2, 4, 5, 7], true)) {
            imageflip($imagen, IMG_FLIP_HORIZONTAL);
        }

        return $imagen;
    }

    /**
     * Orientación EXIF de un JPEG leída del segmento APP1, sin depender de la extensión exif de PHP. 1 si no tiene.
     */
    private function orientacionExif(string $ruta): int
    {
        // EXIF va al principio del archivo (APP1 ocupa como máximo 64 KB).
        $datos = (string) @file_get_contents($ruta, false, null, 0, 131072);
        $largo = strlen($datos);
        $pos = 2;

        if (! str_starts_with($datos, "\xFF\xD8")) {
            return 1;
        }

        while ($pos + 4 <= $largo && $datos[$pos] === "\xFF") {
            $marcador = ord($datos[$pos + 1]);
            $tamano = unpack('n', $datos, $pos + 2)[1];

            if ($marcador === 0xE1 && substr($datos, $pos + 4, 6) === "Exif\0\0") {
                return $this->orientacionTiff(substr($datos, $pos + 10, max(0, $tamano - 8)));
            }

            if ($marcador === 0xDA) {
                break;
            }

            $pos += 2 + $tamano;
        }

        return 1;
    }

    /** Etiqueta 0x0112 (Orientation) del primer IFD de un bloque TIFF/EXIF, en su orden de bytes. */
    private function orientacionTiff(string $tiff): int
    {
        $largo = strlen($tiff);
        [$corto, $largoEntero] = match (substr($tiff, 0, 2)) {
            'II' => ['v', 'V'],
            'MM' => ['n', 'N'],
            default => [null, null],
        };

        if ($corto === null || $largo < 8) {
            return 1;
        }

        $ifd = unpack($largoEntero, $tiff, 4)[1];

        if ($ifd + 2 > $largo) {
            return 1;
        }

        $entradas = unpack($corto, $tiff, $ifd)[1];

        for ($i = 0; $i < $entradas; $i++) {
            $entrada = $ifd + 2 + $i * 12;

            if ($entrada + 12 > $largo) {
                break;
            }

            if (unpack($corto, $tiff, $entrada)[1] === 0x0112) {
                $valor = unpack($corto, $tiff, $entrada + 8)[1];

                return $valor >= 1 && $valor <= 8 ? $valor : 1;
            }
        }

        return 1;
    }

    /**
     * ¿Alcanza la memoria para descomprimir la foto? En hosting compartido intenta subir el límite solo para esta
     * petición; si no se puede, la foto se guarda sin optimizar en vez de cortar la petición.
     */
    private function hayMemoria(int $pixeles): bool
    {
        $necesaria = memory_get_usage() + ($pixeles + self::LADO_MAXIMO ** 2) * self::BYTES_POR_PIXEL + 16 * 1024 * 1024;
        $limite = $this->bytes((string) ini_get('memory_limit'));

        if ($limite < 0 || $necesaria <= $limite) {
            return true;
        }

        return @ini_set('memory_limit', (string) $necesaria) !== false && $this->bytes((string) ini_get('memory_limit')) >= $necesaria;
    }

    /** «512M», «1G», «134217728» o «-1» (sin límite) en bytes. */
    private function bytes(string $valor): int
    {
        $valor = trim($valor);

        if ($valor === '' || $valor === '-1') {
            return -1;
        }

        $numero = (int) $valor;

        return match (strtoupper(substr($valor, -1))) {
            'G' => $numero * 1024 ** 3,
            'M' => $numero * 1024 ** 2,
            'K' => $numero * 1024,
            default => $numero,
        };
    }
}
