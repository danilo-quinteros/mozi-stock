<?php

namespace App\Domain\Produccion\Consultas;

use App\Domain\Inventario\Consultas\StockLotes;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Faltantes de materia prima en una sucursal (ESPECIFICACION_PRODUCCION.md § 6.2, PR7): solo informa al emitir y antes
 * de aprobar. Disponible = saldo de la sucursal menos lo vencido (M3: no se produce con lotes vencidos). Aprobar lo
 * vuelve a validar en LibroStock con los saldos bloqueados. Dos consultas para todas las líneas.
 */
final class DisponibilidadMaterias
{
    public function __construct(private readonly StockLotes $lotes) {}

    /**
     * @param  array<int, string>  $necesarias  producto_id => cantidad
     * @return array<int, array{disponible: string, falta: string}> solo las que no alcanzan
     */
    public function faltantes(int $empresaId, int $sucursalId, array $necesarias): array
    {
        if ($necesarias === []) {
            return [];
        }

        $ids = array_keys($necesarias);
        $saldos = DB::table('stock_niveles')
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->whereIn('producto_id', $ids)
            ->pluck('cantidad', 'producto_id');
        $vencidas = $this->lotes->vencidas($empresaId, $sucursalId, $ids);
        $faltantes = [];

        foreach ($necesarias as $productoId => $cantidad) {
            $disponible = Decimal::restar((string) ($saldos[$productoId] ?? '0'), $vencidas[$productoId] ?? '0');

            if (Decimal::comparar($disponible, '0') < 0) {
                $disponible = '0.000';
            }

            if (Decimal::comparar($disponible, $cantidad) < 0) {
                $faltantes[$productoId] = ['disponible' => $disponible, 'falta' => Decimal::restar($cantidad, $disponible)];
            }
        }

        return $faltantes;
    }
}
