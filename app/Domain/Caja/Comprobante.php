<?php

namespace App\Domain\Caja;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Comprobante opcional de un cobro, de un pago o del respaldo de una obligación (§ 24.4):
 * imagen o PDF de hasta 5 MB, guardado en el disco privado bajo la carpeta de la empresa.
 * Se sirve solo por un controlador autorizado; nunca por una URL pública.
 */
final class Comprobante
{
    public const DISCO = 'local';

    public const MAX_KB = 5120;

    public const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    /**
     * Valida y guarda el archivo. Sin archivo devuelve null: el comprobante nunca es obligatorio.
     *
     * @return array{path: string, mime: string}|null
     *
     * @throws ValidationException
     */
    public static function guardar(?UploadedFile $archivo, int $empresaId, string $carpeta, string $campo = 'comprobante'): ?array
    {
        if ($archivo === null) {
            return null;
        }

        $mime = (string) $archivo->getMimeType();

        if (! $archivo->isValid() || ! array_key_exists($mime, self::TIPOS) || $archivo->getSize() <= 0 || $archivo->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages([$campo => __('El comprobante debe ser JPG, PNG, WEBP o PDF de hasta 5 MB.')]);
        }

        $ruta = $archivo->storeAs("empresas/{$empresaId}/{$carpeta}", Str::random(40).'.'.self::TIPOS[$mime], self::DISCO);

        if ($ruta === false) {
            throw ValidationException::withMessages([$campo => __('No se pudo guardar el comprobante. Inténtalo de nuevo.')]);
        }

        return ['path' => $ruta, 'mime' => $mime];
    }

    /**
     * Quita un archivo guardado cuando la operación que lo acompañaba no se registró.
     *
     * @param  array{path: string, mime: string}|null  $guardado
     */
    public static function descartar(?array $guardado): void
    {
        if ($guardado !== null) {
            Storage::disk(self::DISCO)->delete($guardado['path']);
        }
    }
}
