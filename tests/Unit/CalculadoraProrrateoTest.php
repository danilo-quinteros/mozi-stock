<?php

use App\Domain\Suscripciones\CalculadoraProrrateo;
use App\Models\Plan;
use App\Models\Suscripcion;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

test('prorates upgrade difference proportionally based on remaining days', function () {
    $planActual = new Plan([
        'titulo' => 'Acceso al sistema de inventarios',
        'precio_mensual' => '150.00',
        'siat_habilitado' => false,
    ]);

    $planNuevo = new Plan([
        'titulo' => 'Inventarios + Facturación SIAT',
        'precio_mensual' => '300.00',
        'siat_habilitado' => true,
    ]);

    $suscripcion = new Suscripcion;
    $suscripcion->precio_mensual = '150.00';
    $suscripcion->dia_vencimiento = 31;
    $suscripcion->vencimiento = CarbonImmutable::parse('2026-10-31');
    $suscripcion->setRelation('plan', $planActual);

    // En octubre (del 30/09 al 31/10 = 31 días):
    // Si faltan 16 días (hoy es 15 de octubre):
    // (150 * 16) / 31 = 77.42
    $hoy = CarbonImmutable::parse('2026-10-15');
    $resultado = CalculadoraProrrateo::calcular($suscripcion, $planNuevo, $hoy);

    expect($resultado['tipo'])->toBe('upgrade')
        ->and($resultado['diferencia_mensual'])->toBe('150.00')
        ->and($resultado['dias_restantes'])->toBe(16)
        ->and($resultado['dias_periodo'])->toBe(31)
        ->and($resultado['monto_prorrateado'])->toBe('77.42')
        ->and($resultado['precio_proxima_renovacion'])->toBe('300.00')
        ->and($resultado['vencimiento'])->toBe('2026-10-31');
});

test('upgrade on the day of expiration has 0 remaining days and 0 prorated charge', function () {
    $planActual = new Plan(['precio_mensual' => '150.00']);
    $planNuevo = new Plan(['precio_mensual' => '300.00']);

    $suscripcion = new Suscripcion;
    $suscripcion->precio_mensual = '150.00';
    $suscripcion->dia_vencimiento = 30;
    $suscripcion->vencimiento = CarbonImmutable::parse('2026-11-30');
    $suscripcion->setRelation('plan', $planActual);

    $hoy = CarbonImmutable::parse('2026-11-30');
    $resultado = CalculadoraProrrateo::calcular($suscripcion, $planNuevo, $hoy);

    expect($resultado['tipo'])->toBe('upgrade')
        ->and($resultado['dias_restantes'])->toBe(0)
        ->and($resultado['monto_prorrateado'])->toBe('0.00')
        ->and($resultado['precio_proxima_renovacion'])->toBe('300.00');
});

test('downgrade charges 0 prorated amount and sets next renewal price to reduced price', function () {
    $planActual = new Plan(['precio_mensual' => '300.00']);
    $planNuevo = new Plan(['precio_mensual' => '150.00']);

    $suscripcion = new Suscripcion;
    $suscripcion->precio_mensual = '300.00';
    $suscripcion->dia_vencimiento = 30;
    $suscripcion->vencimiento = CarbonImmutable::parse('2026-11-30');
    $suscripcion->setRelation('plan', $planActual);

    $hoy = CarbonImmutable::parse('2026-11-10');
    $resultado = CalculadoraProrrateo::calcular($suscripcion, $planNuevo, $hoy);

    expect($resultado['tipo'])->toBe('downgrade')
        ->and($resultado['monto_prorrateado'])->toBe('0.00')
        ->and($resultado['precio_proxima_renovacion'])->toBe('150.00')
        ->and($resultado['vencimiento'])->toBe('2026-11-30');
});
