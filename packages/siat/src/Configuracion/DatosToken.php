<?php

namespace MoziStudios\Siat\Configuracion;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;

/**
 * Datos públicos del token delegado (JWT del SIN), leídos sin verificar la firma: sirven para
 * avisar a tiempo de errores evidentes (token de otro NIT, de otro sistema o vencido), no como
 * control de seguridad. El SIN es quien acepta o rechaza el token en cada llamada.
 *
 * Reclamos observados en el piloto (24/10/2026): `nitDelegado` (número), `nit` (número comprimido
 * con gzip y en base64), `codigoSistema`, `exp` e `iat`.
 */
final class DatosToken
{
    private const MAXIMO_JSON = 8192;

    /**
     * @param  list<int>  $nits  NIT que figuran en el token
     */
    public function __construct(
        public readonly array $nits,
        public readonly ?string $codigoSistema,
        public readonly ?DateTimeImmutable $vence,
    ) {}

    /** null si el texto no tiene forma de JWT con datos legibles. */
    public static function leer(TokenDelegado $token): ?self
    {
        $partes = explode('.', $token->valor());

        if (count($partes) !== 3 || strlen($partes[1]) > self::MAXIMO_JSON) {
            return null;
        }

        $json = base64_decode(strtr($partes[1], '-_', '+/'), true);

        try {
            $datos = is_string($json) ? json_decode($json, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            return null;
        }

        if (! is_array($datos)) {
            return null;
        }

        $nits = array_values(array_unique(array_filter([
            self::numero($datos['nitDelegado'] ?? null),
            self::numero($datos['nit'] ?? null) ?? self::numeroComprimido($datos['nit'] ?? null),
        ])));
        $codigo = isset($datos['codigoSistema']) && is_string($datos['codigoSistema']) ? $datos['codigoSistema'] : null;
        $vence = isset($datos['exp']) && is_int($datos['exp']) ? new DateTimeImmutable('@'.$datos['exp']) : null;

        return new self($nits, $codigo, $vence);
    }

    public function incluyeNit(int $nit): bool
    {
        return in_array($nit, $this->nits, true);
    }

    public function vencido(DateTimeInterface $momento): bool
    {
        return $this->vence !== null && $this->vence <= $momento;
    }

    private static function numero(mixed $valor): ?int
    {
        if (is_int($valor) && $valor > 0) {
            return $valor;
        }

        return is_string($valor) && preg_match('/^[1-9]\d{0,12}$/', $valor) === 1 ? (int) $valor : null;
    }

    private static function numeroComprimido(mixed $valor): ?int
    {
        $binario = is_string($valor) ? base64_decode($valor, true) : false;

        if (! is_string($binario) || ! str_starts_with($binario, "\x1f\x8b")) {
            return null;
        }

        // Datos no confiables: límite de tamaño y sin avisos de PHP (ni excepciones del framework) si el gzip está dañado.
        set_error_handler(static fn (): bool => true);

        try {
            $texto = gzdecode($binario, 64);
        } finally {
            restore_error_handler();
        }

        return is_string($texto) ? self::numero($texto) : null;
    }
}
