<?php

use Illuminate\Support\Facades\DB;

/*
 * La aplicación trabaja en UTC. Si la sesión de MySQL usara la zona del servidor (en el equipo local,
 * America/La_Paz), las columnas TIMESTAMP guardarían un instante corrido 4 horas y CONVERT_TZ(col,
 * '+00:00', …) de los reportes partiría de una hora equivocada.
 */
test('la sesión de MySQL usa UTC y las TIMESTAMP guardan el instante de la aplicación', function () {
    expect(config('database.connections.mysql.timezone'))->toBe('+00:00')
        ->and(DB::selectOne('SELECT @@session.time_zone AS tz')->tz)->toBe('+00:00');

    [$empresa] = empresaConAdministrador();
    $empresa->refresh();

    $instante = (int) DB::table('empresas')->where('id', $empresa->id)->value(DB::raw('UNIX_TIMESTAMP(created_at)'));

    expect($instante)->toBe($empresa->created_at->getTimestamp());
});
