<?php

namespace App\Support;

use Closure;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación CSV en streaming: UTF-8 con BOM, separador «;» (Excel en español) y
 * celdas protegidas contra fórmulas.
 */
final class Csv
{
    /**
     * @param  list<string>  $encabezados
     * @param  Closure(): iterable<array<int, string|int|null>>  $filas
     */
    public static function descargar(string $nombre, array $encabezados, Closure $filas): StreamedResponse
    {
        return response()->streamDownload(function () use ($encabezados, $filas): void {
            $salida = fopen('php://output', 'w');

            if ($salida === false) {
                throw new RuntimeException('No se pudo iniciar la exportación.');
            }

            fwrite($salida, "\u{FEFF}");
            fputcsv($salida, $encabezados, ';', escape: '');

            foreach ($filas() as $fila) {
                fputcsv($salida, array_map(self::celda(...), $fila), ';', escape: '');
            }

            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Evita que una hoja de cálculo interprete el texto como fórmula.
     */
    public static function celda(string|int|null $valor): string
    {
        $texto = (string) $valor;

        return preg_match('/^[=+\-@\t\r]/', $texto) === 1 && ! is_numeric($texto) ? "'".$texto : $texto;
    }
}
