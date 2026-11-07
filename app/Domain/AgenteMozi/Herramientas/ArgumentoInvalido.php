<?php

namespace App\Domain\AgenteMozi\Herramientas;

use RuntimeException;

/**
 * Argumentos que pasan el esquema pero no tienen sentido para los datos (campo inexistente, fuente
 * no permitida, rango de fechas al revés). El mensaje vuelve al modelo para que corrija la llamada:
 * nunca lleva datos de negocio ni detalles internos.
 */
final class ArgumentoInvalido extends RuntimeException {}
