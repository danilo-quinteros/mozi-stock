<?php

namespace App\Domain\AgenteMozi\Datos;

/**
 * Campo de una fuente de la consulta genérica (docs/AGENTE_MOZI_IA.md § 5.1): una columna o una
 * medida agregada. La expresión SQL la escribe el catálogo, nunca el modelo; el modelo solo elige el
 * nombre. El tipo es el del Lienzo (string, integer, number, money, percent o date).
 */
final readonly class Campo
{
    public const NUMERICOS = ['integer', 'number', 'money', 'percent'];

    /**
     * El nombre y las expresiones son literal-string: se escriben en el código del catálogo y nunca
     * llevan valores de la petición ni del modelo («hoy» y el desfase salen de la subconsulta referencia).
     *
     * @param  literal-string  $nombre
     * @param  literal-string  $sql
     * @param  literal-string|null  $ordenSql  expresión para ordenar cuando el valor mostrado no ordena bien (día de la semana)
     */
    public function __construct(
        public string $nombre,
        public string $etiqueta,
        public string $tipo,
        public string $sql,
        public bool $medida = false,
        public bool $soloAdministrador = false,
        public ?string $ordenSql = null,
    ) {}

    /**
     * @param  literal-string  $nombre
     * @param  literal-string  $sql
     */
    public static function medida(string $nombre, string $etiqueta, string $tipo, string $sql, bool $soloAdministrador = false): self
    {
        return new self($nombre, $etiqueta, $tipo, $sql, medida: true, soloAdministrador: $soloAdministrador);
    }

    public function esTexto(): bool
    {
        return $this->tipo === 'string';
    }
}
