<?php

namespace App\Domain\Suscripciones\Consultas;

use App\Domain\Reportes\Periodo;
use App\Enums\EstadoSuscripcion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Suscripciones de todas las empresas para el panel global de MoziStudios. Solo datos del
 * plan: nunca ventas, cobros ni clientes de las empresas. La pantalla y su CSV comparten
 * esta consulta (T23).
 */
final class ListadoSuscripciones
{
    public const ESTADOS = ['al_dia', 'por_vencer', 'vencida', 'revision', 'sin_plan'];

    public static function consulta(string $buscar, string $estado, CarbonImmutable $hoy): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $limite = $hoy->addDays(EstadoSuscripcion::DIAS_POR_VENCER)->format('Y-m-d');
        $pendientes = DB::table('pagos_saas')->whereColumn('pagos_saas.empresa_id', 'empresas.id')->where('pagos_saas.estado', 'pendiente');

        return DB::table('empresas')
            ->leftJoin('suscripciones', 'suscripciones.empresa_id', '=', 'empresas.id')
            ->leftJoin('planes', 'planes.id', '=', 'suscripciones.plan_id')
            ->select('empresas.id', 'empresas.nombre', 'empresas.email', 'empresas.activa',
                'suscripciones.id as suscripcion_id', 'suscripciones.precio_mensual', 'suscripciones.vencimiento',
                'planes.titulo as plan_titulo', 'suscripciones.sucursales_extra', 'suscripciones.paquetes_usuarios')
            // Mensualidad de hoy = plan + extras (como CalculadoraExtras::desglose; DECIMAL en SQL, sin float).
            ->selectRaw('suscripciones.precio_mensual'
                .' + CASE WHEN planes.sucursales_incluidas IS NULL THEN 0 ELSE suscripciones.sucursales_extra * planes.precio_sucursal_extra END'
                .' + CASE WHEN planes.usuarios_incluidos IS NULL THEN 0 ELSE suscripciones.paquetes_usuarios * planes.precio_paquete_usuarios END as mensualidad')
            ->selectSub((clone $pendientes)->selectRaw('count(*)'), 'en_revision')
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('empresas.nombre', 'like', $patron)->orWhere('empresas.email', 'like', $patron)))
            ->when($estado === 'sin_plan', fn (Builder $q) => $q->whereNull('suscripciones.id'))
            ->when($estado === 'vencida', fn (Builder $q) => $q->where('suscripciones.vencimiento', '<', $hoy->format('Y-m-d')))
            ->when($estado === 'por_vencer', fn (Builder $q) => $q->whereBetween('suscripciones.vencimiento', [$hoy->format('Y-m-d'), $limite]))
            ->when($estado === 'al_dia', fn (Builder $q) => $q->where('suscripciones.vencimiento', '>', $limite))
            ->when($estado === 'revision', fn (Builder $q) => $q->whereExists($pendientes))
            ->orderByRaw('suscripciones.vencimiento IS NULL')
            ->orderBy('suscripciones.vencimiento')
            ->orderBy('empresas.nombre')
            ->orderBy('empresas.id');
    }

    /**
     * @return array{por_vencer: int, vencidas: int, en_revision: int}
     */
    public static function conteos(CarbonImmutable $hoy): array
    {
        $fila = DB::table('suscripciones')
            ->selectRaw('coalesce(sum(case when vencimiento < ? then 1 else 0 end), 0) as vencidas', [$hoy->format('Y-m-d')])
            ->selectRaw('coalesce(sum(case when vencimiento between ? and ? then 1 else 0 end), 0) as por_vencer',
                [$hoy->format('Y-m-d'), $hoy->addDays(EstadoSuscripcion::DIAS_POR_VENCER)->format('Y-m-d')])
            ->first();

        return [
            'por_vencer' => (int) ($fila->por_vencer ?? 0),
            'vencidas' => (int) ($fila->vencidas ?? 0),
            'en_revision' => DB::table('pagos_saas')->where('estado', 'pendiente')->count(),
        ];
    }

    /**
     * Dinero del SaaS aprobado en el período (fecha de aprobación). No pertenece a la caja
     * de ninguna empresa (T27).
     */
    public static function cobrado(Periodo $periodo): string
    {
        [$desde, $hasta] = $periodo->limitesUtc();

        return (string) DB::table('pagos_saas')->where('estado', 'aprobado')
            ->where('revisado_en', '>=', $desde)->where('revisado_en', '<', $hasta)
            ->selectRaw('coalesce(sum(importe), 0) as total')->value('total');
    }

    /**
     * Último aviso del período vigente de cada empresa listada, en una sola consulta.
     *
     * @param  iterable<\stdClass>  $filas  filas de consulta()
     * @return array<int, \stdClass>
     */
    public static function ultimosAvisos(iterable $filas): array
    {
        $periodos = [];

        foreach ($filas as $fila) {
            if ($fila->vencimiento !== null) {
                $periodos[(int) $fila->id] = (string) $fila->vencimiento;
            }
        }

        if ($periodos === []) {
            return [];
        }

        $ultimos = [];
        $avisos = DB::table('avisos_suscripcion')->whereIn('empresa_id', array_keys($periodos))
            ->whereIn('vencimiento', array_values(array_unique($periodos)))->orderBy('id')->get(['empresa_id', 'vencimiento', 'evento', 'estado', 'fecha_programada', 'enviado_en']);

        foreach ($avisos as $aviso) {
            if ($periodos[(int) $aviso->empresa_id] === (string) $aviso->vencimiento) {
                $ultimos[(int) $aviso->empresa_id] = $aviso;
            }
        }

        return $ultimos;
    }
}
