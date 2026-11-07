<?php

namespace App\Domain\Creditos\Consultas;

use App\Models\Venta;
use App\Support\Decimal;
use Carbon\CarbonInterface;

/**
 * Historial único de pagos de una venta (§ 7): cuota inicial y abonos anteriores (pagos_venta)
 * más lo aplicado de cada cobro (cobro_aplicaciones), en orden de fecha. Cada fila es un pago
 * distinto: el mismo dinero nunca figura en las dos fuentes. Un cobro anulado sigue en su fecha
 * con «anulado» (quién, cuándo y por qué) y no suma en los totales. Lo usan la pantalla del
 * crédito, la impresión, el CSV y el detalle de la venta.
 */
final class HistorialPagos
{
    /**
     * @return list<array{fecha: CarbonInterface, concepto: string, metodo: string, mixto: bool, sucursal: string, receptor: string, nota: string, efectivo: string, electronico: string, monto: string, cobro_id: int|null, comprobante: bool, anulado: string|null}>
     */
    public static function de(Venta $venta): array
    {
        $venta->loadMissing([
            'pagos' => fn ($q) => $q->with(['sucursal:id,nombre', 'receptor:id,name']),
            'aplicaciones' => fn ($q) => $q->with(['cuota:id,numero', 'cobro' => fn ($q) => $q->with(['sucursal:id,nombre', 'receptor:id,name', 'anuladoPor:id,name'])]),
        ]);
        $filas = [];

        foreach ($venta->pagos as $pago) {
            $filas[] = [
                'fecha' => $pago->fecha_cobro, 'concepto' => $pago->tipo === 'inicial' ? __('Cuota inicial') : __('Abono'),
                'metodo' => $pago->metodo->etiqueta(), 'mixto' => $pago->metodo->value === 'mixto',
                'sucursal' => $pago->sucursal->nombre, 'receptor' => $pago->receptor->name, 'nota' => (string) $pago->nota,
                'efectivo' => $pago->monto_efectivo, 'electronico' => $pago->monto_electronico, 'monto' => $pago->monto, 'cobro_id' => null,
                'comprobante' => false, 'anulado' => null,
            ];
        }

        foreach ($venta->aplicaciones as $aplicacion) {
            $cobro = $aplicacion->cobro;
            $filas[] = [
                'fecha' => $cobro->fecha_cobro,
                'concepto' => __('Cobro :codigo', ['codigo' => $cobro->codigo()]).($aplicacion->cuota ? ' · '.__('cuota :numero', ['numero' => $aplicacion->cuota->numero]) : ''),
                'metodo' => $cobro->metodo->etiqueta(), 'mixto' => $cobro->metodo->value === 'mixto',
                'sucursal' => $cobro->sucursal->nombre, 'receptor' => $cobro->receptor->name, 'nota' => ltrim(($cobro->externo ? ' · '.__('fuera de caja') : '').($cobro->referencia ? ' · '.$cobro->referencia : '').($cobro->nota ? ' · '.$cobro->nota : ''), ' ·'),
                'efectivo' => $aplicacion->monto_efectivo, 'electronico' => $aplicacion->monto_electronico, 'monto' => $aplicacion->monto,
                'cobro_id' => $cobro->id, 'comprobante' => $cobro->comprobante_path !== null, 'anulado' => $cobro->descripcionAnulacion(),
            ];
        }

        usort($filas, fn (array $a, array $b) => $a['fecha'] <=> $b['fecha']);

        return $filas;
    }

    /**
     * Suma de un medio ('efectivo', 'electronico' o 'monto') sin los cobros anulados.
     *
     * @param  list<array{efectivo: string, electronico: string, monto: string, anulado: string|null}>  $filas
     */
    public static function total(array $filas, string $campo): string
    {
        return array_reduce($filas, fn (string $suma, array $fila) => $fila['anulado'] === null
            ? Decimal::sumar($suma, $fila[$campo], Decimal::ESCALA_DINERO) : $suma, '0.00');
    }
}
