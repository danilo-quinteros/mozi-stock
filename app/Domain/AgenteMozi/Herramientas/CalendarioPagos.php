<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Datos\CatalogoDatos;
use App\Domain\CuentasPagar\Consultas\CuentasPorPagar;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * get_payables_schedule (UC-12): qué pagar y a quién. Usa la consulta de Cuentas por pagar (sin
 * anuladas): resumen idéntico al módulo, calendario de lo vencido y lo que vence en el horizonte,
 * importes por día (lo vencido se ubica hoy) y saldos por acreedor. Una cuenta pagada nunca
 * aparece vencida. El agente nunca paga.
 */
final class CalendarioPagos extends HerramientaNegocio
{
    public function nombre(): string
    {
        return 'get_payables_schedule';
    }

    public function descripcion(): string
    {
        return 'Calendario de pagos a proveedores y acreedores (cuentas por pagar): resumen (pendiente, vencido, por vencer, cuentas abiertas, lo que vence en el horizonte), '
            .'calendario de cuentas vencidas y por vencer hasta horizon_days con días para vencer (negativo = atraso), importes por día (lo vencido se acumula en hoy) y saldo por acreedor. '
            .'El agente nunca registra pagos: sugiere abrir la cuenta para pagarla.';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'horizon_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'description' => 'Días hacia adelante. Por defecto 15.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return ['horizon_days' => ['sometimes', 'integer', 'between:1,90']];
    }

    public function avance(): string
    {
        return __('Revisando los vencimientos por pagar…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $horizonte = (int) ($argumentos['horizon_days'] ?? 15);
        $hoy = $contexto->fechaReferencia->format('Y-m-d');
        $limite = $contexto->fechaReferencia->addDays($horizonte)->format('Y-m-d');
        $resumen = CuentasPorPagar::resumen($empresaId);
        $abiertas = fn () => DB::query()->fromSub(CuentasPorPagar::consulta($empresaId)->reorder(), 'cxp')->where('cxp.saldo_pendiente', '>', 0);
        $meta = $this->meta($contexto, 'cuentas_por_pagar', null, metodo: 'Cuentas por pagar no anuladas con saldo, a la fecha de hoy.');
        $codigo = CatalogoDatos::sqlCodigo('CXP', 'cxp.numero');
        $origen = "CASE cxp.origen WHEN 'compra' THEN ".CatalogoDatos::sqlCodigo('COM', 'cxp.compra_numero')
            ." WHEN 'egreso' THEN ".CatalogoDatos::sqlCodigo('EGR', 'cxp.egreso_numero')." ELSE 'manual' END";

        $calendario = $abiertas()->where('cxp.vencimiento', '<=', $limite)
            ->orderBy('cxp.vencimiento')->orderByDesc('cxp.saldo_pendiente')
            ->limit(500)
            ->select([DB::raw("{$codigo} AS cuenta"), 'cxp.proveedor as acreedor', 'cxp.concepto', DB::raw("{$origen} AS documento_origen"), 'cxp.sucursal',
                'cxp.vencimiento', 'cxp.saldo_pendiente as saldo'])
            ->selectRaw('DATEDIFF(cxp.vencimiento, ?) AS dias_para_vencer', [$hoy])
            ->selectRaw("CASE WHEN cxp.vencimiento < ? THEN 'vencida' ELSE 'por vencer' END AS estado", [$hoy])
            ->get();

        $porDia = $abiertas()->where('cxp.vencimiento', '<=', $limite)
            ->groupBy('dia')
            ->selectRaw('GREATEST(cxp.vencimiento, ?) AS dia', [$hoy])
            ->selectRaw('SUM(CASE WHEN cxp.vencimiento < ? THEN cxp.saldo_pendiente ELSE 0 END) AS vencido', [$hoy])
            ->selectRaw('SUM(CASE WHEN cxp.vencimiento >= ? THEN cxp.saldo_pendiente ELSE 0 END) AS por_vencer', [$hoy])
            ->get()
            ->keyBy(fn (object $fila) => substr((string) $fila->dia, 0, 10));
        $dias = [];
        $enHorizonte = '0.00';

        for ($d = 0; $d <= $horizonte; $d++) {
            $fecha = $contexto->fechaReferencia->addDays($d)->format('Y-m-d');
            $fila = $porDia->get($fecha);
            $vencido = Decimal::redondear((string) ($fila->vencido ?? '0'));
            $porVencer = Decimal::redondear((string) ($fila->por_vencer ?? '0'));
            $enHorizonte = Decimal::sumar($enHorizonte, $porVencer, 2);
            $dias[] = ['fecha' => $fecha, 'vencido' => $vencido, 'por_vencer' => $porVencer, 'total' => Decimal::sumar($vencido, $porVencer, 2)];
        }

        $acreedores = $abiertas()
            ->groupBy('cxp.proveedor')
            ->orderByDesc('vencido')->orderByDesc('saldo')
            ->limit(100)
            ->select(['cxp.proveedor as acreedor', DB::raw('COUNT(*) AS cuentas'), DB::raw('SUM(cxp.saldo_pendiente) AS saldo')])
            ->selectRaw('SUM(CASE WHEN cxp.vencimiento < ? THEN cxp.saldo_pendiente ELSE 0 END) AS vencido', [$hoy])
            ->selectRaw('SUM(CASE WHEN cxp.vencimiento BETWEEN ? AND ? THEN cxp.saldo_pendiente ELSE 0 END) AS en_horizonte', [$hoy, $limite])
            ->selectRaw('MIN(CASE WHEN cxp.vencimiento >= ? THEN cxp.vencimiento END) AS proximo_vencimiento', [$hoy])
            ->get();

        $dinero = fn (string $c, string $l) => $this->col($c, $l, 'money');

        return [
            $this->dataset('resumen', [
                $dinero('pendiente', __('Pendiente')), $dinero('vencido', __('Vencido')), $dinero('por_vencer', __('Por vencer')),
                $this->col('cuentas_abiertas', __('Cuentas abiertas'), 'integer'), $dinero('en_horizonte', __('Vence en el horizonte')),
            ], [['pendiente' => $resumen['pendiente'], 'vencido' => $resumen['vencido'], 'por_vencer' => $resumen['por_vencer'],
                'cuentas_abiertas' => $resumen['abiertas'], 'en_horizonte' => $enHorizonte]],
                $meta, "Resumen (una fila) igual a Cuentas por pagar. en_horizonte = saldo que vence entre hoy y {$limite} (sin lo ya vencido)."),
            $this->dataset('calendario', [
                $this->col('cuenta', __('Cuenta'), 'string'), $this->col('acreedor', __('Acreedor'), 'string'), $this->col('concepto', __('Concepto'), 'string'),
                $this->col('documento_origen', __('Documento de origen'), 'string'), $this->col('sucursal', __('Sucursal'), 'string'),
                $this->col('vencimiento', __('Vencimiento'), 'date'), $dinero('saldo', __('Saldo')),
                $this->col('dias_para_vencer', __('Días para vencer'), 'integer'), $this->col('estado', __('Estado'), 'string'),
            ], $calendario, $meta, "Cuentas con saldo vencidas o que vencen hasta {$limite}, de la más antigua a la más lejana."),
            $this->dataset('por_fecha', [$this->col('fecha', __('Fecha'), 'date'), $dinero('vencido', __('Vencido')), $dinero('por_vencer', __('Por vencer')), $dinero('total', __('Total'))],
                $dias, $meta, 'Importe a pagar por día desde hoy hasta el fin del horizonte; lo ya vencido se acumula en el día de hoy.'),
            $this->dataset('por_acreedor', [
                $this->col('acreedor', __('Acreedor'), 'string'), $this->col('cuentas', __('Cuentas'), 'integer'), $dinero('saldo', __('Saldo')),
                $dinero('vencido', __('Vencido')), $dinero('en_horizonte', __('Vence en el horizonte')), $this->col('proximo_vencimiento', __('Próximo vencimiento'), 'date'),
            ], $acreedores, $meta, 'Saldo abierto por acreedor (todas sus cuentas), ordenado por vencido y saldo.'),
        ];
    }
}
