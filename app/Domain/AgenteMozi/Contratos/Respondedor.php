<?php

namespace App\Domain\AgenteMozi\Contratos;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\DTOs\RespuestaAgente;
use App\Domain\AgenteMozi\RespuestaNoDisponible;
use Closure;

/**
 * Motor que contesta un turno: Demo\RespondedorDemo (datos sintéticos, sin llamadas) o
 * Ia\Orquestador (proveedor de IA con herramientas). La pantalla, la persistencia y el Lienzo
 * dependen de este contrato, nunca de un proveedor concreto.
 */
interface Respondedor
{
    /**
     * @param  list<array{rol: string, texto: string}>  $historial  mensajes previos de la charla (rol usuario|modelo)
     * @param  (Closure(string): void)|null  $avance  recibe estados de trabajo aptos para la interfaz
     *
     * @throws RespuestaNoDisponible
     */
    public function responder(ContextoAgente $contexto, string $pregunta, array $historial = [], ?Closure $avance = null): RespuestaAgente;
}
