<?php

use App\Domain\Ventas\CalculadoraPedido;
use Illuminate\Validation\ValidationException;

function linea(string $cantidad, string $precio, string $tipo = 'bs', string $valor = '0'): array
{
    return ['cantidad' => $cantidad, 'precio_unitario' => $precio, 'descuento_tipo' => $tipo, 'descuento_valor' => $valor];
}

test('T10 line and global discounts in Bs and % with explicit rounding', function () {
    $r = CalculadoraPedido::calcular([
        linea('2.000', '13.00', 'bs', '2.00'),     // 26,00 − 2,00 = 24,00
        linea('1.000', '24.00'),                   // 24,00
        linea('3.000', '11.10', 'pct', '7.50'),    // 33,30 − 2,4975→2,50 = 30,80
    ], 'pct', '5.00');                             // base 78,80 · 5 % = 3,94

    expect($r['subtotal'])->toBe('83.30')
        ->and($r['descuento_lineas'])->toBe('4.50')
        ->and($r['descuento_global'])->toBe('3.94')
        ->and($r['total'])->toBe('74.86')
        ->and($r['lineas'][2])->toBe(['subtotal' => '33.30', 'descuento' => '2.50', 'total' => '30.80']);
});

test('quantities with three decimals round the line to cents', function () {
    $r = CalculadoraPedido::calcular([linea('1.255', '8.50')], 'bs', '0');   // 10,6675 → 10,67

    expect($r['total'])->toBe('10.67');
});

test('T10 discounts can never make a total negative', function (array $lineas, string $tipo, string $valor) {
    expect(fn () => CalculadoraPedido::calcular($lineas, $tipo, $valor))->toThrow(ValidationException::class);
})->with([
    'línea en Bs mayor al importe' => [[linea('1.000', '10.00', 'bs', '10.01')], 'bs', '0'],
    'línea con más de 100 %' => [[linea('1.000', '10.00', 'pct', '100.01')], 'bs', '0'],
    'global en Bs mayor a la base' => [[linea('1.000', '10.00', 'bs', '4.00')], 'bs', '6.01'],
    'global con más de 100 %' => [[linea('1.000', '10.00')], 'pct', '101'],
    'descuento negativo' => [[linea('1.000', '10.00', 'bs', '-1')], 'bs', '0'],
    'tipo desconocido' => [[linea('1.000', '10.00', 'xx', '1')], 'bs', '0'],
]);

test('a full discount leaves a total of zero, not negative', function () {
    $r = CalculadoraPedido::calcular([linea('1.000', '10.00', 'pct', '100')], 'bs', '0');

    expect($r['total'])->toBe('0.00');
});
