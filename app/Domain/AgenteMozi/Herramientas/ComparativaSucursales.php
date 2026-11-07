<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Periodo;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * get_branch_comparison (UC-16, prevista en UC-10): qué sucursal vendió más. Mismos criterios que
 * Reportes › Ventas (VentasPeriodo): ventas confirmadas por su fecha, devoluciones por la suya y
 * venta neta = vendido − devuelto; el cobrado sale del libro de caja (Cobros). Una sucursal activa
 * sin ventas aparece con 0 y la suma de las sucursales es el total del reporte.
 */
final class ComparativaSucursales extends HerramientaNegocio
{
    public function nombre(): string
    {
        return 'get_branch_comparison';
    }

    public function descripcion(): string
    {
        return 'Comparativa de sucursales del período (por defecto el mes en curso), con los criterios de Reportes › Ventas: valor vendido, devoluciones, venta neta, cantidad de ventas, ticket promedio, '
            .'dinero cobrado (libro de caja), venta neta del período anterior de igual duración, variación y participación. Ordenada por venta neta. Incluye los totales de la empresa.';
    }

    public function parametros(): array
    {
        return ['type' => 'object', 'properties' => $this->propiedadesPeriodo('el primer día del mes en curso'), 'additionalProperties' => false];
    }

    public function reglas(): array
    {
        return $this->reglasPeriodo();
    }

    public function avance(): string
    {
        return __('Comparando las sucursales…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $periodo = $this->periodo($contexto, $argumentos, 0);
        $anterior = $this->anterior($periodo);
        $ventas = $this->ventas($empresaId, $periodo);
        $devueltas = $this->devoluciones($empresaId, $periodo);
        $ventasAntes = $this->ventas($empresaId, $anterior);
        $devueltasAntes = $this->devoluciones($empresaId, $anterior);
        $cobrado = collect(Cobros::porSucursal($empresaId, $periodo))->keyBy('id');
        $filas = [];
        $total = ['valor_vendido' => '0.00', 'devoluciones' => '0.00', 'venta_neta' => '0.00', 'ventas' => 0, 'cobrado' => '0.00', 'venta_neta_anterior' => '0.00'];

        foreach (DB::table('sucursales')->where('empresa_id', $empresaId)->orderBy('nombre')->get(['id', 'nombre', 'activa']) as $sucursal) {
            $id = $sucursal->id;
            $valor = Decimal::redondear((string) ($ventas->get($id)->valor ?? '0'));
            $devuelto = Decimal::redondear((string) ($devueltas->get($id)->importe ?? '0'));
            $antes = Decimal::restar(Decimal::redondear((string) ($ventasAntes->get($id)->valor ?? '0')), Decimal::redondear((string) ($devueltasAntes->get($id)->importe ?? '0')), 2);
            $cantidad = (int) ($ventas->get($id)->ventas ?? 0);
            $cobro = Decimal::redondear((string) ($cobrado->get($id)->total ?? '0'));

            if (! $sucursal->activa && $cantidad === 0 && $this->cero($devuelto) && $this->cero($cobro) && $this->cero($antes)) {
                continue;
            }

            $neta = Decimal::restar($valor, $devuelto, 2);
            $filas[] = [
                'sucursal' => $sucursal->nombre, 'valor_vendido' => $valor, 'devoluciones' => $devuelto, 'venta_neta' => $neta, 'ventas' => $cantidad,
                'ticket_promedio' => $cantidad === 0 ? null : Decimal::redondear(Decimal::dividir($valor, (string) $cantidad, 4)),
                'cobrado' => $cobro, 'venta_neta_anterior' => $antes, 'variacion_pct' => $this->variacion($neta, $antes),
            ];

            foreach (['valor_vendido' => $valor, 'devoluciones' => $devuelto, 'venta_neta' => $neta, 'cobrado' => $cobro, 'venta_neta_anterior' => $antes] as $clave => $importe) {
                $total[$clave] = Decimal::sumar($total[$clave], $importe, 2);
            }

            $total['ventas'] += $cantidad;
        }

        usort($filas, fn (array $a, array $b) => Decimal::comparar($b['venta_neta'], $a['venta_neta'], 2) ?: strcmp($a['sucursal'], $b['sucursal']));
        $filas = array_map(fn (array $f) => $f + ['participacion_pct' => $this->porcentaje($f['venta_neta'], $total['venta_neta'])], $filas);
        $meta = $this->meta($contexto, 'reportes_ventas', $periodo, metodo: 'Reportes › Ventas por sucursal: ventas confirmadas y devoluciones por su fecha; cobrado desde el libro de caja. Período anterior: '
            .$anterior->desde->format('Y-m-d').' a '.$anterior->hasta->format('Y-m-d').'.');
        $dinero = fn (string $c, string $l) => $this->col($c, $l, 'money');
        $columnas = [
            $dinero('valor_vendido', __('Valor vendido')), $dinero('devoluciones', __('Devoluciones')), $dinero('venta_neta', __('Venta neta')),
            $this->col('ventas', __('Ventas'), 'integer'), $dinero('cobrado', __('Cobrado')), $dinero('venta_neta_anterior', __('Venta neta anterior')),
            $this->col('variacion_pct', __('Variación'), 'percent'),
        ];

        return [
            $this->dataset('sucursales', [
                $this->col('sucursal', __('Sucursal'), 'string'), ...$columnas,
                $dinero('ticket_promedio', __('Ticket promedio')), $this->col('participacion_pct', __('Participación'), 'percent'),
            ], $filas, $meta, 'Una fila por sucursal, de mayor a menor venta neta. participacion_pct = parte de la venta neta de la empresa; variacion_pct frente al período anterior (vacía si antes fue 0).'),
            $this->dataset('totales', [...$columnas, $this->col('sucursales', __('Sucursales'), 'integer')],
                [$total + ['variacion_pct' => $this->variacion($total['venta_neta'], $total['venta_neta_anterior']), 'sucursales' => count($filas)]],
                $meta, 'Totales de la empresa (una fila): coinciden con Reportes › Ventas del mismo período.'),
        ];
    }

    /**
     * @return Collection<int|string, \stdClass>
     */
    private function ventas(int $empresaId, Periodo $periodo): Collection
    {
        [$inicio, $fin] = $periodo->limitesUtc();

        return DB::table('ventas')->where('empresa_id', $empresaId)->where('fecha', '>=', $inicio)->where('fecha', '<', $fin)
            ->groupBy('sucursal_id')
            ->get(['sucursal_id', DB::raw('SUM(total) AS valor'), DB::raw('COUNT(*) AS ventas')])
            ->keyBy('sucursal_id');
    }

    /**
     * @return Collection<int|string, \stdClass>
     */
    private function devoluciones(int $empresaId, Periodo $periodo): Collection
    {
        [$inicio, $fin] = $periodo->limitesUtc();

        return DB::table('devoluciones')->where('empresa_id', $empresaId)->where('fecha', '>=', $inicio)->where('fecha', '<', $fin)
            ->groupBy('sucursal_id')
            ->get(['sucursal_id', DB::raw('SUM(importe) AS importe')])
            ->keyBy('sucursal_id');
    }

    private function cero(string $importe): bool
    {
        return Decimal::comparar($importe, '0', 2) === 0;
    }
}
