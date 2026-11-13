<?php

namespace App\Domain\Tenancy;

use App\Enums\Capacidad;
use Illuminate\Support\Facades\DB;

/**
 * Capacidades activas por empresa, leídas con UNA consulta por empresa y petición y guardadas en memoria.
 * Se registra como servicio «scoped»: la cola lo descarta entre jobs, así que nunca arrastra datos de
 * otra empresa ni de otro trabajo. CambiarFuncionesEmpresa lo invalida al confirmar un cambio.
 *
 * Recibe el id de la empresa ya resuelto en el servidor (usuario autenticado o job), nunca de la petición.
 */
final class CapacidadesEmpresa
{
    /** @var array<int, array<string, true>> */
    private array $activas = [];

    public function tiene(int $empresaId, Capacidad $capacidad): bool
    {
        return isset($this->cargar($empresaId)[$capacidad->value]);
    }

    /**
     * @return list<Capacidad>
     */
    public function activas(int $empresaId): array
    {
        return array_values(array_filter(
            Capacidad::cases(),
            fn (Capacidad $capacidad): bool => isset($this->cargar($empresaId)[$capacidad->value]),
        ));
    }

    /**
     * Capacidad que da a las ventas nuevas un estado de entrega pendiente: despachos (Por despachar) o
     * cocina (En cocina). No pueden estar activas a la vez; null si toda venta nace Entregado.
     */
    public function flujoEntrega(int $empresaId): ?Capacidad
    {
        foreach ([Capacidad::Despachos, Capacidad::Cocina] as $capacidad) {
            if ($this->tiene($empresaId, $capacidad)) {
                return $capacidad;
            }
        }

        return null;
    }

    /** Descarta lo leído de una empresa (tras cambiar sus funciones). */
    public function olvidar(int $empresaId): void
    {
        unset($this->activas[$empresaId]);
    }

    /**
     * @return array<string, true>
     */
    private function cargar(int $empresaId): array
    {
        // Consulta directa por empresa: también sirve sin usuario autenticado (jobs, superadmin, consola).
        return $this->activas[$empresaId] ??= array_fill_keys(
            DB::table('empresa_capacidades')
                ->where('empresa_id', $empresaId)
                ->where('activa', true)
                ->pluck('capacidad')
                ->all(),
            true,
        );
    }
}
