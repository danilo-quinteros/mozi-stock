<?php

namespace App\Domain\CuentasPagar;

use App\Domain\Auditoria\Auditor;
use App\Domain\Tenancy\Numerador;
use App\Models\Compra;
use App\Models\CuentaPagar;
use App\Models\Egreso;
use App\Models\User;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Cuenta por pagar que nace de una compra o un egreso confirmados con saldo (§ 12 y § 13.4):
 * por el total menos lo pagado al confirmar, vinculada a su documento. Se ejecuta dentro de
 * la transacción del documento; un documento pagado por completo no genera cuenta.
 */
final class AbrirCuentaPagar
{
    public function __construct(
        private readonly Numerador $numerador,
        private readonly Auditor $auditor,
    ) {}

    public function desdeCompra(User $actor, Compra $compra, string $concepto): ?CuentaPagar
    {
        return $this->abrir($actor, 'compra', $compra, (int) $compra->proveedor_id, $concepto, $compra->total, $compra->pagado, $compra->vencimiento);
    }

    public function desdeEgreso(User $actor, Egreso $egreso): ?CuentaPagar
    {
        return $this->abrir($actor, 'egreso', $egreso, (int) $egreso->proveedor_id, $egreso->descripcion, $egreso->monto, $egreso->pagado, $egreso->vencimiento);
    }

    private function abrir(User $actor, string $origen, Compra|Egreso $documento, int $proveedorId, string $concepto, string $total, string $pagado, ?CarbonInterface $vencimiento): ?CuentaPagar
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Una cuenta por pagar solo se abre dentro de la transacción de su documento.');
        }

        $saldo = Decimal::restar($total, $pagado, Decimal::ESCALA_DINERO);

        if (Decimal::comparar($saldo, '0', 2) <= 0) {
            return null;
        }

        $cuenta = new CuentaPagar;
        $cuenta->empresa_id = $documento->empresa_id;
        $cuenta->numero = $this->numerador->siguiente($cuenta->empresa_id, 'CXP');
        $cuenta->origen = $origen;
        $cuenta->compra_id = $documento instanceof Compra ? $documento->id : null;
        $cuenta->egreso_id = $documento instanceof Egreso ? $documento->id : null;
        $cuenta->sucursal_id = $documento->sucursal_id;
        $cuenta->proveedor_id = $proveedorId;
        $cuenta->usuario_id = $actor->id;
        $cuenta->concepto = mb_substr($concepto, 0, 150);
        $cuenta->fecha = $documento->fecha->copy()->timezone(config('mozistock.business_timezone'))->startOfDay();
        $cuenta->vencimiento = Carbon::instance($vencimiento ?? throw new LogicException('Una deuda exige fecha de vencimiento.'));
        $cuenta->total_documento = $total;
        $cuenta->pagado_origen = $pagado;
        $cuenta->saldo_pendiente = $saldo;
        $cuenta->save();

        $this->auditor->registrar('cuenta_pagar.generada', $cuenta, $actor, $cuenta->empresa_id, despues: [
            'cuenta' => $cuenta->codigo(), 'origen' => $origen, 'documento' => $documento->codigo(), 'proveedor_id' => $proveedorId,
            'total' => $total, 'pagado' => $pagado, 'saldo' => $saldo, 'vencimiento' => $cuenta->vencimiento->format('Y-m-d'),
        ]);

        return $cuenta;
    }
}
