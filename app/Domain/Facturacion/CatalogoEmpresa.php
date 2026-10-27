<?php

namespace App\Domain\Facturacion;

use App\Models\SiatCatalogo;
use Illuminate\Support\Collection;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Consultas a los catálogos del SIN sincronizados por empresa. Los códigos fiscales siempre salen
 * de aquí (decisión D2): nunca se fijan en el código, salvo los del anexo que el paquete ya valida.
 */
final class CatalogoEmpresa
{
    public static function existe(int $empresaId, Catalogo $catalogo, int|string $codigo): bool
    {
        return DeEmpresa::consulta(SiatCatalogo::class, $empresaId)
            ->where('catalogo', $catalogo->name)->where('codigo', (string) $codigo)->exists();
    }

    public static function descripcion(int $empresaId, Catalogo $catalogo, int|string|null $codigo): ?string
    {
        if ($codigo === null) {
            return null;
        }

        $descripcion = DeEmpresa::consulta(SiatCatalogo::class, $empresaId)
            ->where('catalogo', $catalogo->name)->where('codigo', (string) $codigo)->value('descripcion');

        return is_string($descripcion) ? $descripcion : null;
    }

    /**
     * Opciones ordenadas por código numérico, para selects.
     *
     * @return Collection<int, SiatCatalogo>
     */
    public static function opciones(int $empresaId, Catalogo $catalogo): Collection
    {
        return DeEmpresa::consulta(SiatCatalogo::class, $empresaId)
            ->where('catalogo', $catalogo->name)
            ->orderByRaw('CAST(codigo AS UNSIGNED)')
            ->orderBy('id')
            ->get(['id', 'codigo', 'descripcion', 'atributos']);
    }

    /**
     * Código cuya descripción es exactamente $descripcion (sin distinguir mayúsculas ni espacios extra).
     * Sirve solo para PROPONER un valor que el usuario confirma; si no aparece, devuelve null.
     */
    public static function codigoPorDescripcion(int $empresaId, Catalogo $catalogo, string $descripcion): ?int
    {
        $buscada = self::normalizar($descripcion);

        foreach (self::opciones($empresaId, $catalogo) as $opcion) {
            if (self::normalizar($opcion->descripcion) === $buscada && ctype_digit($opcion->codigo)) {
                return (int) $opcion->codigo;
            }
        }

        return null;
    }

    /**
     * Leyenda de la factura para la actividad: el SIN publica varias por actividad y se rotan por número
     * de factura para que el resultado sea determinista (el mismo número siempre da la misma leyenda).
     */
    public static function leyenda(int $empresaId, string $actividad, int $numero): ?string
    {
        $leyendas = DeEmpresa::consulta(SiatCatalogo::class, $empresaId)
            ->where('catalogo', Catalogo::LeyendasFactura->name)->where('codigo', $actividad)
            ->orderBy('id')->pluck('descripcion');

        return $leyendas->isEmpty() ? null : (string) $leyendas[$numero % $leyendas->count()];
    }

    private static function normalizar(string $texto): string
    {
        $texto = str_replace(['–', '—'], '-', $texto);

        return (string) preg_replace('/\s+/u', ' ', mb_strtoupper(trim($texto)));
    }
}
