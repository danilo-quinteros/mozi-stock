<?php

namespace App\Domain\AgenteMozi;

use App\Models\Scopes\EmpresaScope;
use App\Models\Suscripcion;

/**
 * En SaaS, Agente Mozi solo está disponible para empresas cuyo plan lo incluye (planes.agente_ia).
 * Sin planes (modalidad standalone) no hay restricción. Una consulta por empresa y petición: se
 * registra como servicio «scoped», igual que CapacidadesEmpresa.
 *
 * Recibe el id de la empresa ya resuelto en el servidor (usuario autenticado), nunca de la petición.
 * El vencimiento no quita el agente (T26): solo el plan que tiene la suscripción.
 */
final class AccesoPorPlan
{
    /** @var array<int, bool> */
    private array $incluido = [];

    public function permite(int $empresaId): bool
    {
        if (config('mozistock.deployment_mode') !== 'saas') {
            return true;
        }

        return $this->incluido[$empresaId] ??= (bool) Suscripcion::query()
            ->withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)
            ->with('plan')
            ->first()
            ?->incluyeAgenteIa();
    }

    /**
     * Descarta lo leído de una empresa, o de todas si no se indica (un plan cambió su bandera). Lo
     * llaman los modelos Suscripcion y Plan al guardarse, así ningún camino deja una respuesta vieja.
     */
    public function olvidar(?int $empresaId = null): void
    {
        if ($empresaId === null) {
            $this->incluido = [];

            return;
        }

        unset($this->incluido[$empresaId]);
    }
}
