<?php

namespace App\Domain\Ventas;

use App\Domain\Caja\LibroCaja;
use App\Enums\MetodoPago;
use App\Models\PagoVenta;
use App\Models\User;
use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Registra un cobro real de una venta (cuota inicial o abono): un PagoVenta con el total
 * y su desglose, y una entrada en movimientos_caja por cada medio con importe. Debe
 * ejecutarse dentro de la transacción del llamador, con la venta bloqueada. La fecha es
 * la de recepción efectiva (ahora), nunca la de la venta.
 */
final class RegistradorCobros
{
    public function __construct(private readonly LibroCaja $caja) {}

    public function registrar(
        User $receptor,
        Venta $venta,
        int $sucursalId,
        string $tipo,
        MetodoPago $metodo,
        string $efectivo,
        string $electronico,
        string $clave,
        ?string $nota = null,
    ): PagoVenta {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Un cobro solo se registra dentro de una transacción.');
        }

        $monto = Decimal::sumar($efectivo, $electronico, Decimal::ESCALA_DINERO);
        $ahora = Carbon::now();

        $pago = new PagoVenta;
        $pago->empresa_id = $venta->empresa_id;
        $pago->venta_id = $venta->id;
        $pago->sucursal_id = $sucursalId;
        $pago->recibido_por = $receptor->id;
        $pago->tipo = $tipo;
        $pago->metodo = $metodo;
        $pago->monto = $monto;
        $pago->monto_efectivo = $efectivo;
        $pago->monto_electronico = $electronico;
        $pago->fecha_cobro = $ahora;
        $pago->clave_idempotencia = $clave;
        $pago->nota = $nota;
        $pago->save();

        $this->caja->registrar($receptor, $sucursalId, 'ingreso',
            ['efectivo' => $efectivo, 'electronico' => $electronico], 'pago_venta', $pago->id, $ahora);

        return $pago;
    }
}
