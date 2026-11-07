<?php

use App\Domain\CatalogoExterno\InterpretePresentacion;
use App\Models\UnidadMedida;

test('interpreta gramos correctamente sin convertir unidades mayores', function (string $presentacion, string $esperadoContenido, string $esperadaUnidad) {
    $res = InterpretePresentacion::interpretar($presentacion, 'Shampoo');

    expect($res['contenido_simple'])->toBe($esperadoContenido)
        ->and($res['unidad_abreviatura'])->toBe($esperadaUnidad)
        ->and($res['tipo_venta'])->toBe('unidad')
        ->and($res['interpretada'])->toBeTrue()
        ->and($res['nombre_mostrado'])->toBe('Shampoo '.$esperadoContenido.' '.$esperadaUnidad);
})->with([
    ['350gr', '350', 'g'],
    ['350 gr', '350', 'g'],
    ['350g', '350', 'g'],
    ['350grs', '350', 'g'],
    ['500gr', '500', 'g'],
    ['1000gr', '1000', 'g'], // Se conserva en gramos como solicitó el usuario
]);

test('interpreta kilogramos, mililitros y litros', function (string $presentacion, string $esperadoContenido, string $esperadaUnidad) {
    $res = InterpretePresentacion::interpretar($presentacion, 'Detergente');

    expect($res['contenido_simple'])->toBe($esperadoContenido)
        ->and($res['unidad_abreviatura'])->toBe($esperadaUnidad)
        ->and($res['tipo_venta'])->toBe('unidad')
        ->and($res['interpretada'])->toBeTrue();
})->with([
    ['1kg', '1', 'kg'],
    ['1.5kg', '1.5', 'kg'],
    ['1,5 kg', '1.5', 'kg'],
    ['500ml', '500', 'ml'],
    ['500cc', '500', 'ml'],
    ['1lt', '1', 'L'],
    ['1 L', '1', 'L'],
    ['2 litros', '2', 'L'],
]);

test('limpia sufijos redundantes en el nombre original', function () {
    $res1 = InterpretePresentacion::interpretar('350gr', 'Shampoo Anticaspa 350gr');
    expect($res1['nombre_limpio'])->toBe('Shampoo Anticaspa')
        ->and($res1['nombre_mostrado'])->toBe('Shampoo Anticaspa 350 g');

    $res2 = InterpretePresentacion::interpretar('500ml', 'Limpiador Multiuso - 500 ml');
    expect($res2['nombre_limpio'])->toBe('Limpiador Multiuso')
        ->and($res2['nombre_mostrado'])->toBe('Limpiador Multiuso 500 ml');
});

test('maneja presentaciones no reconocidas de forma segura', function () {
    $res = InterpretePresentacion::interpretar('Caja Especial x12', 'Producto X');
    expect($res['contenido'])->toBeNull()
        ->and($res['contenido_simple'])->toBeNull()
        ->and($res['unidad_abreviatura'])->toBe('und.')
        ->and($res['tipo_venta'])->toBe('unidad')
        ->and($res['interpretada'])->toBeFalse()
        ->and($res['nombre_mostrado'])->toBe('Producto X');
});

test('interpreta productos a granel por peso o medida como Litros o Kg', function (string $presentacion, string $esperadaUnidad) {
    $res = InterpretePresentacion::interpretar($presentacion, 'Lava Vajilla');

    expect($res['contenido'])->toBeNull()
        ->and($res['contenido_simple'])->toBeNull()
        ->and($res['unidad_abreviatura'])->toBe($esperadaUnidad)
        ->and($res['tipo_venta'])->toBe('medida')
        ->and($res['interpretada'])->toBeTrue()
        ->and($res['nombre_mostrado'])->toBe('Lava Vajilla');
})->with([
    ['Litros', 'L'],
    ['Litro', 'L'],
    ['lt', 'L'],
    ['Kg.', 'kg'],
    ['Kilos', 'kg'],
    ['Gramos', 'g'],
    ['Metros', 'm'],
]);

test('interpreta producto a granel mediante descripcion si la presentacion es vacia', function () {
    $res = InterpretePresentacion::interpretar(null, 'Lava Vajilla', 'Vajillero Agranel en litros');

    expect($res['tipo_venta'])->toBe('medida')
        ->and($res['unidad_abreviatura'])->toBe('L')
        ->and($res['unidad_medida_sin'])->toBe(28)
        ->and($res['interpretada'])->toBeTrue();
});

test('asigna codigos oficiales SIAT a las unidades de medida', function (string $presentacion, int $esperadoSiat) {
    $res = InterpretePresentacion::interpretar($presentacion, 'Producto');

    expect($res['unidad_medida_sin'])->toBe($esperadoSiat);
})->with([
    ['Litros', 28],      // LITRO
    ['1lt', 28],         // LITRO
    ['4.5L', 28],        // LITRO
    ['Kg.', 22],         // KILOGRAMO
    ['1kg', 22],         // KILOGRAMO
    ['500ml', 34],       // MILILITRO
    ['350gr', 17],       // GRAMO
    ['Unidad', 57],      // UNIDAD (BIENES)
    ['Caja', 6],         // CAJA
]);

test('UnidadMedida::resolverCodigoSin mapea nombres texto y codigos', function () {
    expect(UnidadMedida::resolverCodigoSin('LITRO'))->toBe(28)
        ->and(UnidadMedida::resolverCodigoSin('Litros'))->toBe(28)
        ->and(UnidadMedida::resolverCodigoSin('28'))->toBe(28)
        ->and(UnidadMedida::resolverCodigoSin('KILOGRAMO'))->toBe(22)
        ->and(UnidadMedida::resolverCodigoSin('GRAMO'))->toBe(17)
        ->and(UnidadMedida::resolverCodigoSin('MILILITRO'))->toBe(34)
        ->and(UnidadMedida::resolverCodigoSin('CAJA'))->toBe(6)
        ->and(UnidadMedida::resolverCodigoSin('UNIDAD (BIENES)'))->toBe(57)
        ->and(UnidadMedida::resolverCodigoSin('UNIDAD'))->toBe(57);
});
