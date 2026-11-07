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
     * @return Collection<int, array{codigo: string, nombre: string, siat: bool, precio: string, entero: string, centavos: string}>
     */
    public static function activos(): Collection
    {
        return Plan::query()
            ->where('activo', true)
            ->orderBy('orden')
            ->get(['codigo', 'precio_mensual', 'siat_habilitado'])
            ->map(function (Plan $plan): array {
                [$entero, $centavos] = array_pad(explode('.', (string) $plan->precio_mensual, 2), 2, '00');

                return [
                    'codigo' => $plan->codigo,
                    'nombre' => $plan->siat_habilitado ? 'Inventario + SIAT' : 'Inventario',
                    'siat' => $plan->siat_habilitado,
                    'precio' => $entero.'.'.str_pad(substr($centavos, 0, 2), 2, '0'),
                    'entero' => number_format((int) $entero, 0, ',', '.'),
                    'centavos' => str_pad(substr($centavos, 0, 2), 2, '0'),
                ];
            });
    }
}
