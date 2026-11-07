<?php

namespace App\Domain\Creditos\Consultas;

use App\Models\CuentaCobrar;
use App\Models\Venta;
use App\Models\VentaCuota;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Collection;

/**
 * Saldos que un cobro puede cubrir, en orden de aplicación automática (§ 24.1): vencimiento
 * más antiguo primero; sin vencimiento cuenta la fecha de la venta o de la cuenta. Una venta
 * con plan de cuotas aporta sus cuotas pendientes; sin plan, aporta su saldo completo.
 *
 * Las cuentas manuales anuladas no se cobran. Con $bloquear las filas se leen con FOR UPDATE, siempre en el mismo orden (ventas, cuotas,
 * cuentas): debe usarse dentro de la transacción que aplica el cobro.
 */
final class PendientesCobro
{
    /**
     * @param  'venta'|'cuenta'|'cliente'  $alcance
     * @return array{
     *     items: list<array{clave: string, tipo: string, id: int, venta_id: int|null, documento: string, detalle: string, fecha: string, vencimiento: string|null, monto: string, saldo: string}>,
     *     ventas: Collection<int, Venta>,
     *     cuotas: Collection<int, VentaCuota>,
     *     cuentas: Collection<int, CuentaCobrar>,
     * }
     */
    public static function cargar(string $alcance, int $id, bool $bloquear = false): array
    {
        $ventas = match ($alcance) {
            'venta' => Venta::query()->whereKey($id),
            'cliente' => Venta::query()->where('cliente_id', $id)->where('saldo_pendiente', '>', 0),
            default => null,
        };
        $ventas = $ventas === null ? new Collection : $ventas->orderBy('id')->when($bloquear, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');

        $cuotas = $ventas->isEmpty() ? new Collection : VentaCuota::query()
            ->whereIn('venta_id', $ventas->keys())->orderBy('id')->when($bloquear, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');

        $cuentas = match ($alcance) {
            'cuenta' => CuentaCobrar::query()->whereKey($id)->whereNull('anulado_at'),
            'cliente' => CuentaCobrar::query()->where('cliente_id', $id)->where('saldo_pendiente', '>', 0)->whereNull('anulado_at'),
            default => null,
        };
        $cuentas = $cuentas === null ? new Collection : $cuentas->orderBy('id')->when($bloquear, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');

        $items = [];
        $planes = $cuotas->groupBy('venta_id');

        foreach ($ventas as $venta) {
            $fecha = $venta->fecha->timezone(config('mozistock.business_timezone'))->format('Y-m-d');
            $plan = $planes->get($venta->id);

            if ($plan !== null) {
                $total = $plan->count();

                foreach ($plan as $cuota) {
                    if (Decimal::comparar($cuota->saldo, '0', 2) > 0) {
                        $items[] = [
                            'clave' => 'cuota:'.$cuota->id, 'tipo' => 'cuota', 'id' => $cuota->id, 'venta_id' => $venta->id,
                            'documento' => $venta->codigo(), 'detalle' => __('Cuota :numero de :total', ['numero' => $cuota->numero, 'total' => $total]),
                            'fecha' => $fecha, 'vencimiento' => $cuota->vencimiento->format('Y-m-d'), 'monto' => $cuota->monto, 'saldo' => $cuota->saldo,
                        ];
                    }
                }

                continue;
            }

            if (Decimal::comparar($venta->saldo_pendiente, '0', 2) > 0) {
                $items[] = [
                    'clave' => 'venta:'.$venta->id, 'tipo' => 'venta', 'id' => $venta->id, 'venta_id' => $venta->id,
                    'documento' => $venta->codigo(), 'detalle' => __('Saldo de la venta'),
                    'fecha' => $fecha, 'vencimiento' => $venta->vencimiento?->format('Y-m-d'), 'monto' => $venta->total, 'saldo' => $venta->saldo_pendiente,
                ];
            }
        }

        foreach ($cuentas as $cuenta) {
            if (Decimal::comparar($cuenta->saldo_pendiente, '0', 2) > 0) {
                $items[] = [
                    'clave' => 'cuenta:'.$cuenta->id, 'tipo' => 'cuenta', 'id' => $cuenta->id, 'venta_id' => null,
                    'documento' => $cuenta->codigo(), 'detalle' => $cuenta->concepto,
                    'fecha' => $cuenta->fecha->format('Y-m-d'), 'vencimiento' => $cuenta->vencimiento?->format('Y-m-d'), 'monto' => $cuenta->importe, 'saldo' => $cuenta->saldo_pendiente,
                ];
            }
        }

        // Orden estable: fecha efectiva (vencimiento o, sin él, fecha del documento), fecha y clave.
        usort($items, fn (array $a, array $b) => [$a['vencimiento'] ?? $a['fecha'], $a['fecha'], $a['tipo'] === 'cuenta', $a['id']]
            <=> [$b['vencimiento'] ?? $b['fecha'], $b['fecha'], $b['tipo'] === 'cuenta', $b['id']]);

        return ['items' => $items, 'ventas' => $ventas, 'cuotas' => $cuotas, 'cuentas' => $cuentas];
    }
}
