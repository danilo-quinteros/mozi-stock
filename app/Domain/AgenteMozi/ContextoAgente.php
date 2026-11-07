<?php

namespace App\Domain\AgenteMozi;

use App\Enums\Rol;
use App\Models\User;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Contexto seguro de un turno (AiContextFactory de § 4.2): empresa, usuario, rol, sucursal de
 * trabajo, zona horaria, fecha de referencia e idioma. Sale siempre del usuario autenticado; un
 * empresa_id o sucursal propuesto por el modelo, el usuario o un documento nunca lo reemplaza.
 * referencia es el identificador de correlación del turno (registros, proveedor y respuesta).
 */
final readonly class ContextoAgente
{
    public function __construct(
        public int $empresaId,
        public int $usuarioId,
        public Rol $rol,
        public ?int $sucursalId,
        public string $zonaHoraria,
        public CarbonImmutable $fechaReferencia,
        public string $idioma,
        public string $referencia = '',
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
        );
    }
}
