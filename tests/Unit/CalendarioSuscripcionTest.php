<?php

use App\Domain\Suscripciones\CalendarioSuscripcion as Calendario;
use App\Enums\EstadoSuscripcion;
use App\Enums\EventoAviso;

/*
 * § 8.4 (decisión del propietario, 23/10/2026): el pago aprobado avanza los meses pagados
 * conservando el día de vencimiento, aunque se pague tarde; en meses cortos cae en el último día.
 */

function avanzar(string $vencimiento, int $dia, int $meses): string
{
    return Calendario::avanzar(Calendario::fecha($vencimiento), $dia, $meses)->format('Y-m-d');
}

test('a paid month keeps the due day', function () {
    expect(avanzar('2026-10-28', 28, 1))->toBe('2026-11-28')
        ->and(avanzar('2026-10-28', 28, 3))->toBe('2027-01-28')
        ->and(avanzar('2026-10-28', 28, 12))->toBe('2027-10-28')
        ->and(avanzar('2026-12-15', 15, 1))->toBe('2027-01-15');
});

test('short months fall on their last day and then return to the original day', function () {
    expect(avanzar('2026-01-31', 31, 1))->toBe('2026-02-28')
        ->and(avanzar('2026-02-28', 31, 1))->toBe('2026-03-31')
        ->and(avanzar('2026-03-31', 31, 1))->toBe('2026-04-30')
        ->and(avanzar('2026-04-30', 31, 1))->toBe('2026-05-31')
        ->and(avanzar('2028-01-31', 31, 1))->toBe('2028-02-29')
        ->and(avanzar('2026-01-30', 30, 1))->toBe('2026-02-28')
        ->and(avanzar('2026-02-28', 30, 1))->toBe('2026-03-30')
        ->and(avanzar('2026-01-31', 31, 2))->toBe('2026-03-31');
});

test('paying late does not move the due day and owed months keep it overdue', function () {
    $hoy = Calendario::fecha('2026-11-02');
    $tras1 = Calendario::avanzar(Calendario::fecha('2026-07-28'), 28, 1);
    $tras4 = Calendario::avanzar(Calendario::fecha('2026-07-28'), 28, 4);

    expect($tras1->format('Y-m-d'))->toBe('2026-08-28')
        ->and(Calendario::estado($tras1, $hoy))->toBe(EstadoSuscripcion::Vencida)
        ->and($tras4->format('Y-m-d'))->toBe('2026-11-28')
        ->and(Calendario::estado($tras4, $hoy))->toBe(EstadoSuscripcion::AlDia);
});

test('months outside 1 to 12 and invalid dates are rejected', function () {
    expect(fn () => avanzar('2026-10-28', 28, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => avanzar('2026-10-28', 28, 13))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Calendario::fecha('2026-02-30'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Calendario::fecha('28/10/2026'))->toThrow(InvalidArgumentException::class);
});

test('payment status counts calendar days', function () {
    $hoy = Calendario::fecha('2026-10-23');

    expect(Calendario::estado(Calendario::fecha('2026-10-29'), $hoy))->toBe(EstadoSuscripcion::AlDia)
        ->and(Calendario::estado(Calendario::fecha('2026-10-28'), $hoy))->toBe(EstadoSuscripcion::PorVencer)
        ->and(Calendario::estado(Calendario::fecha('2026-10-23'), $hoy))->toBe(EstadoSuscripcion::PorVencer)
        ->and(Calendario::estado(Calendario::fecha('2026-10-22'), $hoy))->toBe(EstadoSuscripcion::Vencida)
        ->and(Calendario::diasHasta(Calendario::fecha('2026-10-28'), $hoy))->toBe(5)
        ->and(Calendario::diasHasta(Calendario::fecha('2026-10-20'), $hoy))->toBe(-3);
});

test('reminder windows are D-5, D0 and D+3 and never overlap', function () {
    $vence = Calendario::fecha('2026-10-28');
    $evento = fn (string $dia) => Calendario::eventoDelDia($vence, Calendario::fecha($dia));

    expect($evento('2026-10-22'))->toBeNull()
        ->and($evento('2026-10-23'))->toBe(EventoAviso::Previo)
        ->and($evento('2026-10-27'))->toBe(EventoAviso::Previo)
        ->and($evento('2026-10-28'))->toBe(EventoAviso::Dia)
        ->and($evento('2026-10-30'))->toBe(EventoAviso::Dia)
        ->and($evento('2026-10-31'))->toBe(EventoAviso::Posterior)
        ->and($evento('2026-11-02'))->toBe(EventoAviso::Posterior)
        ->and($evento('2026-11-03'))->toBeNull()
        ->and(Calendario::fechaEvento($vence, EventoAviso::Previo)->format('Y-m-d'))->toBe('2026-10-23')
        ->and(Calendario::fechaEvento($vence, EventoAviso::Posterior)->format('Y-m-d'))->toBe('2026-10-31');

    // Cada día cae a lo sumo en una ventana: imposible enviar dos avisos el mismo día.
    for ($d = -10; $d <= 10; $d++) {
        $hoy = $vence->addDays($d);
        $coinciden = array_filter(EventoAviso::cases(), fn (EventoAviso $e) => $d >= $e->desfase() && $d <= $e->hasta());
        expect(count($coinciden))->toBeLessThanOrEqual(1)
            ->and(Calendario::eventoDelDia($vence, $hoy))->toBe($coinciden === [] ? null : array_values($coinciden)[0]);
    }
});
