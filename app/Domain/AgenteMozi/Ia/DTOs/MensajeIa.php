<?php

namespace App\Domain\AgenteMozi\Ia\DTOs;

/**
 * Turno de la conversación con el modelo en el protocolo interno: texto del usuario, turno del
 * modelo (texto y llamadas) o resultados de herramientas. nativo guarda el turno del modelo tal como
 * lo devolvió su proveedor (p. ej. las firmas de razonamiento de Gemini, que deben reenviarse
 * idénticas); otro proveedor lo ignora y usa texto y llamadas.
 */
final readonly class MensajeIa
{
    public const USUARIO = 'usuario';

    public const MODELO = 'modelo';

    public const RESULTADOS = 'resultados';

    /**
     * @param  list<LlamadaHerramienta>  $llamadas
     * @param  list<ResultadoHerramienta>  $resultados
     * @param  array<string, mixed>|null  $nativo
     */
    public function __construct(
        public string $rol,
        public string $texto = '',
        public array $llamadas = [],
        public array $resultados = [],
        public ?array $nativo = null,
        public ?string $origenNativo = null,
    ) {}

    public static function usuario(string $texto): self
    {
        return new self(self::USUARIO, $texto);
    }

    public static function modelo(string $texto): self
    {
        return new self(self::MODELO, $texto);
    }

    /**
     * @param  list<ResultadoHerramienta>  $resultados
     */
    public static function resultados(array $resultados): self
    {
        return new self(self::RESULTADOS, resultados: $resultados);
    }
}
