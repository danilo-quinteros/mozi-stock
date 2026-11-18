<?php

use App\Domain\Reportes\Periodo;
use App\Livewire\Reportes\Panel;
use App\Models\Sucursal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;

/*
 * Selector «Rango de fechas» de Reportes generales: preajustes (por defecto «Este mes hasta hoy») o un rango
 * personalizado; la elección se guarda en la cookie del navegador y se recupera al volver. Sin «Comparar con».
 * Hoy de prueba: miércoles 18 de noviembre de 2026 en La Paz.
 */

function momentoRango(string $momento): void
{
    test()->travelTo(CarbonImmutable::parse($momento, 'America/La_Paz'));
}

/** @return array{0: string, 1: string} */
function fechasDe(string $clave): array
{
    $periodo = Periodo::preajuste($clave);

    return [$periodo->desde->format('Y-m-d'), $periodo->hasta->format('Y-m-d')];
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->actingAs($this->admin);
    momentoRango('2026-11-18 10:00');
});

test('presets: the «to date» ranges end today and the «last» ones are the full previous calendar period', function () {
    expect(fechasDe('hoy'))->toBe(['2026-11-18', '2026-11-18'])
        ->and(fechasDe('ayer'))->toBe(['2026-11-17', '2026-11-17'])
        ->and(fechasDe('semana'))->toBe(['2026-11-16', '2026-11-18'])            // desde el lunes
        ->and(fechasDe('semana_pasada'))->toBe(['2026-11-09', '2026-11-15'])
        ->and(fechasDe('mes'))->toBe(['2026-11-01', '2026-11-18'])
        ->and(fechasDe('mes_pasado'))->toBe(['2026-10-01', '2026-10-31'])
        ->and(fechasDe('trimestre'))->toBe(['2026-10-01', '2026-11-18'])
        ->and(fechasDe('trimestre_pasado'))->toBe(['2026-07-01', '2026-09-30'])
        ->and(fechasDe('anio'))->toBe(['2026-01-01', '2026-11-18'])
        ->and(fechasDe('anio_pasado'))->toBe(['2025-01-01', '2025-12-31'])
        ->and(Periodo::preajuste('comparar'))->toBeNull();

    // Fronteras: 1 de enero (el día anterior es de otro año) y fin de marzo (febrero sin desbordar).
    momentoRango('2026-01-01 00:30');
    expect(fechasDe('ayer'))->toBe(['2025-12-31', '2025-12-31'])
        ->and(fechasDe('mes_pasado'))->toBe(['2025-12-01', '2025-12-31'])
        ->and(fechasDe('trimestre_pasado'))->toBe(['2025-10-01', '2025-12-31'])
        ->and(fechasDe('semana'))->toBe(['2025-12-29', '2026-01-01']);

    momentoRango('2026-03-31 23:30');
    expect(fechasDe('mes_pasado'))->toBe(['2026-02-01', '2026-02-28'])
        ->and(fechasDe('hoy'))->toBe(['2026-03-31', '2026-03-31']);   // 23:30 en La Paz ya es 1 de abril en UTC
});

test('without a cookie the report opens on «Este mes hasta hoy», with no «Comparar con»', function () {
    Livewire::test(Panel::class)
        ->assertSet('rango', 'mes')
        ->assertSet('desde', '2026-11-01')
        ->assertSet('hasta', '2026-11-18')
        ->assertSee('Rango de fechas')
        ->assertSee('Este mes hasta hoy')
        ->assertSee('1 – 18 nov 2026')
        ->assertSee('Preajustes')
        ->assertSee('Personalizado')
        ->assertSee('El trimestre pasado')
        ->assertSee('1 jul – 30 sep 2026')
        ->assertDontSee('Comparar con')
        ->assertDontSee('Fecha desde');
});

test('choosing a preset applies it at once and saves it in the cookie', function () {
    $pantalla = Livewire::test(Panel::class)
        ->call('elegirRango', 'mes_pasado')
        ->assertSet('rango', 'mes_pasado')
        ->assertSet('desde', '2026-10-01')
        ->assertSet('hasta', '2026-10-31')
        ->assertSee('El mes pasado')
        ->assertSee('1 – 31 oct 2026');

    expect(Cookie::queued(Panel::COOKIE_RANGO)?->getValue())->toBe('mes_pasado')
        ->and(Cookie::queued(Panel::COOKIE_RANGO)?->getExpiresTime())->toBeGreaterThan(now()->addDays(360)->getTimestamp());

    // Una clave inventada no cambia nada, y el rango no se puede fijar desde el navegador.
    $pantalla->call('elegirRango', 'comparar_con')->assertSet('rango', 'mes_pasado');
    expect(fn () => $pantalla->set('rango', 'anio'))->toThrow(Exception::class);
});

test('the saved cookie is used when coming back, and an unknown value falls back to this month', function () {
    Livewire::withCookies([Panel::COOKIE_RANGO => 'anio'])->test(Panel::class)
        ->assertSet('rango', 'anio')
        ->assertSet('desde', '2026-01-01')
        ->assertSet('hasta', '2026-11-18')
        ->assertSee('Este año hasta hoy');

    // El preajuste se recalcula cada día: guardado ayer, hoy sigue siendo «este mes» completo hasta hoy.
    momentoRango('2026-12-02 09:00');
    Livewire::withCookies([Panel::COOKIE_RANGO => 'mes'])->test(Panel::class)
        ->assertSet('desde', '2026-12-01')->assertSet('hasta', '2026-12-02');

    Livewire::withCookies([Panel::COOKIE_RANGO => 'personalizado|2026-10-05|2026-10-20'])->test(Panel::class)
        ->assertSet('rango', 'personalizado')
        ->assertSet('desde', '2026-10-05')
        ->assertSet('hasta', '2026-10-20')
        ->assertSet('personalDesde', '2026-10-05')
        ->assertSee('Personalizado')
        ->assertSee('5 – 20 oct 2026');

    foreach (['<script>', 'personalizado|nada|2026-99-99', ''] as $raro) {
        Livewire::withCookies([Panel::COOKIE_RANGO => $raro])->test(Panel::class)->assertSet('rango', $raro === '' || $raro === '<script>' ? 'mes' : 'personalizado');
    }
});

test('custom range: applies valid dates in any order, rejects more than a year and saves it in the cookie', function () {
    $pantalla = Livewire::test(Panel::class)
        ->set('personalDesde', '')
        ->call('aplicarPersonalizado')
        ->assertHasErrors(['personalDesde'])
        ->assertSet('rango', 'mes')
        ->set('personalDesde', '2025-10-01')
        ->set('personalHasta', '2026-11-18')
        ->call('aplicarPersonalizado')
        ->assertHasErrors(['personalHasta'])
        ->assertSee('Elige un rango de hasta un año.')
        ->assertSet('desde', '2026-11-01');

    expect(Cookie::hasQueued(Panel::COOKIE_RANGO))->toBeFalse();

    $pantalla->set('personalDesde', '2026-10-20')
        ->set('personalHasta', '2026-09-15')
        ->call('aplicarPersonalizado')
        ->assertHasNoErrors()
        ->assertDispatched('rango-aplicado')
        ->assertSet('rango', 'personalizado')
        ->assertSet('desde', '2026-09-15')
        ->assertSet('hasta', '2026-10-20')
        ->assertSee('15 sep – 20 oct 2026');

    expect(Cookie::queued(Panel::COOKIE_RANGO)?->getValue())->toBe('personalizado|2026-09-15|2026-10-20');
});

test('the chosen range filters the figures: a sale of last month is out of «Este mes hasta hoy»', function () {
    $producto = productoDe($this->empresa, ['nombre' => 'Leche 1L', 'precio_consumidor' => '20.00']);
    momentoRango('2026-10-20 10:00');
    cargarStock($this->admin, $this->matriz, $producto, '10');
    confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $producto->id, 'cantidad' => '2']]), ['metodo' => 'efectivo']);
    momentoRango('2026-11-18 10:00');
    confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $producto->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);

    $pantalla = Livewire::test(Panel::class)->assertSee('Bs 20,00');
    expect($pantalla->viewData('cobros')['total'])->toBe('20.00');

    $pantalla->call('elegirRango', 'mes_pasado');
    expect($pantalla->viewData('cobros')['total'])->toBe('40.00');

    $pantalla->call('elegirRango', 'trimestre');
    expect($pantalla->viewData('cobros')['total'])->toBe('60.00')
        ->and($pantalla->viewData('filtrosCsv'))->toMatchArray(['desde' => '2026-10-01', 'hasta' => '2026-11-18']);
});
