<?php

namespace App\Domain\SitioWeb;

use App\Models\Plan;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * Planes que muestra el sitio público, leídos de la tabla planes para que el precio publicado sea
 * siempre el que cobra el sistema. Los importes se tratan como texto decimal (sin float).
 */
final class PlanesSitio
{
    /**
     * @return Collection<int, array{codigo: string, nombre: string, siat: bool, ia: bool, precio: string, entero: string, centavos: string, sucursales: int|null, usuarios: int|null, precio_sucursal: string, precio_paquete: string, usuarios_paquete: int}>
     */
    public static function activos(): Collection
    {
        return Plan::query()
            ->where('activo', true)
            ->orderBy('orden')
            ->get(['codigo', 'precio_mensual', 'siat_habilitado', 'agente_ia', 'sucursales_incluidas', 'usuarios_incluidos', 'precio_sucursal_extra', 'precio_paquete_usuarios', 'usuarios_por_paquete'])
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
                    'sucursales' => $plan->sucursales_incluidas,
                    'usuarios' => $plan->usuarios_incluidos,
                    'precio_sucursal' => (string) $plan->precio_sucursal_extra,
                    'precio_paquete' => (string) $plan->precio_paquete_usuarios,
                    'usuarios_paquete' => $plan->usuarios_por_paquete,
                ];
            });
    }

    /**
     * Precios de los extras para la franja bajo las tarjetas. Si los planes cobran distinto, el
     * menor con «desde». Null si ningún plan tiene límite (no hay extras que ofrecer).
     *
     * @param  Collection<int, array{sucursales: int|null, usuarios: int|null, precio_sucursal: string, precio_paquete: string, usuarios_paquete: int}>  $planes
     * @return array{sucursal: string|null, sucursal_desde: bool, paquete: string|null, paquete_desde: bool, usuarios_paquete: int}|null
     */
    public static function extras(Collection $planes): ?array
    {
        $conSucursales = $planes->whereNotNull('sucursales');
        $conUsuarios = $planes->whereNotNull('usuarios');

        if ($conSucursales->isEmpty() && $conUsuarios->isEmpty()) {
            return null;
        }

        $menor = fn (Collection $filas, string $campo): ?string => $filas->pluck($campo)->sort(fn (string $a, string $b) => Decimal::comparar($a, $b, 2))->first();

        return [
            'sucursal' => $menor($conSucursales, 'precio_sucursal'),
            'sucursal_desde' => $conSucursales->pluck('precio_sucursal')->unique()->count() > 1,
            'paquete' => $menor($conUsuarios, 'precio_paquete'),
            'paquete_desde' => $conUsuarios->pluck('precio_paquete')->unique()->count() > 1,
            'usuarios_paquete' => (int) ($conUsuarios->first()['usuarios_paquete'] ?? 5),
        ];
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
