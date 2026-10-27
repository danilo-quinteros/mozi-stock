<?php

use App\Domain\Facturacion\Montos\CalculadoraFiscal;
use App\Domain\Facturacion\Montos\LineaVenta;
use App\Domain\Facturacion\Montos\ResultadoFiscal;
use Tests\TestCase;

/*
 * Calculadora fiscal (decisión D1, precios con IVA incluido). Casos del piloto del SIN del 24/10/2026:
 * Bs 113,00 y 1,00 + 5,00 + 2,50 validadas (908); Bs 1,00 y 100,00 imposibles de facturar exactas.
 */

uses(TestCase::class);

function lineaVenta(string $cantidad, string $precio, string $descuento = '0.00'): LineaVenta
{
    $bruto = bcadd(bcmul($cantidad, $precio, 6), '0.005', 2);

    return new LineaVenta($cantidad, $precio, $descuento, bcsub($bruto, $descuento, 2));
}

/** Comprueba con bcmath las fórmulas oficiales y que el cliente paga exactamente $total. */
function cumpleFormulasSin(ResultadoFiscal $resultado, string $total): void
{
    $suma = '0.00';

    foreach ($resultado->lineas as $linea) {
        $bruto = bcsub(bcmul($linea->cantidad, $linea->precioNetoUnitario, 4), $linea->montoDescuento ?? '0', 4);
        expect($linea->subTotal)->toBe(bcadd($bruto, '0.005', 2));
        $suma = bcadd($suma, $linea->subTotal, 2);
    }

    $neto = bcsub($suma, $resultado->descuentoAdicional ?? '0', 2);

    expect($resultado->cuadra)->toBeTrue()
        ->and($resultado->subTotalNeto)->toBe($suma)
        ->and($resultado->totalPrecioNeto)->toBe($neto)
        ->and($resultado->iva)->toBe(bcadd(bcmul($neto, '0.13', 4), '0.005', 2))
        ->and($resultado->montoaPagar)->toBe(bcadd($neto, $resultado->iva, 2))
        ->and($resultado->montoaPagar)->toBe($total);
}

test('a total with an exact net is invoiced exactly (pilot control, Bs 113.00 validated)', function () {
    $resultado = CalculadoraFiscal::calcular([lineaVenta('1', '113.00')], '0.00', '113.00');

    cumpleFormulasSin($resultado, '113.00');
    expect($resultado->totalPrecioNeto)->toBe('100.00')
        ->and($resultado->iva)->toBe('13.00')
        ->and($resultado->lineas[0]->montoDescuento)->toBeNull()
        ->and($resultado->descuentoAdicional)->toBeNull();
});

test('Bs 1.00 and Bs 100.00 cannot be invoiced exactly: the invoice is blocked and the neighbours are reported', function (string $total, string $inferior, string $superior) {
    $resultado = CalculadoraFiscal::calcular([lineaVenta('1', $total)], '0.00', $total);

    expect($resultado->cuadra)->toBeFalse()
        ->and($resultado->totalInferior)->toBe($inferior)
        ->and($resultado->totalSuperior)->toBe($superior)
        ->and($resultado->montoaPagar)->toBe($total)
        ->and($resultado->lineas)->toBe([])
        ->and($resultado->motivo)->toContain($inferior)->toContain($superior)
        ->and(CalculadoraFiscal::totalesVecinos($total))->toBe([$inferior, $superior]);
})->with([
    'Bs 1,00' => ['1.00', '0.99', '1.01'],
    'Bs 100,00' => ['100.00', '99.99', '100.01'],
    'dos de Bs 50,00 dan 100,00' => ['100.00', '99.99', '100.01'],
]);

test('several lines are split by rounding each net up or down, without inventing discounts (pilot 8.50 validated)', function () {
    $resultado = CalculadoraFiscal::calcular([lineaVenta('1', '1.00'), lineaVenta('1', '5.00'), lineaVenta('1', '2.50')], '0.00', '8.50');

    cumpleFormulasSin($resultado, '8.50');
    expect($resultado->totalPrecioNeto)->toBe('7.52')
        ->and($resultado->iva)->toBe('0.98')
        ->and(array_map(fn ($l) => $l->montoDescuento, $resultado->lineas))->toBe([null, null, null]);
});

test('real line and global discounts become net discounts and the customer pays the same total', function () {
    // 3 × 10,00 con Bs 1,50 de descuento de línea, más 2 × 4,50 y un descuento global de Bs 2,00.
    $lineas = [lineaVenta('3', '10.00', '1.50'), lineaVenta('2', '4.50')];
    $total = bcsub(bcadd($lineas[0]->total, $lineas[1]->total, 2), '2.00', 2);
    $resultado = CalculadoraFiscal::calcular($lineas, '2.00', $total);

    expect(CalculadoraFiscal::totalesVecinos($total))->toBeNull();
    cumpleFormulasSin($resultado, $total);

    foreach ($resultado->lineas as $linea) {
        expect($linea->montoDescuento === null)->toBe($linea->indiceVenta === 1);
    }

    expect($resultado->descuentoAdicional)->not->toBeNull();
});

test('a line with several units may be split into two lines of the same product whose nets differ by 0.01', function () {
    // 3 × Bs 7,00 = Bs 21,00 es facturable (neto 18,58), pero 18,58 no es múltiplo de 3: sin dividir haría falta un descuento.
    $resultado = CalculadoraFiscal::calcular([lineaVenta('3', '7.00')], '0.00', '21.00');

    cumpleFormulasSin($resultado, '21.00');
    expect($resultado->totalPrecioNeto)->toBe('18.58')
        ->and(array_map(fn ($l) => [$l->indiceVenta, $l->cantidad, $l->precioNetoUnitario, $l->montoDescuento], $resultado->lineas))
        ->toBe([[0, '2.00', '6.19', null], [0, '1.00', '6.20', null]]);
});

/** Oráculo por fuerza bruta (cantidades enteras): ¿algún neto hacia abajo o arriba por línea, sin dividir, da $neto? */
function existeCombinacionEntera(array $lineas, string $neto): bool
{
    $sumas = [0];

    foreach ($lineas as $linea) {
        $precio = (int) bcmul($linea->precioUnitario, '100', 0);
        $base = intdiv($precio * 100, 113);
        $opciones = $precio * 100 === $base * 113 ? [$base] : [$base, $base + 1];
        $sumas = array_merge(...array_map(fn (int $s) => array_map(fn (int $u) => $s + (int) $linea->cantidad * $u, $opciones), $sumas));
    }

    return in_array((int) bcmul($neto, '100', 0), $sumas, true);
}

test('a line is split only when no combination of whole lines (one net each) balances the total', function () {
    mt_srand(4242);
    $divididas = 0;

    for ($venta = 0; $venta < 300; $venta++) {
        $lineas = [];

        for ($i = 0, $n = mt_rand(1, 3); $i < $n; $i++) {
            $lineas[] = lineaVenta((string) mt_rand(1, 4), bcdiv((string) mt_rand(1, 3000), '10', 2));
        }

        $total = array_reduce($lineas, fn (string $s, LineaVenta $l) => bcadd($s, $l->total, 2), '0.00');

        if (CalculadoraFiscal::totalesVecinos($total) !== null) {
            continue;
        }

        $resultado = CalculadoraFiscal::calcular($lineas, '0.00', $total);
        cumpleFormulasSin($resultado, $total);
        $dividida = count($resultado->lineas) > count($lineas);
        $divididas += (int) $dividida;

        expect($dividida)->toBe(! existeCombinacionEntera($lineas, $resultado->totalPrecioNeto));
    }

    // La muestra debe ejercitar los dos caminos.
    expect($divididas)->toBeGreaterThan(0)->toBeLessThan(300);
});

test('a fractional quantity takes its unit net from the line amount, not from the price (1.10 × 5.04 = 5.54)', function () {
    // Con 4,46 (precio ÷ 1,13) el subtotal sería 4,91 y el neto exacto es 4,90: el neto unitario sale de 5,54 ÷ 1,13 ÷ 1,10.
    $resultado = CalculadoraFiscal::calcular([lineaVenta('1.10', '5.04')], '0.00', '5.54');

    cumpleFormulasSin($resultado, '5.54');
    expect(array_map(fn ($l) => [$l->cantidad, $l->precioNetoUnitario, $l->subTotal], $resultado->lineas))
        ->toBe([['1.10', '4.45', '4.90']]);
});

test('a quantity with three decimals blocks the invoice (decision D5)', function () {
    $resultado = CalculadoraFiscal::calcular([lineaVenta('1.255', '10.00')], '0.00', '12.55');

    expect($resultado->cuadra)->toBeFalse()
        ->and($resultado->motivo)->toContain('2 decimales');
});

test('every total from Bs 0.01 to Bs 60.00 is either invoiced exactly or blocked, never adjusted', function () {
    for ($centavos = 1; $centavos <= 6000; $centavos++) {
        $total = bcdiv((string) $centavos, '100', 2);
        $resultado = CalculadoraFiscal::calcular([lineaVenta('1', $total)], '0.00', $total);

        if (CalculadoraFiscal::totalesVecinos($total) === null) {
            cumpleFormulasSin($resultado, $total);
        } else {
            expect($resultado->cuadra)->toBeFalse();
        }
    }
});

test('mixed sales with quantities and discounts always meet the SIN formulas when the total is invoiceable', function () {
    mt_srand(20261025);

    for ($venta = 0; $venta < 400; $venta++) {
        $lineas = [];

        for ($i = 0, $n = mt_rand(1, 6); $i < $n; $i++) {
            $cantidad = bcdiv((string) mt_rand(1, 2500), mt_rand(0, 1) === 1 ? '100' : '1', 2);
            $precio = bcdiv((string) mt_rand(1, 50000), '100', 2);
            $bruto = bcadd(bcmul($cantidad, $precio, 6), '0.005', 2);
            $descuento = mt_rand(0, 3) === 0 ? bcdiv(bcmul($bruto, (string) mt_rand(1, 30), 4), '100', 2) : '0.00';
            $lineas[] = lineaVenta($cantidad, $precio, $descuento);
        }

        $base = array_reduce($lineas, fn (string $s, LineaVenta $l) => bcadd($s, $l->total, 2), '0.00');
        $global = mt_rand(0, 3) === 0 ? bcdiv(bcmul($base, (string) mt_rand(1, 20), 4), '100', 2) : '0.00';
        $total = bcsub($base, $global, 2);
        $resultado = CalculadoraFiscal::calcular($lineas, $global, $total);

        if (CalculadoraFiscal::totalesVecinos($total) !== null) {
            expect($resultado->cuadra)->toBeFalse();

            continue;
        }

        // Un total facturable siempre se factura exacto: nunca se bloquea por el reparto entre líneas.
        cumpleFormulasSin($resultado, $total);
        expect($resultado->descuentoAdicional === null)->toBe(bccomp($global, '0', 2) === 0)
            ->and(count($resultado->lineas))->toBeLessThanOrEqual(2 * count($lineas));

        foreach ($resultado->lineas as $linea) {
            expect($linea->montoDescuento === null)->toBe(bccomp($lineas[$linea->indiceVenta]->descuento, '0', 2) === 0);
        }
    }
});
