<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\Caja\Consultas\ReporteCaja;
use App\Domain\Reportes\Periodo;
use App\Models\Caja;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * review_cash_shifts (UC-14): turnos, arqueos y cajas en este momento con las consultas de «Caja y
 * arqueo». El administrador ve toda la empresa, el resumen por responsable y el efectivo registrado
 * fuera de caja; el vendedor, como en «Mi caja», solo sus turnos (abiertos, compartidos o donde
 * registró dinero), lo que él cobró y su caja asignada. Las diferencias se informan sin acusar.
 */
final class RevisarCajas extends HerramientaNegocio
{
    public function __construct(private readonly bool $administrador = false) {}

    public function nombre(): string
    {
        return 'review_cash_shifts';
    }

    public function descripcion(): string
    {
        return 'Control de cajas y arqueos del período (por defecto los últimos 30 días): resumen de cierres (diferencia total, cuadrados, faltantes y sobrantes, turnos abiertos y abiertos desde días anteriores), '
            .'turnos con apertura, efectivo esperado, contado, diferencia y resultado (abierto, cuadrada, sobrante, faltante o sin_conteo) y el estado actual de las cajas. '
            .($this->administrador
                ? 'Incluye resumen por responsable y el efectivo cobrado fuera de caja. '
                : 'Solo los turnos del usuario y su caja asignada, como en «Mi caja». ')
            .'Una diferencia es un dato a revisar, nunca una acusación.';
    }

    public function parametros(): array
    {
        return ['type' => 'object', 'properties' => $this->propiedadesPeriodo('hace 29 días'), 'additionalProperties' => false];
    }

    public function reglas(): array
    {
        return $this->reglasPeriodo();
    }

    public function permiso(): ?string
    {
        return null;
    }

    public function avance(): string
    {
        return __('Revisando los turnos de caja…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $periodo = $this->periodo($contexto, $argumentos, 30);
        $usuario = User::query()->where('empresa_id', $contexto->empresaId)->findOrFail($contexto->usuarioId);
        $administrador = $this->sucursalVendedor($contexto) === null;
        $meta = $this->meta($contexto, 'caja_y_arqueo', $periodo, metodo: $administrador
            ? 'Turnos con actividad en el período y cierres por fecha de cierre (Caja y arqueo).'
            : 'Turnos que abrió, compartió o en los que registró dinero el usuario (Mi caja).');

        $turnos = $administrador
            ? ReporteCaja::turnos($contexto->empresaId, $periodo, null, null)->limit(500)->get()
            : $this->turnosDelVendedor($usuario, $periodo);
        $ids = array_values(array_map(fn (TurnoCaja $t): int => $t->id, $turnos->all()));
        $totales = ReporteCaja::totalesTurnos($contexto->empresaId, $ids, $administrador ? null : $usuario->id);
        $cobrado = $administrador ? $totales : ReporteCaja::totalesTurnos($contexto->empresaId, $ids);
        $zona = $contexto->zonaHoraria;
        $filas = $turnos->map(fn (TurnoCaja $t) => [
            'caja' => $t->caja->nombre,
            'sucursal' => $t->sucursal->nombre,
            'abierto_por' => $t->usuarioApertura->name,
            'apertura' => $t->fecha_apertura->copy()->timezone($zona)->format('Y-m-d'),
            'hora_apertura' => $t->fecha_apertura->copy()->timezone($zona)->format('H:i'),
            'cierre' => $t->fecha_cierre?->copy()->timezone($zona)->format('Y-m-d'),
            'resultado' => $t->resultado(),
            'monto_apertura' => (string) $t->monto_apertura,
            'efectivo_esperado' => $t->estaAbierto() && ! $administrador && ! $t->usuarioPuedeOperar($usuario)
                ? null : ReporteCaja::esperado($t, $cobrado[$t->id] ?? null),
            'contado' => $t->monto_cierre === null ? null : (string) $t->monto_cierre,
            'diferencia' => $t->diferencia === null ? null : (string) $t->diferencia,
            'cobrado' => $totales[$t->id]['ingresos'] ?? '0',
            'cerrado_por' => $t->usuarioCierre?->name,
            'observaciones' => $t->observaciones === null ? null : mb_substr($t->observaciones, 0, 200),
        ])->all();

        $dinero = fn (string $c, string $l) => $this->col($c, $l, 'money');
        $datasets = [
            $this->dataset('resumen', [
                $dinero('diferencia_total', __('Diferencia total')), $this->col('cierres', __('Cierres'), 'integer'),
                $this->col('cuadrados', __('Cuadrados'), 'integer'), $this->col('faltantes', __('Con faltante'), 'integer'),
                $this->col('sobrantes', __('Con sobrante'), 'integer'), $this->col('turnos_abiertos', __('Turnos abiertos'), 'integer'),
                $this->col('abiertos_dias_anteriores', __('Abiertos desde días anteriores'), 'integer'),
                ...($administrador ? [$dinero('efectivo_fuera_de_caja', __('Efectivo cobrado fuera de caja'))] : []),
            ], [$this->resumen($contexto, $periodo, $turnos, $administrador)], $meta,
                'Resumen (una fila). Cierres con fecha de cierre en el período; turnos abiertos a hoy.'
                .($administrador ? ' efectivo_fuera_de_caja = cobros en efectivo registrados sin caja en el período.' : '')),
            $this->dataset('turnos', [
                $this->col('caja', __('Caja'), 'string'), $this->col('sucursal', __('Sucursal'), 'string'), $this->col('abierto_por', __('Abierto por'), 'string'),
                $this->col('apertura', __('Apertura'), 'date'), $this->col('hora_apertura', __('Hora de apertura'), 'string'), $this->col('cierre', __('Cierre'), 'date'),
                $this->col('resultado', __('Resultado'), 'string'), $dinero('monto_apertura', __('Fondo inicial')), $dinero('efectivo_esperado', __('Efectivo esperado')),
                $dinero('contado', __('Contado')), $dinero('diferencia', __('Diferencia')),
                $dinero('cobrado', $administrador ? __('Cobrado en el turno') : __('Cobrado por mí')),
                $this->col('cerrado_por', __('Cerrado por'), 'string'), $this->col('observaciones', __('Observaciones'), 'string'),
            ], $filas, $meta, 'Turnos del período, del más reciente al más antiguo. diferencia = contado − esperado (negativo = faltante).'
                .($administrador ? '' : ' cobrado = lo que registró el usuario en ese turno.')),
            $this->dataset('cajas_ahora', [
                $this->col('caja', __('Caja'), 'string'), $this->col('sucursal', __('Sucursal'), 'string'), $this->col('estado', __('Estado'), 'string'),
                $this->col('abierta_por', __('Abierta por'), 'string'), $this->col('desde', __('Abierta desde'), 'date'),
                $dinero('efectivo_esperado', __('Efectivo esperado')), $this->col('ultimo_cierre', __('Último cierre'), 'date'),
                $dinero('ultima_diferencia', __('Última diferencia')),
            ], $this->cajasAhora($contexto, $usuario, $administrador), $this->meta($contexto, 'caja_y_arqueo', null),
                $administrador ? 'Estado actual de cada caja activa.' : 'Estado actual de la caja asignada al usuario (vacío si no tiene).'),
        ];

        if ($administrador) {
            $datasets[] = $this->dataset('por_responsable', [
                $this->col('responsable', __('Responsable'), 'string'), $this->col('rol', __('Rol'), 'string'), $this->col('cajas', __('Cajas'), 'string'),
                $this->col('cobros', __('Cobros'), 'integer'), $dinero('efectivo', __('Efectivo')), $dinero('electronico', __('Electrónico')),
                $dinero('total', __('Total cobrado')), $dinero('salidas', __('Salidas')), $this->col('turnos_abiertos', __('Turnos abiertos'), 'integer'),
                $this->col('cierres', __('Cierres'), 'integer'), $dinero('diferencia', __('Diferencia de sus cierres')),
            ], array_map(fn (object $r) => $this->responsable(get_object_vars($r)), ReporteCaja::porResponsable($contexto->empresaId, $periodo, null, null)), $meta,
                'Dinero registrado por cada usuario en el período y resultado de los turnos que abrió y se cerraron en el período.');
        }

        return $datasets;
    }

    /**
     * @return Collection<int, TurnoCaja>
     */
    private function turnosDelVendedor(User $usuario, Periodo $periodo): Collection
    {
        [$inicio, $fin] = $periodo->limitesUtc();

        return ReporteCaja::turnosDeUsuario($usuario)
            ->where('fecha_apertura', '<', $fin)
            ->where(fn (EloquentBuilder $q) => $q->whereNull('fecha_cierre')->orWhere('fecha_cierre', '>=', $inicio))
            ->with(['caja:id,nombre', 'sucursal:id,nombre', 'usuarioApertura:id,name', 'usuarioCierre:id,name', 'participantes'])
            ->orderByDesc('fecha_apertura')->orderByDesc('id')
            ->limit(500)->get();
    }

    /**
     * @param  Collection<int, TurnoCaja>  $turnos
     * @return array<string, string|int>
     */
    private function resumen(ContextoAgente $contexto, Periodo $periodo, Collection $turnos, bool $administrador): array
    {
        [$inicio, $fin] = $periodo->limitesUtc();
        $hoy = Periodo::hoy()->limitesUtc()[0];

        if ($administrador) {
            $cierres = ReporteCaja::diferencias($contexto->empresaId, $periodo, null, null);
            $abiertos = DB::table('turnos_caja')->where('empresa_id', $contexto->empresaId)->where('estado', 'abierto')
                ->selectRaw('COUNT(*) AS abiertos, COALESCE(SUM(CASE WHEN fecha_apertura < ? THEN 1 ELSE 0 END), 0) AS anteriores', [$hoy])->first();
            $fuera = DB::table('movimientos_caja')->where('empresa_id', $contexto->empresaId)->whereNull('caja_id')
                ->where('sentido', 'ingreso')->where('metodo', 'efectivo')
                ->where('fecha_operacion', '>=', $inicio)->where('fecha_operacion', '<', $fin)->sum('monto');

            return [
                'diferencia_total' => $cierres['total'], 'cierres' => $cierres['cierres'],
                'cuadrados' => $cierres['cierres'] - $cierres['faltantes'] - $cierres['sobrantes'],
                'faltantes' => $cierres['faltantes'], 'sobrantes' => $cierres['sobrantes'],
                'turnos_abiertos' => (int) ($abiertos->abiertos ?? 0), 'abiertos_dias_anteriores' => (int) ($abiertos->anteriores ?? 0),
                'efectivo_fuera_de_caja' => Decimal::redondear((string) $fuera),
            ];
        }

        $cerrados = $turnos->filter(fn (TurnoCaja $t) => ! $t->estaAbierto() && $t->fecha_cierre !== null
            && $t->fecha_cierre->greaterThanOrEqualTo($inicio) && $t->fecha_cierre->lessThan($fin));
        $resultados = $cerrados->map(fn (TurnoCaja $t) => $t->resultado());
        $abiertos = $turnos->filter(fn (TurnoCaja $t) => $t->estaAbierto());

        return [
            'diferencia_total' => $cerrados->reduce(fn (string $total, TurnoCaja $t) => Decimal::sumar($total, (string) ($t->diferencia ?? '0'), 2), '0.00'),
            'cierres' => $cerrados->count(),
            'cuadrados' => $resultados->filter(fn (string $r) => $r === 'cuadrada')->count(),
            'faltantes' => $resultados->filter(fn (string $r) => $r === 'faltante')->count(),
            'sobrantes' => $resultados->filter(fn (string $r) => $r === 'sobrante')->count(),
            'turnos_abiertos' => $abiertos->count(),
            'abiertos_dias_anteriores' => $abiertos->filter(fn (TurnoCaja $t) => $t->esDelDiaAnterior())->count(),
        ];
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function cajasAhora(ContextoAgente $contexto, User $usuario, bool $administrador): array
    {
        $zona = $contexto->zonaHoraria;

        if ($administrador) {
            $estados = ReporteCaja::cajasAhora($contexto->empresaId, null, null);
        } else {
            $caja = $usuario->caja_id === null ? null : Caja::withoutGlobalScopes()->where('empresa_id', $contexto->empresaId)
                ->where('sucursal_id', $usuario->sucursal_id)->where('activa', true)->find($usuario->caja_id);
            $estados = $caja === null ? [] : ReporteCaja::cajasAhora($contexto->empresaId, (int) $caja->sucursal_id, (int) $caja->id);
        }

        return array_map(function (array $estado) use ($zona, $administrador, $usuario): array {
            $turno = $estado['turno'];
            $ultimo = $estado['ultimo'];
            $visible = $turno !== null && ($administrador || $turno->usuarioPuedeOperar($usuario));

            return [
                'caja' => $estado['caja']->nombre,
                'sucursal' => $estado['caja']->sucursal->nombre,
                'estado' => $turno === null ? 'cerrada' : 'abierta',
                'abierta_por' => $turno?->usuarioApertura?->name,
                'desde' => $turno?->fecha_apertura->copy()->timezone($zona)->format('Y-m-d'),
                'efectivo_esperado' => $visible ? $estado['esperado'] : null,
                'ultimo_cierre' => $ultimo?->fecha_cierre?->copy()->timezone($zona)->format('Y-m-d'),
                'ultima_diferencia' => $ultimo?->diferencia === null ? null : (string) $ultimo->diferencia,
            ];
        }, $estados);
    }

    /**
     * Fila de ReporteCaja::porResponsable con los nombres de columna del dataset.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function responsable(array $fila): array
    {
        $columnas = ['responsable' => 'nombre', 'rol' => 'rol', 'cajas' => 'cajas', 'cobros' => 'cobros', 'efectivo' => 'efectivo', 'electronico' => 'electronico',
            'total' => 'total', 'salidas' => 'salidas', 'turnos_abiertos' => 'abiertos', 'cierres' => 'cierres', 'diferencia' => 'diferencia'];

        return array_map(fn (string $origen) => $fila[$origen] ?? null, $columnas);
    }
}
