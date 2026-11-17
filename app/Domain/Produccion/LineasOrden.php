<?php

namespace App\Domain\Produccion;

use App\Models\Producto;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lecturas y escrituras en bloque de las materias primas de una orden o de una receta (§ 11: una consulta por tabla,
 * nunca una por línea). Cada consulta filtra por empresa en todas las tablas que une.
 */
final class LineasOrden
{
    /**
     * Líneas de una orden con los datos de su materia prima, en el orden de la receta.
     *
     * @return list<array{id: int, producto_id: int, nombre: string, sku: string|null, unidad: string, activo: bool, entera: bool, cantidad_receta: string, cantidad_teorica: string, cantidad_real: string|null, costo_unitario: string|null}>
     */
    public static function deOrden(int $empresaId, int $ordenId): array
    {
        return array_values(DB::table('orden_produccion_items as i')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->join('unidades_medida as um', 'um.id', '=', 'p.unidad_medida_id')
            ->where('i.empresa_id', $empresaId)
            ->where('p.empresa_id', $empresaId)
            ->where('i.orden_id', $ordenId)
            ->orderBy('i.orden')
            ->get([
                'i.id', 'i.producto_id', 'p.nombre', 'p.contenido', 'p.sku', 'p.activo', 'p.tipo_venta', 'um.admite_decimales', 'um.abreviatura',
                'i.cantidad_receta', 'i.cantidad_teorica', 'i.cantidad_real', 'i.costo_unitario',
            ])
            ->map(fn (stdClass $fila): array => self::materia($fila) + [
                'id' => (int) $fila->id,
                'cantidad_receta' => (string) $fila->cantidad_receta,
                'cantidad_teorica' => (string) $fila->cantidad_teorica,
                'cantidad_real' => $fila->cantidad_real === null ? null : (string) $fila->cantidad_real,
                'costo_unitario' => $fila->costo_unitario === null ? null : (string) $fila->costo_unitario,
            ])
            ->all());
    }

    /**
     * Líneas de una versión de receta con los datos de su materia prima (para emitir y para la vista previa).
     *
     * @return list<array{producto_id: int, nombre: string, sku: string|null, unidad: string, activo: bool, entera: bool, cantidad_receta: string}>
     */
    public static function deReceta(int $empresaId, int $recetaId): array
    {
        return array_values(DB::table('receta_items as i')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->join('unidades_medida as um', 'um.id', '=', 'p.unidad_medida_id')
            ->where('i.empresa_id', $empresaId)
            ->where('p.empresa_id', $empresaId)
            ->where('i.receta_id', $recetaId)
            ->orderBy('i.orden')
            ->get(['i.producto_id', 'p.nombre', 'p.contenido', 'p.sku', 'p.activo', 'p.tipo_venta', 'um.admite_decimales', 'um.abreviatura', 'i.cantidad as cantidad_receta'])
            ->map(fn (stdClass $fila): array => self::materia($fila) + ['cantidad_receta' => (string) $fila->cantidad_receta])
            ->all());
    }

    /**
     * Cantidades teóricas de cada línea para una cantidad a producir (M5: enteras hacia arriba).
     *
     * @param  list<array{producto_id: int, entera: bool, cantidad_receta: string}>  $lineas
     * @return array<int, string> producto_id => cantidad teórica
     */
    public static function escalar(array $lineas, string $planificada, string $base): array
    {
        $teoricas = [];

        foreach ($lineas as $linea) {
            $teoricas[$linea['producto_id']] = Escalado::teorica($planificada, $base, $linea['cantidad_receta'], $linea['entera']);
        }

        return $teoricas;
    }

    /**
     * Una sentencia UPDATE … CASE para una columna de varias líneas de la orden.
     *
     * @param  'cantidad_teorica'|'cantidad_real'|'costo_unitario'  $columna
     * @param  array<int, string>  $valores  id de la línea => valor
     */
    public static function actualizar(int $empresaId, string $columna, array $valores): void
    {
        if ($valores === []) {
            return;
        }

        $casos = implode(' ', array_fill(0, count($valores), 'WHEN ? THEN ?'));
        $parametros = [];

        foreach ($valores as $id => $valor) {
            array_push($parametros, $id, $valor);
        }

        $marcadores = implode(',', array_fill(0, count($valores), '?'));
        DB::update(
            "UPDATE orden_produccion_items SET {$columna} = CASE id {$casos} END WHERE empresa_id = ? AND id IN ({$marcadores})",
            [...$parametros, $empresaId, ...array_keys($valores)],
        );
    }

    /**
     * @return array{producto_id: int, nombre: string, sku: string|null, unidad: string, activo: bool, entera: bool}
     */
    private static function materia(stdClass $fila): array
    {
        return [
            'producto_id' => (int) $fila->producto_id,
            'nombre' => Producto::formatoNombreCompleto((string) $fila->nombre, $fila->contenido, (string) $fila->abreviatura),
            'sku' => $fila->sku === null ? null : (string) $fila->sku,
            'unidad' => (string) $fila->abreviatura,
            'activo' => (bool) $fila->activo,
            'entera' => Escalado::esEntera($fila->tipo_venta === null ? null : (string) $fila->tipo_venta, (bool) $fila->admite_decimales),
        ];
    }
}
