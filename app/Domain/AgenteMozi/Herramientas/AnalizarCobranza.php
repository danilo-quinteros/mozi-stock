<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Datos\SaldosPorCobrar;
use App\Domain\Creditos\Consultas\CuentasPorCobrar;
use Illuminate\Support\Facades\DB;

/**
 * analyze_receivables (UC-11): a quién cobrar primero. Resumen igual a Cuentas por cobrar,
 * antigüedad del saldo por tramos de atraso, ranking de clientes con prioridad y puntualidad, y
 * vencimientos próximos. Lo vencido se mide por cuota; un saldo sin vencimiento nunca figura vencido.
 * La puntualidad solo usa cobros aplicados a cuotas: los abonos antiguos (pagos_venta) no se ligan
 * a una cuota.
 */
final class AnalizarCobranza extends HerramientaNegocio
{
    private const TRAMOS = ['por vencer', '1-30 días', '31-60 días', '61-90 días', 'más de 90 días', 'sin vencimiento'];

    public function nombre(): string
    {
        return 'analyze_receivables';
    }

    public function descripcion(): string
    {
        return 'Cobranza priorizada (cuentas por cobrar): resumen (pendiente, vencido, por vencer, clientes con saldo, lo que vence en days_ahead días), antigüedad del saldo por tramos de atraso, '
            .'ranking de clientes (saldo, vencido, máximo atraso, próximo vencimiento, último cobro, cobros de cuotas a tiempo y atrasados, prioridad alta/media/baja) y vencimientos próximos. '
            .'Prioridad: alta con atraso de más de 30 días, media con algo vencido, baja sin vencido. El agente nunca registra cobros: sugiere abrir «Registrar cobro».';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days_ahead' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'description' => 'Días hacia adelante para los vencimientos próximos. Por defecto 7.'],
                'top_n' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 50, 'description' => 'Clientes del ranking. Por defecto 15.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return [
            'days_ahead' => ['sometimes', 'integer', 'between:1,60'],
            'top_n' => ['sometimes', 'integer', 'between:5,50'],
        ];
    }

    public function avance(): string
    {
        return __('Revisando las cuentas por cobrar…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $dias = (int) ($argumentos['days_ahead'] ?? 7);
        $top = (int) ($argumentos['top_n'] ?? 15);
        $hoy = $contexto->fechaReferencia->format('Y-m-d');
        $limite = $contexto->fechaReferencia->addDays($dias)->format('Y-m-d');
        $resumen = CuentasPorCobrar::resumen($empresaId);
        $items = fn () => SaldosPorCobrar::items($empresaId);
        $desfase = $this->desfase($contexto);
        $atraso = 'DATEDIFF(?, i.vencimiento)';
        $meta = $this->meta($contexto, 'cuentas_por_cobrar', null, metodo: 'Saldos abiertos de ventas a crédito (por cuota) y cuentas manuales no anuladas, a la fecha de hoy.');

        $agregados = $items()->selectRaw('COALESCE(SUM(CASE WHEN i.vencimiento BETWEEN ? AND ? THEN i.saldo ELSE 0 END), 0) AS horizonte', [$hoy, $limite])
            ->selectRaw('COALESCE(SUM(CASE WHEN i.vencimiento < ? THEN 1 ELSE 0 END), 0) AS documentos_vencidos', [$hoy])
            ->first();

        $tramos = $items()->selectRaw("CASE WHEN i.vencimiento IS NULL THEN 'sin vencimiento' WHEN i.vencimiento >= ? THEN 'por vencer'
                WHEN {$atraso} <= 30 THEN '1-30 días' WHEN {$atraso} <= 60 THEN '31-60 días' WHEN {$atraso} <= 90 THEN '61-90 días' ELSE 'más de 90 días' END AS tramo", [$hoy, $hoy, $hoy, $hoy])
            ->selectRaw('SUM(i.saldo) AS saldo, COUNT(*) AS documentos')
            ->groupBy('tramo')->get()->keyBy('tramo');

        $porCliente = $items()
            ->groupBy('i.cliente_id')
            ->select(['i.cliente_id', DB::raw('SUM(i.saldo) AS saldo'), DB::raw('COUNT(*) AS documentos')])
            ->selectRaw('SUM(CASE WHEN i.vencimiento < ? THEN i.saldo ELSE 0 END) AS vencido', [$hoy])
            ->selectRaw("MAX(CASE WHEN i.vencimiento < ? THEN {$atraso} ELSE 0 END) AS max_atraso", [$hoy, $hoy])
            ->selectRaw('MIN(CASE WHEN i.vencimiento >= ? THEN i.vencimiento END) AS proximo_vencimiento', [$hoy]);
        $cobrado = 'DATE('.$this->local('co.fecha_cobro').')';
        $puntualidad = DB::table('cobro_aplicaciones as ca')
            ->join('venta_cuotas as q', 'q.id', '=', 'ca.venta_cuota_id')
            ->join('cobros as co', 'co.id', '=', 'ca.cobro_id')
            ->where('ca.empresa_id', $empresaId)
            ->whereNull('co.anulado_at')
            ->groupBy('co.cliente_id')
            ->select(['co.cliente_id'])
            ->selectRaw("SUM(CASE WHEN {$cobrado} <= q.vencimiento THEN 1 ELSE 0 END) AS a_tiempo", [$desfase])
            ->selectRaw("SUM(CASE WHEN {$cobrado} > q.vencimiento THEN 1 ELSE 0 END) AS atrasados", [$desfase]);
        $ultimos = DB::query()->fromSub(DB::table('cobros')->where('empresa_id', $empresaId)->whereNull('anulado_at')
            ->select(['cliente_id', DB::raw('fecha_cobro AS fecha')])
            ->unionAll(DB::table('pagos_venta as pv')->join('ventas as v', 'v.id', '=', 'pv.venta_id')
                ->where('pv.empresa_id', $empresaId)->where('pv.tipo', 'abono')
                ->select(['v.cliente_id', DB::raw('pv.fecha_cobro AS fecha')])), 'uc')
            ->groupBy('cliente_id')
            ->select(['cliente_id', DB::raw('MAX(fecha) AS ultima')]);
        $prioridad = "CASE WHEN pc.max_atraso > 30 THEN 'alta' WHEN pc.vencido > 0 THEN 'media' ELSE 'baja' END";

        $clientes = DB::query()->fromSub($porCliente, 'pc')
            ->leftJoin('clientes as c', fn ($j) => $j->on('c.id', '=', 'pc.cliente_id')->where('c.empresa_id', $empresaId))
            ->leftJoinSub($puntualidad, 'pu', 'pu.cliente_id', '=', 'pc.cliente_id')
            ->leftJoinSub($ultimos, 'ul', 'ul.cliente_id', '=', 'pc.cliente_id')
            ->orderByRaw('CASE WHEN pc.max_atraso > 30 THEN 0 WHEN pc.vencido > 0 THEN 1 ELSE 2 END')
            ->orderByDesc('pc.vencido')->orderByDesc('pc.saldo')
            ->limit($top)
            ->select([DB::raw("COALESCE(c.nombre, 'Sin cliente') AS cliente"), 'c.documento', 'c.telefono', 'pc.saldo', 'pc.vencido', 'pc.documentos',
                'pc.max_atraso', 'pc.proximo_vencimiento', DB::raw('COALESCE(pu.a_tiempo, 0) AS cobros_a_tiempo'),
                DB::raw('COALESCE(pu.atrasados, 0) AS cobros_atrasados'), DB::raw("{$prioridad} AS prioridad")])
            ->selectRaw('DATE('.$this->local('ul.ultima').') AS ultimo_cobro', [$desfase])
            ->get();

        $proximos = $items()
            ->leftJoin('clientes as c', fn ($j) => $j->on('c.id', '=', 'i.cliente_id')->where('c.empresa_id', $empresaId))
            ->whereBetween('i.vencimiento', [$hoy, $limite])
            ->orderBy('i.vencimiento')->orderByDesc('i.saldo')
            ->limit(500)
            ->select(['i.documento', DB::raw("COALESCE(c.nombre, 'Sin cliente') AS cliente"), 'i.vencimiento', 'i.saldo'])
            ->selectRaw('DATEDIFF(i.vencimiento, ?) AS dias_para_vencer', [$hoy])
            ->get();

        $saldo = fn (string $c, string $l) => $this->col($c, $l, 'money');

        return [
            $this->dataset('resumen', [
                $saldo('pendiente', __('Pendiente')), $saldo('vencido', __('Vencido')), $saldo('por_vencer', __('Por vencer')),
                $this->col('clientes', __('Clientes con saldo'), 'integer'), $saldo('vence_en_horizonte', __('Vence pronto')),
                $this->col('documentos_vencidos', __('Documentos vencidos'), 'integer'),
            ], [[
                'pendiente' => $resumen['pendiente'], 'vencido' => $resumen['vencido'], 'por_vencer' => $resumen['por_vencer'],
                'clientes' => $resumen['clientes'], 'vence_en_horizonte' => $agregados->horizonte ?? '0', 'documentos_vencidos' => $agregados->documentos_vencidos ?? 0,
            ]], $meta, "Resumen (una fila) igual a Cuentas por cobrar. vence_en_horizonte = saldo que vence entre hoy y {$dias} días."),
            $this->dataset('antiguedad', [$this->col('tramo', __('Antigüedad'), 'string'), $saldo('saldo', __('Saldo')), $this->col('documentos', __('Documentos'), 'integer')],
                array_map(fn (string $tramo) => ['tramo' => $tramo, 'saldo' => $tramos->get($tramo)->saldo ?? '0', 'documentos' => $tramos->get($tramo)->documentos ?? 0], self::TRAMOS),
                $meta, 'Saldo pendiente por tramo de atraso (en orden). «sin vencimiento» nunca se considera vencido.'),
            $this->dataset('clientes', [
                $this->col('cliente', __('Cliente'), 'string'), $this->col('documento', __('Documento'), 'string'), $this->col('telefono', __('Teléfono'), 'string'),
                $saldo('saldo', __('Saldo')), $saldo('vencido', __('Vencido')), $this->col('documentos', __('Documentos'), 'integer'),
                $this->col('max_atraso', __('Máximo atraso (días)'), 'integer'), $this->col('proximo_vencimiento', __('Próximo vencimiento'), 'date'),
                $this->col('ultimo_cobro', __('Último cobro'), 'date'), $this->col('cobros_a_tiempo', __('Cobros a tiempo'), 'integer'),
                $this->col('cobros_atrasados', __('Cobros atrasados'), 'integer'), $this->col('prioridad', __('Prioridad'), 'string'),
            ], $clientes, $meta, "Hasta {$top} clientes con saldo, ordenados por prioridad, vencido y saldo. Cobros a tiempo/atrasados: aplicaciones a cuotas pagadas hasta su vencimiento o después."),
            $this->dataset('proximos', [
                $this->col('documento', __('Documento'), 'string'), $this->col('cliente', __('Cliente'), 'string'), $this->col('vencimiento', __('Vencimiento'), 'date'),
                $saldo('saldo', __('Saldo')), $this->col('dias_para_vencer', __('Días para vencer'), 'integer'),
            ], $proximos, $meta, "Vencimientos (cuotas, ventas y cuentas) entre hoy y {$limite}, del más cercano al más lejano."),
        ];
    }
}
