<?php

namespace App\Domain\AgenteMozi\Datos;

use Closure;
use Illuminate\Database\Query\Builder;

/**
 * Fuente de la consulta genérica: una consulta base que ya filtra la empresa (y la sucursal del
 * vendedor), sus campos y medidas, la fecha principal para date_from/date_to y el orden por defecto.
 * Los campos usan la subconsulta referencia (hoy, desfase): la consulta se arma con
 * CatalogoDatos::consulta(), no llamando a $base directamente.
 */
final readonly class Fuente
{
    /** @var array<string, Campo> */
    public array $campos;

    /**
     * @param  Closure(): Builder  $base
     * @param  list<Campo>  $campos
     * @param  list<string>  $predeterminados  campos que se listan cuando el modelo no elige ninguno
     * @param  array{0: string, 1: 'asc'|'desc'}  $orden
     * @param  literal-string|null  $fechaSql  columna de la fecha principal: timestamp UTC o DATE según $fechaConHora
     * @param  literal-string|null  $soloActivos  condición SQL que se aplica si la consulta no filtra por «estado»
     */
    public function __construct(
        public string $nombre,
        public string $descripcion,
        public bool $soloAdministrador,
        public Closure $base,
        array $campos,
        public array $predeterminados,
        public array $orden,
        public ?string $fechaSql = null,
        public bool $fechaConHora = true,
        public ?string $soloActivos = null,
    ) {
        $porNombre = [];

        foreach ($campos as $campo) {
            $porNombre[$campo->nombre] = $campo;
        }

        $this->campos = $porNombre;
    }

    public function campo(string $nombre): ?Campo
    {
        return $this->campos[$nombre] ?? null;
    }

    /**
     * @return list<Campo>
     */
    public function visibles(bool $administrador, bool $medidas): array
    {
        return array_values(array_filter($this->campos, fn (Campo $campo) => $campo->medida === $medidas
            && ($administrador || ! $campo->soloAdministrador)));
    }
}
