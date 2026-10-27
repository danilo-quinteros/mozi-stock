<?php

namespace App\Domain\Caja;

use App\Models\MovimientoCaja;
use App\Models\User;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Único punto que escribe movimientos_caja: una fila por medio con importe y evento de
 * origen (pago_venta, compra, egreso, devolucion). La restricción única por origen y medio
 * impide registrar dos veces el mismo dinero. Debe ejecutarse dentro de la transacción del
 * llamador, junto con el registro que origina el movimiento.
 */
final class LibroCaja
{
    /**
     * @param  'ingreso'|'egreso'  $sentido
     * @param  array{efectivo: string, electronico: string}  $importes
     */
    public function registrar(
        User $usuario,
        int $sucursalId,
        string $sentido,
        array $importes,
        string $origenTipo,
        int $origenId,
        CarbonInterface $fecha,
    ): void {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Un movimiento de caja solo se registra dentro de una transacción.');
        }

        foreach (['efectivo' => $importes['efectivo'], 'electronico' => $importes['electronico']] as $medio => $importe) {
            if (Decimal::comparar($importe, '0', Decimal::ESCALA_DINERO) <= 0) {
                continue;
            }

            $movimiento = new MovimientoCaja;
            $movimiento->empresa_id = (int) $usuario->empresa_id;
            $movimiento->sucursal_id = $sucursalId;
            $movimiento->sentido = $sentido;
            $movimiento->metodo = $medio;
            $movimiento->monto = $importe;
            $movimiento->origen_tipo = $origenTipo;
            $movimiento->origen_id = $origenId;
            $movimiento->fecha_operacion = Carbon::instance($fecha);
            $movimiento->usuario_id = $usuario->id;
            $movimiento->save();
        }
    }
}
