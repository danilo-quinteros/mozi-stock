<?php

namespace App\Domain\CatalogoExterno\Consultas;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Productos del catálogo de un proveedor externo (Cyclone) que la empresa todavía no vende: los buscadores de compras
 * y ajustes los ofrecen para agregarlos al catálogo propio sin salir de la pantalla.
 *
 * Solo de proveedores activos a los que la empresa está vinculada y activa; «todavía no vende» = sin vínculo local o
 * con el producto local inactivo («Dejar de vender»). Nunca expone precios ni costos: el catálogo central no los tiene.
 */
final class SugerenciasCatalogoExterno
{
    /** Hasta cuántas sugerencias se muestran debajo de las propias. */
    public const LIMITE = 5;

    /** Si la empresa revende algún proveedor activo: sin esto no hace falta buscar. */
    public static function empresaVinculada(int $empresaId): bool
    {
        return DB::table('empresa_proveedor_externo as epe')
            ->join('proveedores_externos as pe', fn (JoinClause $j) => $j->on('pe.id', '=', 'epe.proveedor_externo_id')->where('pe.activo', true))
            ->where('epe.empresa_id', $empresaId)
            ->where('epe.activo', true)
            ->exists();
    }

    /**
     * Una consulta. El término busca por nombre o código del proveedor (comodines escapados).
     *
     * @return Collection<int, object{id: int, nombre: string, codigo: string|null, linea: string|null, proveedor: string, inactivo: bool}>
     */
    public static function buscar(int $empresaId, string $termino, int $limite = self::LIMITE): Collection
    {
        $termino = trim($termino);

        if (mb_strlen($termino) < 2) {
            return collect();
        }

        $patron = '%'.addcslashes($termino, '\\%_').'%';

        /** @var Collection<int, object{id: int, nombre: string, codigo: string|null, linea: string|null, proveedor: string, inactivo: bool}> */
        return DB::table('catalogo_externo_productos as c')
            ->join('empresa_proveedor_externo as epe', fn (JoinClause $j) => $j->on('epe.proveedor_externo_id', '=', 'c.proveedor_externo_id')
                ->where('epe.empresa_id', $empresaId)->where('epe.activo', true))
            ->join('proveedores_externos as pe', fn (JoinClause $j) => $j->on('pe.id', '=', 'c.proveedor_externo_id')->where('pe.activo', true))
            // Vínculo y producto siempre de esta empresa: el de otra empresa no cuenta.
            ->leftJoin('producto_vinculos_externos as v', fn (JoinClause $j) => $j->on('v.proveedor_externo_id', '=', 'c.proveedor_externo_id')
                ->on('v.externo_id', '=', 'c.externo_id')->where('v.empresa_id', $empresaId))
            ->leftJoin('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'v.producto_id')->where('p.empresa_id', $empresaId))
            ->where('c.disponible', true)
            ->where(fn ($q) => $q->whereNull('p.id')->orWhere('p.activo', false))
            ->where(fn ($q) => $q->where('c.nombre_limpio', 'like', $patron)->orWhere('c.codigo', 'like', $patron))
            ->orderBy('c.nombre_limpio')
            ->limit($limite)
            ->get(['c.id', 'c.nombre_limpio as nombre', 'c.codigo', 'c.linea_nombre as linea', 'pe.nombre as proveedor', DB::raw('CASE WHEN p.id IS NULL THEN 0 ELSE 1 END as inactivo')])
            ->map(function (object $fila): object {
                $fila->id = (int) $fila->id;
                $fila->inactivo = (bool) $fila->inactivo;

                return $fila;
            });
    }
}
