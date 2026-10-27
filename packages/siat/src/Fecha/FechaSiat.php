<?php

namespace MoziStudios\Siat\Fecha;

use DateTimeImmutable;
use DateTimeInterface;
use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * Instante de emisión con precisión de milisegundos. Es el MISMO valor el que se escribe en
 * fechaEmision (yyyy-MM-ddTHH:mm:ss.SSS, sin zona) y el que entra al CUF (yyyyMMddHHmmssSSS):
 * así nunca difieren por llamar dos veces al reloj.
 */
final class FechaSiat
{
    private function __construct(private readonly DateTimeImmutable $momento) {}

    /** Trunca a milisegundos conservando la zona horaria recibida (la del emisor). */
    public static function desde(DateTimeInterface $fecha): self
    {
        $momento = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $fecha->format('Y-m-d H:i:s.v'), $fecha->getTimezone());

        if ($momento === false) {
            throw new DatosInvalidos('Fecha de emisión inválida.');
        }

        return new self($momento);
    }

    public function xml(): string
    {
        return $this->momento->format('Y-m-d\TH:i:s.v');
    }

    public function cuf(): string
    {
        return $this->momento->format('YmdHisv');
    }

    public function momento(): DateTimeImmutable
    {
        return $this->momento;
    }
}
