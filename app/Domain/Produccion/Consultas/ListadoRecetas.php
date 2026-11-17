<?php

namespace App\Domain\Produccion\Consultas;

use App\Models\Producto;
use App\Models\Receta;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado de recetas (ESPECIFICACION_PRODUCCION.md § 3 y § 5): una fila por producto fabricado, con su versión vigente.
 * El scope de empresa filtra recetas; la columna generada producto_vigente (índice único con empresa_id) deja solo las
 * vigentes. Tres consultas por página: recetas, productos con su unidad y el conteo de líneas.
 */
final class ListadoRecetas
{
    public const ESTADOS = ['activas', 'inactivas', 'todas'];

    /**
     * @return Builder<Receta>
     */
    public static function consulta(string $buscar = '', string $estado = 'activas'): Builder
    {
        $termino = trim($buscar);

        return Receta::query()
            ->select('recetas.*')
            ->join('productos', 'productos.id', '=', 'recetas.producto_id')
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'productos.unidad_medida_id')
            ->whereNotNull('recetas.producto_vigente')
            ->when($termino !== '', fn (Builder $q) => Producto::filtrarPorTexto($q, $termino))
            ->when($estado === 'activas', fn (Builder $q) => $q->where('recetas.activa', true))
            ->when($estado === 'inactivas', fn (Builder $q) => $q->where('recetas.activa', false))
            ->with(['producto' => fn ($q) => $q->select(['id', 'empresa_id', 'nombre', 'contenido', 'sku', 'unidad_medida_id', 'activo', 'controla_stock'])
                ->with('unidad:id,abreviatura')])
            ->withCount('items')
            ->orderBy('productos.nombre');
    }

    /**
     * Avisos de una receta que impedirán producir o que conviene completar antes (P3 los vuelve a validar al emitir).
     *
     * @return list<string>
     */
    public static function avisos(Producto $producto): array
    {
        $avisos = array_keys(array_filter([
            'Producto inactivo' => ! $producto->activo,
            'No controla stock' => ! $producto->controla_stock,
        ]));

        return array_map(fn (string $aviso): string => __($aviso), $avisos);
    }
}
