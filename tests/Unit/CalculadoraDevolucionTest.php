<?php

use App\Domain\Ventas\CalculadoraDevolucion;
use App\Support\Decimal;

/*
 * Devolución a precio neto (decisión del propietario, 23/10/2026): descuentos de línea ya
 * incluidos y descuento global prorrateado, sin float y sin perder ni ganar céntimos.
 */

function sumaDe(array $importes): string
{
    return array_reduce($importes, fn (string $s, string $i) => Decimal::sumar($s, $i, 2), '0.00');
}

test('without global discount each line keeps its own total', function () {
    expect(CalculadoraDevolucion::netosPorLinea([1 => '40.00', 2 => '160.00'], '0.00'))
        ->toBe([1 => '40.00', 2 => '160.00']);
});

test('the global discount is prorated and the nets add up exactly to the sale total', function () {
    // 3 líneas de Bs 10,00 con Bs 10,00 de descuento global: 3,33 + 3,33 + 3,34.
    $netos = CalculadoraDevolucion::netosPorLinea([1 => '10.00', 2 => '10.00', 3 => '10.00'], '10.00');

    expect($netos)->toBe([1 => '6.67', 2 => '6.67', 3 => '6.66'])
        ->and(sumaDe($netos))->toBe('20.00');
});

test('no net is ever negative even with tiny lines and a discount equal to the subtotal', function () {
    $netos = CalculadoraDevolucion::netosPorLinea([1 => '0.01', 2 => '99.99', 3 => '0.01'], '100.01');

    expect(sumaDe($netos))->toBe('0.00');
    foreach ($netos as $neto) {
        expect(Decimal::comparar($neto, '0', 2))->toBeGreaterThanOrEqual(0);
    }

    $libres = CalculadoraDevolucion::netosPorLinea([1 => '0.00', 2 => '5.00'], '1.00');
    expect($libres)->toBe([1 => '0.00', 2 => '4.00']);
});

test('partial returns use cumulative rounding and a full return equals the net', function () {
    // Línea de 3 unidades con neto Bs 10,00: devolver de una en una da 3,33 + 3,34 + 3,33.
    $primera = CalculadoraDevolucion::importe('10.00', '3.000', '0', '1.000');
    $segunda = CalculadoraDevolucion::importe('10.00', '3.000', '1.000', '1.000');
    $tercera = CalculadoraDevolucion::importe('10.00', '3.000', '2.000', '1.000');

    expect([$primera, $segunda, $tercera])->toBe(['3.33', '3.34', '3.33'])
        ->and(sumaDe([$primera, $segunda, $tercera]))->toBe('10.00')
        ->and(CalculadoraDevolucion::importe('10.00', '3.000', '0', '3.000'))->toBe('10.00');
});

test('fractional quantities are prorated to the cent', function () {
    // 2,5 kg por Bs 27,50 (neto); devolver 0,75 kg = Bs 8,25.
    expect(CalculadoraDevolucion::importe('27.50', '2.500', '0', '0.750'))->toBe('8.25')
        ->and(CalculadoraDevolucion::importe('27.50', '2.500', '0.750', '1.750'))->toBe('19.25');
});
