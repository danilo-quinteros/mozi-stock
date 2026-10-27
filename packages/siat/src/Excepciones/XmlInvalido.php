<?php

namespace MoziStudios\Siat\Excepciones;

/**
 * El XML no está bien formado o no cumple el XSD oficial. Nunca se envía al SIN un XML que no pasó la validación.
 */
final class XmlInvalido extends SiatException
{
    /**
     * @param  list<string>  $errores  mensajes de libxml con su línea
     */
    public function __construct(public readonly array $errores)
    {
        parent::__construct('El XML no cumple el XSD oficial: '.implode(' | ', $errores));
    }
}
