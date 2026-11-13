<?php

namespace App\Domain\Inventario;

use Illuminate\Support\Carbon;

/**
 * Datos de un lote al ingresar stock (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 5.1) y regla de vencido (§ 3 y § 5.7).
 * Solo interpreta texto: la existencia del lote y la coincidencia de su fecha las valida LibroLotes en el servidor.
 */
final class DatosLote
{
    public const MAX_NUMERO = 40;

    /**
     * Número de lote sin espacios al inicio ni al final; null si queda vacío. No se cambia a mayúsculas: la
     * intercalación de la base compara sin distinguir mayúsculas («a123» y «A123» son el mismo lote).
     */
    public static function numero(mixed $valor): ?string
    {
        $texto = is_scalar($valor) ? trim((string) $valor) : '';

        return $texto === '' ? null : $texto;
    }

    /**
     * Clave para agrupar líneas del mismo lote igual que lo hace la base.
     */
    public static function clave(int $productoId, string $numero): string
    {
        return $productoId.'|'.mb_strtoupper($numero);
    }

    /**
     * Fecha de vencimiento en formato Y-m-d, o null si no es válida. Acepta dd/mm/aaaa, aaaa-mm-dd (selector de
     * fecha) y mes/año (mm/aaaa o aaaa-mm), que se guarda como el último día de ese mes, como se rotulan los
     * medicamentos (§ 5.1.2). Años de 2000 a 2099 para descartar errores de tipeo.
     */
    public static function vencimiento(mixed $valor): ?string
    {
        $texto = is_scalar($valor) ? (string) preg_replace('/\s+/', '', (string) $valor) : '';
        $dia = null;

        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $texto, $m) === 1) {
            [$dia, $mes, $anio] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $texto, $m) === 1) {
            [$anio, $mes, $dia] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{4})$/', $texto, $m) === 1) {
            [$mes, $anio] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^(\d{4})-(\d{1,2})$/', $texto, $m) === 1) {
            [$anio, $mes] = [(int) $m[1], (int) $m[2]];
        } else {
            return null;
        }

        if ($anio < 2000 || $anio > 2099 || $mes < 1 || $mes > 12) {
            return null;
        }

        // Mes/año: último día del mes, incluido el 29 de febrero de un año bisiesto.
        $dia ??= (int) Carbon::create($anio, $mes, 1)->endOfMonth()->format('j');

        return checkdate($mes, $dia, $anio) ? sprintf('%04d-%02d-%02d', $anio, $mes, $dia) : null;
    }

    /**
     * Fecha de hoy en la zona horaria del negocio (America/La_Paz), Y-m-d.
     */
    public static function hoy(): string
    {
        return Carbon::now((string) config('mozistock.business_timezone'))->format('Y-m-d');
    }

    /**
     * Vencido = fecha anterior a hoy en la zona del negocio: el día del vencimiento todavía se vende (§ 3).
     * El lote de regularización (sin fecha) nunca está vencido (§ 9.4).
     */
    public static function vencido(?string $vencimiento, ?string $hoy = null): bool
    {
        return $vencimiento !== null && substr($vencimiento, 0, 10) < ($hoy ?? self::hoy());
    }

    /**
     * Número y vencimiento de una línea con lote (§ 5.1): el número es obligatorio (P1), hasta 40 caracteres y sin
     * espacios al inicio ni al final; la fecha, completa o mes/año. Los errores se agregan con el nombre del producto.
     *
     * @param  list<array<mixed>|string>  $errores  mensajes de __()
     *
     * @param-out list<array<mixed>|string>  $errores
     *
     * @return array{numero: string, vencimiento: string}|null
     */
    public static function leer(string $producto, mixed $numero, mixed $vencimiento, array &$errores): ?array
    {
        $numero = self::numero($numero);
        $fecha = self::vencimiento($vencimiento);

        if ($numero === null) {
            $errores[] = __(':producto: escribe el número de lote.', ['producto' => $producto]);
        } elseif (mb_strlen($numero) > self::MAX_NUMERO) {
            $errores[] = __(':producto: el número de lote admite hasta :maximo caracteres.', ['producto' => $producto, 'maximo' => self::MAX_NUMERO]);
            $numero = null;
        }

        if ($fecha === null) {
            $errores[] = __(':producto: escribe el vencimiento del lote como dd/mm/aaaa o mm/aaaa.', ['producto' => $producto]);
        }

        return $numero === null || $fecha === null ? null : ['numero' => $numero, 'vencimiento' => $fecha];
    }

    public static function formatear(?string $vencimiento): string
    {
        return $vencimiento === null ? '—' : Carbon::parse(substr($vencimiento, 0, 10))->format('d/m/Y');
    }
}
