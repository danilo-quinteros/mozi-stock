@use('App\Domain\Suscripciones\CalendarioSuscripcion')
@use('App\Enums\EstadoAviso')
@use('App\Enums\EstadoSuscripcion')
@use('App\Enums\EventoAviso')
@use('App\Support\Decimal')
@php
    $estado = $suscripcion?->estado($hoy);
    $dias = $suscripcion?->diasHastaVencimiento($hoy) ?? 0;
    $atraso = abs($dias);
    $vista = match ($estado) {
        EstadoSuscripcion::PorVencer => [
            'clave' => 'pronto', 'icono' => 'status-soon', 'etiqueta' => __('Próxima a vencer'),
            'titulo' => __('Tu suscripción vence pronto'),
            'descripcion' => match ($dias) {
                0 => __('Tu mensualidad vence hoy. Puedes pagar ahora y enviar el comprobante para revisión.'),
                1 => __('Tu mensualidad vence mañana. Puedes pagar ahora y enviar el comprobante para revisión.'),
                default => __('Tu mensualidad vence en :dias días. Puedes pagar ahora y enviar el comprobante para revisión.', ['dias' => $dias]),
            },
            'cifra' => $dias === 0 ? __('Hoy') : trans_choice(':count día restante|:count días restantes', $dias),
            'pista' => $dias === 0 ? __('vence tu mensualidad') : __('para el vencimiento'),
            'fecha' => __('Fecha de vencimiento'), 'tarjeta' => __('Renueva tu suscripción'),
            'subtitulo' => __('Escanea el QR y envía el comprobante para evitar atrasos.'),
        ],
        EstadoSuscripcion::Vencida => [
            'clave' => 'vencida', 'icono' => 'status-alert', 'etiqueta' => __('Suscripción vencida'),
            'titulo' => __('Tienes un pago pendiente'),
            'descripcion' => $atraso === 1
                ? __('Tu suscripción venció ayer. El acceso sigue habilitado por ahora; regulariza el pago para evitar una suspensión manual.')
                : __('Tu suscripción venció hace :dias días. El acceso sigue habilitado por ahora; regulariza el pago para evitar una suspensión manual.', ['dias' => $atraso]),
            'cifra' => trans_choice(':count día de atraso|:count días de atraso', $atraso),
            'pista' => __('desde el vencimiento'),
            'fecha' => __('Venció el'), 'tarjeta' => __('Regulariza tu mensualidad'),
            'subtitulo' => __('Paga con QR y envía el comprobante para que podamos revisarlo.'),
        ],
        default => [
            'clave' => 'vigente', 'icono' => 'status-ok', 'etiqueta' => __('Suscripción al día'),
            'titulo' => __('Todo está en orden'),
            'descripcion' => __('Tu suscripción está vigente. Por ahora no necesitas hacer nada.'),
            'cifra' => trans_choice(':count día restante|:count días restantes', $dias),
            'pista' => __('para tu próximo vencimiento'),
            'fecha' => __('Próximo vencimiento'), 'tarjeta' => __('Tu próxima mensualidad'),
            'subtitulo' => __('Puedes adelantar el pago si lo deseas; no es obligatorio ahora.'),
        ],
    };
    $mostrarPago = $estado !== EstadoSuscripcion::AlDia || $formulario;
    $pagaExtras = $modoPago === 'extras' && ($extras['cobra_hoy'] ?? false);
    if ($pagaExtras) {
        $vista['tarjeta'] = __('Paga tus extras');
        $vista['subtitulo'] = __('Escanea el QR y envía el comprobante: tus extras se activan cuando MoziStudios lo apruebe.');
    }
    $zona = config('mozistock.business_timezone');
@endphp
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Mi empresa') }}</div>
            <h1>{{ __('Mi suscripción') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Tu plan, tu próximo vencimiento y tus pagos, sin complicaciones.') }}</p>
        </div>
        @if ($suscripcion)
            <div class="page-actions">
                <button class="btn soft" type="button" wire:click="abrirModalPlanes">
                    <x-icon name="package" /> {{ __('Cambiar de plan') }}
                </button>
            </div>
        @endif
    </div>

    <x-empresa-nav />

    @if (! $suscripcion)
        <div class="notice"><x-icon name="info" /><div><b>{{ __('Tu plan todavía no está configurado') }}</b><p>{{ __('MoziStudios definirá el precio mensual y el vencimiento de tu empresa. Mientras tanto no necesitas hacer nada: el sistema sigue disponible.') }}</p></div></div>
    @else
        @if ($suscripcion->enPrueba($hoy))
            @php($diasPrueba = CalendarioSuscripcion::diasHasta($suscripcion->prueba_hasta, $hoy))
            <div class="notice" style="margin-bottom:18px;border-color:var(--accent-border);background:var(--accent-soft)" data-test="aviso-prueba">
                <x-icon name="sparkles" />
                <div class="grow">
                    <b>{{ $diasPrueba === 0
                        ? __('Hoy es el último día de tu prueba gratis')
                        : trans_choice('Estás en tu prueba gratis: te queda :count día|Estás en tu prueba gratis: te quedan :count días', $diasPrueba) }}</b>
                    <p>{{ __('La prueba dura hasta el :fecha. Para continuar después, paga tu primera mensualidad con QR desde esta pantalla; tus datos se conservan.', ['fecha' => $suscripcion->prueba_hasta->format('d/m/Y')]) }}</p>
                </div>
            </div>
        @endif
        @if ($suscripcion->plan_siguiente_id && $suscripcion->planSiguiente)
            <div class="notice" style="margin-bottom:18px;border-color:var(--accent-border);background:var(--accent-soft)">
                <x-icon name="calendar" />
                <div class="grow">
                    <b>{{ __('Cambio de plan programado para tu próxima renovación (:fecha)', ['fecha' => $suscripcion->vencimiento->format('d/m/Y')]) }}</b>
                    <p>{{ __('Pasarás a :plan con el precio reducido de :precio/mes. Conservas todos los beneficios de tu plan actual hasta el vencimiento.', ['plan' => $suscripcion->planSiguiente->titulo, 'precio' => Decimal::bs((string) $suscripcion->planSiguiente->precio_mensual)]) }}</p>
                </div>
                <button class="btn ghost sm" type="button" wire:click="cancelarCambioPlan">{{ __('Cancelar cambio') }}</button>
            </div>
        @endif

        <div class="sub-status" data-status="{{ $vista['clave'] }}" role="status">
            <div class="sub-hero-content">
                <span class="sub-status-icon" aria-hidden="true"><x-icon :name="$vista['icono']" /></span>
                <div><span class="sub-status-tag">{{ $vista['etiqueta'] }}</span><h2>{{ $vista['titulo'] }}</h2><p>{{ $vista['descripcion'] }}</p></div>
            </div>
            <div class="sub-days"><strong>{{ $vista['cifra'] }}</strong><span>{{ $vista['pista'] }}</span></div>
        </div>

        <div class="sub-overview" aria-label="{{ __('Datos principales de tu suscripción') }}">
            <div class="sub-fact">
                <label>{{ __('Plan actual') }}</label>
                <div class="row between">
                    <b>{{ $suscripcion->plan?->titulo ?? __('Plan configurado') }}</b>
                    <button class="btn ghost sm" type="button" wire:click="abrirModalPlanes" style="padding:2px 8px;min-height:28px">{{ __('Cambiar') }}</button>
                </div>
            </div>
            <div class="sub-fact">
                <label>{{ __('Mensualidad') }}</label>
                <b>{{ Decimal::bs($extras['actual']['total']) }}</b>
                @if (Decimal::comparar($precioRenovacion, $extras['actual']['total'], 2) !== 0)
                    <small style="display:block;color:var(--brand-bright);margin-top:2px">{{ __('Próxima renovación: :precio', ['precio' => Decimal::bs($precioRenovacion)]) }}</small>
                @elseif (Decimal::comparar($extras['actual']['extras'], '0', 2) > 0)
                    <small style="display:block;color:var(--faint);margin-top:2px">{{ __('Plan :plan + extras :extras', ['plan' => Decimal::bs($extras['actual']['plan']), 'extras' => Decimal::bs($extras['actual']['extras'])]) }}</small>
                @endif
            </div>
            <div class="sub-fact"><label>{{ $vista['fecha'] }}</label><b>{{ $suscripcion->vencimiento->format('d/m/Y') }}</b></div>
            <div class="sub-fact"><label>{{ __('Acceso al sistema') }}</label><b class="sub-access">{{ __('● Habilitado') }}</b></div>
        </div>


        {{-- Inline: un bloque @php después de @php(...) confunde al compilador de Blade. --}}
        @php([$plan, $nuevo, $limites, $uso, $bloqueado, $dif] = [$extras['plan'], $extras['nuevo'], $extras['limites'], $extras['uso'], $extras['en_revision'], $extras['diferencia']])
        <section class="sub-card sub-extras" aria-labelledby="sub-extras-titulo" data-test="extras">
            <h3 class="sub-card-title" id="sub-extras-titulo">{{ __('Lo que pagas cada mes') }}</h3>
            <p class="sub-card-subtitle">
                @if ($plan)
                    {{ __('Tu plan incluye :sucursales y :usuarios. ¿Necesitas más? Súmalos aquí.', [
                        'sucursales' => $plan->sucursales_incluidas === null ? __('sucursales sin límite') : trans_choice(':count sucursal|:count sucursales', $plan->sucursales_incluidas),
                        'usuarios' => $plan->usuarios_incluidos === null ? __('usuarios sin límite') : trans_choice(':count usuario|:count usuarios', $plan->usuarios_incluidos),
                    ]) }}
                @else
                    {{ __('MoziStudios configuró tu mensualidad a mano. Para sumar sucursales o usuarios, escríbenos.') }}
                @endif
            </p>

            <div class="sub-bill">
                <div class="sub-bill-row">
                    <div class="sub-bill-what"><b>{{ $plan?->titulo ?? __('Plan configurado') }}</b><small>{{ __('Precio del plan') }}</small></div>
                    <b class="sub-bill-amount">{{ Decimal::bs($nuevo['plan']) }}</b>
                </div>
                @if ($plan && $plan->sucursales_incluidas !== null)
                    <div class="sub-bill-row">
                        <div class="sub-bill-what"><b>{{ __('Sucursales extra') }}</b><small>{{ __(':precio cada una, por mes', ['precio' => Decimal::bs($nuevo['precio_sucursal'])]) }}</small></div>
                        <div class="sub-stepper" role="group" aria-label="{{ __('Sucursales extra') }}">
                            <button type="button" wire:click="restar('sucursales')" @disabled($bloqueado || $sucursalesExtra === 0) aria-label="{{ __('Quitar una sucursal extra') }}"><x-icon name="minus" /></button>
                            <output aria-live="polite" data-test="sucursales-extra">{{ $sucursalesExtra }}</output>
                            <button type="button" wire:click="sumar('sucursales')" @disabled($bloqueado) aria-label="{{ __('Agregar una sucursal extra') }}"><x-icon name="plus" /></button>
                        </div>
                        <b class="sub-bill-amount">{{ Decimal::bs($nuevo['subtotal_sucursales']) }}</b>
                    </div>
                @endif
                @if ($plan && $plan->usuarios_incluidos !== null)
                    <div class="sub-bill-row">
                        <div class="sub-bill-what"><b>{{ __('Paquetes de :n usuarios', ['n' => $plan->usuarios_por_paquete]) }}</b><small>{{ __(':precio cada paquete, por mes', ['precio' => Decimal::bs($nuevo['precio_paquete'])]) }}</small></div>
                        <div class="sub-stepper" role="group" aria-label="{{ __('Paquetes de usuarios') }}">
                            <button type="button" wire:click="restar('usuarios')" @disabled($bloqueado || $paquetesUsuarios === 0) aria-label="{{ __('Quitar un paquete de usuarios') }}"><x-icon name="minus" /></button>
                            <output aria-live="polite" data-test="paquetes-usuarios">{{ $paquetesUsuarios }}</output>
                            <button type="button" wire:click="sumar('usuarios')" @disabled($bloqueado) aria-label="{{ __('Agregar un paquete de usuarios') }}"><x-icon name="plus" /></button>
                        </div>
                        <b class="sub-bill-amount">{{ Decimal::bs($nuevo['subtotal_paquetes']) }}</b>
                    </div>
                @endif
                <div class="sub-bill-total">
                    <span>{{ $extras['cambiado'] ? __('Nuevo total por mes') : __('Total por mes') }}</span>
                    <b data-test="total-mensual">{{ Decimal::bs($nuevo['total']) }}</b>
                </div>
            </div>

            @if ($limites['sucursales'] !== null || $limites['usuarios'] !== null)
                <p class="sub-usage">
                    {{ __('Hoy usas') }}
                    @if ($limites['sucursales'] !== null)<b>{{ __(':uso de :limite sucursales', ['uso' => $uso['sucursales'], 'limite' => $limites['sucursales']]) }}</b>@endif
                    @if ($limites['sucursales'] !== null && $limites['usuarios'] !== null) {{ __('y') }} @endif
                    @if ($limites['usuarios'] !== null)<b>{{ __(':uso de :limite usuarios', ['uso' => $uso['usuarios'], 'limite' => $limites['usuarios']]) }}</b>@endif.
                </p>
            @endif

            @error('extras')<div class="notice sub-extras-error" role="alert"><x-icon name="info" /><div><p>{{ $message }}</p></div></div>@enderror

            @if ($bloqueado)
                <p class="sub-usage">{{ __('Tienes un comprobante en revisión. Podrás cambiar tus extras cuando MoziStudios responda.') }}</p>
            @elseif ($extras['cambiado'])
                <div class="sub-extras-confirm" data-test="confirmar-extras">
                    @if ($extras['cobra_hoy'])
                        <p>
                            {{ __('Para activarlo hoy pagas') }} <b>{{ Decimal::bs($dif['importe']) }}</b>:
                            {{ $dif['meses_completos'] > 0
                                ? __('la diferencia por :meses y :dias hasta tu vencimiento del :fecha.', ['meses' => trans_choice(':count mes|:count meses', $dif['meses_completos']), 'dias' => trans_choice(':count día|:count días', $dif['dias_restantes']), 'fecha' => $suscripcion->vencimiento->format('d/m/Y')])
                                : __('la parte proporcional de los :dias que faltan para tu vencimiento del :fecha.', ['dias' => trans_choice(':count día|:count días', $dif['dias_restantes']), 'fecha' => $suscripcion->vencimiento->format('d/m/Y')]) }}
                            {{ __('Desde tu próximo pago, tu mensualidad será') }} <b>{{ Decimal::bs($nuevo['total']) }}</b>.
                        </p>
                        <div class="sub-extras-actions">
                            <button type="button" class="btn ghost sm" wire:click="deshacerExtras">{{ __('Deshacer') }}</button>
                            <button type="button" class="btn primary sm" wire:click="pagarExtras"><x-icon name="qr" /> {{ __('Pagar :importe y activar', ['importe' => Decimal::bs($dif['importe'])]) }}</button>
                        </div>
                    @else
                        <p>
                            @if ($dif['motivo_gratis'] === 'prueba')
                                {{ __('Estás en tu prueba gratis: se activa ya, sin costo.') }}
                            @elseif ($dif['motivo_gratis'] === 'vencida')
                                {{ __('Se activa ya y tu próximo pago incluye la nueva mensualidad.') }}
                            @elseif ($extras['quita'])
                                {{ __('Se quita ya. Lo que ya pagaste de este período no se devuelve.') }}
                            @endif
                            {{ __('Desde tu próximo pago, tu mensualidad será') }} <b>{{ Decimal::bs($nuevo['total']) }}</b>.
                        </p>
                        <div class="sub-extras-actions">
                            <button type="button" class="btn ghost sm" wire:click="deshacerExtras">{{ __('Deshacer') }}</button>
                            <button type="button" class="btn primary sm" wire:click="guardarExtras" wire:loading.attr="disabled" wire:target="guardarExtras"><x-icon name="check" /> {{ __('Guardar cambios') }}</button>
                        </div>
                    @endif
                </div>
            @endif

            <div class="sub-empresa">
                <span class="sub-empresa-icon" aria-hidden="true"><x-icon name="building" /></span>
                <div class="grow">
                    <b>{{ __('Plan Empresa') }}</b>
                    <p>{{ __('¿Muchas sucursales o una cadena de tiendas? Te armamos un precio especial.') }}</p>
                </div>
                @if ($extras['whatsapp'])
                    <a class="btn soft sm" href="{{ $extras['whatsapp'] }}" target="_blank" rel="noopener">{{ __('Escríbenos por WhatsApp') }}</a>
                @else
                    <span class="sub-empresa-pronto">{{ $extras['correo'] ? __('Escríbenos a :correo', ['correo' => $extras['correo']]) : __('Muy pronto por WhatsApp') }}</span>
                @endif
            </div>
        </section>

        <div class="sub-layout">
            <section class="sub-card">
                <h3 class="sub-card-title">{{ $vista['tarjeta'] }}</h3>
                <p class="sub-card-subtitle">{{ $vista['subtitulo'] }}</p>

                @if ($enRevision)
                    <div class="notice"><x-icon name="info" /><div><b>{{ __('Comprobante :codigo en revisión', ['codigo' => $enRevision->codigo()]) }}</b><p>{{ __('Enviado el :format por :importe (:concepto). Podrás enviar otro cuando MoziStudios responda.', ['format' => $enRevision->created_at->timezone($zona)->format('d/m/Y H:i'), 'importe' => Decimal::bs((string) $enRevision->importe), 'concepto' => $enRevision->concepto()]) }}</p></div></div>
                @elseif (! $mostrarPago)
                    <div class="sub-payment-prompt">
                        <div><b>{{ __('No tienes pagos vencidos') }}</b><p>{{ __('Si deseas, puedes adelantar tu siguiente mensualidad.') }}</p></div>
                        <button class="btn soft" type="button" wire:click="abrir">{{ __('Ver opciones de pago') }}</button>
                    </div>
                @else
                    <div class="sub-qr-grid">
                        @if ($ajustes->qr_path)
                            <img class="sub-qr-image" src="{{ route('suscripcion.qr') }}" alt="{{ __('QR de pago de :receptor_nombre', ['receptor_nombre' => $ajustes->receptor_nombre ?: __('MoziStudios')]) }}">
                        @else
                            <div class="sub-qr-placeholder">
                                <span class="sub-qr-icon" aria-hidden="true"><x-icon name="qr" /></span>
                                <b>{{ __('QR de MoziStudios') }}</b><small>{{ __('MoziStudios aún no cargó su QR. Escribe a soporte para pagar.') }}</small>
                            </div>
                        @endif
                        <form wire:submit="enviar">
                            @if ($pagaExtras)
                                <div class="sub-payment-amount">{{ __('Pagas hoy por tus extras') }}<strong aria-live="polite" data-test="importe-extras">{{ Decimal::bs($extras['diferencia']['importe']) }}</strong></div>
                            @else
                                <div class="sub-payment-amount">{{ __('Importe a pagar') }}<strong aria-live="polite">{{ Decimal::bs(Decimal::multiplicar($precioRenovacion, (string) $mesesElegidos, 2)) }}</strong></div>
                                @php($renovacion = $suscripcion->desgloseRenovacion())
                                @if (Decimal::comparar($renovacion['extras'], '0', 2) > 0)
                                    <p class="sub-payment-detail">{{ __('Cada mes: plan :plan + extras :extras = :total', ['plan' => Decimal::bs($renovacion['plan']), 'extras' => Decimal::bs($renovacion['extras']), 'total' => Decimal::bs($renovacion['total'])]) }}</p>
                                @endif
                            @endif
                            <div class="sub-steps">
                                <div class="sub-step"><span class="sub-step-num">1</span><span>{{ __('Escanea el QR:receptor_nombre con la aplicación de tu banco y paga exactamente este importe.', ['receptor_nombre' => $ajustes->receptor_nombre ? ' de '.$ajustes->receptor_nombre : ' oficial']) }}</span></div>
                                <div class="sub-step"><span class="sub-step-num">2</span><span>{{ __('Adjunta el comprobante para que MoziStudios pueda verificarlo.') }}</span></div>
                            </div>
                            @if ($pagaExtras)
                                <p class="subtle" style="margin:8px 0 14px">
                                    {{ __('Tu plan quedará con :extras. Tu vencimiento (:fecha) no cambia.', [
                                        'extras' => implode(' y ', array_filter([
                                            $sucursalesExtra > 0 ? trans_choice(':count sucursal extra|:count sucursales extra', $sucursalesExtra) : null,
                                            $paquetesUsuarios > 0 ? trans_choice(':count paquete de usuarios|:count paquetes de usuarios', $paquetesUsuarios) : null,
                                        ])),
                                        'fecha' => $suscripcion->vencimiento->format('d/m/Y'),
                                    ]) }}
                                    <button type="button" class="link" wire:click="$set('modoPago', 'renovacion')">{{ __('Pagar la mensualidad en su lugar') }}</button>
                                </p>
                            @else
                            <x-campo etiqueta="Meses que pagas" campo="meses">
                                <select id="meses" class="select" wire:model.live="meses">
                                    @foreach (CalendarioSuscripcion::MESES_PAGABLES as $m)
                                        <option value="{{ $m }}">{{ $m }} {{ $m === 1 ? 'mes' : 'meses' }} · {{ Decimal::bs(Decimal::multiplicar($precioRenovacion, (string) $m, 2)) }}</option>
                                    @endforeach
                                </select>
                            </x-campo>
                            <p class="subtle" style="margin:8px 0 14px">{{ __('Si se aprueba, el vencimiento pasa del :format al', ['format' => $suscripcion->vencimiento->format('d/m/Y')]) }} <b>{{ $vencimientoTras->format('d/m/Y') }}</b>@if ($vencimientoTras->lessThan($hoy)) {{ __('(seguirá vencida: hay meses anteriores sin pagar)') }}@endif.</p>
                            @endif
                            <div class="sub-file">
                                <div class="sub-file-text">
                                    <strong>{{ $archivo ? __('Archivo seleccionado') : __('Comprobante de pago') }}</strong>
                                    <span wire:loading.remove wire:target="archivo">{{ $archivo ? \Illuminate\Support\Str::limit($archivo->getClientOriginalName(), 60) : __('JPG, PNG, WebP o PDF · máximo 5 MB') }}</span>
                                    <span wire:loading wire:target="archivo">{{ __('Subiendo…') }}</span>
                                </div>
                                <label class="btn soft sm" for="archivo">{{ __('Seleccionar archivo') }}</label>
                                <input id="archivo" class="sub-file-input" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" wire:model="archivo">
                            </div>
                            @if ($archivo)
                                <div class="sub-preview" wire:loading.remove wire:target="archivo">
                                    @if ($archivo->isPreviewable())
                                        <img src="{{ $archivo->temporaryUrl() }}" alt="{{ __('Vista previa del comprobante') }}">
                                    @else
                                        <span class="sub-preview-doc"><x-icon name="receipt" /> {{ __(':getclientoriginalname · PDF sin vista previa', ['getclientoriginalname' => \Illuminate\Support\Str::limit($archivo->getClientOriginalName(), 60)]) }}</span>
                                    @endif
                                </div>
                            @endif
                            @error('archivo')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                            <x-campo etiqueta="Nota para MoziStudios (opcional)" campo="nota">
                                <input id="nota" class="input" type="text" wire:model="nota" maxlength="300" placeholder="{{ __('Ej.: número de operación o cuenta de origen') }}">
                            </x-campo>
                            <div style="height:12px"></div>
                            <button class="btn primary sub-btn-full" type="submit" wire:loading.attr="disabled" wire:target="enviar,archivo"><x-icon name="upload" /> {{ __('Enviar comprobante para revisión') }}</button>
                            <p class="sub-feedback">{{ $pagaExtras ? __('Los extras se activan cuando MoziStudios apruebe el comprobante.') : __('El pago se acreditará cuando MoziStudios apruebe el comprobante; enviarlo no cambia tu vencimiento.') }}</p>
                        </form>
                    </div>
                @endif
            </section>

            <aside class="stack">
                <section class="sub-card" aria-labelledby="historial-pagos">
                    <h3 class="sub-card-title" id="historial-pagos">{{ __('Últimos pagos') }}</h3>
                    <p class="sub-card-subtitle">{{ __('Comprobantes enviados y su revisión por MoziStudios.') }}</p>
                    <div class="sub-history">
                        @forelse ($pagos as $pago)
                            <div class="sub-history-row" wire:key="pago-{{ $pago->id }}">
                                <div>
                                    <b>{{ $pago->esExtras() ? $pago->concepto() : ($pago->vencimiento_nuevo ? $pago->vencimiento_anterior?->format('d/m/Y').' → '.$pago->vencimiento_nuevo->format('d/m/Y') : $pago->concepto()) }}</b>
                                    <small>{{ __(':codigo · enviado :format ·', ['codigo' => $pago->codigo(), 'format' => $pago->created_at->timezone($zona)->format('d/m/Y')]) }} <a class="link" href="{{ route('suscripcion.comprobante', $pago->id) }}" target="_blank" rel="noopener">{{ $pago->esPdf() ? 'PDF' : __('Ver archivo') }}</a></small>
                                    @if ($pago->motivo_rechazo)<small>{{ $pago->motivo_rechazo }}</small>@endif
                                </div>
                                <div class="sub-history-right"><b>{{ Decimal::bs((string) $pago->importe) }}</b><span class="badge {{ $pago->estado->insignia() }}">{{ $pago->estado->etiqueta() }}</span></div>
                            </div>
                        @empty
                            <div class="empty" style="padding:18px 0">{{ __('Todavía no enviaste comprobantes.') }}</div>
                        @endforelse
                    </div>
                    @if ($pagos->hasPages())
                        <div class="table-footer">
                            <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $pagos->currentPage(), 'lastpage' => $pagos->lastPage()]) }}</span>
                            <span class="row">
                                @unless ($pagos->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                                @if ($pagos->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                            </span>
                        </div>
                    @endif
                </section>

                <section class="sub-card" aria-labelledby="recordatorios">
                    <h3 class="sub-card-title" id="recordatorios">{{ __('Recordatorios') }}</h3>
                    <p class="sub-card-subtitle" style="margin-bottom:6px">{{ __('Del vencimiento del :format, por correo y en este panel.', ['format' => $suscripcion->vencimiento->format('d/m/Y')]) }}</p>
                    @foreach (EventoAviso::cases() as $evento)
                        @php($registro = $avisos[$evento->value] ?? null)
                        @php($fecha = CalendarioSuscripcion::fechaEvento($suscripcion->vencimiento, $evento))
                        <div class="list-line">
                            <span>{{ $evento->etiqueta() }}<small style="display:block;color:var(--faint)">{{ $fecha->format('d/m/Y') }}</small></span>
                            @if ($registro)
                                <span class="badge {{ $registro->estado->insignia() }}">{{ $registro->estado === EstadoAviso::Enviado ? __('Enviado ').$registro->enviado_en?->timezone($zona)->format('d/m') : $registro->estado->etiqueta() }}</span>
                            @elseif ($fecha->greaterThanOrEqualTo($hoy))
                                <span class="badge info">{{ __('Programado') }}</span>
                            @else
                                <span class="badge">{{ __('Sin aviso') }}</span>
                            @endif
                        </div>
                    @endforeach
                </section>

                <div class="sub-help-block">
                    <b>{{ __('Pagos con revisión manual') }}</b>
                    {{ __('Tu comprobante quedará pendiente hasta que MoziStudios lo apruebe. El vencimiento no desactiva automáticamente tu empresa.') }}
                    @if ($ajustes->correo_contacto)<br>{{ __('Consultas:') }} <b style="display:inline">{{ $ajustes->correo_contacto }}</b>@endif
                </div>
            </aside>
        </div>
    @endif

    {{-- Modal para cambiar de plan con prorrateo --}}
    @if ($modalPlanesAbierto && $suscripcion)
        <div class="modal-backdrop open">
            <div class="modal" style="max-width:760px">
                <div class="modal-head">
                    <div>
                        <h2>{{ __('Cambiar plan de suscripción') }}</h2>
                        <p>{{ __('Elige el plan que prefieras para tu negocio. Puedes actualizar o cambiar de plan cuando gustes.') }}</p>
                    </div>
                    <button type="button" class="icon-action" wire:click="cerrarModalPlanes" aria-label="{{ __('Cerrar') }}"><x-icon name="x" /></button>
                </div>

                <div class="grid two" style="margin-bottom:16px">
                    @foreach ($planesDisponibles as $p)
                        @php($esActual = $suscripcion->plan_id === $p->id && ! $suscripcion->plan_siguiente_id)
                        @php($esSiguiente = $suscripcion->plan_siguiente_id === $p->id)
                        @php($estaSeleccionado = $planSeleccionadoId === $p->id)
                        <article class="card card-pad {{ $estaSeleccionado ? 'hover-lift' : '' }}" style="display:flex;flex-direction:column;justify-content:space-between;{{ $estaSeleccionado ? 'border-color:var(--brand);box-shadow:0 0 0 2px var(--focus);' : '' }}">
                            <div>
                                <div class="row between" style="margin-bottom:8px">
                                    <b>{{ $p->titulo }}</b>
                                    @if ($esActual)
                                        <span class="badge success">{{ __('Plan actual') }}</span>
                                    @elseif ($esSiguiente)
                                        <span class="badge warning">{{ __('Programado') }}</span>
                                    @elseif ($p->siat_habilitado)
                                        <span class="badge info">{{ __('Facturación SIAT') }}</span>
                                    @else
                                        <span class="badge">{{ __('Solo inventario') }}</span>
                                    @endif
                                </div>
                                <div class="stat-value" style="font-size:22px;margin:6px 0">{{ Decimal::bs((string) $p->precio_mensual) }}<small style="font-size:11px;color:var(--faint)"> / mes</small></div>
                                <p class="lead" style="font-size:11.5px;line-height:1.55;margin:0 0 10px">{{ $p->descripcion }}</p>
                            </div>
                            <div style="margin-top:10px">
                                @if ($esActual)
                                    <button type="button" class="btn disabled sm" style="width:100%" disabled>{{ __('Plan actual activo') }}</button>
                                @else
                                    <button type="button" class="btn {{ $estaSeleccionado ? 'primary' : 'soft' }} sm" style="width:100%" wire:click="seleccionarPlan({{ $p->id }})">
                                        {{ $estaSeleccionado ? __('Seleccionado') : __('Elegir este plan') }}
                                    </button>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                {{-- Detalle y confirmación del plan seleccionado con cálculo de prorrateo --}}
                @if ($planSeleccionado && $calculoProrrateo)
                    @if ($calculoProrrateo['tipo'] === 'upgrade')
                        <div class="card card-pad" style="border-color:var(--accent-border);background:var(--panel-elevated)">
                            <div class="heading" style="color:var(--brand-bright);margin-bottom:8px">
                                <x-icon name="arrow-up" />
                                <b>{{ __('Subir de plan: :plan (Upgrade)', ['plan' => $planSeleccionado->titulo]) }}</b>
                            </div>
                            <p style="margin:0 0 14px;font-size:12px;line-height:1.6">
                                {{ __('Al subir de plan pagas solo la diferencia proporcional por los días restantes de tu período actual.') }}
                            </p>

                            <div class="fields three" style="margin-bottom:14px">
                                <div class="sub-fact">
                                    <label>{{ __('Días restantes del ciclo') }}</label>
                                    <b>{{ trans_choice(':count día|:count días', $calculoProrrateo['dias_restantes']) }}</b>
                                    <small style="color:var(--faint)">{{ __('hasta el :fecha', ['fecha' => $suscripcion->vencimiento->format('d/m/Y')]) }}</small>
                                </div>
                                <div class="sub-fact">
                                    <label>{{ __('Monto por actualización') }}</label>
                                    <b style="color:var(--brand-bright);font-size:22px">{{ Decimal::bs((string) $calculoProrrateo['monto_prorrateado']) }}</b>
                                    <small style="color:var(--faint)">{{ __('únicamente por los días restantes') }}</small>
                                </div>
                                <div class="sub-fact">
                                    <label>{{ __('Próxima renovación') }}</label>
                                    <b style="font-size:22px">{{ Decimal::bs((string) $calculoProrrateo['precio_proxima_renovacion']) }}</b>
                                    <small style="color:var(--faint)">{{ __('precio completo del nuevo plan') }}</small>
                                </div>
                            </div>

                            <div class="notice" style="margin-bottom:16px">
                                <x-icon name="info" />
                                <div>
                                    <b>{{ __('Condiciones de la actualización:') }}</b>
                                    <p style="margin-top:4px;line-height:1.6">
                                        • <strong>{{ __('Este monto (:monto) es únicamente por la actualización por los días restantes.', ['monto' => Decimal::bs((string) $calculoProrrateo['monto_prorrateado'])]) }}</strong><br>
                                        • <strong>{{ __('En la próxima renovación pagarás el precio completo del nuevo plan (:precio).', ['precio' => Decimal::bs((string) $calculoProrrateo['precio_proxima_renovacion'])]) }}</strong><br>
                                        • {{ __('Tu fecha de vencimiento original (:fecha) se mantiene.', ['fecha' => $suscripcion->vencimiento->format('d/m/Y')]) }}<br>
                                        @if ($planSeleccionado->siat_habilitado)
                                            • <strong style="color:var(--green)">{{ __('El acceso a facturación electrónica SIAT se habilitará de inmediato.') }}</strong>
                                        @endif
                                    </p>
                                </div>
                            </div>

                            <div class="modal-actions" style="margin:0">
                                <button type="button" class="btn ghost" wire:click="$set('planSeleccionadoId', null)">{{ __('Cancelar selección') }}</button>
                                <button type="button" class="btn primary" wire:click="confirmarCambioPlan">
                                    {{ __('Confirmar y subir a :plan', ['plan' => $planSeleccionado->titulo]) }}
                                </button>
                            </div>
                        </div>
                    @elseif ($calculoProrrateo['tipo'] === 'downgrade')
                        <div class="card card-pad" style="border-color:var(--border);background:var(--panel-elevated)">
                            <div class="heading" style="margin-bottom:8px">
                                <x-icon name="arrow-down" />
                                <b>{{ __('Cambiar a plan: :plan (Downgrade)', ['plan' => $planSeleccionado->titulo]) }}</b>
                            </div>
                            <div class="notice" style="margin-bottom:16px">
                                <x-icon name="info" />
                                <div>
                                    <b>{{ __('Condiciones de reducción de plan:') }}</b>
                                    <p style="margin-top:4px;line-height:1.6">
                                        • <strong>{{ __('Conservas todos los beneficios de tu plan actual hasta su vencimiento (:fecha).', ['fecha' => $suscripcion->vencimiento->format('d/m/Y')]) }}</strong><br>
                                        • <strong>{{ __('En la siguiente renovación se aplicará automáticamente el nuevo plan con su precio reducido de :precio/mes.', ['precio' => Decimal::bs((string) $planSeleccionado->precio_mensual)]) }}</strong><br>
                                        • {{ __('Tu fecha de vencimiento original (:fecha) se mantiene.', ['fecha' => $suscripcion->vencimiento->format('d/m/Y')]) }}<br>
                                        • {{ __('No pagas nada el día de hoy por este cambio.') }}
                                    </p>
                                </div>
                            </div>

                            <div class="modal-actions" style="margin:0">
                                <button type="button" class="btn ghost" wire:click="$set('planSeleccionadoId', null)">{{ __('Cancelar selección') }}</button>
                                <button type="button" class="btn soft" wire:click="confirmarCambioPlan">
                                    {{ __('Confirmar programación de cambio') }}
                                </button>
                            </div>
                        </div>
                    @endif
                @endif

                <div class="modal-actions" style="margin-top:18px">
                    <button type="button" class="btn ghost" wire:click="cerrarModalPlanes">{{ __('Cerrar') }}</button>
                </div>
            </div>
        </div>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
