<?php

namespace App\Domain\Catalogo;

use Illuminate\Support\Str;

/**
 * Propuesta de talla y color a partir del nombre de un producto suelto (ESPECIFICACION_TALLAS_Y_COLORES.md § 9.3):
 * «Polera Básica M Negro» → talla M, color Negro y nombre base «Polera Básica». Solo propone: en «Agrupar en modelo»
 * el usuario siempre confirma o corrige cada fila, y AgruparEnModelo valida todo en el servidor.
 *
 * Compara palabras completas, sin tildes ni mayúsculas; prueba primero los nombres más largos («Azul marino» antes
 * que «Azul», «XXL» antes que «XL»). No consulta la base: recibe las listas ya cargadas.
 */
final class AdivinarVariante
{
    /**
     * @param  iterable<array{id: int, nombre: string}>  $tallas
     * @param  iterable<array{id: int, nombre: string}>  $colores
     * @return array{talla_id: int|null, color_id: int|null, base: string}
     */
    public static function desdeNombre(string $nombre, iterable $tallas, iterable $colores): array
    {
        $palabras = self::palabras($nombre);
        $comparables = array_map(fn (string $p): string => self::normalizar($p), $palabras);

        [$colorId, $comparables] = self::buscar($comparables, $colores);
        [$tallaId, $comparables] = self::buscar($comparables, $tallas);

        $base = [];

        foreach ($palabras as $indice => $palabra) {
            if ($comparables[$indice] !== null) {
                $base[] = $palabra;
            }
        }

        $base = trim(implode(' ', $base));

        return ['talla_id' => $tallaId, 'color_id' => $colorId, 'base' => $base === '' ? trim($nombre) : $base];
    }

    /**
     * Primera opción (la de nombre más largo) cuyas palabras aparecen seguidas en el nombre; las marca como usadas.
     *
     * @param  array<int, string|null>  $comparables  null = palabra ya usada
     * @param  iterable<array{id: int, nombre: string}>  $opciones
     * @return array{0: int|null, 1: array<int, string|null>}
     */
    private static function buscar(array $comparables, iterable $opciones): array
    {
        $candidatas = [];

        foreach ($opciones as $opcion) {
            $partes = array_map(fn (string $p): string => self::normalizar($p), self::palabras($opcion['nombre']));

            if ($partes !== []) {
                $candidatas[] = ['id' => $opcion['id'], 'partes' => $partes, 'largo' => mb_strlen(implode(' ', $partes))];
            }
        }

        usort($candidatas, fn (array $a, array $b): int => $b['largo'] <=> $a['largo']);

        foreach ($candidatas as $candidata) {
            $n = count($candidata['partes']);

            // Desde el final: la talla y el color suelen ir después del nombre («Polera M Negro»).
            for ($i = count($comparables) - $n; $i >= 0; $i--) {
                if (array_slice($comparables, $i, $n) === $candidata['partes']) {
                    for ($j = $i; $j < $i + $n; $j++) {
                        $comparables[$j] = null;
                    }

                    return [$candidata['id'], $comparables];
                }
            }
        }

        return [null, $comparables];
    }

    /**
     * @return list<string>
     */
    private static function palabras(string $texto): array
    {
        return array_values(array_filter(preg_split('/[\s·,;:()\[\]|]+|\s-\s/u', trim($texto)) ?: [], fn (string $p): bool => $p !== '' && $p !== '-'));
    }

    private static function normalizar(string $palabra): string
    {
        return Str::lower(Str::ascii($palabra));
    }
}
