<?php

namespace App\Domain\AgenteMozi\Ia\DTOs;

/**
 * Uso real informado por el proveedor (tokens de entrada; de salida, incluido el razonamiento que se
 * factura como salida) más la latencia y las herramientas ejecutadas en el turno.
 */
final readonly class UsoIa
{
    public function __construct(
        public int $tokensEntrada = 0,
        public int $tokensSalida = 0,
        public int $latenciaMs = 0,
        public int $herramientas = 0,
    ) {}

    public function mas(self $otro): self
    {
        return new self(
            $this->tokensEntrada + $otro->tokensEntrada,
            $this->tokensSalida + $otro->tokensSalida,
            $this->latenciaMs + $otro->latenciaMs,
            $this->herramientas + $otro->herramientas,
        );
    }

    public function cerrar(int $latenciaMs, int $herramientas): self
    {
        return new self($this->tokensEntrada, $this->tokensSalida, $latenciaMs, $herramientas);
    }
}
