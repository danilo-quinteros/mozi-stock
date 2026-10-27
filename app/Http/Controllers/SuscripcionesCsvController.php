<?php

namespace App\Http\Controllers;

use App\Domain\Suscripciones\CalendarioSuscripcion;
use App\Domain\Suscripciones\Consultas\ListadoComprobantes;
use App\Domain\Suscripciones\Consultas\ListadoSuscripciones;
use App\Enums\EstadoPagoSaas;
use App\Models\PagoSaas;
use App\Models\Suscripcion;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV del panel global con la misma consulta y filtros de cada pantalla (T23). Solo datos
 * del plan SaaS: nada comercial de las empresas.
 */
class SuscripcionesCsvController extends Controller
{
    public function suscripciones(Request $request): StreamedResponse
    {
        Gate::forUser($request->user())->authorize('configurar', Suscripcion::class);
        $hoy = CalendarioSuscripcion::hoy();
        $filas = ListadoSuscripciones::consulta((string) $request->query('q', ''), (string) $request->query('estado', ''), $hoy)->lazy(500);

        return Csv::descargar('suscripciones.csv', [__('Empresa'), __('Correo'), __('Mensualidad'), __('Vencimiento'), __('Estado'), __('Comprobante en revisión'), __('Acceso')],
            function () use ($filas, $hoy): iterable {
                foreach ($filas as $f) {
                    $estado = $f->vencimiento === null ? __('Sin plan') : CalendarioSuscripcion::estado(CalendarioSuscripcion::fecha((string) $f->vencimiento), $hoy)->etiqueta();
                    yield [$f->nombre, $f->email ?? '', $f->precio_mensual === null ? '' : (string) $f->precio_mensual,
                        $f->vencimiento === null ? '' : CalendarioSuscripcion::fecha((string) $f->vencimiento)->format('d/m/Y'),
                        $estado, (int) $f->en_revision > 0 ? __('Sí') : __('No'), $f->activa ? __('Activa') : __('Inactiva')];
                }
            });
    }

    public function comprobantes(Request $request): StreamedResponse
    {
        Gate::forUser($request->user())->authorize('revisar', PagoSaas::class);
        $filas = ListadoComprobantes::consulta((string) $request->query('q', ''), (string) $request->query('estado', ''))->lazy(500);
        $zona = (string) config('mozistock.business_timezone');

        return Csv::descargar('comprobantes-saas.csv', [__('Comprobante'), __('Empresa'), __('Enviado'), __('Envió'), __('Meses'), __('Precio mensual'), __('Importe'), __('Estado'), __('Revisado'), __('Revisó'), __('Vencimiento anterior'), __('Vencimiento nuevo'), __('Motivo de rechazo')],
            function () use ($filas, $zona): iterable {
                foreach ($filas as $f) {
                    yield ['CP-'.str_pad((string) $f->id, 5, '0', STR_PAD_LEFT), $f->empresa, Carbon::parse($f->created_at)->timezone($zona)->format('d/m/Y H:i'),
                        $f->remitente, (int) $f->meses, (string) $f->precio_mensual, (string) $f->importe, EstadoPagoSaas::from($f->estado)->etiqueta(),
                        $f->revisado_en === null ? '' : Carbon::parse($f->revisado_en)->timezone($zona)->format('d/m/Y H:i'), $f->revisor ?? '',
                        $f->vencimiento_anterior === null ? '' : CalendarioSuscripcion::fecha((string) $f->vencimiento_anterior)->format('d/m/Y'),
                        $f->vencimiento_nuevo === null ? '' : CalendarioSuscripcion::fecha((string) $f->vencimiento_nuevo)->format('d/m/Y'),
                        $f->motivo_rechazo ?? ''];
                }
            });
    }
}
