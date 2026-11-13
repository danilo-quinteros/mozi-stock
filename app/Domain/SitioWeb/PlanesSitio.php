<?php

namespace App\Domain\SitioWeb;

use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * Planes que muestra el sitio público, leídos de la tabla planes para que el precio publicado sea
 * siempre el que cobra el sistema. Los importes se tratan como texto decimal (sin float).
 */
final class PlanesSitio
{
    /**
     * @return Collection<int, array{codigo: string, nombre: string, siat: bool, ia: bool, precio: string, entero: string, centavos: string}>
     */
    public static function activos(): Collection
    {
        return Plan::query()
            ->where('activo', true)
            ->orderBy('orden')
            ->get(['codigo', 'precio_mensual', 'siat_habilitado', 'agente_ia'])
            ->map(function (Plan $plan): array {
                [$entero, $centavos] = array_pad(explode('.', (string) $plan->precio_mensual, 2), 2, '00');

                return [
                    'codigo' => $plan->codigo,
                    'nombre' => self::nombre($plan),
                    'siat' => $plan->siat_habilitado,
                    'ia' => $plan->agente_ia,
                    'precio' => $entero.'.'.str_pad(substr($centavos, 0, 2), 2, '0'),
                    'entero' => number_format((int) $entero, 0, ',', '.'),
                    'centavos' => str_pad(substr($centavos, 0, 2), 2, '0'),
                ];
            });
    }

    /**
     * Nombre corto para tarjetas y registro (el título del plan en la tabla es más largo).
     * Escalera: Inventario → Inventario + SIAT → SIAT + Agente IA.
     */
    public static function nombre(Plan $plan): string
    {
        return match (true) {
            $plan->agente_ia && $plan->siat_habilitado => 'SIAT + Agente IA',
            $plan->agente_ia => 'Inventario + Agente IA',
            $plan->siat_habilitado => 'Inventario + SIAT',
            default => 'Inventario',
        };
    }
}
