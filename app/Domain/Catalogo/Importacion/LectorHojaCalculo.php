<?php

namespace App\Domain\Catalogo\Importacion;

use DateInterval;
use DateTimeInterface;
use Generator;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\CSV\Options as OpcionesCsv;
use OpenSpout\Reader\CSV\Reader as LectorCsv;
use OpenSpout\Reader\ODS\Options as OpcionesOds;
use OpenSpout\Reader\ODS\Reader as LectorOds;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\RowIteratorInterface;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\SheetIteratorInterface;
use OpenSpout\Reader\SheetWithVisibilityInterface;
use OpenSpout\Reader\XLSX\Options as OpcionesXlsx;
use OpenSpout\Reader\XLSX\Reader as LectorXlsx;
use Throwable;

/**
 * Lee hojas de Excel (.xlsx), LibreOffice (.ods) o CSV/TXT en streaming con OpenSpout.
 * Devuelve cada celda como texto limpio y marca las que llegaron como número de la hoja
 * (sin ambigüedad de separadores). Las fechas se convierten a texto y las fórmulas aportan
 * su último valor calculado.
 */
final class LectorHojaCalculo
{
    public const EXTENSIONES = ['xlsx', 'ods', 'csv', 'txt'];

    public const MAX_FILAS = 5000;

    public const MAX_COLUMNAS = 60;

    private const MAX_TEXTO = 2000;

    // Hojas con formato hasta la fila un millón: se deja de leer tras tantas filas vacías seguidas.
    private const MAX_VACIAS_SEGUIDAS = 500;

    /**
     * Hojas visibles del archivo (un CSV tiene una sola).
     *
     * @return list<array{indice: int, nombre: string}>
     */
    public function hojas(string $ruta, string $extension): array
    {
        if (! in_array($extension, ['xlsx', 'ods'], true)) {
            return [['indice' => 0, 'nombre' => 'CSV']];
        }

        return $this->conLector($ruta, $extension, function (ReaderInterface $lector): array {
            $hojas = [];

            foreach ($lector->getSheetIterator() as $hoja) {
                if (! $hoja instanceof SheetWithVisibilityInterface || $hoja->isVisible()) {
                    $hojas[] = ['indice' => $hoja->getIndex(), 'nombre' => $hoja->getName()];
                }
            }

            return $hojas;
        });
    }

    /**
     * Filas con al menos una celda, con su número real en la hoja (1 = primera fila).
     *
     * @return Generator<int, array{fila: int, celdas: array<int, string>, numericas: list<int>}>
     */
    public function filas(string $ruta, string $extension, int $hoja): Generator
    {
        $lector = $this->abrir($ruta, $extension);

        try {
            foreach ($lector->getSheetIterator() as $actual) {
                if ($actual->getIndex() !== $hoja) {
                    continue;
                }

                $numero = 0;
                $leidas = 0;
                $vacias = 0;

                foreach ($actual->getRowIterator() as $fila) {
                    $numero++;
                    $celdas = [];
                    $numericas = [];

                    foreach (array_slice($fila->getCells(), 0, self::MAX_COLUMNAS, true) as $indice => $celda) {
                        [$texto, $esNumero] = self::texto($celda);

                        if ($texto !== '') {
                            $celdas[(int) $indice] = $texto;

                            if ($esNumero) {
                                $numericas[] = (int) $indice;
                            }
                        }
                    }

                    if ($celdas === []) {
                        if (++$vacias >= self::MAX_VACIAS_SEGUIDAS) {
                            break;
                        }

                        continue;
                    }

                    $vacias = 0;

                    if (++$leidas > self::MAX_FILAS + 1) {
                        throw ValidationException::withMessages(['archivo' => __('El archivo tiene más de :maximo filas. Divídelo en partes y súbelas por separado.', ['maximo' => number_format(self::MAX_FILAS, 0, ',', '.')])]);
                    }

                    yield ['fila' => $numero, 'celdas' => $celdas, 'numericas' => $numericas];
                }

                return;
            }
        } finally {
            $lector->close();
        }
    }

    /**
     * @return array{0: string, 1: bool} texto y si llegó como número
     */
    public static function texto(Cell $celda): array
    {
        $valor = $celda instanceof FormulaCell ? $celda->getComputedValue() : $celda->getValue();

        return match (true) {
            $valor === null, $valor instanceof DateInterval => ['', false],
            is_bool($valor) => [$valor ? 'SI' : 'NO', false],
            is_int($valor) => [(string) $valor, true],
            is_float($valor) => [self::flotante($valor), is_finite($valor)],
            $valor instanceof DateTimeInterface => [$valor->format('d/m/Y'), false],
            default => [self::limpiar((string) $valor), false],
        };
    }

    /**
     * Número de la hoja a texto con punto decimal, sin notación científica ni restos binarios
     * (11.299999999999999 → «11.3»).
     */
    private static function flotante(float $valor): string
    {
        if (! is_finite($valor)) {
            return '';
        }

        $texto = sprintf('%.10F', $valor);
        $texto = rtrim(rtrim($texto, '0'), '.');

        return $texto === '-0' ? '0' : $texto;
    }

    private static function limpiar(string $texto): string
    {
        // Espacios duros, tabulaciones y saltos de línea de Excel se vuelven espacios simples.
        $texto = (string) preg_replace('/[\s\x{00A0}\x{2007}\x{202F}]+/u', ' ', $texto);
        $texto = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}]/u', '', $texto);

        return mb_substr(trim($texto), 0, self::MAX_TEXTO);
    }

    /**
     * @template T
     *
     * @param  callable(ReaderInterface<covariant SheetIteratorInterface<covariant SheetInterface<covariant RowIteratorInterface>>>): T  $uso
     * @return T
     */
    private function conLector(string $ruta, string $extension, callable $uso): mixed
    {
        $lector = $this->abrir($ruta, $extension);

        try {
            return $uso($lector);
        } finally {
            $lector->close();
        }
    }

    /**
     * @return ReaderInterface<covariant SheetIteratorInterface<covariant SheetInterface<covariant RowIteratorInterface>>>
     */
    private function abrir(string $ruta, string $extension): ReaderInterface
    {
        $lector = match ($extension) {
            'xlsx' => new LectorXlsx(tap(new OpcionesXlsx, fn (OpcionesXlsx $o) => $o->SHOULD_PRESERVE_EMPTY_ROWS = true)),
            'ods' => new LectorOds(tap(new OpcionesOds, fn (OpcionesOds $o) => $o->SHOULD_PRESERVE_EMPTY_ROWS = true)),
            'csv', 'txt' => new LectorCsv($this->opcionesCsv($ruta)),
            default => throw ValidationException::withMessages(['archivo' => __('Sube un archivo .xlsx, .ods o .csv.')]),
        };

        try {
            $lector->open($ruta);
        } catch (Throwable) {
            throw ValidationException::withMessages(['archivo' => __('No se pudo leer el archivo. Revisa que no esté dañado ni protegido con contraseña.')]);
        }

        return $lector;
    }

    /**
     * Detecta codificación (BOM, UTF-8 válido o Windows-1252 de Excel en español) y separador.
     */
    private function opcionesCsv(string $ruta): OpcionesCsv
    {
        $muestra = (string) file_get_contents($ruta, false, null, 0, 65536);
        $opciones = new OpcionesCsv;
        $opciones->SHOULD_PRESERVE_EMPTY_ROWS = true;

        if (str_starts_with($muestra, "\xFF\xFE") || str_starts_with($muestra, "\xFE\xFF")) {
            $opciones->ENCODING = str_starts_with($muestra, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $muestra = (string) mb_convert_encoding(substr($muestra, 2), 'UTF-8', $opciones->ENCODING);
        } elseif (str_starts_with($muestra, "\xEF\xBB\xBF")) {
            $muestra = substr($muestra, 3);
        } else {
            // La muestra puede cortar un carácter multibyte: se valida hasta el último salto de línea.
            $completa = strlen($muestra) < 65536 ? $muestra : substr($muestra, 0, (int) strrpos($muestra, "\n"));

            if (! mb_check_encoding($completa, 'UTF-8')) {
                $opciones->ENCODING = 'WINDOWS-1252';
            }
        }

        $primera = '';

        foreach (preg_split('/\r\n|\r|\n/', $muestra) ?: [] as $linea) {
            if (trim($linea) !== '') {
                $primera = $linea;

                break;
            }
        }

        $conteos = [];

        foreach ([';', ',', "\t", '|'] as $separador) {
            $conteos[$separador] = substr_count($primera, $separador);
        }

        arsort($conteos);
        $opciones->FIELD_DELIMITER = (int) reset($conteos) > 0 ? (string) array_key_first($conteos) : ',';

        return $opciones;
    }
}
