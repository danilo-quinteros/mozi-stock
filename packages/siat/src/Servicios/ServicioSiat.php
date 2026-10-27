<?php

namespace MoziStudios\Siat\Servicios;

use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Configuracion\Contribuyente;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Soap\Servicio;
use MoziStudios\Siat\Soap\Transporte;

/**
 * Base de los servicios: cada instancia queda atada a UN contribuyente (su NIT y su token), de modo
 * que una solicitud nunca puede salir con el NIT de una empresa y el token de otra.
 */
abstract class ServicioSiat
{
    public function __construct(
        protected readonly ConfiguracionSistema $configuracion,
        protected readonly Transporte $transporte,
        protected readonly Contribuyente $contribuyente,
    ) {}

    abstract protected function servicio(): Servicio;

    /**
     * @param  array<string, mixed>  $parametros
     */
    protected function llamar(string $operacion, array $parametros): object
    {
        return $this->transporte->llamar($this->configuracion, $this->servicio(), $operacion, $parametros, $this->contribuyente->token);
    }

    protected static function codigo(string $valor, string $campo): string
    {
        $valor = trim($valor);

        if ($valor === '' || preg_match('/^[A-Za-z0-9+\/=_-]+$/', $valor) !== 1) {
            throw new DatosInvalidos("{$campo} está vacío o tiene caracteres no válidos.");
        }

        return $valor;
    }
}
