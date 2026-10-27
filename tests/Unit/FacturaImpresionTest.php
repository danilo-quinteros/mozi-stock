<?php

use App\Support\NumeroEnLetras;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Factura\EnlaceQr;

test('el monto a pagar se escribe en letras con los centavos', function (string $monto, string $letras) {
    expect(NumeroEnLetras::bolivianos($monto))->toBe($letras);
})->with([
    ['113.00', 'Ciento trece 00/100 Bolivianos'],
    ['0.50', 'Cero 50/100 Bolivianos'],
    ['21.00', 'Veintiuno 00/100 Bolivianos'],
    ['100.00', 'Cien 00/100 Bolivianos'],
    ['1001.05', 'Mil uno 05/100 Bolivianos'],
    ['21000.00', 'Veintiún mil 00/100 Bolivianos'],
    ['1000000.99', 'Un millón 99/100 Bolivianos'],
    ['2500000.10', 'Dos millones quinientos mil 10/100 Bolivianos'],
]);

test('el enlace del QR lleva nit, cuf, número y tamaño a la consulta del SIN', function () {
    expect(EnlaceQr::url(EnlaceQr::URL_PILOTO, 1023456029, 'ABC123', 7, EnlaceQr::ROLLO))
        ->toBe('https://pilotosiat.impuestos.gob.bo/consulta/QR?nit=1023456029&cuf=ABC123&numero=7&t=1')
        ->and(EnlaceQr::url(EnlaceQr::URL_PILOTO, 1023456029, 'ABC123', 7, EnlaceQr::MEDIA_PAGINA))->toEndWith('&t=2');
});

test('el enlace del QR rechaza una base insegura o un CUF con otros caracteres', function (string $base, string $cuf) {
    expect(fn () => EnlaceQr::url($base, 1023456029, $cuf, 1))->toThrow(DatosInvalidos::class);
})->with([
    'http' => ['http://pilotosiat.impuestos.gob.bo/consulta/QR', 'ABC'],
    'con query' => ['https://pilotosiat.impuestos.gob.bo/consulta/QR?x=1', 'ABC'],
    'cuf inválido' => [EnlaceQr::URL_PILOTO, 'AB&C'],
]);
