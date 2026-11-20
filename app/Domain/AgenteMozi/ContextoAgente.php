<?php

namespace App\Domain\AgenteMozi;

use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Permiso;
use App\Models\User;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Contexto seguro de un turno (AiContextFactory de § 4.2): empresa, usuario, alcance, sucursal de
 * trabajo, zona horaria, fecha de referencia, idioma y funciones activas de la empresa. Sale siempre
 * del usuario autenticado; un empresa_id o sucursal propuesto por el modelo, el usuario o un documento
 * nunca lo reemplaza. referencia es el identificador de correlación del turno (registros, proveedor y
 * respuesta). Las capacidades se leen en el servidor al empezar el turno (§ 5.2): con ellas se ofrecen
 * o se niegan las fuentes de producción y de lotes.
 *
 * amplio (antes «administrador»): el usuario ve toda la empresa con costos, compras, gastos y cuentas. Exige
 * reportes.ver y trabajar en todas las sucursales (ESPECIFICACION_PERMISOS.md § 6.7); sin eso el agente responde
 * solo sobre su sucursal y sin datos sensibles, como hasta ahora al vendedor.
 */
final readonly class ContextoAgente
{
    /**
     * @param  list<string>  $capacidades  valores de Capacidad activos en la empresa
     */
    public function __construct(
        public int $empresaId,
        public int $usuarioId,
        public bool $amplio,
        public ?int $sucursalId,
        public string $zonaHoraria,
        public CarbonImmutable $fechaReferencia,
        public string $idioma,
        public string $referencia = '',
        public array $capacidades = [],
        public bool $produccionPermitida = false,
    ) {}

    public static function desde(User $usuario, string $referencia = ''): self
    {
        if (! $usuario->perteneceAEmpresa()) {
            throw new LogicException('Agente Mozi requiere un usuario de empresa.');
        }

        $zona = (string) config('mozistock.business_timezone');
        $amplio = self::amplio($usuario);

        return new self(
            empresaId: (int) $usuario->empresa_id,
            usuarioId: $usuario->id,
            amplio: $amplio,
            // Sin vista amplia siempre hay una sucursal: la elegida en el menú o la asignada.
            sucursalId: $amplio ? $usuario->sucursalActivaId() : ($usuario->sucursalActivaId() ?? $usuario->sucursal_id),
            zonaHoraria: $zona,
            fechaReferencia: CarbonImmutable::now($zona),
            idioma: app()->getLocale(),
            referencia: $referencia,
            capacidades: self::capacidadesDe($usuario),
            produccionPermitida: $usuario->tienePermiso(Permiso::ProduccionGestionar),
        );
    }

    /** Vista amplia del agente: reportes.ver y todas las sucursales (el administrador siempre). */
    public static function amplio(User $usuario): bool
    {
        return $usuario->tienePermiso(Permiso::ReportesVer) && $usuario->veTodasLasSucursales();
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

    /** Producción con el permiso produccion.gestionar y la capacidad, como el Gate usar-produccion. */
    public function produccion(): bool
    {
        return $this->produccionPermitida && $this->tiene(Capacidad::Produccion);
    }

    public function lotes(): bool
    {
        return $this->tiene(Capacidad::LotesVencimientos);
    }
}
