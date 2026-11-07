<?php

namespace App\Domain\Catalogo\Importacion;

/**
 * Interpreta precios y cantidades escritos en una hoja: «12,50», «12.50», «1.234,50»,
 * «1,234.50», «Bs 12,50». Nunca usa float: devuelve el valor como texto decimal con punto.
 * Un único separador seguido de exactamente tres cifras («1.500») es ambiguo (¿mil quinientos
 * o uno y medio?) y se rechaza en vez de adivinar. Los valores que llegaron como número de la
 * hoja ya vienen en formato canónico y no tienen esa ambigüedad.
 */
final class NumeroImportado
{
    public const VACIO = 'vacio';

    public const INVALIDO = 'invalido';

    public const AMBIGUO = 'ambiguo';

    public const CIENTIFICO = 'cientifico';

    /**
     * @return array{0: numeric-string|null, 1: string|null} [valor, motivo del rechazo]
     */
    public static function leer(string $texto, bool $esNumeroDeHoja): array
    {
        $limpio = (string) preg_replace('/\s+/u', '', $texto);
        $limpio = (string) preg_replace('/^(bs\.?|\$)|(bs\.?)$/i', '', $limpio);

        if ($limpio === '') {
            return [null, self::VACIO];
        }

        if ($esNumeroDeHoja) {
            return preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $limpio, $partes) === 1
                ? [self::armar($partes[1] === '-', $partes[2], $partes[3] ?? ''), null]
                : [null, self::INVALIDO];
        }

        if (preg_match('/^-?\d+([.,]\d+)?e[+-]?\d+$/i', $limpio) === 1) {
            return [null, self::CIENTIFICO];
        }

        $negativo = str_starts_with($limpio, '-');
        $cuerpo = ltrim($limpio, '-');

        if (preg_match('/^[\d.,]+$/', $cuerpo) !== 1 || preg_match('/\d/', $cuerpo) !== 1) {
            return [null, self::INVALIDO];
        }

        $comas = substr_count($cuerpo, ',');
        $puntos = substr_count($cuerpo, '.');

        if ($comas > 0 && $puntos > 0) {
            // El separador que aparece al final es el decimal; el otro agrupa miles.
            $decimal = strrpos($cuerpo, ',') > strrpos($cuerpo, '.') ? ',' : '.';
            $miles = $decimal === ',' ? '.' : ',';
            [$entero, $fraccion] = array_pad(explode($decimal, $cuerpo), 2, '');

            if (substr_count($cuerpo, $decimal) > 1 || ! self::milesValidos($entero, $miles) || $fraccion === '') {
                return [null, self::INVALIDO];
            }

            return [self::armar($negativo, str_replace($miles, '', $entero), $fraccion), null];
        }

        if ($comas + $puntos === 0) {
            return [self::armar($negativo, $cuerpo, ''), null];
        }

        $separador = $comas > 0 ? ',' : '.';

        if ($comas + $puntos > 1) {
            // «1.234.567»: solo puede ser separador de miles.
            return self::milesValidos($cuerpo, $separador)
                ? [self::armar($negativo, str_replace($separador, '', $cuerpo), ''), null]
                : [null, self::INVALIDO];
        }

        [$entero, $fraccion] = explode($separador, $cuerpo);

        if ($entero === '' || $fraccion === '') {
            return [null, self::INVALIDO];
        }

        if (strlen($fraccion) === 3 && strlen($entero) <= 3 && ltrim($entero, '0') !== '') {
            return [null, self::AMBIGUO];
        }

        return [self::armar($negativo, $entero, $fraccion), null];
    }

    private static function milesValidos(string $entero, string $separador): bool
    {
        return preg_match('/^\d{1,3}(\\'.$separador.'\d{3})+$/', $entero) === 1 || preg_match('/^\d+$/', $entero) === 1;
    }

    /**
     * @return numeric-string
     */
    private static function armar(bool $negativo, string $entero, string $fraccion): string
    {
        $entero = ltrim($entero, '0') === '' ? '0' : ltrim($entero, '0');
        $valor = $entero.($fraccion === '' ? '' : '.'.$fraccion);

        /** @var numeric-string */
        return ($negativo && $valor !== '0' ? '-' : '').$valor;
    }
}
