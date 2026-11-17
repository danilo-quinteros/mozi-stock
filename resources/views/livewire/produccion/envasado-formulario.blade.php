@use('App\Support\Decimal')
@use('App\Domain\Inventario\DatosLote')
{{-- Nuevo envasado (ESPECIFICACION_PRODUCCION.md § 7.2). --}}
@php
    $nombre = fn ($p) => $p->nombre;
    $merma = function (string $teorico, string $usado): ?string {
        $real = Decimal::normalizar(trim($usado), Decimal::ESCALA_CANTIDAD);

        return $real !== null && Decimal::comparar($real, $teorico) > 0 ? Decimal::restar($real, $teorico) : null;
    };
@endphp
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción · Envasado') }}</div>
            <h1>{{ __('Nuevo envasado') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Escribe las unidades buenas de cada presentación. Se descuentan el granel y los envases usados y entran las unidades, con el mismo lote del granel.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('produccion.envasados') }}"><x-icon name="arrow-left" /> {{ __('Volver sin guardar') }}</a>
        </div>
    </div>

    @if ($sucursales->isEmpty())
        <div class="notice" style="margin-bottom:17px"><x-icon name="info" /><div><b>{{ __('Primero registra una sucursal') }}</b><p>{{ __('El stock siempre pertenece a una sucursal.') }} <a class="link" href="{{ route('sucursales.index') }}">{{ __('Ir a Sucursales') }} <x-icon name="arrow" size="sm" /></a></p></div></div>
    @elseif ($graneles->isEmpty())
        <div class="notice" style="margin-bottom:17px" data-test="sin-presentaciones"><x-icon name="info" /><div><b>{{ __('Primero configura las presentaciones') }}</b><p>{{ __('En la ficha de cada presentación, en la sección «Producto obtenido mediante envasado», elige su producto de origen, cuánto lleva cada envase y sus materiales de envasado.') }} <a class="link" href="{{ route('productos.index') }}">{{ __('Ir a Productos') }} <x-icon name="arrow" size="sm" /></a></p></div></div>
    @endif

    @if ($errors->hasAny(['unidades', 'lineas', 'sucursal_id']))
        <div class="notice caja-alerta" style="margin-bottom:17px" role="alert" data-test="errores-envasado" data-error-validacion><x-icon name="status-alert" /><div>
            @foreach (['unidades', 'lineas', 'sucursal_id'] as $clave)
                @foreach ($errors->get($clave) as $mensaje)<p>{{ $mensaje }}</p>@endforeach
            @endforeach
        </div></div>
    @endif

    <form wire:submit="guardar" class="stack">
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="swap" /></span>{{ __('Datos del envasado') }}</div></div>
            <div class="fields">
                <x-campo etiqueta="Sucursal" campo="sucursal_id">
                    <select id="sucursal_id" class="select" wire:model.live="sucursal_id" data-test="envasado-sucursal">
                        @foreach ($sucursales as $sucursal)
                            <option value="{{ $sucursal->id }}">{{ $sucursal->nombre }}</option>
                        @endforeach
                    </select>
                </x-campo>
                <x-campo etiqueta="Granel" campo="granel_id">
                    <select id="granel_id" class="select" wire:model.live="granel_id" data-test="envasado-granel">
                        <option value="">{{ __('Elige el granel a envasar') }}</option>
                        @foreach ($graneles as $granel)
                            <option value="{{ $granel->id }}">{{ $nombre($granel) }}{{ $granel->sku ? ' · '.$granel->sku : '' }}</option>
                        @endforeach
                    </select>
                </x-campo>
                @if ($conLote)
                    <x-campo etiqueta="Lote del granel" campo="lote_id">
                        <select id="lote_id" class="select" wire:model.live="lote_id" data-test="envasado-lote">
                            @if ($lotes === [])
                                <option value="">{{ __('No hay lotes vigentes con stock en esta sucursal') }}</option>
                            @endif
                            @foreach ($lotes as $lote)
                                <option value="{{ $lote['id'] }}">
                                    {{ $lote['numero'] ?? __('Sin lote (stock anterior)') }}@if ($lote['vencimiento']) · {{ __('vence :fecha', ['fecha' => DatosLote::formatear($lote['vencimiento'])]) }}@endif · {{ Decimal::formatear($lote['cantidad']) }} {{ $preparacion['granel']->unidad->abreviatura }}
                                </option>
                            @endforeach
                        </select>
                        <small class="subtle" style="display:block;margin-top:6px">{{ __('Las presentaciones entran con este número de lote y su vencimiento. Los lotes vencidos no se envasan.') }}</small>
                    </x-campo>
                @endif
                <x-campo etiqueta="Nota (opcional)" campo="nota" style="grid-column:1/-1">
                    <input id="nota" class="input" type="text" maxlength="250" wire:model="nota" placeholder="{{ __('Ej. Envasado para el pedido del mayorista') }}">
                </x-campo>
            </div>
        </section>

        @if ($preparacion)
            @php
                $granel = $preparacion['granel'];
                $unidadGranel = $granel->unidad->abreviatura;
                $teoricos = $preparacion['teoricos'];
            @endphp
            <section class="card card-pad" data-test="envasado-presentaciones">
                <div class="card-head">
                    <div>
                        <div class="heading"><span class="heading-icon"><x-icon name="box" /></span>{{ __('Presentaciones') }}</div>
                        <p class="subtle" style="margin:9px 0 0">{{ __('Escribe solo las unidades buenas; deja vacías las que no se envasan.') }}</p>
                    </div>
                </div>
                @if ($preparacion['presentaciones']->isEmpty())
                    <div class="empty">{{ __(':granel no tiene presentaciones activas.', ['granel' => $granel->nombre]) }}</div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>{{ __('Presentación') }}</th><th>{{ __('Cada unidad lleva') }}</th><th>{{ __('Unidades buenas') }}</th><th>{{ __('Granel necesario') }}</th></tr></thead>
                            <tbody>
                                @foreach ($preparacion['presentaciones'] as $presentacion)
                                    @php
                                        $partes = $preparacion['composicion'][$presentacion->id] ?? ['granel_por_unidad' => '0', 'envases' => []];
                                    @endphp
                                    <tr wire:key="presentacion-{{ $presentacion->id }}">
                                        <td data-label="{{ __('Presentación') }}"><b>{{ $nombre($presentacion) }}</b>@if ($presentacion->sku)<small style="display:block;color:var(--faint);margin-top:4px">{{ $presentacion->sku }}</small>@endif</td>
                                        <td data-label="{{ __('Cada unidad lleva') }}">
                                            <small style="display:block">{{ Decimal::formatear($partes['granel_por_unidad']) }} {{ $unidadGranel }} {{ __('de granel') }}</small>
                                            @forelse ($partes['envases'] as $materiaId => $cantidad)
                                                @if ($materia = $preparacion['materias']->get($materiaId))
                                                    <small style="display:block">{{ Decimal::formatear($cantidad) }} {{ $materia->unidad->abreviatura }} {{ $materia->nombre }}</small>
                                                @endif
                                            @empty
                                                {{-- Sin materiales no es un error (p. ej. se vende en el envase del cliente), pero suele faltar configurarlos. --}}
                                                <small class="accent-text" data-test="sin-materiales-{{ $presentacion->id }}">
                                                    {{ __('Sin materiales de envasado.') }}
                                                    <a class="link" href="{{ route('productos.editar', $presentacion->id) }}#materiales-envasado">{{ __('Agregar') }}</a>
                                                </small>
                                            @endforelse
                                        </td>
                                        <td data-label="{{ __('Unidades buenas') }}">
                                            <div class="row" style="gap:8px;align-items:center">
                                                <input class="input" style="max-width:140px" type="text" inputmode="decimal" wire:model.live.debounce.400ms="unidades.{{ $presentacion->id }}" aria-label="{{ __('Unidades buenas de :presentacion', ['presentacion' => $presentacion->nombre]) }}" data-test="unidades-{{ $presentacion->id }}">
                                                <span class="subtle">{{ $presentacion->unidad->abreviatura }}</span>
                                            </div>
                                            @error('unidades.'.$presentacion->id)<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                                        </td>
                                        <td data-label="{{ __('Granel necesario') }}">
                                            @isset($teoricos['granel_por_presentacion'][$presentacion->id])
                                                {{ Decimal::formatear($teoricos['granel_por_presentacion'][$presentacion->id]) }} {{ $unidadGranel }}
                                            @else
                                                —
                                            @endisset
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="card card-pad" data-test="envasado-materiales">
                <div class="card-head">
                    <div>
                        <div class="heading"><span class="heading-icon"><x-icon name="table" /></span>{{ __('Lo que se usó') }}</div>
                        <p class="subtle" style="margin:9px 0 0">{{ __('Viene con lo necesario para las unidades buenas. Si se usó más (derrames, envases dañados), escribe lo usado: la diferencia es la merma y su costo se reparte entre las presentaciones.') }}</p>
                    </div>
                </div>
                @if (Decimal::comparar($teoricos['granel'], '0') <= 0)
                    <div class="empty">{{ __('Escribe las unidades buenas de al menos una presentación.') }}</div>
                @else
                    @if ($faltantes !== [])
                        <div class="notice mini-note caja-alerta" style="margin-bottom:14px" data-test="aviso-faltantes"><x-icon name="status-alert" /><div>{{ __('No alcanza el stock de algunos materiales. Revisa el lote o la sucursal: sin stock suficiente no se registra nada.') }}</div></div>
                    @endif
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>{{ __('Material') }}</th><th>{{ __('Necesario') }}</th><th>{{ __('Usado') }}</th><th>{{ __('Merma') }}</th></tr></thead>
                            <tbody>
                                @php
                                    $mermaGranel = $merma($teoricos['granel'], $granel_real);
                                @endphp
                                <tr wire:key="material-granel">
                                    <td data-label="{{ __('Material') }}"><b>{{ $granel->nombre }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ __('Granel') }}</small></td>
                                    <td data-label="{{ __('Necesario') }}">{{ Decimal::formatear($teoricos['granel']) }} {{ $unidadGranel }}
                                        @if ($falta = $faltantes[$granel->id] ?? null)<small class="danger-text" style="display:block;margin-top:3px">{{ __('Disponible :disponible · faltan :falta', ['disponible' => Decimal::formatear($falta['disponible']), 'falta' => Decimal::formatear($falta['falta'])]) }}</small>@endif
                                    </td>
                                    <td data-label="{{ __('Usado') }}">
                                        <div class="row" style="gap:8px;align-items:center">
                                            <input class="input" style="max-width:140px" type="text" inputmode="decimal" wire:model.live.debounce.400ms="granel_real" aria-label="{{ __('Granel usado') }}" data-test="granel-real">
                                            <span class="subtle">{{ $unidadGranel }}</span>
                                        </div>
                                        @error('granel_real')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                                    </td>
                                    <td data-label="{{ __('Merma') }}">@if ($mermaGranel)<span class="danger-text">{{ Decimal::formatear($mermaGranel) }} {{ $unidadGranel }}</span>@else — @endif</td>
                                </tr>
                                @foreach ($teoricos['envases_total'] as $materiaId => $teorico)
                                    @php
                                        $materia = $preparacion['materias']->get($materiaId);
                                        $mermaEnvase = $merma($teorico, (string) ($envases[$materiaId] ?? ''));
                                    @endphp
                                    @if ($materia)
                                        <tr wire:key="material-{{ $materiaId }}">
                                            <td data-label="{{ __('Material') }}"><b>{{ $materia->nombre }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ __('Envase') }}</small></td>
                                            <td data-label="{{ __('Necesario') }}">{{ Decimal::formatear($teorico) }} {{ $materia->unidad->abreviatura }}
                                                @if ($falta = $faltantes[$materiaId] ?? null)<small class="danger-text" style="display:block;margin-top:3px">{{ __('Disponible :disponible · faltan :falta', ['disponible' => Decimal::formatear($falta['disponible']), 'falta' => Decimal::formatear($falta['falta'])]) }}</small>@endif
                                            </td>
                                            <td data-label="{{ __('Usado') }}">
                                                <div class="row" style="gap:8px;align-items:center">
                                                    <input class="input" style="max-width:140px" type="text" inputmode="decimal" wire:model.live.debounce.400ms="envases.{{ $materiaId }}" aria-label="{{ __('Usado de :materia', ['materia' => $materia->nombre]) }}" data-test="envase-real-{{ $materiaId }}">
                                                    <span class="subtle">{{ $materia->unidad->abreviatura }}</span>
                                                </div>
                                                @error('envases.'.$materiaId)<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                                            </td>
                                            <td data-label="{{ __('Merma') }}">@if ($mermaEnvase)<span class="danger-text">{{ Decimal::formatear($mermaEnvase) }} {{ $materia->unidad->abreviatura }}</span>@else — @endif</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        <div class="row" style="justify-content:flex-end;gap:10px">
            <a class="btn ghost" href="{{ route('produccion.envasados') }}">{{ __('Cancelar') }}</a>
            <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar" data-test="registrar-envasado" @disabled($sucursales->isEmpty() || ! $preparacion)>
                <x-icon name="check" /> {{ __('Registrar envasado') }}
            </button>
        </div>
    </form>
</div>
