<?php

namespace MoziStudios\Siat\Excepciones;

use RuntimeException;

/**
 * Base de todas las excepciones del paquete: permite atraparlas juntas sin capturar errores ajenos.
 * Ningún mensaje incluye el token delegado.
 */
class SiatException extends RuntimeException {}
