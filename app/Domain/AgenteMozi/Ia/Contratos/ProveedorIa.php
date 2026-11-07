<?php

namespace App\Domain\AgenteMozi\Ia\Contratos;

use App\Domain\AgenteMozi\Ia\DTOs\CapacidadesIa;
use App\Domain\AgenteMozi\Ia\DTOs\RespuestaIa;
use App\Domain\AgenteMozi\Ia\DTOs\SolicitudIa;
use App\Domain\AgenteMozi\Ia\ErrorProveedorIa;

/**
 * Adaptador de un proveedor de IA (AiProviderInterface de § 4.2). Solo traduce mensajes, herramientas
 * y uso entre el protocolo interno y la API del proveedor: no conoce reglas de negocio, empresas ni
 * el Lienzo. El orquestador, las herramientas y la pantalla dependen de este contrato, nunca de Gemini
 * u OpenAI. El streaming de texto no forma parte del contrato todavía (capacidad streaming = false).
 */
interface ProveedorIa
{
    public function id(): string;

    public function nombre(): string;

    /** La credencial existe en el servidor. Nunca expone su valor. */
    public function configurado(): bool;

    /**
     * Modelos ofrecidos por configuración, con sus capacidades verificadas.
     *
     * @return list<string>
     */
    public function modelos(): array;

    public function capacidades(string $modelo): CapacidadesIa;

    /**
     * @throws ErrorProveedorIa
     */
    public function generar(SolicitudIa $solicitud): RespuestaIa;

    /**
     * Prueba de conexión con el modelo: devuelve la versión que informa el proveedor.
     *
     * @throws ErrorProveedorIa
     */
    public function comprobar(string $modelo): string;
}
