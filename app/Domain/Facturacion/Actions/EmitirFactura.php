<?php

namespace App\Domain\Facturacion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Facturacion\DatosFactura;
use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Facturacion\ErrorFacturacion;
use App\Enums\EstadoFactura;
use App\Jobs\Siat\EnviarFactura;
use App\Models\Empresa;
use App\Models\SiatFactura;
use App\Models\User;
use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Emisión desde el detalle de la venta, para el administrador de la empresa (gate facturacion-siat):
 * facturar una venta que quedó sin factura (modo manual o después de anular) y reintentar una factura
 * bloqueada, rechazada u observada, con los datos del comprador corregidos si hace falta.
 */
final class EmitirFactura
{
    public function __construct(
        private readonly RegistrarFacturaVenta $registro,
        private readonly Auditor $auditor,
    ) {}

    public function emitir(User $actor, Venta $venta, DatosFactura $datos): SiatFactura
    {
        Gate::forUser($actor)->authorize('view', $venta);
        Gate::forUser($actor)->authorize('facturacion-siat', Empresa::query()->findOrFail($venta->empresa_id));

        return DB::transaction(function () use ($actor, $venta, $datos): SiatFactura {
            // Bloquea la venta: dos clics simultáneos no crean dos facturas (además del índice único).
            $venta = DeEmpresa::consulta(Venta::class, $venta->empresa_id)->whereKey($venta->id)->lockForUpdate()->firstOrFail();

            if (Decimal::comparar((string) $venta->devuelto, '0', 2) > 0) {
                throw new ErrorFacturacion(__('La venta tiene devoluciones: no se puede facturar por el total original.'));
            }

            if (DeEmpresa::consulta(SiatFactura::class, $venta->empresa_id)->where('venta_vigente', $venta->id)->exists()) {
                throw new ErrorFacturacion(__('La venta ya tiene una factura vigente.'));
            }

            return $this->registro->registrar($actor, $venta, $datos)
                ?? throw new ErrorFacturacion(__('La facturación SIAT no está habilitada para esta empresa.'));
        });
    }

    /**
     * Pendiente: vuelve a poner el envío en cola (consulta su estado si ya se envió). Bloqueada, rechazada u
     * observada: recalcula con la configuración actual y, si se indican, con los datos corregidos del comprador.
     */
    public function reintentar(User $actor, SiatFactura $factura, ?DatosFactura $datos = null): SiatFactura
    {
        Gate::forUser($actor)->authorize('facturacion-siat', Empresa::query()->findOrFail($factura->empresa_id));

        $factura = DB::transaction(function () use ($actor, $factura, $datos): SiatFactura {
            $factura = DeEmpresa::consulta(SiatFactura::class, $factura->empresa_id)->whereKey($factura->id)->lockForUpdate()->firstOrFail();

            if ($factura->estado === EstadoFactura::Pendiente) {
                return $factura;
            }

            if (! $factura->estado->reintentable() || ! $factura->vigente()) {
                throw new ErrorFacturacion(__('Solo se reintenta una factura bloqueada, rechazada u observada.'));
            }

            $antes = ['estado' => $factura->estado->value, 'motivo' => $factura->motivo];

            if ($datos !== null) {
                $factura->tipo_documento = $datos->tipoDocumento;
                $factura->numero_documento = $datos->numeroDocumento;
                $factura->complemento = $datos->complemento;
                $factura->razon_social = $datos->razonSocial;
                $factura->codigo_cliente = $datos->codigoCliente();
                $factura->codigo_excepcion = $datos->codigoExcepcion;
                $factura->metodo_pago = $datos->metodoPago;
                $factura->numero_tarjeta = $datos->numeroTarjeta;
            }

            $this->registro->recalcular($factura);
            $factura->save();
            $this->auditor->registrar('siat.factura.reintentada', $factura, $actor, $factura->empresa_id, $antes, [
                'estado' => $factura->estado->value, 'motivo' => $factura->motivo, 'datos_comprador' => $datos !== null,
            ]);

            return $factura;
        });

        if ($factura->estado === EstadoFactura::Pendiente) {
            EnviarFactura::dispatch($factura->empresa_id, $factura->id);
        }

        return $factura;
    }
}
