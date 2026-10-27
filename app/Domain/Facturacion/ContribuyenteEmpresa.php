<?php

namespace App\Domain\Facturacion;

use App\Models\Empresa;
use App\Models\SiatConfiguracion;
use Illuminate\Contracts\Encryption\DecryptException;
use MoziStudios\Siat\Configuracion\Contribuyente;
use MoziStudios\Siat\Configuracion\TokenDelegado;

/**
 * Único lugar donde se descifra el token delegado: arma el Contribuyente (NIT y token) de UNA empresa
 * para una llamada al SIN, después de comprobar que la empresa puede facturar.
 */
final class ContribuyenteEmpresa
{
    /**
     * @throws ErrorFacturacion
     */
    public function para(Empresa $empresa): Contribuyente
    {
        if (! $empresa->activa || ! $empresa->siat_habilitado) {
            throw new ErrorFacturacion(__('La facturación SIAT no está habilitada para esta empresa.'));
        }

        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresa->id)->first();

        if ($configuracion?->nit === null) {
            throw new ErrorFacturacion(__('Falta registrar el NIT fiscal de la empresa.'));
        }

        if (! $configuracion->tieneToken()) {
            throw new ErrorFacturacion(__('Falta cargar el token delegado del SIN.'));
        }

        if ($configuracion->tokenVencido()) {
            throw new ErrorFacturacion(__('El token delegado venció el :fecha. Genera uno nuevo en el portal SIAT y cárgalo.', [
                'fecha' => $configuracion->token_vence_en?->timezone((string) config('mozistock.business_timezone'))->format('d/m/Y'),
            ]));
        }

        try {
            $token = $configuracion->tokenDescifrado();
        } catch (DecryptException) {
            throw new ErrorFacturacion(__('No se pudo leer el token guardado. Vuelve a cargarlo.'));
        }

        return new Contribuyente($configuracion->nit, new TokenDelegado($token));
    }
}
