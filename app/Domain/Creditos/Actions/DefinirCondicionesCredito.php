<?php

namespace App\Domain\Creditos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Creditos\PlanCuotas;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaCuota;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Vencimiento de una venta a crédito (Cuentas por cobrar, decisión 2): ninguno, una fecha única
 * para todo el saldo o un plan de cuotas con fecha e importe por cuota. Decisión del propietario
 * (30/10/2026): el vencimiento es opcional. El plan reparte el saldo pendiente actual (la suma de
 * las cuotas es exactamente ese saldo) y solo puede reemplazarse mientras ninguna cuota tenga
 * cobros o devoluciones aplicados. No mueve dinero ni stock; queda auditado.
 */
final class DefinirCondicionesCredito
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array{tipo: string, vencimiento?: string|null, cuotas?: array<array-key, array{vencimiento?: mixed, monto?: mixed}>}  $datos
     */
    public function ejecutar(User $actor, Venta $venta, array $datos): Venta
    {
        Gate::forUser($actor)->authorize('condicionesCredito', $venta);

        if (! in_array($datos['tipo'], ['ninguno', 'unico', 'cuotas'], true)) {
            throw ValidationException::withMessages(['tipo' => __('Elige sin vencimiento, fecha única o plan de cuotas.')]);
        }

        return DB::transaction(function () use ($actor, $venta, $datos): Venta {
            $bloqueada = Venta::query()->whereKey($venta->id)->lockForUpdate()->firstOrFail();
            $saldo = $bloqueada->saldo_pendiente;

            if (Decimal::comparar($saldo, '0', 2) <= 0) {
                throw ValidationException::withMessages(['tipo' => __('El crédito ya está pagado; no admite vencimientos.')]);
            }

            $actuales = VentaCuota::query()->where('venta_id', $bloqueada->id)->orderBy('numero')->lockForUpdate()->get();

            // Un cobro aplicado a una cuota (aunque luego se anulara) queda en su historial: el plan ya no se reemplaza.
            if ($actuales->contains(fn (VentaCuota $c) => Decimal::comparar($c->saldo, $c->monto, 2) !== 0)
                || DB::table('cobro_aplicaciones')->whereIn('venta_cuota_id', $actuales->modelKeys())->exists()) {
                throw ValidationException::withMessages(['tipo' => __('El plan ya tiene cobros o devoluciones aplicados; no puede reemplazarse.')]);
            }

            $desde = $bloqueada->fecha->timezone(config('mozistock.business_timezone'))->format('Y-m-d');
            $plan = match ($datos['tipo']) {
                'cuotas' => $this->plan($datos['cuotas'] ?? [], $saldo, $desde),
                default => [],
            };
            $vencimiento = $datos['tipo'] === 'unico' ? $this->fecha($datos['vencimiento'] ?? null, $desde, 'vencimiento') : null;
            $antes = [
                'vencimiento' => $bloqueada->vencimiento?->format('Y-m-d'),
                'cuotas' => $actuales->map(fn (VentaCuota $c) => ['vencimiento' => $c->vencimiento->format('Y-m-d'), 'monto' => $c->monto])->all(),
            ];

            VentaCuota::query()->where('venta_id', $bloqueada->id)->delete();

            foreach ($plan as $indice => $fila) {
                $cuota = new VentaCuota;
                $cuota->empresa_id = $bloqueada->empresa_id;
                $cuota->venta_id = $bloqueada->id;
                $cuota->numero = $indice + 1;
                $cuota->vencimiento = Carbon::parse($fila['vencimiento']);
                $cuota->monto = $fila['monto'];
                $cuota->saldo = $fila['monto'];
                $cuota->save();
            }

            $bloqueada->vencimiento = $vencimiento === null ? null : Carbon::parse($vencimiento);
            $bloqueada->save();

            $this->auditor->registrar('venta.condiciones_credito', $bloqueada, $actor, $bloqueada->empresa_id, $antes,
                ['tipo' => $datos['tipo'], 'vencimiento' => $vencimiento, 'cuotas' => $plan, 'saldo_pendiente' => $saldo]);

            return $bloqueada;
        });
    }

    /**
     * @param  array<array-key, array{vencimiento?: mixed, monto?: mixed}>  $filas
     * @return list<array{vencimiento: string, monto: string}>
     */
    private function plan(array $filas, string $saldo, string $desde): array
    {
        if (count($filas) < 1 || count($filas) > PlanCuotas::MAX_CUOTAS) {
            throw ValidationException::withMessages(['cuotas' => __('El plan debe tener entre 1 y :max cuotas.', ['max' => PlanCuotas::MAX_CUOTAS])]);
        }

        $plan = [];
        $suma = '0.00';

        foreach (array_values($filas) as $fila) {
            $monto = Decimal::normalizar(is_scalar($fila['monto'] ?? null) ? (string) $fila['monto'] : '', Decimal::ESCALA_DINERO);

            if ($monto === null || Decimal::comparar($monto, '0', 2) <= 0) {
                throw ValidationException::withMessages(['cuotas' => __('Cada cuota debe tener un importe mayor que Bs 0,00 (hasta 2 decimales).')]);
            }

            $plan[] = ['vencimiento' => $this->fecha($fila['vencimiento'] ?? null, $desde, 'cuotas'), 'monto' => $monto];
            $suma = Decimal::sumar($suma, $monto, Decimal::ESCALA_DINERO);
        }

        if (Decimal::comparar($suma, $saldo, 2) !== 0) {
            throw ValidationException::withMessages(['cuotas' => __('Las cuotas suman :suma y deben sumar el saldo pendiente (:saldo).', ['suma' => Decimal::bs($suma), 'saldo' => Decimal::bs($saldo)])]);
        }

        usort($plan, fn (array $a, array $b) => $a['vencimiento'] <=> $b['vencimiento']);

        return $plan;
    }

    private function fecha(mixed $valor, string $desde, string $campo): string
    {
        $texto = is_string($valor) ? trim($valor) : '';
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1 ? Carbon::createFromFormat('!Y-m-d', $texto) : null;

        if ($fecha === null || $fecha->format('Y-m-d') !== $texto) {
            throw ValidationException::withMessages([$campo => __('Indica una fecha de vencimiento válida.')]);
        }

        if ($texto < $desde) {
            throw ValidationException::withMessages([$campo => __('El vencimiento no puede ser anterior a la fecha de la venta.')]);
        }

        return $texto;
    }
}
