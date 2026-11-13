<?php

namespace App\Domain\Inventario;

use App\Domain\Tenancy\Numerador;
use App\Enums\TipoOperacionInventario;
use App\Models\OperacionInventario;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use stdClass;

/**
 * Empieza o termina el control por lote de productos que ya tienen stock, sin cambiar nunca el saldo total
 * (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 5.6 y § 9; ESPECIFICACION_RUBROS_Y_CAPACIDADES.md § 5.1 y § 5.2):
 *
 * - iniciar(): al marcar un producto, o al reactivar la capacidad con productos marcados, lo que el total tiene de
 *   más sobre la suma por lote pasa al lote de regularización de cada sucursal. Así se restablece el invariante
 *   stock_niveles = Σ stock_lotes.
 * - vaciar(): al desmarcar un producto, o al apagar la capacidad, deja en cero el saldo de cada lote.
 *
 * Cada cambio queda en el libro por lote bajo una operación de tipo Ajuste por sucursal (motivo de sistema, sin
 * movimientos de stock_niveles), así la trazabilidad del lote sigue cuadrando. No hay unidades que se muevan.
 *
 * Corre dentro de la transacción del llamador. Bloqueo, en el mismo orden que LibroStock: fichas de los productos
 * (exclusivo: ninguna venta ni ajuste de esos productos corre mientras tanto) → stock_niveles → stock_lotes.
 */
final class ControlLotes
{
    public function __construct(private readonly Numerador $numerador, private readonly LibroLotes $lotes) {}

    /**
     * El actor puede ser el superadmin (Funciones del negocio desde su panel): la empresa se indica aparte.
     *
     * @param  list<int>  $productoIds  null en vaciar(): todos los de la empresa
     * @return string unidades que pasaron al lote de regularización
     */
    public function iniciar(User $actor, int $empresaId, array $productoIds, string $nota): string
    {
        $ids = $this->bloquearFichas($empresaId, $productoIds);

        if ($ids === []) {
            return '0';
        }

        $niveles = DB::table('stock_niveles')->where('empresa_id', $empresaId)->whereIn('producto_id', $ids)
            ->orderBy('sucursal_id')->orderBy('producto_id')->lockForUpdate()
            ->get(['sucursal_id', 'producto_id', 'cantidad']);
        $sumas = [];

        foreach ($this->bloquearSaldos($empresaId, $ids) as $fila) {
            $par = $fila->sucursal_id.':'.$fila->producto_id;
            $sumas[$par] = Decimal::sumar($sumas[$par] ?? '0', (string) $fila->cantidad);
        }

        $movimientos = [];
        $total = '0';

        foreach ($niveles as $nivel) {
            $diferencia = Decimal::restar((string) $nivel->cantidad, $sumas[$nivel->sucursal_id.':'.$nivel->producto_id] ?? '0');
            $comparacion = Decimal::comparar($diferencia, '0');

            if ($comparacion < 0) {
                throw new LogicException("Stock por lote mayor que el total: empresa {$empresaId}, sucursal {$nivel->sucursal_id}, producto {$nivel->producto_id}.");
            }

            if ($comparacion === 0) {
                continue;
            }

            $loteId = $this->lotes->loteRegularizacion($empresaId, (int) $nivel->producto_id);
            $fila = $this->saldo($empresaId, (int) $nivel->sucursal_id, (int) $nivel->producto_id, $loteId);
            $movimientos[] = [
                'stock_lote_id' => (int) $fila->id, 'sucursal_id' => (int) $nivel->sucursal_id, 'producto_id' => (int) $nivel->producto_id,
                'lote_id' => $loteId, 'cantidad' => $diferencia, 'saldo_anterior' => (string) $fila->cantidad,
                'saldo_posterior' => Decimal::sumar((string) $fila->cantidad, $diferencia),
            ];
            $total = Decimal::sumar($total, $diferencia);
        }

        $this->registrar($actor, $empresaId, 'inicio_control_lotes', $nota, $movimientos);

        return $total;
    }

    /**
     * @param  list<int>|null  $productoIds  null: todos los productos de la empresa (apagado de la capacidad)
     * @return int lotes con saldo que quedaron en cero
     */
    public function vaciar(User $actor, int $empresaId, ?array $productoIds, string $nota): int
    {
        $ids = $productoIds === null ? null : $this->bloquearFichas($empresaId, $productoIds);

        if ($ids === []) {
            return 0;
        }

        if ($ids === null) {
            // Apagado de la capacidad: las fichas marcadas, en orden, antes que sus saldos.
            DB::table('productos')->where('empresa_id', $empresaId)->where('controla_vencimiento', true)->orderBy('id')->lockForUpdate()->pluck('id');
        }

        $movimientos = [];

        foreach ($this->bloquearSaldos($empresaId, $ids) as $fila) {
            if (Decimal::comparar((string) $fila->cantidad, '0') <= 0) {
                continue;
            }

            $movimientos[] = [
                'stock_lote_id' => (int) $fila->id, 'sucursal_id' => (int) $fila->sucursal_id, 'producto_id' => (int) $fila->producto_id,
                'lote_id' => (int) $fila->lote_id, 'cantidad' => Decimal::restar('0', (string) $fila->cantidad),
                'saldo_anterior' => (string) $fila->cantidad, 'saldo_posterior' => '0.000',
            ];
        }

        $this->registrar($actor, $empresaId, 'fin_control_lotes', $nota, $movimientos);

        return count($movimientos);
    }

    /**
     * @param  list<int>  $productoIds
     * @return list<int>
     */
    private function bloquearFichas(int $empresaId, array $productoIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productoIds)));
        sort($ids);

        return $ids === [] ? [] : array_values(DB::table('productos')->where('empresa_id', $empresaId)->whereIn('id', $ids)
            ->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * @param  list<int>|null  $productoIds
     * @return list<stdClass>
     */
    private function bloquearSaldos(int $empresaId, ?array $productoIds): array
    {
        return array_values(DB::table('stock_lotes')->where('empresa_id', $empresaId)
            ->when($productoIds !== null, fn ($q) => $q->whereIn('producto_id', $productoIds))
            ->orderBy('sucursal_id')->orderBy('producto_id')->orderBy('lote_id')->lockForUpdate()
            ->get(['id', 'sucursal_id', 'producto_id', 'lote_id', 'cantidad'])->all());
    }

    /**
     * Saldo del lote en la sucursal, que se crea en cero si no existe. Las fichas del producto están bloqueadas en
     * exclusiva, así que ninguna otra operación inserta saldos de ese producto a la vez.
     */
    private function saldo(int $empresaId, int $sucursalId, int $productoId, int $loteId): stdClass
    {
        $ahora = Carbon::now();
        DB::table('stock_lotes')->insertOrIgnore([
            'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId, 'producto_id' => $productoId, 'lote_id' => $loteId,
            'cantidad' => '0.000', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        return DB::table('stock_lotes')->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)->where('lote_id', $loteId)
            ->lockForUpdate()->first(['id', 'cantidad'])
            ?? throw new LogicException("No se pudo preparar el saldo del lote {$loteId}.");
    }

    /**
     * Una operación de ajuste por sucursal con sus movimientos por lote. Sin movimientos no se registra nada.
     *
     * @param  list<array{stock_lote_id: int, sucursal_id: int, producto_id: int, lote_id: int, cantidad: string, saldo_anterior: string, saldo_posterior: string}>  $movimientos
     */
    private function registrar(User $actor, int $empresaId, string $motivo, string $nota, array $movimientos): void
    {
        $porSucursal = [];

        foreach ($movimientos as $movimiento) {
            $porSucursal[$movimiento['sucursal_id']][] = $movimiento;
        }

        ksort($porSucursal);
        $tipo = TipoOperacionInventario::Ajuste;

        foreach ($porSucursal as $sucursalId => $lista) {
            $operacion = new OperacionInventario;
            $operacion->empresa_id = $empresaId;
            $operacion->serie = TipoOperacionInventario::SERIE_LOTES;
            $operacion->numero = $this->numerador->siguiente($empresaId, TipoOperacionInventario::SERIE_LOTES);
            $operacion->tipo = $tipo;
            $operacion->motivo = $motivo;
            $operacion->sucursal_id = $sucursalId;
            $operacion->sucursal_destino_id = null;
            $operacion->nota = mb_substr($nota, 0, 250);
            $operacion->usuario_id = $actor->id;
            $operacion->total_lineas = count(array_unique(array_column($lista, 'producto_id')));
            $operacion->clave_idempotencia = (string) Str::uuid();
            $operacion->save();

            $this->lotes->escribir($empresaId, $operacion->id, $lista);
        }
    }
}
