<?php

namespace App\Domain\Facturacion;

use App\Domain\Facturacion\Firma\FirmadorXmlDSig;
use App\Models\Empresa;
use App\Models\SiatConfiguracion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Certificado digital .p12 de la modalidad electrónica. Comprueba que el archivo abra con la contraseña
 * (sin enviar nada al SIN: el SIN solo valida la firma al recibir la primera factura) y lo guarda en
 * el disco privado. El archivo anterior se borra solo después de que los datos fiscales se guardaron.
 */
final class CertificadoDigital
{
    /**
     * Datos del certificado para GuardarDatosFiscales. Sin archivo nuevo, solo cambia la contraseña
     * (si se escribió una) del archivo ya guardado. El llamador confirma con `confirmar()` o descarta con `descartar()`.
     *
     * @return array{datos: array{certificado_ruta?: string, certificado_nombre?: string, certificado_pass?: string, certificado_vence_en?: Carbon|null}, nuevo: string|null, anterior: string|null}
     *
     * @throws ValidationException
     */
    public static function preparar(Empresa $empresa, ?SiatConfiguracion $configuracion, ?UploadedFile $archivo, #[SensitiveParameter] string $contrasena): array
    {
        if ($archivo !== null) {
            $extension = strtolower((string) $archivo->getClientOriginalExtension());

            if (! in_array($extension, ['p12', 'pfx'], true)) {
                throw ValidationException::withMessages(['certificado' => __('El archivo debe ser un certificado digital en formato .p12 o .pfx.')]);
            }

            if (blank($contrasena)) {
                throw ValidationException::withMessages(['certificado_pass' => __('Digita la contraseña para verificar y abrir el archivo .p12.')]);
            }

            $inspeccion = FirmadorXmlDSig::inspeccionarP12((string) file_get_contents((string) $archivo->getRealPath()), $contrasena);

            if (! $inspeccion['valido']) {
                throw ValidationException::withMessages(['certificado_pass' => __('La contraseña no corresponde al archivo .p12 o el certificado es inválido: :error', [
                    'error' => $inspeccion['error'] ?? __('verificación fallida'),
                ])]);
            }

            self::exigirVigente($inspeccion['vence_en']);

            $ruta = Storage::disk('local')->putFileAs('certificados', $archivo, 'empresa_'.$empresa->id.'_'.Str::random(16).'.p12');

            if (! is_string($ruta)) {
                throw ValidationException::withMessages(['certificado' => __('No se pudo guardar el archivo del certificado. Intenta de nuevo.')]);
            }

            return [
                'datos' => [
                    'certificado_ruta' => $ruta,
                    'certificado_nombre' => (string) $archivo->getClientOriginalName(),
                    'certificado_pass' => $contrasena,
                    'certificado_vence_en' => $inspeccion['vence_en'],
                ],
                'nuevo' => $ruta,
                'anterior' => $configuracion?->certificado_ruta,
            ];
        }

        if ($configuracion?->tieneCertificado() && filled($contrasena)) {
            $ruta = $configuracion->certificadoRutaAbsoluta();
            $inspeccion = $ruta !== null && is_file($ruta)
                ? FirmadorXmlDSig::inspeccionarP12((string) file_get_contents($ruta), $contrasena)
                : ['valido' => false];

            if (! $inspeccion['valido']) {
                throw ValidationException::withMessages(['certificado_pass' => __('La nueva contraseña no corresponde al archivo .p12 guardado.')]);
            }

            return ['datos' => ['certificado_pass' => $contrasena], 'nuevo' => null, 'anterior' => null];
        }

        return ['datos' => [], 'nuevo' => null, 'anterior' => null];
    }

    /**
     * @param  array{nuevo: string|null, anterior: string|null}  $preparado
     */
    public static function confirmar(array $preparado): void
    {
        if ($preparado['anterior'] !== null && $preparado['anterior'] !== $preparado['nuevo'] && Storage::disk('local')->exists($preparado['anterior'])) {
            Storage::disk('local')->delete($preparado['anterior']);
        }
    }

    /**
     * @param  array{nuevo: string|null}  $preparado
     */
    public static function descartar(array $preparado): void
    {
        if ($preparado['nuevo'] !== null) {
            Storage::disk('local')->delete($preparado['nuevo']);
        }
    }

    /**
     * Revisión local del certificado guardado: abre con su contraseña y está vigente.
     *
     * @return array{valido: bool, error: string|null, vence_en: Carbon|null, titular: string|null}
     */
    public static function revisar(SiatConfiguracion $configuracion): array
    {
        if (! $configuracion->tieneCertificado()) {
            return ['valido' => false, 'error' => __('Falta subir el certificado .p12 y su contraseña.'), 'vence_en' => null, 'titular' => null];
        }

        $ruta = $configuracion->certificadoRutaAbsoluta();

        if ($ruta === null || ! is_file($ruta)) {
            return ['valido' => false, 'error' => __('El archivo del certificado no está en el servidor. Vuelve a subirlo.'), 'vence_en' => null, 'titular' => null];
        }

        try {
            $contrasena = (string) $configuracion->certificadoPasswordDescifrado();
        } catch (\Throwable) {
            return ['valido' => false, 'error' => __('No se pudo leer la contraseña guardada. Vuelve a escribirla.'), 'vence_en' => null, 'titular' => null];
        }

        $inspeccion = FirmadorXmlDSig::inspeccionarP12((string) file_get_contents($ruta), $contrasena);
        $titular = $inspeccion['subject']['CN'] ?? null;
        $titular = is_array($titular) ? implode(', ', $titular) : $titular;

        if (! $inspeccion['valido']) {
            return ['valido' => false, 'error' => __('La contraseña guardada ya no abre el certificado. Vuelve a escribirla.'), 'vence_en' => null, 'titular' => null];
        }

        if ($inspeccion['vence_en'] !== null && $inspeccion['vence_en']->isPast()) {
            return ['valido' => false, 'error' => __('El certificado venció. Sube uno vigente.'), 'vence_en' => $inspeccion['vence_en'], 'titular' => $titular];
        }

        return ['valido' => true, 'error' => null, 'vence_en' => $inspeccion['vence_en'], 'titular' => $titular];
    }

    private static function exigirVigente(?Carbon $venceEn): void
    {
        if ($venceEn !== null && $venceEn->isPast()) {
            throw ValidationException::withMessages(['certificado' => __('El certificado venció el :fecha. Sube uno vigente.', [
                'fecha' => $venceEn->timezone((string) config('mozistock.business_timezone'))->format('d/m/Y'),
            ])]);
        }
    }
}
