<?php

namespace App\Domain\Facturacion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Facturacion\CatalogoEmpresa;
use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Facturacion\EmisionFacturas;
use App\Domain\Facturacion\ErrorFacturacion;
use App\Enums\EstadoFactura;
use App\Models\Empresa;
use App\Models\SiatFactura;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use MoziStudios\Siat\Respuestas\RespuestaRecepcion;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Anulación y reversión de anulación ante el SIN (gate configurar-facturacion: nunca durante «Entrar como»,
 * decisión D6). Plazo: hasta el día 9 del mes siguiente a la emisión. La reversión se permite una sola vez.
 * Anular la factura no toca la venta, la caja ni el stock; la devolución es otra operación.
 */
final class AnularFactura
{
    public function __construct(
        private readonly EmisionFacturas $emision,
        private readonly Auditor $auditor,
    ) {}

    public function anular(User $actor, SiatFactura $factura, int $motivo): SiatFactura
    {
        Gate::forUser($actor)->authorize('configurar-facturacion', Empresa::query()->findOrFail($factura->empresa_id));

        if (! CatalogoEmpresa::existe($factura->empresa_id, Catalogo::MotivosAnulacion, $motivo)) {
            throw new ErrorFacturacion(__('Elige un motivo de anulación del catálogo del SIN.'));
        }

        return $this->emision->conFacturaBloqueada($factura->id, function () use ($actor, $factura, $motivo): SiatFactura {
            $factura = DeEmpresa::consulta(SiatFactura::class, $factura->empresa_id)->findOrFail($factura->id);

            if ($factura->estado !== EstadoFactura::Validada || $factura->cuf === null) {
                throw new ErrorFacturacion(__('Solo se anula una factura validada por el SIN.'));
            }

            if (! $factura->dentroDelPlazoDeAnulacion()) {
                throw new ErrorFacturacion(__('Venció el plazo de anulación: hasta el día 9 del mes siguiente a la emisión.'));
            }

            [$servicio, $contexto] = $this->emision->operacion($factura);
            $respuesta = $servicio->anulacion($contexto, $factura->cuf, $motivo);

            if ($respuesta->codigoEstado !== RespuestaRecepcion::ESTADO_ANULACION_CONFIRMADA) {
                throw ErrorFacturacion::delSin(__('El SIN no anuló la factura.'), $respuesta->mensajes);
            }

            $factura->estado = EstadoFactura::Anulada;
            $factura->venta_vigente = null;
            $factura->motivo_anulacion = $motivo;
            $factura->anulada_en = Carbon::now();
            $factura->anulada_por = $actor->id;
            $factura->codigo_estado = $respuesta->codigoEstado;
            $factura->mensajes = EmisionFacturas::mensajes($respuesta);
            $factura->motivo = null;
            $factura->save();

            $this->auditor->registrar('siat.factura.anulada', $factura, $actor, $factura->empresa_id,
                ['estado' => EstadoFactura::Validada->value], ['estado' => $factura->estado->value, 'motivo_anulacion' => $motivo, 'cuf' => $factura->cuf]);

            return $factura;
        });
    }

    public function revertir(User $actor, SiatFactura $factura): SiatFactura
    {
        Gate::forUser($actor)->authorize('configurar-facturacion', Empresa::query()->findOrFail($factura->empresa_id));

        return $this->emision->conFacturaBloqueada($factura->id, function () use ($actor, $factura): SiatFactura {
            $factura = DeEmpresa::consulta(SiatFactura::class, $factura->empresa_id)->findOrFail($factura->id);

            if ($factura->estado !== EstadoFactura::Anulada || $factura->anulada_en === null || $factura->cuf === null) {
                throw new ErrorFacturacion(__('Solo se revierte la anulación de una factura anulada.'));
            }

            if ($factura->revertida_en !== null) {
                throw new ErrorFacturacion(__('La anulación de esta factura ya se revirtió una vez; el SIN no permite otra.'));
            }

            if (! $factura->dentroDelPlazoDeAnulacion()) {
                throw new ErrorFacturacion(__('Venció el plazo para revertir la anulación: hasta el día 9 del mes siguiente a la emisión.'));
            }

            if (DeEmpresa::consulta(SiatFactura::class, $factura->empresa_id)->where('venta_vigente', $factura->venta_id)->exists()) {
                throw new ErrorFacturacion(__('La venta ya tiene otra factura vigente: no se puede revertir esta anulación.'));
            }

            [$servicio, $contexto] = $this->emision->operacion($factura);
            $respuesta = $servicio->reversionAnulacion($contexto, $factura->cuf);

            if ($respuesta->codigoEstado !== RespuestaRecepcion::ESTADO_REVERSION_CONFIRMADA) {
                throw ErrorFacturacion::delSin(__('El SIN no revirtió la anulación.'), $respuesta->mensajes);
            }

            $factura->estado = EstadoFactura::Validada;
            $factura->venta_vigente = $factura->venta_id;
            $factura->revertida_en = Carbon::now();
            $factura->revertida_por = $actor->id;
            $factura->codigo_estado = $respuesta->codigoEstado;
            $factura->mensajes = EmisionFacturas::mensajes($respuesta);
            $factura->save();

            $this->auditor->registrar('siat.factura.anulacion_revertida', $factura, $actor, $factura->empresa_id,
                ['estado' => EstadoFactura::Anulada->value], ['estado' => $factura->estado->value, 'cuf' => $factura->cuf]);

            return $factura;
        });
    }
}
