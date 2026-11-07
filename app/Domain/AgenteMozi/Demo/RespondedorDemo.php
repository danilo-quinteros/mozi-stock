<?php

namespace App\Domain\AgenteMozi\Demo;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Contratos\Respondedor;
use App\Domain\AgenteMozi\DTOs\RespuestaAgente;
use Closure;
use Illuminate\Support\Str;

/**
 * Modo demostración de la Fase 1: reconoce la intención por palabras clave (español e inglés) y
 * responde con escenarios sintéticos marcados como tales. No llama a ningún proveedor de IA ni lee
 * datos de la empresa; sirve para revisar la experiencia del chat y del Lienzo.
 */
final class RespondedorDemo implements Respondedor
{
    /** Orden de evaluación: lo más específico primero («vender el próximo mes» es pronóstico, no ventas). */
    private const INTENCIONES = [
        'visualizacion' => ['personaliz', 'creativ', 'interactiv', 'custom', 'creative', 'visualizacion', 'visualization'],
        'pronostico' => ['pronost', 'proyecc', 'prever', 'proximo mes', 'podriamos vender', 'forecast', 'predict', 'next month'],
        'rentabilidad' => ['margen', 'utilidad', 'rentab', 'ganancia', 'profit', 'margin'],
        'capital' => ['estancad', 'inmoviliz', 'sin movimiento', 'capital', 'dinero parado', 'idle', 'tied up', 'slow mov', 'dead stock'],
        'stock' => ['stock', 'agot', 'quedar sin', 'quedan sin', 'cobertura', 'existencia', 'inventario', 'reponer', 'run out', 'running out'],
        'ventas' => ['vend', 'venta', 'cobr', 'ingreso', 'caja', 'sell', 'sold', 'sales', 'collect', 'revenue', 'cash'],
    ];

    public function responder(ContextoAgente $contexto, string $pregunta, array $historial = [], ?Closure $avance = null): RespuestaAgente
    {
        $texto = Str::lower(Str::ascii($pregunta));
        $escenarios = new EscenariosDemo($contexto);

        foreach (self::INTENCIONES as $intencion => $claves) {
            if (Str::contains($texto, $claves)) {
                return match ($intencion) {
                    'visualizacion' => (bool) config('agente_mozi.sandbox_web') ? $escenarios->visualizacion() : $escenarios->stock(sinVistaPersonalizada: true),
                    'pronostico' => $escenarios->pronostico(),
                    'rentabilidad' => $escenarios->rentabilidad(),
                    'capital' => $escenarios->capital(),
                    'stock' => $escenarios->stock(),
                    default => $escenarios->ventasYCobros(),
                };
            }
        }

        return $escenarios->ayuda();
    }
}
