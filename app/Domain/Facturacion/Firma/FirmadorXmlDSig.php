<?php

namespace App\Domain\Facturacion\Firma;

use App\Domain\Facturacion\ErrorFacturacion;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

/**
 * Servicio de firma digital XMLDSig para la modalidad "Electrónica en Línea" del SIAT.
 * Utiliza el motor XMLDSig (enveloped, C14N inclusive, RSA-SHA256) certificado en la Etapa VIII.
 */
class FirmadorXmlDSig
{
    /**
     * Firma un XML de factura electrónica utilizando un certificado digital PKCS#12 (.p12).
     *
     * @throws ErrorFacturacion si el archivo no existe, la contraseña es inválida o el firmador falla.
     */
    public function firmar(string $xml, string $p12Path, string $password): string
    {
        if (! file_exists($p12Path)) {
            throw new ErrorFacturacion(__('No se encuentra el archivo de certificado digital .p12 en el servidor.'));
        }

        $tmpDir = function_exists('storage_path') && app()->has('path.storage')
            ? storage_path('app/private/tmp_firmas')
            : sys_get_temp_dir().DIRECTORY_SEPARATOR.'siat_firmas';
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0700, true);
        }

        $unsigned = tempnam($tmpDir, 'xml_in_');
        $signed = tempnam($tmpDir, 'xml_out_');

        if ($unsigned === false || $signed === false) {
            throw new ErrorFacturacion(__('No se pudieron crear archivos temporales para la firma digital.'));
        }

        try {
            file_put_contents($unsigned, $xml);

            $signerClassDir = __DIR__;
            $process = new Process([
                'java',
                '-cp',
                $signerClassDir,
                'Signer',
                $unsigned,
                $signed,
                $p12Path,
                $password,
            ]);
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful() || ! file_exists($signed) || filesize($signed) === 0) {
                $err = trim($process->getErrorOutput() ?: $process->getOutput());
                throw new ErrorFacturacion(__('Fallo al firmar digitalmente la factura: :error', [
                    'error' => $err !== '' ? $err : __('Error interno en el componente XMLDSig.'),
                ]));
            }

            return (string) file_get_contents($signed);
        } finally {
            if (file_exists($unsigned)) {
                @unlink($unsigned);
            }
            if (file_exists($signed)) {
                @unlink($signed);
            }
        }
    }

    /**
     * Comprueba si Java Runtime Environment (JRE) está instalado y disponible.
     */
    public function estaDisponible(): bool
    {
        try {
            $process = new Process(['java', '-version']);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Valida la integridad y contraseña de un archivo PKCS#12 (.p12) y extrae sus metadatos.
     *
     * @return array{valido: bool, error: ?string, vence_en: ?Carbon, subject: array<string, mixed>, issuer: array<string, mixed>}
     */
    public static function inspeccionarP12(string $p12Contenido, string $password): array
    {
        $certs = [];
        $ok = @openssl_pkcs12_read($p12Contenido, $certs, $password);

        if (! $ok || empty($certs['cert']) || empty($certs['pkey'])) {
            $err = openssl_error_string();

            return [
                'valido' => false,
                'error' => $err ?: __('Contraseña incorrecta o archivo de certificado no válido.'),
                'vence_en' => null,
                'subject' => [],
                'issuer' => [],
            ];
        }

        $info = openssl_x509_parse($certs['cert']);
        $venceEn = isset($info['validTo_time_t']) ? Carbon::createFromTimestamp($info['validTo_time_t']) : null;

        return [
            'valido' => true,
            'error' => null,
            'vence_en' => $venceEn,
            'subject' => $info['subject'] ?? [],
            'issuer' => $info['issuer'] ?? [],
        ];
    }
}
