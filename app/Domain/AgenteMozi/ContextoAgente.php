<?php

namespace App\Domain\AgenteMozi;

use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rol;
use App\Models\User;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Contexto seguro de un turno (AiContextFactory de § 4.2): empresa, usuario, rol, sucursal de
 * trabajo, zona horaria, fecha de referencia, idioma y funciones activas de la empresa. Sale siempre
 * del usuario autenticado; un empresa_id o sucursal propuesto por el modelo, el usuario o un documento
 * nunca lo reemplaza. referencia es el identificador de correlación del turno (registros, proveedor y
 * respuesta). Las capacidades se leen en el servidor al empezar el turno (§ 5.2): con ellas se ofrecen
 * o se niegan las fuentes de producción y de lotes.
 */
final readonly class ContextoAgente
{
    /**
     * @param  list<string>  $capacidades  valores de Capacidad activos en la empresa
     */
    public function __construct(
        public int $empresaId,
        public int $usuarioId,
        public Rol $rol,
        public ?int $sucursalId,
        public string $zonaHoraria,
        public CarbonImmutable $fechaReferencia,
        public string $idioma,
        public string $referencia = '',
        public array $capacidades = [],
    ) {}

    public static function desde(User $usuario, string $referencia = ''): self
    {
        if (! $usuario->perteneceAEmpresa() || $usuario->rol === null) {
            throw new LogicException('Agente Mozi requiere un usuario de empresa.');
        }

        $zona = (string) config('mozistock.business_timezone');

        return new self(
            empresaId: (int) $usuario->empresa_id,
            usuarioId: $usuario->id,
            rol: $usuario->rol,
            sucursalId: $usuario->sucursalActivaId(),
            zonaHoraria: $zona,
            fechaReferencia: CarbonImmutable::now($zona),
            idioma: app()->getLocale(),
            referencia: $referencia,
            capacidades: self::capacidadesDe($usuario),
        );
    }

    /**
     * Funciones activas de la empresa del usuario, leídas en el servidor (nunca de la petición).
     *
     * @return list<string>
     */
    public static function capacidadesDe(User $usuario): array
    {
        return array_map(fn (Capacidad $c): string => $c->value, app(CapacidadesEmpresa::class)->activas((int) $usuario->empresa_id));
    }

    public function tiene(Capacidad $capacidad): bool
    {
        return in_array($capacidad->value, $this->capacidades, true);
    }

    /** Producción solo para el administrador con la capacidad (PR16), como el Gate usar-produccion. */
    public function produccion(): bool
    {
        return $this->rol === Rol::Administrador && $this->tiene(Capacidad::Produccion);
    }

    public function lotes(): bool
    {
        return $this->tiene(Capacidad::LotesVencimientos);
    }
}
