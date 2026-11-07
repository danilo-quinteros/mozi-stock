<?php

namespace App\Domain\AgenteMozi\Ia;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\RespuestaNoDisponible;
use App\Domain\Reportes\Periodo;
use App\Models\AgenteMensaje;

/**
 * AiUsageMeter de § 4.2: cuota diaria por empresa (día de La Paz) de respuestas completadas y tokens
 * de un proveedor de IA. Las respuestas fallidas no cuentan como consulta, pero sí sus tokens: el
 * proveedor los cobró. La
 * cuota es blanda: dos envíos simultáneos en el límite pueden pasarla por una respuesta.
 */
final class MedidorUso
{
    public function __construct(private readonly GestorProveedores $gestor) {}

    /**
     * @throws RespuestaNoDisponible
     */
    public function verificar(ContextoAgente $contexto, string $proveedor): void
    {
        $uso = $this->hoy($contexto->empresaId, $proveedor);
        $cuotas = $this->gestor->cuotas();

        if ($uso['mensajes'] >= $cuotas['mensajes'] || $uso['tokens'] >= $cuotas['tokens']) {
            throw new RespuestaNoDisponible(__('Tu empresa alcanzó el límite diario de consultas con IA de Agente Mozi. Vuelve a intentarlo mañana.'), $proveedor);
        }
    }

    /**
     * @return array{mensajes: int, tokens: int}
     */
    public function hoy(int $empresaId, string $proveedor): array
    {
        [$desde, $hasta] = Periodo::hoy()->limitesUtc();

        $fila = AgenteMensaje::query()
            ->where('empresa_id', $empresaId)
            ->where('proveedor', $proveedor)
            ->where('created_at', '>=', $desde)
            ->where('created_at', '<', $hasta)
            ->selectRaw('COALESCE(SUM(estado = ?), 0) AS mensajes, COALESCE(SUM(tokens_entrada), 0) + COALESCE(SUM(tokens_salida), 0) AS tokens', [AgenteMensaje::COMPLETADO])
            ->toBase()
            ->first();

        return ['mensajes' => (int) ($fila->mensajes ?? 0), 'tokens' => (int) ($fila->tokens ?? 0)];
    }
}
