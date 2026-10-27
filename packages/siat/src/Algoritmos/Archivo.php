<?php

namespace MoziStudios\Siat\Algoritmos;

use MoziStudios\Siat\Excepciones\DatosInvalidos;
use PharData;
use Throwable;

/**
 * Preparación del contenido que viaja en «archivo» y «hashArchivo»: el XML se comprime con GZIP y el
 * hash SHA-256 (hexadecimal en minúsculas) se calcula sobre el archivo ya comprimido (anexo «Emisión y envío»).
 * Para paquetes de facturas, se construye un archivo TAR comprimido con GZIP (.tar.gz).
 */
final class Archivo
{
    public static function comprimir(string $contenido): string
    {
        $comprimido = gzencode($contenido, 9);

        if ($comprimido === false) {
            throw new DatosInvalidos('No se pudo comprimir el archivo con GZIP.');
        }

        return $comprimido;
    }

    public static function descomprimir(string $comprimido): string
    {
        $contenido = @gzdecode($comprimido);

        if ($contenido === false) {
            throw new DatosInvalidos('El archivo no es un GZIP válido.');
        }

        return $contenido;
    }

    public static function sha256(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /**
     * Empaqueta un conjunto de archivos en formato TAR (ustar) usando PharData temporal.
     *
     * @param  array<string, string>  $archivos  [nombre_archivo => contenido_string]
     * @return string contenido binario del archivo TAR
     */
    public static function tar(array $archivos): string
    {
        if ($archivos === []) {
            throw new DatosInvalidos('El archivo TAR no puede estar vacío.');
        }

        $tar = '';
        foreach ($archivos as $nombre => $contenido) {
            $tamano = strlen($contenido);
            $header = pack('a100', $nombre);
            $header .= pack('a8', sprintf('%07o ', 0644));
            $header .= pack('a8', sprintf('%07o ', 0));
            $header .= pack('a8', sprintf('%07o ', 0));
            $header .= pack('a12', sprintf('%011o ', $tamano));
            $header .= pack('a12', sprintf('%011o ', time()));
            $header .= '        ';
            $header .= '0';
            $header .= pack('a100', '');
            $header .= 'ustar  ';
            $header .= pack('a2', '00');
            $header .= pack('a32', '');
            $header .= pack('a32', '');
            $header .= pack('a8', '');
            $header .= pack('a8', '');
            $header .= pack('a155', '');
            $header = str_pad($header, 512, "\0");

            $chksum = 0;
            for ($i = 0; $i < 512; $i++) {
                $chksum += ord($header[$i]);
            }
            $chksumOctal = sprintf('%06o', $chksum)."\0 ";
            for ($i = 0; $i < 8; $i++) {
                $header[148 + $i] = $chksumOctal[$i];
            }

            $tar .= $header;
            $tar .= $contenido;
            $resto = $tamano % 512;
            if ($resto > 0) {
                $tar .= str_repeat("\0", 512 - $resto);
            }
        }

        $tar .= str_repeat("\0", 1024);

        return $tar;
    }

    /**
     * Empaqueta un conjunto de archivos en formato TAR y lo comprime con GZIP (.tar.gz).
     *
     * @param  array<string, string>  $archivos  [nombre_archivo => contenido_string]
     * @return string contenido binario comprimido con GZIP
     */
    public static function tarGz(array $archivos): string
    {
        return self::comprimir(self::tar($archivos));
    }

    /**
     * Extrae un archivo TAR binario a un mapa [nombre => contenido].
     *
     * @return array<string, string>
     */
    public static function desglosarTar(string $tarBytes): array
    {
        $tempTar = sys_get_temp_dir().DIRECTORY_SEPARATOR.'siat_read_'.bin2hex(random_bytes(8)).'.tar';

        try {
            if (file_put_contents($tempTar, $tarBytes) === false) {
                throw new DatosInvalidos('No se pudo escribir el archivo temporal TAR.');
            }
            $phar = new PharData($tempTar);
            $archivos = [];
            foreach ($phar as $archivo) {
                $archivos[$archivo->getFilename()] = (string) file_get_contents($archivo->getPathname());
            }

            return $archivos;
        } catch (Throwable $e) {
            throw new DatosInvalidos('Error al leer el archivo TAR: '.$e->getMessage(), previous: $e);
        } finally {
            if (file_exists($tempTar)) {
                @unlink($tempTar);
            }
        }
    }
}
