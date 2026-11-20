<?php

use App\Support\OptimizadorImagen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Fotos de productos y modelos achicadas con GD (pedido del propietario, 20/11/2026): lado mayor 1600 px, WebP,
 * orientación de la cámara corregida y, si no se puede o no conviene, la foto original tal cual.
 */

/** Archivo temporal con el contenido dado (se borra al terminar la prueba). */
function archivoImagen(string $contenido): string
{
    $ruta = (string) tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($ruta, $contenido);
    register_shutdown_function(fn () => @unlink($ruta));

    return $ruta;
}

/** JPEG azul con el cuarto de arriba a la izquierda rojo, con la orientación EXIF indicada (APP1 tras el SOI). */
function jpegConOrientacion(int $ancho, int $alto, ?int $orientacion): string
{
    $lienzo = imagecreatetruecolor($ancho, $alto);
    imagefill($lienzo, 0, 0, (int) imagecolorallocate($lienzo, 0, 0, 255));
    imagefilledrectangle($lienzo, 0, 0, intdiv($ancho, 2) - 1, intdiv($alto, 2) - 1, (int) imagecolorallocate($lienzo, 255, 0, 0));
    ob_start();
    imagejpeg($lienzo, null, 90);
    $jpeg = (string) ob_get_clean();

    if ($orientacion === null) {
        return $jpeg;
    }

    $tiff = 'MM'.pack('nN', 42, 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('n', $orientacion)."\0\0".pack('N', 0);

    return "\xFF\xD8\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\0\0".$tiff.substr($jpeg, 2);
}

/** En qué cuarto de la imagen quedó el rojo. */
function cuartoRojo(GdImage $imagen): string
{
    $ancho = imagesx($imagen);
    $alto = imagesy($imagen);

    foreach (['arriba-izquierda' => [1, 1], 'arriba-derecha' => [3, 1], 'abajo-izquierda' => [1, 3], 'abajo-derecha' => [3, 3]] as $cuarto => [$x, $y]) {
        $color = imagecolorsforindex($imagen, imagecolorat($imagen, intdiv($ancho * $x, 4), intdiv($alto * $y, 4)));

        if ($color['red'] > $color['blue']) {
            return $cuarto;
        }
    }

    return 'ninguno';
}

test('photos are reduced to 1600 px on the longest side as WebP', function () {
    $resultado = app(OptimizadorImagen::class)->optimizar(archivoImagen(jpegConOrientacion(3200, 1800, null)));

    expect($resultado)->not->toBeNull()
        ->and($resultado['extension'])->toBe('webp')
        ->and([$resultado['ancho'], $resultado['alto']])->toBe([1600, 900])
        ->and(array_slice(getimagesizefromstring($resultado['contenido']), 0, 3))->toBe([1600, 900, IMAGETYPE_WEBP]);
})->skip(! extension_loaded('gd'), 'Requiere la extensión GD de PHP');

test('the camera orientation is applied so the photo looks as on the phone', function (int $orientacion, array $medidas, string $cuarto) {
    $original = jpegConOrientacion(400, 200, $orientacion);
    // Sin girar y sin ganar peso puede quedar la original: se revisa la que se guardaría.
    $imagen = imagecreatefromstring(app(OptimizadorImagen::class)->optimizar(archivoImagen($original))['contenido'] ?? $original);

    expect([imagesx($imagen), imagesy($imagen)])->toBe($medidas)
        ->and(cuartoRojo($imagen))->toBe($cuarto);
})->with([
    // Guardada: 400 × 200, azul con el cuarto de arriba a la izquierda rojo.
    'normal' => [1, [400, 200], 'arriba-izquierda'],
    'espejo horizontal' => [2, [400, 200], 'arriba-derecha'],
    '180°' => [3, [400, 200], 'abajo-derecha'],
    'espejo vertical' => [4, [400, 200], 'abajo-izquierda'],
    'transpuesta' => [5, [200, 400], 'arriba-izquierda'],
    '90° horario (celular vertical)' => [6, [200, 400], 'arriba-derecha'],
    'transversa' => [7, [200, 400], 'abajo-derecha'],
    '90° antihorario' => [8, [200, 400], 'abajo-izquierda'],
])->skip(! extension_loaded('gd'), 'Requiere la extensión GD de PHP');

test('transparency of a PNG is kept in the WebP', function () {
    $lienzo = imagecreatetruecolor(2000, 1000);
    imagealphablending($lienzo, false);
    imagesavealpha($lienzo, true);
    imagefill($lienzo, 0, 0, (int) imagecolorallocatealpha($lienzo, 0, 0, 0, 127));
    imagefilledrectangle($lienzo, 500, 250, 1500, 750, (int) imagecolorallocate($lienzo, 0, 128, 0));
    ob_start();
    imagepng($lienzo);
    $png = (string) ob_get_clean();

    $imagen = imagecreatefromstring(app(OptimizadorImagen::class)->optimizar(archivoImagen($png))['contenido']);

    expect(imagecolorsforindex($imagen, imagecolorat($imagen, 5, 5))['alpha'])->toBe(127)
        ->and(imagecolorsforindex($imagen, imagecolorat($imagen, 800, 400))['alpha'])->toBe(0);
})->skip(! extension_loaded('gd'), 'Requiere la extensión GD de PHP');

test('a small photo that would not get lighter is kept as it was', function () {
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    Storage::fake('local');

    $ruta = app(OptimizadorImagen::class)->guardar(UploadedFile::fake()->createWithContent('logo.png', $png), 'empresas/1/productos', 'local');

    expect($ruta)->toStartWith('empresas/1/productos/')->toEndWith('.png')
        ->and(Storage::disk('local')->get($ruta))->toBe($png);
})->skip(! extension_loaded('gd'), 'Requiere la extensión GD de PHP');

test('a file GD cannot read is stored as it was instead of failing', function () {
    Storage::fake('local');
    // Pasa la validación por extensión pero no es una imagen que GD pueda leer (o el servidor no tiene GD).
    $contenido = 'no es una imagen';

    expect(app(OptimizadorImagen::class)->optimizar(archivoImagen($contenido)))->toBeNull();

    $ruta = app(OptimizadorImagen::class)->guardar(UploadedFile::fake()->createWithContent('rara.jpg', $contenido), 'empresas/1/modelos', 'local');
    expect($ruta)->toStartWith('empresas/1/modelos/')
        ->and(Storage::disk('local')->get($ruta))->toBe($contenido);
});
