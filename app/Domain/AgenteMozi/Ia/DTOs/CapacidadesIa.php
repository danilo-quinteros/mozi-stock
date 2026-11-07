<?php

namespace App\Domain\AgenteMozi\Ia\DTOs;

/**
 * Capacidades de un modelo concreto (§ 4.3): varían incluso dentro de un mismo proveedor, por eso
 * salen de la configuración por modelo y nunca se suponen.
 */
final readonly class CapacidadesIa
{
    public function __construct(
        public bool $herramientas,
        public bool $vision,
        public bool $streaming,
        public int $contextoMaximo,
        public int $salidaMaxima,
        public ?string $razonamiento = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function desde(array $config): self
    {
        return new self(
            herramientas: (bool) ($config['herramientas'] ?? false),
            vision: (bool) ($config['vision'] ?? false),
            streaming: (bool) ($config['streaming'] ?? false),
            contextoMaximo: (int) ($config['contexto'] ?? 0),
            salidaMaxima: (int) ($config['salida'] ?? 0),
            razonamiento: isset($config['razonamiento']) ? (string) $config['razonamiento'] : null,
        );
    }
}
