<?php

namespace MoziStudios\Siat\Configuracion;

use MoziStudios\Siat\Constantes\Ambiente;
use MoziStudios\Siat\Constantes\Modalidad;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Soap\Servicio;

/**
 * Datos del sistema informático (proveedor) comunes a todos los contribuyentes: código de sistema
 * de la solicitud de autorización, ambiente, modalidad y ruta base de los servicios SOAP V3 (`/sfd/`).
 * No contiene secretos.
 */
final class ConfiguracionSistema
{
    public const URL_PILOTO = 'https://pilotosiatservicios.impuestos.gob.bo/sfd/';

    public readonly string $urlBase;

    public function __construct(
        public readonly string $codigoSistema,
        public readonly Ambiente $ambiente = Ambiente::Pruebas,
        public readonly Modalidad $modalidad = Modalidad::Computarizada,
        string $urlBase = self::URL_PILOTO,
        public readonly int $segundosConexion = 15,
        public readonly int $segundosRespuesta = 60,
    ) {
        if (preg_match('/^[A-Za-z0-9]{1,50}$/', $codigoSistema) !== 1) {
            throw new DatosInvalidos('Falta el código de sistema o tiene caracteres no válidos.');
        }

        if ($modalidad !== Modalidad::Computarizada) {
            throw new DatosInvalidos('Este paquete solo implementa la modalidad computarizada en línea.');
        }

        $host = parse_url($urlBase, PHP_URL_HOST);

        if (! str_starts_with($urlBase, 'https://') || ! is_string($host)) {
            throw new DatosInvalidos('La URL base de los servicios del SIN debe usar https.');
        }

        // Evita mezclar ambientes: una factura de pruebas nunca debe ir a producción, ni al revés.
        $esPiloto = str_contains($host, 'piloto');

        if ($ambiente === Ambiente::Produccion && $esPiloto) {
            throw new DatosInvalidos('El ambiente es producción pero la URL apunta al piloto del SIN.');
        }

        if ($ambiente === Ambiente::Pruebas && ! $esPiloto) {
            throw new DatosInvalidos('El ambiente es pruebas pero la URL no es la del piloto del SIN.');
        }

        if ($segundosConexion < 1 || $segundosRespuesta < 1) {
            throw new DatosInvalidos('Los tiempos de espera deben ser de al menos 1 segundo.');
        }

        $this->urlBase = rtrim($urlBase, '/').'/';
    }

    public function urlServicio(Servicio $servicio): string
    {
        return $this->urlBase.$servicio->value;
    }
}
