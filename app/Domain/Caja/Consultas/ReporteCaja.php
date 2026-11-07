<?php

namespace App\Domain\Caja\Consultas;

use App\Domain\Reportes\Periodo;
use App\Models\Caja;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de «Caja y arqueo» (administrador) y «Mi caja» (vendedor). El dinero sale siempre de
 * movimientos_caja y el resultado de cada cierre de turnos_caja (guardado al cerrar). Cada
 * método resuelve su bloque con una o dos consultas agrupadas, nunca una por fila, y filtra
 * por empresa en todas las tablas.
 */
final class ReporteCaja
{
    /** Valor del filtro «caja» para los movimientos sin caja ni turno («Fuera de caja», solo administrador). */
    public const FUERA_DE_CAJA = 0;

    /**
     * Filtro «caja» de la pantalla, el CSV y la impresión: id de caja, «externo» (FUERA_DE_CAJA)
     * o vacío (todas). Las consultas siempre filtran por empresa, así que un id ajeno no muestra nada.
     */
    public static function cajaElegida(string $valor): ?int
    {
        return match (true) {
            $valor === 'externo' => self::FUERA_DE_CAJA,
            ctype_digit($valor) && (int) $valor > 0 => (int) $valor,
            default => null,
        };
    }

    /** Filtra por caja; FUERA_DE_CAJA deja solo los movimientos sin caja. */
    public static function filtrarCaja(Builder $q, string $columna, ?int $cajaId): Builder
    {
        return match ($cajaId) {
            null => $q,
            self::FUERA_DE_CAJA => $q->whereNull($columna),
            default => $q->where($columna, $cajaId),
        };
    }

    /**
     * Una fila por operación (venta, abono, compra, egreso, reembolso, pago o anulación) con sus
     * importes por medio. Sin período cuando se filtra por turno (un turno puede cruzar días).
     */
    public static function movimientos(
        int $empresaId,
        ?Periodo $periodo = null,
        ?int $sucursalId = null,
        ?int $cajaId = null,
        string $sentido = '',
        string $medio = '',
        string $buscar = '',
        ?int $usuarioId = null,
        ?int $turnoId = null,
    ): Builder {
        $origen = fn (string $alias, string $tipo) => fn (JoinClause $join) => $join
            ->on("{$alias}.id", '=', 'm.origen_id')->where('m.origen_tipo', '=', $tipo);
        $destinos = DB::table('cobro_aplicaciones')
            ->where('empresa_id', $empresaId)
            ->groupBy('cobro_id')
            ->select(['cobro_id', DB::raw('MIN(venta_id) AS venta_id'), DB::raw('MIN(cuenta_cobrar_id) AS cuenta_id'),
                DB::raw('COUNT(DISTINCT venta_id) + COUNT(DISTINCT cuenta_cobrar_id) AS documentos')]);
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $numero = (int) preg_replace('/\D/', '', $termino);

        return DB::table('movimientos_caja as m')
            ->leftJoin('pagos_venta as p', $origen('p', 'pago_venta'))
            ->leftJoin('ventas as vp', 'vp.id', '=', 'p.venta_id')
            ->leftJoin('cobros as co', $origen('co', 'cobro'))
            ->leftJoin('cobros as coa', $origen('coa', 'anulacion_cobro'))
            ->leftJoinSub($destinos, 'dest', 'dest.cobro_id', '=', DB::raw('COALESCE(co.id, coa.id)'))
            ->leftJoin('ventas as vca', 'vca.id', '=', 'dest.venta_id')
            ->leftJoin('cuentas_cobrar as cca', 'cca.id', '=', 'dest.cuenta_id')
            ->leftJoin('compras as c', $origen('c', 'compra'))
            ->leftJoin('proveedores as pr', 'pr.id', '=', 'c.proveedor_id')
            ->leftJoin('egresos as e', $origen('e', 'egreso'))
            ->leftJoin('categorias_egreso as ce', 'ce.id', '=', 'e.categoria_egreso_id')
            ->leftJoin('devoluciones as d', $origen('d', 'devolucion'))
            ->leftJoin('ventas as vd', 'vd.id', '=', 'd.venta_id')
            ->leftJoin('pagos_cuenta_pagar as pc', $origen('pc', 'pago_cxp'))
            ->leftJoin('pagos_cuenta_pagar as pca', $origen('pca', 'anulacion_pago_cxp'))
            ->leftJoin('cuentas_pagar as cp', 'cp.id', '=', DB::raw('COALESCE(pc.cuenta_pagar_id, pca.cuenta_pagar_id)'))
            ->leftJoin('proveedores as cpp', 'cpp.id', '=', 'cp.proveedor_id')
            ->leftJoin('clientes as cl', 'cl.id', '=', DB::raw('COALESCE(vp.cliente_id, co.cliente_id, coa.cliente_id, vd.cliente_id)'))
            ->leftJoin('cajas as cj', 'cj.id', '=', 'm.caja_id')
            ->join('sucursales as s', 's.id', '=', 'm.sucursal_id')
            ->join('users as u', 'u.id', '=', 'm.usuario_id')
            ->where('m.empresa_id', $empresaId)
            ->when($periodo !== null, function (Builder $q) use ($periodo): void {
                [$inicio, $fin] = $periodo->limitesUtc();
                $q->where('m.fecha_operacion', '>=', $inicio)->where('m.fecha_operacion', '<', $fin);
            })
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('m.sucursal_id', $sucursalId))
            ->when($cajaId !== null, fn (Builder $q) => self::filtrarCaja($q, 'm.caja_id', $cajaId))
            ->when($usuarioId !== null, fn (Builder $q) => $q->where('m.usuario_id', $usuarioId))
            ->when($turnoId !== null, fn (Builder $q) => $q->where('m.turno_caja_id', $turnoId))
            ->when(in_array($sentido, ['ingreso', 'egreso'], true), fn (Builder $q) => $q->where('m.sentido', $sentido))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('cl.nombre', 'like', $patron)
                ->orWhere('u.name', 'like', $patron)
                ->orWhere('pr.nombre', 'like', $patron)
                ->orWhere('cpp.nombre', 'like', $patron)
                ->orWhere('e.descripcion', 'like', $patron)
                ->when($numero > 0, fn (Builder $q) => $q->orWhere('vp.numero', $numero)->orWhere('vca.numero', $numero)
                    ->orWhere('co.numero', $numero)->orWhere('c.numero', $numero)->orWhere('e.numero', $numero)
                    ->orWhere('d.numero', $numero)->orWhere('cp.numero', $numero))))
            ->groupBy('m.origen_tipo', 'm.origen_id', 'm.sentido')
            ->when(in_array($medio, ['efectivo', 'electronico'], true),
                fn (Builder $q) => $q->havingRaw('SUM(CASE WHEN m.metodo = ? THEN m.monto ELSE 0 END) > 0', [$medio]))
            ->orderByDesc('fecha')
            ->orderByDesc('m.origen_id')
            ->select([
                'm.origen_tipo', 'm.origen_id', 'm.sentido', DB::raw('MIN(m.fecha_operacion) AS fecha'),
                DB::raw('MAX(p.tipo) AS pago_tipo'), DB::raw('MAX(vp.numero) AS venta_numero'),
                DB::raw('MAX(CASE WHEN vp.cobrado_inicial >= vp.total THEN 1 ELSE 0 END) AS al_contado'),
                DB::raw('MAX(COALESCE(co.numero, coa.numero)) AS cobro_numero'), DB::raw('MAX(vca.numero) AS cobro_venta_numero'),
                DB::raw('MAX(cca.numero) AS cobro_cuenta_numero'), DB::raw('MAX(dest.documentos) AS cobro_documentos'),
                DB::raw('MAX(c.numero) AS compra_numero'), DB::raw('MAX(pr.nombre) AS proveedor'),
                DB::raw('MAX(e.numero) AS egreso_numero'), DB::raw('MAX(e.descripcion) AS egreso_descripcion'), DB::raw('MAX(ce.nombre) AS categoria'),
                DB::raw('MAX(d.numero) AS devolucion_numero'), DB::raw('MAX(vd.numero) AS devolucion_venta_numero'), DB::raw('MAX(d.motivo) AS devolucion_motivo'),
                DB::raw('MAX(cp.numero) AS cuenta_numero'), DB::raw('MAX(cp.concepto) AS cuenta_concepto'), DB::raw('MAX(cpp.nombre) AS acreedor'),
                DB::raw('MAX(cl.nombre) AS cliente'), DB::raw('MAX(cj.nombre) AS caja'), DB::raw('MAX(s.nombre) AS sucursal'), DB::raw('MAX(u.name) AS usuario'),
                DB::raw("SUM(CASE WHEN m.metodo = 'efectivo' THEN m.monto ELSE 0 END) AS efectivo"),
                DB::raw("SUM(CASE WHEN m.metodo = 'electronico' THEN m.monto ELSE 0 END) AS electronico"),
                DB::raw('SUM(m.monto) AS total'),
            ]);
    }

    /**
     * Concepto, documento y detalle legibles de una fila de movimientos().
     *
     * @return array{concepto: string, referencia: string, detalle: string}
     */
    public static function describir(\stdClass $fila): array
    {
        $codigo = fn (string $serie, int|string|null $numero) => $numero === null ? '' : $serie.'-'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT);
        $unir = fn (?string ...$partes) => implode(' · ', array_filter($partes, fn (?string $p) => $p !== null && $p !== ''));
        $cliente = $fila->cliente ?? __('Consumidor final');
        $cobro = match (true) {
            (int) $fila->cobro_documentos > 1 => $codigo('COB', $fila->cobro_numero).' · '.__(':n documentos', ['n' => (int) $fila->cobro_documentos]),
            $fila->cobro_venta_numero !== null => $codigo('V', $fila->cobro_venta_numero),
            $fila->cobro_cuenta_numero !== null => $codigo('CXC', $fila->cobro_cuenta_numero),
            default => $codigo('COB', $fila->cobro_numero),
        };

        return match ($fila->origen_tipo) {
            'pago_venta' => ['concepto' => match (true) {
                $fila->pago_tipo !== 'inicial' => __('Abono'),
                (bool) $fila->al_contado => __('Venta'),
                default => __('Cuota inicial'),
            }, 'referencia' => $codigo('V', $fila->venta_numero), 'detalle' => $cliente],
            'cobro' => ['concepto' => __('Abono'), 'referencia' => $cobro, 'detalle' => $cliente],
            'anulacion_cobro' => ['concepto' => __('Anulación de cobro'), 'referencia' => $cobro, 'detalle' => $cliente],
            'compra' => ['concepto' => __('Compra'), 'referencia' => $codigo('COM', $fila->compra_numero), 'detalle' => $fila->proveedor ?? __('Sin proveedor')],
            'egreso' => ['concepto' => __('Egreso'), 'referencia' => $codigo('EGR', $fila->egreso_numero), 'detalle' => $unir($fila->categoria, $fila->egreso_descripcion)],
            'devolucion' => ['concepto' => __('Reembolso'), 'referencia' => $codigo('DEV', $fila->devolucion_numero),
                'detalle' => $unir(__('Venta :codigo', ['codigo' => $codigo('V', $fila->devolucion_venta_numero)]), $fila->devolucion_motivo)],
            'pago_cxp' => ['concepto' => __('Pago de cuenta por pagar'), 'referencia' => $codigo('CXP', $fila->cuenta_numero), 'detalle' => $unir($fila->acreedor, $fila->cuenta_concepto)],
            'anulacion_pago_cxp' => ['concepto' => __('Anulación de pago'), 'referencia' => $codigo('CXP', $fila->cuenta_numero), 'detalle' => $unir($fila->acreedor, $fila->cuenta_concepto)],
            default => ['concepto' => __('Movimiento'), 'referencia' => '', 'detalle' => ''],
        };
    }

    /**
     * Totales de varios turnos en una consulta: efectivo que entró y salió, electrónico recibido
     * y ventas. Con usuario, solo lo que ese usuario registró.
     *
     * @param  list<int>  $turnoIds
     * @return array<int, array{efectivo_ingreso: string, efectivo_egreso: string, electronico_ingreso: string, ingresos: string, ventas: int}>
     */
    public static function totalesTurnos(int $empresaId, array $turnoIds, ?int $usuarioId = null): array
    {
        if ($turnoIds === []) {
            return [];
        }

        $sumas = DB::table('movimientos_caja')
            ->where('empresa_id', $empresaId)
            ->whereIn('turno_caja_id', $turnoIds)
            ->when($usuarioId !== null, fn (Builder $q) => $q->where('usuario_id', $usuarioId))
            ->groupBy('turno_caja_id')
            ->get([
                'turno_caja_id',
                DB::raw("SUM(CASE WHEN metodo = 'efectivo' AND sentido = 'ingreso' THEN monto ELSE 0 END) AS efectivo_ingreso"),
                DB::raw("SUM(CASE WHEN metodo = 'efectivo' AND sentido = 'egreso' THEN monto ELSE 0 END) AS efectivo_egreso"),
                DB::raw("SUM(CASE WHEN metodo = 'electronico' AND sentido = 'ingreso' THEN monto ELSE 0 END) AS electronico_ingreso"),
                DB::raw("SUM(CASE WHEN sentido = 'ingreso' THEN monto ELSE 0 END) AS ingresos"),
            ])->keyBy('turno_caja_id');
        $ventas = DB::table('ventas')
            ->where('empresa_id', $empresaId)
            ->whereIn('turno_caja_id', $turnoIds)
            ->when($usuarioId !== null, fn (Builder $q) => $q->where('vendedor_id', $usuarioId))
            ->groupBy('turno_caja_id')
            ->pluck(DB::raw('COUNT(*)'), 'turno_caja_id');

        $totales = [];
        foreach ($turnoIds as $id) {
            $fila = $sumas->get($id);
            $totales[$id] = [
                'efectivo_ingreso' => Decimal::redondear((string) ($fila->efectivo_ingreso ?? '0')),
                'efectivo_egreso' => Decimal::redondear((string) ($fila->efectivo_egreso ?? '0')),
                'electronico_ingreso' => Decimal::redondear((string) ($fila->electronico_ingreso ?? '0')),
                'ingresos' => Decimal::redondear((string) ($fila->ingresos ?? '0')),
                'ventas' => (int) ($ventas[$id] ?? 0),
            ];
        }

        return $totales;
    }

    /**
     * Efectivo esperado de un turno: el guardado al cerrar o, si sigue abierto, fondo + cobros −
     * salidas en efectivo según totalesTurnos().
     *
     * @param  array{efectivo_ingreso: string, efectivo_egreso: string}|null  $totales
     */
    public static function esperado(TurnoCaja $turno, ?array $totales): string
    {
        if (! $turno->estaAbierto() && $turno->efectivo_esperado !== null) {
            return (string) $turno->efectivo_esperado;
        }

        return Decimal::restar(
            Decimal::sumar((string) $turno->monto_apertura, $totales['efectivo_ingreso'] ?? '0', Decimal::ESCALA_DINERO),
            $totales['efectivo_egreso'] ?? '0', Decimal::ESCALA_DINERO);
    }

    /**
     * Estado actual de cada caja activa: su turno abierto con el efectivo que debe haber, o su
     * último cierre.
     *
     * @return list<array{caja: Caja, turno: TurnoCaja|null, ultimo: TurnoCaja|null, totales: array<string, mixed>|null, esperado: string|null}>
     */
    public static function cajasAhora(int $empresaId, ?int $sucursalId, ?int $cajaId): array
    {
        $cajas = Caja::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->where('activa', true)
            ->when($sucursalId !== null, fn (EloquentBuilder $q) => $q->where('sucursal_id', $sucursalId))
            ->when($cajaId !== null, fn (EloquentBuilder $q) => $q->whereKey($cajaId))
            ->with(['sucursal:id,nombre', 'turnoActivo.usuarioApertura:id,name', 'turnoActivo.participantes.usuario:id,name'])
            ->orderBy('sucursal_id')->orderByDesc('es_principal')->orderBy('nombre')
            ->get();

        $ultimos = TurnoCaja::withoutGlobalScopes()
            ->with('usuarioApertura:id,name')
            ->whereIn('id', TurnoCaja::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->where('estado', 'cerrado')
                ->whereIn('caja_id', $cajas->pluck('id'))
                ->groupBy('caja_id')
                ->select(DB::raw('MAX(id)')))
            ->get()->keyBy('caja_id');
        $abiertos = array_values($cajas->map(fn (Caja $caja): ?int => $caja->turnoActivo?->id)->filter()->all());
        $totales = self::totalesTurnos($empresaId, $abiertos);

        return array_values($cajas->map(function (Caja $caja) use ($ultimos, $totales): array {
            $turno = $caja->turnoActivo;
            $suma = $turno !== null ? $totales[$turno->id] : null;

            return [
                'caja' => $caja,
                'turno' => $turno,
                'ultimo' => $turno === null ? $ultimos->get($caja->id) : null,
                'totales' => $suma,
                'esperado' => $turno !== null ? self::esperado($turno, $suma) : null,
            ];
        })->all());
    }

    /**
     * Turnos con actividad en el período: abiertos antes de que termine y no cerrados antes de
     * que empiece (incluye los que siguen abiertos).
     *
     * @return EloquentBuilder<TurnoCaja>
     */
    public static function turnos(int $empresaId, Periodo $periodo, ?int $sucursalId, ?int $cajaId): EloquentBuilder
    {
        [$inicio, $fin] = $periodo->limitesUtc();

        return TurnoCaja::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->where('fecha_apertura', '<', $fin)
            ->where(fn (EloquentBuilder $q) => $q->whereNull('fecha_cierre')->orWhere('fecha_cierre', '>=', $inicio))
            ->when($sucursalId !== null, fn (EloquentBuilder $q) => $q->where('sucursal_id', $sucursalId))
            ->when($cajaId !== null, fn (EloquentBuilder $q) => $q->where('caja_id', $cajaId))
            ->with(['caja:id,nombre,modalidad', 'sucursal:id,nombre', 'usuarioApertura:id,name', 'usuarioCierre:id,name', 'participantes.usuario:id,name'])
            ->orderByDesc('fecha_apertura')
            ->orderByDesc('id');
    }

    /**
     * Turnos del vendedor: los que abrió, en los que participó o en los que registró dinero.
     *
     * @return EloquentBuilder<TurnoCaja>
     */
    public static function turnosDeUsuario(User $usuario): EloquentBuilder
    {
        $id = $usuario->id;

        return TurnoCaja::withoutGlobalScopes()
            ->where('empresa_id', (int) $usuario->empresa_id)
            ->where(fn (EloquentBuilder $q) => $q
                ->where('usuario_apertura_id', $id)
                ->orWhereIn('id', DB::table('turno_usuarios')->where('user_id', $id)->select('turno_caja_id'))
                ->orWhereIn('id', DB::table('movimientos_caja')->where('empresa_id', (int) $usuario->empresa_id)
                    ->where('usuario_id', $id)->whereNotNull('turno_caja_id')->select('turno_caja_id')));
    }

    /**
     * Cierres del período (por fecha de cierre): suma de diferencias y cuántos cuadraron,
     * faltaron o sobraron.
     *
     * @return array{total: string, cierres: int, faltantes: int, sobrantes: int}
     */
    public static function diferencias(int $empresaId, Periodo $periodo, ?int $sucursalId, ?int $cajaId): array
    {
        [$inicio, $fin] = $periodo->limitesUtc();
        $fila = DB::table('turnos_caja')
            ->where('empresa_id', $empresaId)
            ->where('estado', 'cerrado')
            ->where('fecha_cierre', '>=', $inicio)
            ->where('fecha_cierre', '<', $fin)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursalId))
            ->when($cajaId !== null, fn (Builder $q) => $q->where('caja_id', $cajaId))
            ->first([
                DB::raw('COALESCE(SUM(diferencia), 0) AS total'),
                DB::raw('COUNT(*) AS cierres'),
                DB::raw('COALESCE(SUM(CASE WHEN diferencia < 0 THEN 1 ELSE 0 END), 0) AS faltantes'),
                DB::raw('COALESCE(SUM(CASE WHEN diferencia > 0 THEN 1 ELSE 0 END), 0) AS sobrantes'),
            ]);

        return [
            'total' => Decimal::redondear((string) $fila->total),
            'cierres' => (int) $fila->cierres,
            'faltantes' => (int) $fila->faltantes,
            'sobrantes' => (int) $fila->sobrantes,
        ];
    }

    /**
     * Avisos que piden acción: turnos abiertos desde un día anterior (estado actual) y cierres del
     * período con faltante o sobrante.
     *
     * @return array{anteriores: Collection<int, TurnoCaja>, diferencias: Collection<int, TurnoCaja>}
     */
    public static function avisos(int $empresaId, Periodo $periodo, ?int $sucursalId, ?int $cajaId): array
    {
        [$inicio, $fin] = $periodo->limitesUtc();
        $hoy = Periodo::hoy()->limitesUtc()[0];

        return [
            'anteriores' => self::turnosParaAvisos($empresaId, $sucursalId, $cajaId)
                ->where('estado', 'abierto')->where('fecha_apertura', '<', $hoy)
                ->orderBy('fecha_apertura')->limit(5)->get(),
            'diferencias' => self::turnosParaAvisos($empresaId, $sucursalId, $cajaId)
                ->where('estado', 'cerrado')->where('diferencia', '<>', 0)
                ->where('fecha_cierre', '>=', $inicio)->where('fecha_cierre', '<', $fin)
                ->orderBy('diferencia')->limit(5)->get(),
        ];
    }

    /**
     * @return EloquentBuilder<TurnoCaja>
     */
    private static function turnosParaAvisos(int $empresaId, ?int $sucursalId, ?int $cajaId): EloquentBuilder
    {
        return TurnoCaja::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->when($sucursalId !== null, fn (EloquentBuilder $q) => $q->where('sucursal_id', $sucursalId))
            ->when($cajaId !== null, fn (EloquentBuilder $q) => $q->where('caja_id', $cajaId))
            ->with(['caja:id,nombre', 'sucursal:id,nombre', 'usuarioApertura:id,name', 'usuarioCierre:id,name']);
    }

    /**
     * Cobros y salidas por usuario en el período, con sus cajas y el resultado de los turnos que
     * abrió y se cerraron en el período.
     *
     * @return list<object>
     */
    public static function porResponsable(int $empresaId, Periodo $periodo, ?int $sucursalId, ?int $cajaId): array
    {
        [$inicio, $fin] = $periodo->limitesUtc();
        $dinero = DB::table('movimientos_caja as m')
            ->leftJoin('cajas as cj', 'cj.id', '=', 'm.caja_id')
            ->where('m.empresa_id', $empresaId)
            ->where('m.fecha_operacion', '>=', $inicio)
            ->where('m.fecha_operacion', '<', $fin)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('m.sucursal_id', $sucursalId))
            ->when($cajaId !== null, fn (Builder $q) => self::filtrarCaja($q, 'm.caja_id', $cajaId))
            ->groupBy('m.usuario_id')
            ->get([
                'm.usuario_id',
                DB::raw("COUNT(DISTINCT CASE WHEN m.sentido = 'ingreso' THEN CONCAT(m.origen_tipo, ':', m.origen_id) END) AS cobros"),
                DB::raw("SUM(CASE WHEN m.sentido = 'ingreso' AND m.metodo = 'efectivo' THEN m.monto ELSE 0 END) AS efectivo"),
                DB::raw("SUM(CASE WHEN m.sentido = 'ingreso' AND m.metodo = 'electronico' THEN m.monto ELSE 0 END) AS electronico"),
                DB::raw("SUM(CASE WHEN m.sentido = 'ingreso' THEN m.monto ELSE 0 END) AS total"),
                DB::raw("SUM(CASE WHEN m.sentido = 'egreso' THEN m.monto ELSE 0 END) AS salidas"),
                DB::raw("GROUP_CONCAT(DISTINCT cj.nombre ORDER BY cj.nombre SEPARATOR ', ') AS cajas"),
                DB::raw('MAX(m.caja_id IS NULL) AS fuera_de_caja'),
            ])->keyBy('usuario_id');
        $turnos = DB::table('turnos_caja')
            ->where('empresa_id', $empresaId)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursalId))
            ->when($cajaId !== null, fn (Builder $q) => $q->where('caja_id', $cajaId))
            ->where(fn (Builder $q) => $q->where('estado', 'abierto')
                ->orWhere(fn (Builder $q) => $q->where('fecha_cierre', '>=', $inicio)->where('fecha_cierre', '<', $fin)))
            ->groupBy('usuario_apertura_id')
            ->get([
                'usuario_apertura_id',
                DB::raw("SUM(CASE WHEN estado = 'abierto' THEN 1 ELSE 0 END) AS abiertos"),
                DB::raw("SUM(CASE WHEN estado = 'cerrado' THEN 1 ELSE 0 END) AS cierres"),
                DB::raw("COALESCE(SUM(CASE WHEN estado = 'cerrado' THEN diferencia END), 0) AS diferencia"),
            ])->keyBy('usuario_apertura_id');
        $usuarios = DB::table('users')
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $dinero->keys()->merge($turnos->keys())->unique()->all())
            ->orderBy('name')
            ->get(['id', 'name', 'rol']);

        return array_values($usuarios->map(function (object $u) use ($dinero, $turnos): object {
            $d = $dinero->get($u->id);
            $t = $turnos->get($u->id);
            $cajas = array_filter([(string) ($d->cajas ?? ''), ($d->fuera_de_caja ?? 0) ? __('Fuera de caja') : '']);

            return (object) [
                'id' => $u->id,
                'nombre' => $u->name,
                'rol' => $u->rol,
                'cajas' => implode(', ', $cajas),
                'cobros' => (int) ($d->cobros ?? 0),
                'efectivo' => Decimal::redondear((string) ($d->efectivo ?? '0')),
                'electronico' => Decimal::redondear((string) ($d->electronico ?? '0')),
                'total' => Decimal::redondear((string) ($d->total ?? '0')),
                'salidas' => Decimal::redondear((string) ($d->salidas ?? '0')),
                'abiertos' => (int) ($t->abiertos ?? 0),
                'cierres' => (int) ($t->cierres ?? 0),
                'diferencia' => Decimal::redondear((string) ($t->diferencia ?? '0')),
            ];
        })->all());
    }

    /**
     * Entradas, salidas, neto y diferencias por día de negocio, del más reciente al más antiguo.
     *
     * @return list<array{fecha: CarbonImmutable, entradas: string, salidas: string, neto: string, diferencia: string}>
     */
    public static function porDia(int $empresaId, Periodo $periodo, ?int $sucursalId, ?int $cajaId): array
    {
        [$inicio, $fin] = $periodo->limitesUtc();
        // El desfase del día de negocio va como parámetro, no dentro del SQL.
        $desfase = $periodo->desfase();
        $filtros = fn (Builder $q) => $q->where('empresa_id', $empresaId)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursalId))
            ->when($cajaId !== null, fn (Builder $q) => self::filtrarCaja($q, 'caja_id', $cajaId));
        $dinero = $filtros(DB::table('movimientos_caja'))
            ->where('fecha_operacion', '>=', $inicio)->where('fecha_operacion', '<', $fin)
            ->selectRaw("DATE(CONVERT_TZ(fecha_operacion, '+00:00', ?)) AS dia", [$desfase])
            ->selectRaw("SUM(CASE WHEN sentido = 'ingreso' THEN monto ELSE 0 END) AS entradas")
            ->selectRaw("SUM(CASE WHEN sentido = 'egreso' THEN monto ELSE 0 END) AS salidas")
            ->groupBy('dia')
            ->get()
            ->keyBy('dia');
        $diferencias = $filtros(DB::table('turnos_caja'))
            ->where('estado', 'cerrado')
            ->where('fecha_cierre', '>=', $inicio)->where('fecha_cierre', '<', $fin)
            ->selectRaw("DATE(CONVERT_TZ(fecha_cierre, '+00:00', ?)) AS dia", [$desfase])
            ->selectRaw('COALESCE(SUM(diferencia), 0) AS diferencia')
            ->groupBy('dia')
            ->get()
            ->keyBy('dia');

        $filas = [];
        for ($d = $periodo->hasta; $d->greaterThanOrEqualTo($periodo->desde); $d = $d->subDay()) {
            $clave = $d->format('Y-m-d');
            $entradas = Decimal::redondear((string) ($dinero->get($clave)->entradas ?? '0'));
            $salidas = Decimal::redondear((string) ($dinero->get($clave)->salidas ?? '0'));
            $filas[] = [
                'fecha' => $d,
                'entradas' => $entradas,
                'salidas' => $salidas,
                'neto' => Decimal::restar($entradas, $salidas, Decimal::ESCALA_DINERO),
                'diferencia' => Decimal::redondear((string) ($diferencias->get($clave)->diferencia ?? '0')),
            ];
        }

        return $filas;
    }

    /**
     * Horas y minutos desde la apertura, para «abierta hace 7 h 40 min».
     */
    public static function duracion(TurnoCaja $turno): string
    {
        $minutos = (int) $turno->fecha_apertura->diffInMinutes(Carbon::now());

        return intdiv($minutos, 60) > 0
            ? __(':h h :m min', ['h' => intdiv($minutos, 60), 'm' => $minutos % 60])
            : __(':m min', ['m' => $minutos]);
    }
}
