<?php

namespace MoziStudios\Siat\Tests;

use DateTimeImmutable;
use DateTimeZone;
use MoziStudios\Siat\Codigos\Cufd;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Configuracion\Contribuyente;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Factura\DetalleCompraVenta;
use MoziStudios\Siat\Factura\FacturaCompraVenta;
use MoziStudios\Siat\Fecha\FechaSiat;

/**
 * Datos de prueba. La factura reproduce el ejemplo oficial del anexo (facturaComputarizadaCompraVenta.xml);
 * NIT, CUFD y token son ficticios.
 */
final class Fabrica
{
    public const TOKEN = 'eyJ0b2tlbi1kZS1wcnVlYmEifQ.ficticio.SECRETO1234';

    public static function configuracion(): ConfiguracionSistema
    {
        return new ConfiguracionSistema('ABC123DEF456');
    }

    public static function contribuyente(int $nit = 123451020): Contribuyente
    {
        return new Contribuyente($nit, new TokenDelegado(self::TOKEN));
    }

    public static function cufd(): Cufd
    {
        return new Cufd('BQUE+QytqQUDBKVUFOSVRPQkxVRFZNVFVJBMDAwMDAwM', 'A19E23EF34124CD', 'A', 'Av. Juan XXIII');
    }

    public static function fecha(): FechaSiat
    {
        return FechaSiat::desde(new DateTimeImmutable('2027-06-01 09:19:48.675', new DateTimeZone('America/La_Paz')));
    }

    /**
     * @param  array<string, mixed>  $cambios  argumentos con nombre que reemplazan a los del ejemplo
     */
    public static function factura(array $cambios = []): FacturaCompraVenta
    {
        $argumentos = $cambios + [
            'nitEmisor' => 123451020,
            'razonSocialEmisor' => 'Metales Totai S.R.L.',
            'municipio' => 'Yacuiba',
            'telefono' => '2824512',
            'numeroFactura' => 2377,
            'punto' => new PuntoEmision(1, 0),
            'direccion' => 'Av. Juan XXIII',
            'fechaEmision' => self::fecha(),
            'cufd' => self::cufd(),
            'nombreRazonSocial' => 'DAVID ZELADA',
            'codigoTipoDocumentoIdentidad' => 1,
            'numeroDocumento' => '987654',
            'complemento' => '1B',
            'codigoCliente' => '1864',
            'codigoMetodoPago' => 1,
            'subTotalNeto' => '262.12',
            'totalPrecioNeto' => '142.12',
            'iva' => '18.48',
            'montoaPagar' => '160.60',
            'codigoMoneda' => 1,
            'tipoCambio' => '1',
            'montoTotalMoneda' => '160.60',
            'montoGiftCard' => '100',
            'descuentoAdicional' => '20',
            'leyenda' => 'Ley N° 453: El proveedor deberá entregar el producto en las modalidades y términos ofertados o convenidos.',
            'usuario' => 'pperez',
            'detalles' => self::detalles(),
        ];

        return new FacturaCompraVenta(...$argumentos);
    }

    /**
     * @return list<DetalleCompraVenta>
     */
    public static function detalles(): array
    {
        return [
            new DetalleCompraVenta('4752100', 1001912, 'GA-CL-013', 'GANCHO P/ CALAMINA M6*J 60', '200', 1, '0.54', '100.48', '7.52', '0', '0'),
            new DetalleCompraVenta('4752100', 1001912, 'GA-CL-015', 'GANCHO P/ CALAMINA M6*J 70', '100', 1, '0.61', '56.89', '4.11', '0', '0'),
            new DetalleCompraVenta('4752100', 1001912, 'RP-FR-01', 'REPOSICIÓN FORMULARIO', '1', 1, '0.83', '0.83', '0.00', '0', '0'),
            new DetalleCompraVenta('4752100', 1001912, 'HI-CO-004', 'HI. CORRUGADO 5/16" (8,00MM) CA- 50', '3', 1, '34.64', '103.92', '0.00', '0', '0'),
        ];
    }

    /**
     * Factura de una línea con los montos indicados (para probar el IVA de importes pequeños).
     */
    public static function facturaSimple(string $precioNeto, string $iva, string $montoaPagar): FacturaCompraVenta
    {
        return self::factura([
            'detalles' => [new DetalleCompraVenta('4752100', 1001912, 'P1', 'Producto', '1', 1, $precioNeto, $precioNeto)],
            'subTotalNeto' => $precioNeto,
            'totalPrecioNeto' => $precioNeto,
            'iva' => $iva,
            'montoaPagar' => $montoaPagar,
            'montoTotalMoneda' => $montoaPagar,
            'montoGiftCard' => null,
            'descuentoAdicional' => null,
        ]);
    }
}
