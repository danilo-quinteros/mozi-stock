<?php

use App\Domain\Catalogo\Importacion\CamposImportacion;
use App\Domain\Catalogo\Importacion\NumeroImportado;

test('reads Bolivian and English number formats without float', function (string $texto, ?string $esperado, ?string $motivo) {
    expect(NumeroImportado::leer($texto, false))->toBe([$esperado, $motivo]);
})->with([
    'integer' => ['15', '15', null],
    'decimal comma' => ['12,50', '12.50', null],
    'decimal point' => ['12.5', '12.5', null],
    'Bolivian thousands' => ['1.234,50', '1234.50', null],
    'English thousands' => ['1,234.50', '1234.50', null],
    'only thousands' => ['1.234.567', '1234567', null],
    'currency prefix' => ['Bs 13,00', '13.00', null],
    'currency suffix' => ['13,00 Bs.', '13.00', null],
    'leading zero is decimal' => ['0,250', '0.250', null],
    'four integer digits are decimal' => ['1234.500', '1234.500', null],
    'negative' => ['-3,5', '-3.5', null],
    'ambiguous point' => ['1.500', null, NumeroImportado::AMBIGUO],
    'ambiguous comma' => ['12,500', null, NumeroImportado::AMBIGUO],
    'scientific' => ['7,79E+12', null, NumeroImportado::CIENTIFICO],
    'text' => ['doce', null, NumeroImportado::INVALIDO],
    'bad grouping' => ['1.23.4', null, NumeroImportado::INVALIDO],
    'two decimal separators' => ['1,2,3.4,5', null, NumeroImportado::INVALIDO],
    'empty' => ['  ', null, NumeroImportado::VACIO],
]);

test('values typed as numbers in the sheet are already canonical', function () {
    expect(NumeroImportado::leer('1.5', true))->toBe(['1.5', null])
        ->and(NumeroImportado::leer('1500', true))->toBe(['1500', null])
        ->and(NumeroImportado::leer('1,5', true))->toBe([null, NumeroImportado::INVALIDO]);
});

test('column headers are detected by synonyms, most specific first', function () {
    $encabezados = ['Cód.', 'Descripción', 'Código de barras', 'Precio de venta Bs', 'P. Mayorista', 'Stock mínimo', 'Existencias', 'Costo'];

    expect(CamposImportacion::detectar($encabezados))->toEqual([
        'sku' => 0,
        'codigo_barras' => 2,
        'precio_consumidor' => 3,
        'precio_mayorista' => 4,
        'stock_minimo' => 5,
        'stock' => 6,
        'nombre' => 1,
    ]);
});
