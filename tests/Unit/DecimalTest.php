<?php

use App\Support\Decimal;

test('normalizes quantities and money without float', function () {
    expect(Decimal::normalizar('2,5', 3))->toBe('2.500')
        ->and(Decimal::normalizar('0.1', 2))->toBe('0.10')
        ->and(Decimal::normalizar('1.234', 2))->toBeNull()
        ->and(Decimal::normalizar('1.000,50', 2))->toBeNull()
        ->and(Decimal::normalizar('abc', 3))->toBeNull()
        ->and(Decimal::sumar('0.1', '0.2'))->toBe('0.300')
        ->and(Decimal::restar('1.000', '1.001'))->toBe('-0.001');
});

test('formats in bolivian style', function () {
    expect(Decimal::formatear('1234567.500'))->toBe('1.234.567,5')
        ->and(Decimal::formatear('12.000'))->toBe('12')
        ->and(Decimal::bs('11.1'))->toBe('Bs 11,10')
        ->and(Decimal::bs('1500'))->toBe('Bs 1.500,00')
        ->and(Decimal::simple('1000.000'))->toBe('1000')
        ->and(Decimal::simple('2.500'))->toBe('2.5');
});
