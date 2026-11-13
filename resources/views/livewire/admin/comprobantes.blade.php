@use('App\Domain\Suscripciones\CalendarioSuscripcion')
@use('App\Enums\EstadoPagoSaas')
@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Superadministración') }}</div>
            <h1>{{ __('Comprobantes de pago') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Revisión manual de pagos de empresas.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('admin.comprobantes.exportar', array_filter(['q' => $buscar, 'estado' => $estado])) }}"><x-icon name="download" /> {{ __('Exportar listado') }}</a>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat">
            <span class="stat-label">{{ __('Pendientes') }}</span>
            <div class="stat-value">{{ $conteos['pendientes'] }}</div>
            <div class="stat-foot">{{ __('Revisar manualmente') }}</div>
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Aprobados este mes') }}</span>
            <div class="stat-value">{{ $conteos['aprobados'] }}</div>
            <div class="stat-foot">{{ __('Cada uno renovó su suscripción') }}</div>
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Rechazados este mes') }}</span>
            <div class="stat-value">{{ $conteos['rechazados'] }}</div>
            <div class="stat-foot">{{ __('Necesitan un nuevo comprobante') }}</div>
        </article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="receipt" /></span>{{ __('Solicitudes de pago') }}</div></div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar por empresa...') }}" aria-label="{{ __('Buscar por empresa') }}">
            </div>
            <select class="select" wire:model.live="estado" aria-label="{{ __('Filtrar por estado') }}">
                <option value="">{{ __('Todos los estados') }}</option>
                <option value="pendiente">{{ __('En revisión') }}</option>
                <option value="aprobado">{{ __('Aprobados') }}</option>
                <option value="rechazado">{{ __('Rechazados') }}</option>
            </select>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Empresa') }}</th><th>{{ __('Enviado') }}</th><th>{{ __('Período') }}</th><th>{{ __('Importe') }}</th><th>{{ __('Comprobante') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Revisión') }}</th></tr></thead>
                <tbody>
                    @forelse ($comprobantes as $fila)
                        @php($estadoFila = EstadoPagoSaas::from($fila->estado))
                        <tr wire:key="comprobante-{{ $fila->id }}">
                            <td><b>{{ $fila->empresa }}</b><small style="display:block;color:var(--faint);margin-top:4px">CP-{{ str_pad((string) $fila->id, 5, '0', STR_PAD_LEFT) }} · {{ $fila->remitente }}</small></td>
                            <td>{{ \Illuminate\Support\Carbon::parse($fila->created_at)->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</td>
                            <td>
                                @if ($fila->tipo === 'extras')
                                    <span class="badge info">{{ __('Extras') }}</span>
                                    <small style="display:block;color:var(--faint);margin-top:4px">{{ trans_choice(':count sucursal extra|:count sucursales extra', (int) $fila->sucursales_extra) }} · {{ trans_choice(':count paquete de usuarios|:count paquetes de usuarios', (int) $fila->paquetes_usuarios) }}</small>
                                @elseif ($fila->vencimiento_nuevo)
                                    {{ CalendarioSuscripcion::fecha((string) $fila->vencimiento_anterior)->format('d/m/Y') }} → {{ CalendarioSuscripcion::fecha((string) $fila->vencimiento_nuevo)->format('d/m/Y') }}
                                @else
                                    {{ $fila->meses }} {{ (int) $fila->meses === 1 ? 'mes' : 'meses' }}
                                @endif
                            </td>
                            <td>{{ Decimal::bs((string) $fila->importe) }}</td>
                            <td><a class="btn sm ghost" href="{{ route('admin.comprobantes.archivo', $fila->id) }}" target="_blank" rel="noopener">{{ $fila->archivo_mime === 'application/pdf' ? __('Descargar PDF') : __('Ver archivo') }}</a></td>
                            <td><span class="badge {{ $estadoFila->insignia() }}" style="white-space:nowrap">{{ $estadoFila->etiqueta() }}</span></td>
                            <td><button class="btn {{ $estadoFila === EstadoPagoSaas::Pendiente ? 'soft' : 'ghost' }} sm" type="button" wire:click="revisar({{ $fila->id }})">{{ $estadoFila === EstadoPagoSaas::Pendiente ? __('Revisar') : __('Detalle') }}</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty">{{ __('No hay comprobantes que coincidan con el filtro.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($comprobantes->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $comprobantes->currentPage(), 'lastpage' => $comprobantes->lastPage()]) }}</span>
                <span class="row">
                    @unless ($comprobantes->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($comprobantes->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
        <div class="divider"></div>
        <div class="notice"><x-icon name="info" /><div><b>{{ __('La imagen no confirma el pago') }}</b><p>{{ __('Aprueba solo después de ver el importe exacto en la cuenta de MoziStudios. Aprobar o rechazar queda auditado y no se repite con un doble clic.') }}</p></div></div>
    </section>

    @if ($revisando)
        <x-modal :titulo="($revisando->estado === EstadoPagoSaas::Pendiente ? 'Revisar comprobante ' : 'Comprobante ').$revisando->codigo()" cerrar="cerrar"
            descripcion="Aprobación exclusiva del superadministrador. Solo un pago aprobado renueva la suscripción.">
            <div class="list-line"><span>{{ __('Empresa') }}</span><b>{{ $revisando->empresa->nombre }}</b></div>
            <div class="list-line"><span>{{ __('Enviado por') }}</span><b>{{ $revisando->remitente->name }}</b></div>
            <div class="list-line"><span>{{ __('Fecha de envío') }}</span><b>{{ $revisando->created_at->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</b></div>
            @if ($revisando->esExtras())
                <div class="list-line"><span>{{ __('Concepto') }}</span><b>{{ __('Extras a mitad del período') }}</b></div>
                <div class="list-line"><span>{{ __('Extras que se activan') }}</span><b>{{ trans_choice(':count sucursal extra|:count sucursales extra', (int) $revisando->sucursales_extra) }} · {{ trans_choice(':count paquete de usuarios|:count paquetes de usuarios', (int) $revisando->paquetes_usuarios) }}</b></div>
                <div class="list-line"><span>{{ __('Mensualidad desde el próximo pago') }}</span><b>{{ Decimal::bs((string) $revisando->precio_mensual) }}</b></div>
            @else
                <div class="list-line"><span>{{ __('Meses') }}</span><b>{{ $revisando->meses }} × {{ Decimal::bs((string) $revisando->precio_mensual) }}</b></div>
                @if ($revisando->precio_plan !== null && (int) $revisando->sucursales_extra + (int) $revisando->paquetes_usuarios > 0)
                    <div class="list-line"><span>{{ __('Mensualidad') }}</span><b>{{ __('Plan :plan + extras :extras', ['plan' => Decimal::bs((string) $revisando->precio_plan), 'extras' => Decimal::bs(Decimal::restar((string) $revisando->precio_mensual, (string) $revisando->precio_plan, 2))]) }}</b></div>
                @endif
            @endif
            <div class="list-line"><span>{{ __('Importe esperado') }}</span><b>{{ $importeTexto }}</b></div>
            @if ($revisando->nota)
                <div class="list-line"><span>{{ __('Nota de la empresa') }}</span><b style="text-align:right;max-width:60%">{{ $revisando->nota }}</b></div>
            @endif
            <div class="list-line"><span>{{ __('Archivo adjunto') }}</span><a class="btn sm ghost" href="{{ route('admin.comprobantes.archivo', $revisando->id) }}" target="_blank" rel="noopener">{{ $revisando->esPdf() ? __('Descargar PDF') : __('Ver archivo') }}</a></div>

            @if ($revisando->estado === EstadoPagoSaas::Pendiente)
                @if ($revisando->esExtras())
                    <div class="list-line"><span>{{ __('Vencimiento') }}</span><b>{{ __(':fecha (no cambia)', ['fecha' => $suscripcion?->vencimiento->format('d/m/Y')]) }}</b></div>
                @else
                <div class="list-line"><span>{{ __('Vencimiento') }}</span><b>{{ $suscripcion?->vencimiento->format('d/m/Y') }} → {{ $proyectado?->format('d/m/Y') }}</b></div>
                @endif
                @if ($proyectado && $proyectado->lessThan($hoy))
                    <p class="subtle" style="margin:8px 0 0">{{ __('Aun aprobado, seguirá vencida: los meses atrasados se siguen debiendo.') }}</p>
                @endif
                <label class="row" style="gap:10px;margin-top:16px;align-items:flex-start">
                    <input type="checkbox" wire:model="verificado" style="margin-top:3px">
                    <span>{{ __('Verifiqué que MoziStudios recibió exactamente') }} <b>{{ $importeTexto }}</b> {{ __('de esta empresa.') }}</span>
                </label>
                @error('verificado')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                @if ($rechazando)
                    <x-campo etiqueta="Motivo del rechazo (lo verá la empresa)" campo="motivo" style="margin-top:14px">
                        <textarea id="motivo" class="input" rows="2" wire:model="motivo" maxlength="300" placeholder="{{ __('Ej.: el importe no coincide o la imagen no es legible') }}" autofocus></textarea>
                    </x-campo>
                @endif
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cancelar') }}</button>
                    <button type="button" class="btn ghost" wire:click="rechazar" wire:loading.attr="disabled" wire:target="rechazar,aprobar">{{ $rechazando ? __('Confirmar rechazo') : __('Rechazar') }}</button>
                    @unless ($rechazando)
                        <button type="button" class="btn primary" wire:click="aprobar" wire:loading.attr="disabled" wire:target="rechazar,aprobar"><x-icon name="check" /> {{ $revisando->esExtras() ? __('Aprobar y activar extras') : __('Aprobar y renovar') }}</button>
                    @endunless
                </div>
            @else
                <div class="list-line"><span>{{ __('Estado') }}</span><span class="badge {{ $revisando->estado->insignia() }}">{{ $revisando->estado->etiqueta() }}</span></div>
                <div class="list-line"><span>{{ __('Revisado') }}</span><b>{{ $revisando->revisado_en?->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }} · {{ $revisando->revisor?->name }}</b></div>
                @if ($revisando->vencimiento_nuevo && ! $revisando->esExtras())
                    <div class="list-line"><span>{{ __('Vencimiento') }}</span><b>{{ $revisando->vencimiento_anterior?->format('d/m/Y') }} → {{ $revisando->vencimiento_nuevo->format('d/m/Y') }}</b></div>
                @endif
                @if ($revisando->motivo_rechazo)
                    <div class="list-line"><span>{{ __('Motivo') }}</span><b style="text-align:right;max-width:60%">{{ $revisando->motivo_rechazo }}</b></div>
                @endif
                <div class="modal-actions"><button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cerrar') }}</button></div>
            @endif
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
