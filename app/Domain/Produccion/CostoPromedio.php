<?php

namespace App\Domain\Produccion;

use App\Models\Producto;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Costo promedio ponderado (PPP) de las materias primas y de los productos fabricados (ESPECIFICACION_PRODUCCION.md
 * § 9, PR12):
 *
 *     nuevo = (S × anterior + costo del ingreso) / (S + cantidad)
 *
 * con S = stock total de la empresa (todas las sucursales) antes del ingreso. Si S ≤ 0, el nuevo costo es el del
 * ingreso. Ajustes, traspasos y salidas no lo cambian. Cuentas con Decimal (bcmath), nunca float; 6 decimales.
 *
 * Uso dentro de la transacción del ingreso:
 * 1. bloquear() ANTES de LibroStock::aplicar(): FOR UPDATE sobre las fichas, por id. Como toda operación de stock lee
 *    las fichas con bloqueo compartido antes de bloquear saldos, ninguna otra operación de estos productos avanza
 *    hasta el commit: el stock total que se lee después es exacto y no hay interbloqueos (orden ficha → saldos).
 * 2. ingresar() DESPUÉS de aplicar(): el saldo ya incluye lo ingresado, así que S = saldo − cantidad.
 */
final class CostoPromedio
{
    /**
     * Bloquea con FOR UPDATE, en una sola pasada por id, todas las fichas de $productoIds y devuelve el costo actual de
     * las que llevan costo promedio: las materias primas (compras) o, con $todas, todas (cierre de una orden de
     * producción, § 9). Bloquear todas en una pasada mantiene un único orden de bloqueo entre compras y órdenes que
     * comparten fichas (sin interbloqueos).
     *
     * @param  list<int>  $productoIds
     * @return array<int, string> producto_id => costo_promedio
     */
    public function bloquear(int $empresaId, array $productoIds, bool $todas = false): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('CostoPromedio::bloquear() requiere una transacción abierta.');
        }

        $ids = array_values(array_unique(array_map('intval', $productoIds)));

        if ($ids === []) {
            return [];
        }

        return DB::table('productos')
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'clase', 'costo_promedio'])
            ->filter(fn (object $fila): bool => $todas || $fila->clase === Producto::CLASE_MATERIA_PRIMA)
            ->mapWithKeys(fn (object $fila): array => [(int) $fila->id => (string) $fila->costo_promedio])
            ->all();
    }

    /**
     * Recalcula el costo de las fichas bloqueadas con lo que ingresó. Una consulta para los saldos y una sentencia
     * UPDATE … CASE para todos los productos.
     *
     * @param  array<int, string>  $bloqueados  resultado de bloquear()
     * @param  list<array{producto_id: int, cantidad: string, importe: string}>  $ingresos  importe = costo total de la línea
     * @return array<int, array{antes: string, despues: string}> cambios, para la auditoría
     */
    public function ingresar(int $empresaId, array $bloqueados, array $ingresos): array
    {
        $porProducto = [];

        foreach ($ingresos as $ingreso) {
            $id = (int) $ingreso['producto_id'];

            if (! isset($bloqueados[$id])) {
                continue;
            }

            $porProducto[$id] ??= ['cantidad' => '0', 'importe' => '0'];
            $porProducto[$id]['cantidad'] = Decimal::sumar($porProducto[$id]['cantidad'], $ingreso['cantidad']);
            $porProducto[$id]['importe'] = Decimal::sumar($porProducto[$id]['importe'], $ingreso['importe'], 6);
        }

        if ($porProducto === []) {
            return [];
        }

        $saldos = DB::table('stock_niveles')
            ->where('empresa_id', $empresaId)
            ->whereIn('producto_id', array_keys($porProducto))
            ->groupBy('producto_id')
            ->selectRaw('producto_id, SUM(cantidad) as total')
            ->pluck('total', 'producto_id');
        $cambios = [];

        foreach ($porProducto as $id => $ingreso) {
            if (Decimal::comparar($ingreso['cantidad'], '0') <= 0) {
                continue;
            }

            $antes = $bloqueados[$id];
            $previo = Decimal::restar((string) ($saldos[$id] ?? '0'), $ingreso['cantidad']);
            $cambios[$id] = ['antes' => $antes, 'despues' => self::promedio($previo, $antes, $ingreso['cantidad'], $ingreso['importe'])];
        }

        $this->escribir($empresaId, $cambios);

        return $cambios;
    }

    /**
     * (S × anterior + importe) / (S + cantidad); con S ≤ 0, importe / cantidad. Redondeo comercial a 6 decimales
     * (bcdiv solo trunca). Pública para las pruebas de céntimos.
     */
    public static function promedio(string $stockPrevio, string $costoAnterior, string $cantidad, string $importe): string
    {
        if (Decimal::comparar($stockPrevio, '0') <= 0) {
            return Decimal::redondear(Decimal::dividir($importe, $cantidad, 8), 6);
        }

        $valorPrevio = Decimal::multiplicar($stockPrevio, $costoAnterior, 9);

        return Decimal::redondear(Decimal::dividir(Decimal::sumar($valorPrevio, $importe, 9), Decimal::sumar($stockPrevio, $cantidad), 8), 6);
    }

    /**
     * @param  array<int, array{antes: string, despues: string}>  $cambios
     */
    private function escribir(int $empresaId, array $cambios): void
    {
        if ($cambios === []) {
            return;
        }

        $casos = implode(' ', array_fill(0, count($cambios), 'WHEN ? THEN ?'));
        $valores = [];

        foreach ($cambios as $id => $cambio) {
            array_push($valores, $id, $cambio['despues']);
        }

        $marcadores = implode(',', array_fill(0, count($cambios), '?'));
        DB::update(
            "UPDATE productos SET costo_promedio = CASE id {$casos} END, updated_at = ? WHERE empresa_id = ? AND id IN ({$marcadores})",
            [...$valores, Carbon::now(), $empresaId, ...array_keys($cambios)],
        );
    }
}
