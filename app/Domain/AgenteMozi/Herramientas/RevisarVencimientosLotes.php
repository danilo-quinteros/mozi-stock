<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\Acciones\CatalogoAcciones;
use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\Inventario\Consultas\ReporteVencimientos;
use App\Domain\Produccion\Escalado;
use App\Models\Producto;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * review_expiring_lots (UC-27, AGENTE_MOZI_IA.md § 2.1): lotes con stock vencidos o por vencer en 30, 60 o 90 días,
 * con la consulta de Inventario › Vencimientos (ReporteVencimientos), así las cifras coinciden. Es la excepción a las
 * reglas de Producción: pide administrador y la capacidad Lotes y vencimientos (Gate analizar-lotes), así sirve a una
 * farmacia sin Producción. Solo con Producción agrega las recetas que usan cada materia prima por vencer y cuánto se
 * podría producir con ese lote (solo con esa materia prima: las demás pueden limitar).
 */
final class RevisarVencimientosLotes extends HerramientaProduccion
{
    private const MAX_FILAS = 500;

    public function nombre(): string
    {
        return 'review_expiring_lots';
    }

    public function descripcion(): string
    {
        return 'Lotes con stock vencidos o por vencer (por defecto en los próximos 30 días; 60 o 90 con dias), por clase (venta o materia_prima), con las cifras de Inventario › Vencimientos: '
            .'producto, lote, vencimiento, días para vencer (negativo = vencido), sucursal, cantidad y valor a costo. Vencido = fecha anterior a hoy; el día del vencimiento todavía se puede vender y usar. '
            .'Con Producción activa, también en qué recetas se usa cada materia prima por vencer y cuánto se podría producir con ese lote.';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dias' => ['type' => 'integer', 'enum' => [30, 60, 90], 'description' => 'Ventana de «por vencer» en días. Por defecto 30.'],
                'clase' => ['type' => 'string', 'enum' => [Producto::CLASE_VENTA, Producto::CLASE_MATERIA_PRIMA], 'description' => 'Solo productos de venta o solo materias primas. Por defecto ambas.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return [
            'dias' => ['sometimes', 'nullable', 'integer', 'in:30,60,90'],
            'clase' => ['sometimes', 'nullable', 'string', 'in:'.Producto::CLASE_VENTA.','.Producto::CLASE_MATERIA_PRIMA],
        ];
    }

    public function permiso(): string
    {
        return 'analizar-lotes-empresa';
    }

    public function avance(): string
    {
        return __('Revisando los lotes por vencer…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $dias = (int) ($argumentos['dias'] ?? 30);
        $clase = is_string($argumentos['clase'] ?? null) ? $argumentos['clase'] : null;
        $hoy = $contexto->fechaReferencia->format('Y-m-d');
        $leer = fn (string $estado) => ReporteVencimientos::consulta($empresaId, ['estado' => $estado])
            ->when($clase !== null, fn (Builder $q) => $q->where('p.clase', $clase))
            ->addSelect(['p.clase', 'p.costo_promedio', 'um.nombre as unidad'])
            ->selectRaw('DATEDIFF(l.vencimiento, ?) AS dias', [$hoy])
            ->limit(self::MAX_FILAS)
            ->get();
        $filas = $leer('vencidos')->concat($leer((string) $dias));
        $lotes = [];
        $resumen = ['lotes_vencidos' => 0, 'valor_vencido' => '0', 'lotes_por_vencer' => 0, 'valor_por_vencer' => '0'];

        foreach ($filas as $fila) {
            $vencido = (int) $fila->dias < 0;
            $valor = Decimal::multiplicar((string) $fila->cantidad, (string) $fila->costo_promedio, 6);
            $resumen[$vencido ? 'lotes_vencidos' : 'lotes_por_vencer']++;
            $resumen[$vencido ? 'valor_vencido' : 'valor_por_vencer'] = Decimal::sumar($resumen[$vencido ? 'valor_vencido' : 'valor_por_vencer'], $valor, 6);
            $lotes[] = [
                'producto' => Producto::formatoNombreCompleto((string) $fila->producto, $fila->contenido, (string) $fila->abreviatura),
                'clase' => $fila->clase === Producto::CLASE_MATERIA_PRIMA ? 'materia prima' : 'venta',
                'lote' => $fila->numero ?? 'sin número', 'vencimiento' => $fila->vencimiento, 'dias_para_vencer' => (int) $fila->dias,
                'estado' => $vencido ? 'vencido' : "vence en {$dias} días", 'sucursal' => $fila->sucursal, 'cantidad' => (string) $fila->cantidad,
                'unidad' => $fila->unidad, 'valor_costo' => $valor,
                '_producto_id' => (int) $fila->producto_id, '_materia' => $fila->clase === Producto::CLASE_MATERIA_PRIMA, '_lote_id' => (int) $fila->lote_id,
            ];
        }

        $meta = $this->meta($contexto, 'inventario.vencimientos', null,
            metodo: "Lotes con stock vencidos y por vencer en {$dias} días (Inventario › Vencimientos)".($clase !== null ? ", clase {$clase}" : '').'.',
            extra: ['period' => ['from' => $hoy, 'to' => $contexto->fechaReferencia->addDays($dias)->format('Y-m-d')]]);
        $t = fn (string $c, string $l) => $this->col($c, $l, 'string');
        $n = fn (string $c, string $l) => $this->col($c, $l, 'number');
        $d = fn (string $c, string $l) => $this->col($c, $l, 'money');
        $i = fn (string $c, string $l) => $this->col($c, $l, 'integer');
        $datasets = [
            $this->dataset('resumen', [
                $i('lotes_vencidos', __('Lotes vencidos')), $d('valor_vencido', __('Valor vencido a costo')),
                $i('lotes_por_vencer', __('Lotes por vencer')), $d('valor_por_vencer', __('Valor por vencer a costo')),
            ], [$resumen], $meta, "Resumen (una fila): lotes con stock vencidos y por vencer en {$dias} días, por sucursal."),
            // Acción (§ UC-27): «Ver trazabilidad» del lote; el mismo lote en varias sucursales se ofrece una vez.
            $this->conAcciones($this->dataset('lotes', [
                $t('producto', __('Producto')), $t('clase', __('Clase')), $t('lote', __('Lote')), $this->col('vencimiento', __('Vencimiento'), 'date'),
                $i('dias_para_vencer', __('Días para vencer')), $t('estado', __('Estado')), $t('sucursal', __('Sucursal')), $n('cantidad', __('Cantidad')),
                $t('unidad', __('Unidad')), $d('valor_costo', __('Valor a costo')),
            ], $lotes, $meta, 'Lotes con stock por sucursal: primero los vencidos y luego los que vencen antes.'
                .($filas->count() >= self::MAX_FILAS ? ' Puede estar recortado.' : '')),
                array_map(fn (array $l) => CatalogoAcciones::crear('ver_lote', $l['_lote_id'], __('Ver trazabilidad del lote :lote · :producto', ['lote' => $l['lote'], 'producto' => $l['producto']])), $lotes)),
        ];

        // Las columnas de producción solo aparecen con la capacidad (§ 2.1, UC-27).
        if ($contexto->produccion()) {
            $datasets[] = $this->dataset('usos', [
                $t('materia_prima', __('Materia prima')), $t('lote', __('Lote')), $this->col('vencimiento', __('Vencimiento'), 'date'), $t('sucursal', __('Sucursal')),
                $n('cantidad', __('Cantidad del lote')), $t('producto', __('Se usa en')), $n('cantidad_receta', __('Cantidad en la receta')),
                $n('cantidad_base', __('Cantidad base')), $n('alcanza_para', __('Producible con ese lote')), $t('unidad_producto', __('Unidad del producto')),
            ], $this->usos($empresaId, array_values(array_filter($lotes, fn (array $l) => $l['_materia']))), $meta,
                'Para cada lote de materia prima, las recetas vigentes que la usan y cuánto producto se podría hacer con ese lote si las demás materias primas alcanzan (UC-19 da el máximo real).');
        }

        return $datasets;
    }

    /**
     * Recetas vigentes y activas que usan cada materia prima por vencer, y lo producible con la cantidad del lote.
     *
     * @param  list<array<string, mixed>>  $lotes
     * @return list<array<string, mixed>>
     */
    private function usos(int $empresaId, array $lotes): array
    {
        if ($lotes === []) {
            return [];
        }

        $recetas = DB::table('receta_items as ri')
            ->join('recetas as r', fn (JoinClause $j) => $j->on('r.id', '=', 'ri.receta_id')->on('r.empresa_id', '=', 'ri.empresa_id'))
            ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'r.producto_id')->on('p.empresa_id', '=', 'r.empresa_id'))
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->join('productos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'ri.producto_id')->on('m.empresa_id', '=', 'ri.empresa_id'))
            ->join('unidades_medida as mu', 'mu.id', '=', 'm.unidad_medida_id')
            ->where('ri.empresa_id', $empresaId)
            ->where('r.vigente', true)
            ->where('r.activa', true)
            ->whereIn('ri.producto_id', array_values(array_unique(array_column($lotes, '_producto_id'))))
            ->orderBy('p.nombre')
            ->get(['ri.producto_id', 'ri.cantidad', 'r.cantidad_base', 'p.tipo_venta', 'u.admite_decimales', 'u.nombre as unidad',
                'm.tipo_venta as m_tipo_venta', 'mu.admite_decimales as m_decimales', DB::raw(Producto::sqlNombreCompleto('p', 'u').' AS producto')])
            ->groupBy('producto_id');
        $filas = [];

        foreach ($lotes as $lote) {
            foreach ($recetas->get($lote['_producto_id'], collect()) as $receta) {
                $linea = ['producto_id' => (int) $receta->producto_id, 'cantidad' => (string) $receta->cantidad,
                    'entera' => Escalado::esEntera($receta->m_tipo_venta === null ? null : (string) $receta->m_tipo_venta, (bool) $receta->m_decimales)];
                $maximo = $this->maximo([$linea], (string) $receta->cantidad_base,
                    Escalado::esEntera($receta->tipo_venta === null ? null : (string) $receta->tipo_venta, (bool) $receta->admite_decimales),
                    [$linea['producto_id'] => $lote['cantidad']]);
                $filas[] = [
                    'materia_prima' => $lote['producto'], 'lote' => $lote['lote'], 'vencimiento' => $lote['vencimiento'], 'sucursal' => $lote['sucursal'],
                    'cantidad' => $lote['cantidad'], 'producto' => $receta->producto, 'cantidad_receta' => (string) $receta->cantidad,
                    'cantidad_base' => (string) $receta->cantidad_base, 'alcanza_para' => $maximo['maximo'], 'unidad_producto' => $receta->unidad,
                ];
            }
        }

        return $filas;
    }
}
