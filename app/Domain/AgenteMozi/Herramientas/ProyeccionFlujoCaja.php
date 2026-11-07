<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Datos\SaldosPorCobrar;
use App\Domain\Caja\Consultas\ReporteCaja;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * forecast_cash_flow (UC-13): ¿alcanza el dinero para lo que vence? Dos acumulados diarios:
 * comprometido (solo vencimientos por cobrar y por pagar) y estimado (además, el promedio diario
 * de los últimos 28 días de lo cobrado al vender y de lo pagado en compras, gastos y reembolsos).
 * Lo vencido por pagar se ubica hoy; lo vencido por cobrar y lo que no tiene fecha quedan fuera y
 * se informan. MoziStock no conoce el saldo del banco: opening_balance lo indica el usuario, se usa
 * solo en este cálculo y no se guarda. Sin movimientos recientes no se inventa una tendencia.
 */
final class ProyeccionFlujoCaja extends HerramientaNegocio
{
    private const DIAS_TENDENCIA = 28;

    public function nombre(): string
    {
        return 'forecast_cash_flow';
    }

    public function descripcion(): string
    {
        return 'Proyección de flujo de caja (estimación): por día, entradas y salidas comprometidas (vencimientos por cobrar y por pagar) y estimadas (promedio diario de los últimos 28 días), '
            .'con saldo acumulado comprometido y estimado desde opening_balance (o desde 0 si no se indica: entonces muestra la brecha, no un saldo real). '
            .'Resumen: totales, saldo final, saldo mínimo estimado y su fecha, cobros vencidos y sin fecha que quedaron fuera, y efectivo esperado en cajas abiertas como referencia. '
            .'Un cobro esperado nunca es dinero cobrado.';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'horizon_days' => ['type' => 'integer', 'minimum' => 7, 'maximum' => 90, 'description' => 'Días hacia adelante. Por defecto 30.'],
                'opening_balance' => ['type' => 'string', 'description' => 'Dinero disponible hoy según el usuario, en Bs con punto decimal (por ejemplo 12500.50). Solo si el usuario lo dijo; no se guarda.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return [
            'horizon_days' => ['sometimes', 'integer', 'between:7,90'],
            'opening_balance' => ['sometimes', 'nullable'],
        ];
    }

    public function avance(): string
    {
        return __('Proyectando el flujo de caja…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $horizonte = (int) ($argumentos['horizon_days'] ?? 30);
        $inicial = $this->saldoInicial($argumentos['opening_balance'] ?? null);
        $hoy = $contexto->fechaReferencia->format('Y-m-d');
        $fin = $contexto->fechaReferencia->addDays($horizonte)->format('Y-m-d');

        $porCobrar = SaldosPorCobrar::items($empresaId)
            ->selectRaw('SUM(CASE WHEN i.vencimiento < ? THEN i.saldo ELSE 0 END) AS vencido', [$hoy])
            ->selectRaw('SUM(CASE WHEN i.vencimiento IS NULL THEN i.saldo ELSE 0 END) AS sin_fecha')
            ->first();
        $entradas = SaldosPorCobrar::items($empresaId)
            ->whereBetween('i.vencimiento', [$hoy, $fin])
            ->groupBy('i.vencimiento')
            ->get([DB::raw('i.vencimiento AS dia'), DB::raw('SUM(i.saldo) AS monto')])
            ->keyBy(fn (object $f) => substr((string) $f->dia, 0, 10));
        $salidas = DB::table('cuentas_pagar')
            ->where('empresa_id', $empresaId)->whereNull('anulado_at')->where('saldo_pendiente', '>', 0)->where('vencimiento', '<=', $fin)
            ->selectRaw('GREATEST(vencimiento, ?) AS dia', [$hoy])->selectRaw('SUM(saldo_pendiente) AS monto')
            ->groupBy('dia')
            ->get()
            ->keyBy(fn (object $f) => substr((string) $f->dia, 0, 10));
        [$entradaDiaria, $salidaDiaria] = $this->tendencia($contexto);

        $serie = [];
        $comprometido = $inicial ?? '0.00';
        $estimado = $comprometido;
        $minimo = null;
        $totales = ['entradas' => '0.00', 'salidas' => '0.00'];

        for ($d = 0; $d <= $horizonte; $d++) {
            $fecha = $contexto->fechaReferencia->addDays($d)->format('Y-m-d');
            $entra = Decimal::redondear((string) ($entradas->get($fecha)->monto ?? '0'));
            $sale = Decimal::redondear((string) ($salidas->get($fecha)->monto ?? '0'));
            $totales['entradas'] = Decimal::sumar($totales['entradas'], $entra, 2);
            $totales['salidas'] = Decimal::sumar($totales['salidas'], $sale, 2);
            $comprometido = Decimal::sumar($comprometido, Decimal::restar($entra, $sale, 2), 2);
            $estimado = Decimal::sumar($estimado, Decimal::restar(Decimal::sumar($entra, $entradaDiaria ?? '0', 2), Decimal::sumar($sale, $salidaDiaria ?? '0', 2), 2), 2);
            $minimo = $minimo === null || Decimal::comparar($estimado, $minimo['saldo'], 2) < 0 ? ['saldo' => $estimado, 'fecha' => $fecha] : $minimo;
            $serie[] = [
                'fecha' => $fecha, 'entradas_comprometidas' => $entra, 'salidas_comprometidas' => $sale,
                'entradas_estimadas' => $entradaDiaria, 'salidas_estimadas' => $salidaDiaria,
                'saldo_comprometido' => $comprometido, 'saldo_estimado' => $estimado,
            ];
        }

        $efectivo = array_reduce(ReporteCaja::cajasAhora($empresaId, null, null),
            fn (string $total, array $caja) => $caja['esperado'] === null ? $total : Decimal::sumar($total, $caja['esperado'], 2), '0.00');
        $metodo = 'Comprometido: vencimientos por cobrar (cuotas, ventas a crédito y cuentas manuales) y por pagar. Estimado: además, el promedio diario de los últimos '
            .self::DIAS_TENDENCIA.' días de lo cobrado al vender y lo pagado en compras, gastos y reembolsos.';
        $meta = $this->meta($contexto, 'flujo_de_caja', null, 'estimated', $metodo, [
            'period' => ['from' => $hoy, 'to' => $fin],
            'note' => $inicial === null ? 'Sin saldo inicial: los acumulados parten de 0 y muestran la brecha, no el dinero disponible.' : 'Saldo inicial indicado por el usuario; no se guarda.',
        ]);
        $dinero = fn (string $c, string $l) => $this->col($c, $l, 'money');

        return [
            $this->dataset('resumen', [
                $dinero('saldo_inicial', __('Saldo inicial')), $dinero('entradas_comprometidas', __('Por cobrar en el horizonte')),
                $dinero('salidas_comprometidas', __('Por pagar en el horizonte')), $dinero('brecha_comprometida', __('Brecha comprometida')),
                $dinero('saldo_final_comprometido', __('Saldo final comprometido')), $dinero('saldo_final_estimado', __('Saldo final estimado')),
                $dinero('saldo_minimo_estimado', __('Saldo mínimo estimado')), $this->col('fecha_saldo_minimo', __('Fecha del saldo mínimo'), 'date'),
                $dinero('entrada_diaria_estimada', __('Entrada diaria estimada')), $dinero('salida_diaria_estimada', __('Salida diaria estimada')),
                $dinero('cobros_vencidos_excluidos', __('Cobros vencidos (fuera)')), $dinero('cobros_sin_fecha_excluidos', __('Cobros sin fecha (fuera)')),
                $dinero('efectivo_en_cajas', __('Efectivo esperado en cajas abiertas')),
            ], [[
                'saldo_inicial' => $inicial, 'entradas_comprometidas' => $totales['entradas'], 'salidas_comprometidas' => $totales['salidas'],
                'brecha_comprometida' => Decimal::restar($totales['entradas'], $totales['salidas'], 2),
                'saldo_final_comprometido' => $comprometido, 'saldo_final_estimado' => $estimado,
                'saldo_minimo_estimado' => $minimo['saldo'] ?? null, 'fecha_saldo_minimo' => $minimo['fecha'] ?? null,
                'entrada_diaria_estimada' => $entradaDiaria, 'salida_diaria_estimada' => $salidaDiaria,
                'cobros_vencidos_excluidos' => $porCobrar->vencido ?? '0', 'cobros_sin_fecha_excluidos' => $porCobrar->sin_fecha ?? '0',
                'efectivo_en_cajas' => $efectivo,
            ]], $meta, 'Resumen (una fila). saldo_inicial vacío = el usuario no lo indicó. Estimaciones diarias vacías = sin movimientos en los últimos '
                .self::DIAS_TENDENCIA.' días (no hay tendencia). efectivo_en_cajas es una referencia, no se suma a los acumulados.'),
            $this->dataset('flujo', [
                $this->col('fecha', __('Fecha'), 'date'), $dinero('entradas_comprometidas', __('Por cobrar')), $dinero('salidas_comprometidas', __('Por pagar')),
                $dinero('entradas_estimadas', __('Entradas estimadas')), $dinero('salidas_estimadas', __('Salidas estimadas')),
                $dinero('saldo_comprometido', __('Acumulado comprometido')), $dinero('saldo_estimado', __('Acumulado estimado')),
            ], $serie, $meta, "Serie diaria de hoy a {$fin}. Lo vencido por pagar está en hoy."),
        ];
    }

    private function saldoInicial(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $texto = is_float($valor) ? (string) json_encode($valor) : (is_scalar($valor) ? (string) $valor : '');

        return Decimal::normalizar($texto, 2)
            ?? throw new ArgumentoInvalido('opening_balance debe ser un importe en Bs con punto decimal y hasta 2 decimales, sin separador de miles.');
    }

    /**
     * Promedio diario de los últimos días completos (sin hoy) de lo cobrado al vender y de lo pagado
     * en compras, gastos y reembolsos. null si no hubo movimientos: no se inventa una tendencia.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function tendencia(ContextoAgente $contexto): array
    {
        $fin = $contexto->fechaReferencia->startOfDay();
        $fila = DB::table('movimientos_caja as m')
            ->leftJoin('pagos_venta as pv', fn ($j) => $j->on('pv.id', '=', 'm.origen_id')->where('m.origen_tipo', '=', 'pago_venta'))
            ->where('m.empresa_id', $contexto->empresaId)
            ->where('m.fecha_operacion', '>=', $fin->subDays(self::DIAS_TENDENCIA)->utc())
            ->where('m.fecha_operacion', '<', $fin->utc())
            ->first([
                DB::raw("COALESCE(SUM(CASE WHEN m.sentido = 'ingreso' AND pv.tipo = 'inicial' THEN m.monto ELSE 0 END), 0) AS entradas"),
                DB::raw("COALESCE(SUM(CASE WHEN m.sentido = 'egreso' AND m.origen_tipo IN ('compra', 'egreso', 'devolucion') THEN m.monto ELSE 0 END), 0) AS salidas"),
                DB::raw('COUNT(*) AS movimientos'),
            ]);

        if ((int) ($fila->movimientos ?? 0) === 0) {
            return [null, null];
        }

        $diario = fn (string $total) => Decimal::redondear(Decimal::dividir($total, (string) self::DIAS_TENDENCIA, 4));

        return [$diario((string) $fila->entradas), $diario((string) $fila->salidas)];
    }
}
