<?php

namespace App\Domain\AgenteMozi\Lienzo;

use InvalidArgumentException;

/**
 * Un dataset o artefacto no cumple el protocolo del Lienzo. El mensaje es técnico (para el registro
 * con su referencia de correlación); al usuario nunca se le muestra un artefacto inventado en su lugar.
 */
final class ArtefactoInvalido extends InvalidArgumentException {}
