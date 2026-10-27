@use('App\Domain\Suscripciones\CalendarioSuscripcion')
@use('App\Enums\EstadoAviso')
@use('App\Enums\EventoAviso')
@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Superadministración') }}</div>
            <h1>{{ __('Suscripciones') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Vencimientos, recordatorios, mensualidades y planes.') }}</p>
        </div>
        <div class="page-actions">
            @if ($pestana === 'planes')
                <button class="btn primary" type="button" wire:click="crearPlan">
                    <x-icon name="plus" /> {{ __('Nuevo plan') }}
                </button>
            @endif
            <button class="btn soft" type="button" wire:click="$set('pestana', '{{ $pestana === 'empresas' ? 'recordatorios' : ($pestana === 'recordatorios' ? 'planes' : 'empresas') }}')">
                <x-icon name="bell" /> {{ match ($pestana) { 'empresas' => __('Ver recordatorios'), 'recordatorios' => __('Ver planes'), default => __('Ver suscripciones') } }}
            </button>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat">
            <span class="stat-label">{{ __('Por vencer') }}</span>
            <div class="stat-value">{{ $conteos['por_vencer'] }} {{ $conteos['por_vencer'] === 1 ? 'empresa' : 'empresas' }}</div>
            <div class="stat-foot">{{ __('Hoy o en los próximos 5 días') }}</div>
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Vencidas') }}</span>
            <div class="stat-value">{{ $conteos['vencidas'] }} {{ $conteos['vencidas'] === 1 ? 'empresa' : 'empresas' }}</div>
            <div class="stat-foot">{{ __('Continúan activas hasta desactivarlas a mano') }}</div>
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Cobrado SaaS este mes') }}</span>
            <div class="stat-value">{{ Decimal::bs($cobradoMes) }}</div>
            <div class="stat-foot">{{ __('Comprobantes aprobados · no pertenece a la caja de las empresas') }}</div>
        </article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="calendar" /></span>{{ match ($pestana) { 'empresas' => __('Suscripciones'), 'recordatorios' => __('Recordatorios enviados'), default => __('Planes de suscripción') } }}</div></div>
        <div class="tabs" role="tablist">
            @foreach (['empresas' => 'Suscripciones', 'recordatorios' => 'Recordatorios', 'planes' => 'Planes'] as $opcionPestana => $etiqueta)
                <button type="button" role="tab" class="tab {{ $pestana === $opcionPestana ? 'active' : '' }}" aria-selected="{{ $pestana === $opcionPestana ? 'true' : 'false' }}" wire:click="$set('pestana', '{{ $opcionPestana }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>

        @if ($pestana !== 'planes')
            <div class="tool-row">
                <div class="search">
                    <x-icon name="search" />
                    <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar empresa...') }}" aria-label="{{ __('Buscar empresa') }}">
                </div>
                @if ($pestana === 'empresas')
                    <select class="select" wire:model.live="estado" aria-label="{{ __('Filtrar por estado') }}">
                        <option value="">{{ __('Todos los estados') }}</option>
                        <option value="al_dia">{{ __('Al día') }}</option>
                        <option value="por_vencer">{{ __('Por vencer') }}</option>
                        <option value="vencida">{{ __('Vencidas') }}</option>
                        <option value="revision">{{ __('Con comprobante en revisión') }}</option>
                        <option value="sin_plan">{{ __('Sin plan configurado') }}</option>
                    </select>
                    <a class="btn ghost sm" href="{{ route('admin.suscripciones.exportar', array_filter(['q' => $buscar, 'estado' => $estado])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
                @endif
            </div>
        @endif

        @if ($pestana === 'empresas')
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Empresa') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Mensualidad') }}</th><th>{{ __('Vencimiento') }}</th><th>{{ __('Aviso') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Acceso') }}</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            @php
                                $vence = $fila->vencimiento === null ? null : CalendarioSuscripcion::fecha((string) $fila->vencimiento);
                                $ultimo = $ultimosAvisos[$fila->id] ?? null;
                                $orden = array_flip(array_map(fn ($e) => $e->value, EventoAviso::cases()));
                                $siguiente = $vence === null ? null : collect(EventoAviso::cases())->first(fn ($e) => CalendarioSuscripcion::fechaEvento($vence, $e)->greaterThanOrEqualTo($hoy)
                                    && ($ultimo === null || $orden[$e->value] > $orden[$ultimo->evento]));
                            @endphp
                            <tr wire:key="suscripcion-{{ $fila->id }}">
                                <td><b>{{ $fila->nombre }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ $fila->email ?? __('Sin correo de contacto') }}</small></td>
                                <td><span class="badge info" style="white-space:nowrap">{{ $fila->plan_titulo ?? __('Plan estándar') }}</span></td>
                                @if ($vence)
                                    <td>{{ Decimal::bs((string) $fila->precio_mensual) }}</td>
                                    <td>{{ $vence->format('d/m/Y') }}</td>
                                    <td>
                                        @if ($ultimo)
                                             {{ EventoAviso::from($ultimo->evento)->codigo() }} · {{ EstadoAviso::from($ultimo->estado)->etiqueta() }}
                                        @else
                                            —
                                        @endif
                                        @if ($siguiente)<small style="display:block;color:var(--faint)">{{ __('Próximo :codigo · :format', ['codigo' => $siguiente->codigo(), 'format' => CalendarioSuscripcion::fechaEvento($vence, $siguiente)->format('d/m')]) }}</small>@endif
                                    </td>
                                    <td>
                                        @php($situacion = CalendarioSuscripcion::estado($vence, $hoy))
                                        <span class="badge {{ $situacion->insignia() }}" style="white-space:nowrap">{{ $situacion->etiqueta() }}</span>
                                        @if ((int) $fila->en_revision > 0)<small style="display:block;margin-top:4px"><a class="link" href="{{ route('admin.comprobantes', ['q' => $fila->nombre, 'estado' => 'pendiente']) }}">{{ __('Comprobante en revisión') }}</a></small>@endif
                                    </td>
                                @else
                                    <td colspan="4"><span class="badge info">{{ __('Sin plan configurado') }}</span></td>
                                @endif
                                <td><span class="badge {{ $fila->activa ? 'success' : 'danger' }}" style="white-space:nowrap">{{ $fila->activa ? __('Activa') : __('Inactiva') }}</span></td>
                                <td><a class="btn sm ghost" href="{{ route('admin.empresas', ['editar' => $fila->id]) }}"><x-icon name="edit" size="sm" /> {{ $vence ? __('Editar') : __('Configurar') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="8"><div class="empty">{{ __('No hay empresas que coincidan con el filtro.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($filas->hasPages())
                <div class="table-footer">
                    <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $filas->currentPage(), 'lastpage' => $filas->lastPage()]) }}</span>
                    <span class="row">
                        @unless ($filas->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                        @if ($filas->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                    </span>
                </div>
            @endif
        @elseif ($pestana === 'recordatorios')
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Registrado') }}</th><th>{{ __('Empresa') }}</th><th>{{ __('Aviso') }}</th><th>{{ __('Vencimiento') }}</th><th>{{ __('Destinatarios') }}</th><th>{{ __('Estado') }}</th></tr></thead>
                    <tbody>
                        @forelse ($avisos as $registro)
                            @php($correos = json_decode((string) $registro->destinatarios, true) ?: [])
                            <tr wire:key="aviso-{{ $registro->id }}">
                                <td>{{ \Illuminate\Support\Carbon::parse($registro->created_at)->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</td>
                                <td><b>{{ $registro->empresa }}</b></td>
                                <td>{{ EventoAviso::from($registro->evento)->codigo() }}<small style="display:block;color:var(--faint)">{{ EventoAviso::from($registro->evento)->etiqueta() }}</small></td>
                                <td>{{ CalendarioSuscripcion::fecha((string) $registro->vencimiento)->format('d/m/Y') }}</td>
                                <td>{{ $correos === [] ? '—' : implode(', ', $correos) }}</td>
                                <td><span class="badge {{ EstadoAviso::from($registro->estado)->insignia() }}">{{ EstadoAviso::from($registro->estado)->etiqueta() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><div class="empty">{{ __('Todavía no se generaron recordatorios.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($avisos->hasPages())
                <div class="table-footer">
                    <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $avisos->currentPage(), 'lastpage' => $avisos->lastPage()]) }}</span>
                    <span class="row">
                        @unless ($avisos->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                        @if ($avisos->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                    </span>
                </div>
            @endif
        @else
            <div class="grid two" style="margin-top:14px">
                @forelse ($planes as $p)
                    <article class="card card-pad" wire:key="plan-{{ $p->id }}" style="display:flex;flex-direction:column;justify-content:space-between">
                        <div>
                            <div class="card-head" style="margin-bottom:12px">
                                <div class="heading">
                                    <span class="heading-icon"><x-icon name="package" /></span>
                                    <div>
                                        <b style="font-size:15px">{{ $p->titulo }}</b>
                                        <small style="display:block;color:var(--faint)">{{ $p->codigo }}</small>
                                    </div>
                                </div>
                                <div>
                                    @if ($p->siat_habilitado)
                                        <span class="badge success">{{ __('Facturación SIAT') }}</span>
                                    @else
                                        <span class="badge">{{ __('Solo inventarios') }}</span>
                                    @endif
                                </div>
                            </div>
                            <div style="margin: 12px 0;">
                                <span class="stat-label">{{ __('Precio mensual') }}</span>
                                <div class="stat-value" style="font-size:26px;margin:4px 0 8px">{{ Decimal::bs((string) $p->precio_mensual) }}<small style="font-size:12px;color:var(--faint)"> / mes</small></div>
                                <p class="lead" style="font-size:12px;line-height:1.6;margin:0 0 14px">{{ $p->descripcion }}</p>
                            </div>
                        </div>
                        <div>
                            <div class="divider" style="margin:12px 0"></div>
                            <div class="row between">
                                <span class="subtle">{{ trans_choice(':count empresa suscrita|:count empresas suscritas', $p->suscripciones_count) }}</span>
                                <button class="btn soft sm" type="button" wire:click="editarPlan({{ $p->id }})">
                                    <x-icon name="edit" size="sm" /> {{ __('Editar') }}
                                </button>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="empty">{{ __('No hay planes configurados.') }}</div>
                @endforelse
            </div>
        @endif

        <div class="divider"></div>
        <div class="notice"><x-icon name="info" /><div><b>{{ __('Gestión integral de suscripciones') }}</b><p>{{ __('MoziStock permite configurar planes con o sin facturación SIAT, editar precios y recordatorios automáticos. El estado de pago nunca bloquea el acceso de forma automática.') }}</p></div></div>
    </section>

    @if ($modalPlanAbierto)
        <div class="modal-backdrop open">
            <div class="modal">
                <div class="modal-head">
                    <div>
                        <h2>{{ $planEditandoId ? __('Editar plan') : __('Nuevo plan') }}</h2>
                        <p>{{ __('Configura el título, la descripción y el precio mensual de este plan de suscripción.') }}</p>
                    </div>
                    <button type="button" class="icon-action" wire:click="cerrarModalPlan" aria-label="{{ __('Cerrar') }}"><x-icon name="x" /></button>
                </div>
                <form wire:submit="guardarPlan" class="stack">
                    <x-campo etiqueta="Título del plan" campo="planTitulo">
                        <input class="input" type="text" wire:model="planTitulo" maxlength="150" placeholder="{{ __('Ej.: Acceso al sistema de inventarios') }}" required>
                    </x-campo>
                    <x-campo etiqueta="Descripción" campo="planDescripcion">
                        <textarea class="textarea" wire:model="planDescripcion" maxlength="1000" rows="3" placeholder="{{ __('Describe los módulos y beneficios que incluye...') }}" required></textarea>
                    </x-campo>
                    <x-campo etiqueta="Precio mensual (Bs)" campo="planPrecio">
                        <input class="input" type="text" inputmode="decimal" wire:model="planPrecio" placeholder="{{ __('150.00') }}" required>
                    </x-campo>
                    <div style="margin: 4px 0;">
                        <label class="row" style="cursor:pointer">
                            <input type="checkbox" class="checkbox" wire:model="planSiat">
                            <span><b>{{ __('Incluye facturación electrónica SIAT') }}</b><small style="display:block;color:var(--faint)">{{ __('Las empresas con este plan tendrán habilitado el módulo de facturación SIAT.') }}</small></span>
                        </label>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn ghost" wire:click="cerrarModalPlan">{{ __('Cancelar') }}</button>
                        <button type="submit" class="btn primary">{{ $planEditandoId ? __('Guardar cambios') : __('Crear plan') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
