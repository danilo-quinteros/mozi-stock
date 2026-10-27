<?php

namespace MoziStudios\Siat\Respuestas;

/**
 * Respuesta de los servicios de facturación (recepción, estado, anulación, reversión).
 * Estados del anexo «Emisión y envío»: 908 validada, 904 observada, 901 pendiente; el resto
 * se interpreta con la paramétrica de mensajes sincronizada.
 */
final class RespuestaRecepcion
{
    public const ESTADO_PENDIENTE = 901;

    public const ESTADO_RECHAZADA = 902;

    public const ESTADO_OBSERVADA = 904;

    /** Respuesta de anulacionFactura cuando el SIN anuló el documento. */
    public const ESTADO_ANULACION_CONFIRMADA = 905;

    /** Respuesta de reversionAnulacionFactura cuando el SIN revirtió la anulación. */
    public const ESTADO_REVERSION_CONFIRMADA = 907;

    public const ESTADO_VALIDADA = 908;

    /**
     * @param  list<Mensaje>  $mensajes
     */
    public function __construct(
        public readonly bool $transaccion,
        public readonly array $mensajes,
        public readonly ?int $codigoEstado,
        public readonly ?string $codigoDescripcion,
        public readonly ?string $codigoRecepcion,
    ) {}

    public static function desde(object $respuesta): self
    {
        return new self(
            Lector::booleano($respuesta, 'transaccion'),
            Lector::mensajes($respuesta),
            Lector::entero($respuesta, 'codigoEstado'),
            Lector::texto($respuesta, 'codigoDescripcion'),
            Lector::texto($respuesta, 'codigoRecepcion'),
        );
    }

    public function validada(): bool
    {
        return $this->codigoEstado === self::ESTADO_VALIDADA;
    }

    public function observada(): bool
    {
        return $this->codigoEstado === self::ESTADO_OBSERVADA;
    }

    public function pendiente(): bool
    {
        return $this->codigoEstado === self::ESTADO_PENDIENTE;
    }

    public function rechazada(): bool
    {
        return $this->codigoEstado === self::ESTADO_RECHAZADA;
    }

    public function anulacionConfirmada(): bool
    {
        return $this->codigoEstado === self::ESTADO_ANULACION_CONFIRMADA;
    }

    public function reversionConfirmada(): bool
    {
        return $this->codigoEstado === self::ESTADO_REVERSION_CONFIRMADA;
    }
}
