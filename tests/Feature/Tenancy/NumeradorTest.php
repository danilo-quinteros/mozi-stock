<?php

use App\Domain\Tenancy\Numerador;
use Illuminate\Support\Facades\DB;

/*
 * Numerador: primero bloquea la fila existente y solo la crea si la serie es nueva. Antes hacía INSERT IGNORE
 * sobre la fila existente y luego FOR UPDATE: con 8 pedidos simultáneos de la misma empresa, 5 fallaban por
 * deadlock aun con 3 reintentos (prueba de concurrencia de lotes L1, 08/11/2026). La concurrencia con varios
 * procesos no se reproduce aquí; esta prueba fija el comportamiento secuencial.
 */

test('a new series starts at 1, continues from the stored value and is separate per company and series', function () {
    [$empresaA] = empresaConAdministrador();
    [$empresaB] = empresaConAdministrador();
    $numerador = app(Numerador::class);

    $numeros = DB::transaction(fn () => [
        $numerador->siguiente($empresaA->id, 'PRU'),
        $numerador->siguiente($empresaA->id, 'PRU'),
        $numerador->siguiente($empresaB->id, 'PRU'),
        $numerador->siguiente($empresaA->id, 'OTR'),
    ]);

    DB::table('numeradores')->where('empresa_id', $empresaA->id)->where('serie', 'PRU')->update(['ultimo' => 41]);

    expect($numeros)->toBe([1, 2, 1, 1])
        ->and(DB::transaction(fn () => $numerador->siguiente($empresaA->id, 'PRU')))->toBe(42)
        ->and(DB::table('numeradores')->where('serie', 'PRU')->count())->toBe(2);
});

test('a rolled back operation does not consume a number', function () {
    [$empresa] = empresaConAdministrador();
    $numerador = app(Numerador::class);

    DB::transaction(fn () => $numerador->siguiente($empresa->id, 'PRU'));

    try {
        DB::transaction(function () use ($numerador, $empresa): void {
            $numerador->siguiente($empresa->id, 'PRU');

            throw new RuntimeException('falla');
        });
    } catch (RuntimeException) {
    }

    expect(DB::transaction(fn () => $numerador->siguiente($empresa->id, 'PRU')))->toBe(2);
});
