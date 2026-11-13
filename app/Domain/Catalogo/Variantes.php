<?php

namespace App\Domain\Catalogo;

use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reglas comunes de tallas y colores (ESPECIFICACION_TALLAS_Y_COLORES.md): nombre de la variante, SKU sugerido,
 * código de color y la capacidad «variantes».
 */
final class Variantes
{
    public const SEPARADOR = ' · ';

    /**
     * Largo máximo del nombre del modelo: con la talla (15) y el color (40) más largos, el nombre de la variante cabe
     * en productos.nombre (150), también después de renombrar una talla o un color.
     */
    public const MAX_NOMBRE_MODELO = 80;

    public const MAX_NOMBRE_TALLA = 15;

    public const MAX_NOMBRE_COLOR = 40;

    public const MAX_SKU = 40;

    public const MAX_SKU_BASE = 30;

    public const MAX_CODIGO_COLOR = 6;

    /** Mismo formato que el formulario de producto. */
    public const PATRON_SKU = '/^[A-Za-z0-9._\-\/]+$/';

    public function __construct(private readonly CapacidadesEmpresa $capacidades) {}

    public function activa(int $empresaId): bool
    {
        return $this->capacidades->tiene($empresaId, Capacidad::Variantes);
    }

    /**
     * Rechazo en el servidor con la capacidad apagada, aunque se llame a la acción directamente (rubros § 6).
     */
    public function exigir(int $empresaId): void
    {
        if (! $this->activa($empresaId)) {
            throw ValidationException::withMessages(['modelo' => __('La empresa no tiene activa la función «Tallas y colores».')]);
        }
    }

    /**
     * «Polera Básica · M · Negro», «Zapato Oxford · 40» o «Gorra Trucker · Rojo» (§ 6.4). Igual que CONCAT_WS en SQL.
     */
    public static function nombre(string $modelo, ?string $talla, ?string $color): string
    {
        return implode(self::SEPARADOR, array_filter([trim($modelo), $talla, $color], fn (?string $parte): bool => $parte !== null && $parte !== ''));
    }

    /**
     * Expresión SQL del nombre de la variante, para regenerar nombres con una sola sentencia (§ 6.4). Recibe las
     * expresiones del nombre del modelo, de la talla y del color (siempre columnas fijas del código, nunca datos).
     *
     * @param  literal-string  $modelo
     * @param  literal-string  $talla
     * @param  literal-string  $color
     * @return literal-string
     */
    public static function nombreSql(string $modelo, string $talla, string $color): string
    {
        return "CONCAT_WS(' · ', {$modelo}, {$talla}, {$color})";
    }

    /**
     * Regenera con UNA sentencia el nombre de las variantes de un modelo, una talla o un color (§ 6.4), a partir de
     * los nombres ya guardados. Las ventas pasadas no cambian: venta_items guarda su propia descripción.
     *
     * @param  'modelo_id'|'talla_id'|'color_id'  $columna
     * @return int variantes renombradas
     */
    public static function regenerarNombres(int $empresaId, string $columna, int $id): int
    {
        return DB::table('productos as p')
            ->join('modelos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'p.modelo_id')->on('m.empresa_id', '=', 'p.empresa_id'))
            ->leftJoin('tallas as t', fn (JoinClause $j) => $j->on('t.id', '=', 'p.talla_id')->on('t.empresa_id', '=', 'p.empresa_id'))
            ->leftJoin('colores as c', fn (JoinClause $j) => $j->on('c.id', '=', 'p.color_id')->on('c.empresa_id', '=', 'p.empresa_id'))
            ->where('p.empresa_id', $empresaId)
            ->where('p.'.$columna, $id)
            ->update(['p.nombre' => DB::raw(self::nombreSql('m.nombre', 't.nombre', 'c.nombre')), 'p.updated_at' => now()]);
    }

    /**
     * SKU sugerido SKU-BASE-TALLA-COLOR (§ 4.2), por ejemplo POL001-M-NEG. Sin SKU base no se sugiere ninguno.
     */
    public static function sku(?string $base, ?string $talla, ?string $codigoColor): ?string
    {
        $base = self::limpiarSku((string) $base);

        if ($base === '') {
            return null;
        }

        $partes = array_filter([$base, self::limpiarSku((string) $talla), self::limpiarSku((string) $codigoColor)], fn (string $parte): bool => $parte !== '');

        return Str::limit(implode('-', $partes), self::MAX_SKU, '');
    }

    /**
     * Mayúsculas, sin tildes ni espacios y solo los caracteres que admite un SKU.
     */
    public static function limpiarSku(string $texto): string
    {
        return (string) preg_replace('/[^A-Z0-9._\-\/]/', '', Str::upper(Str::ascii(trim($texto))));
    }

    /**
     * Código corto sugerido para un color (§ 3.2): «Negro» → NEG, «Azul marino» → AZM.
     */
    public static function codigoColor(string $nombre): string
    {
        $palabras = array_values(array_filter(preg_split('/\s+/', Str::upper(Str::ascii(trim($nombre)))) ?: [], fn (string $p): bool => $p !== ''));
        $palabras = array_map(fn (string $p): string => (string) preg_replace('/[^A-Z0-9]/', '', $p), $palabras);
        $palabras = array_values(array_filter($palabras, fn (string $p): bool => $p !== ''));

        if ($palabras === []) {
            return '';
        }

        if (count($palabras) === 1) {
            return substr($palabras[0], 0, 3);
        }

        return substr($palabras[0], 0, 2).substr($palabras[1], 0, 1);
    }

    /**
     * Identifica una combinación en formularios: «talla-color», con 0 cuando no tiene talla o color.
     */
    public static function clave(?int $tallaId, ?int $colorId): string
    {
        return ($tallaId ?? 0).'-'.($colorId ?? 0);
    }

    /**
     * @return array{0: int|null, 1: int|null} talla y color de una clave «talla-color»
     */
    public static function desdeClave(string $clave): array
    {
        [$talla, $color] = array_pad(explode('-', $clave, 2), 2, '0');

        return [ctype_digit($talla) && (int) $talla > 0 ? (int) $talla : null, ctype_digit($color) && (int) $color > 0 ? (int) $color : null];
    }
}
