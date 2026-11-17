@use('App\Support\Decimal')
{{-- Nueva orden de producción o edición de una Emitida (ESPECIFICACION_PRODUCCION.md § 6.1 y M2). --}}
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción · Órdenes') }}</div>
            <h1>{{ $orden ? __('Editar orden :codigo', ['codigo' => $orden->codigo()]) : __('Nueva orden de producción') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Emitir no mueve stock: las materias primas se descuentan al aprobar la orden.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ $orden ? route('produccion.ordenes.ver', $orden) : route('produccion.ordenes') }}"><x-icon name="arrow-left" /> {{ __('Volver sin guardar') }}</a>
        </div>
    </div>

    @if ($sucursales->isEmpty())
        <div class="notice" style="margin-bottom:17px"><x-icon name="info" /><div><b>{{ __('Primero registra una sucursal') }}</b><p>{{ __('El stock siempre pertenece a una sucursal.') }} <a class="link" href="{{ route('sucursales.index') }}">{{ __('Ir a Sucursales') }} <x-icon name="arrow" size="sm" /></a></p></div></div>
    @elseif (! $orden && $productos->isEmpty())
        <div class="notice" style="margin-bottom:17px"><x-icon name="info" /><div><b>{{ __('Primero crea una receta') }}</b><p>{{ __('Solo se producen productos con una receta activa.') }} <a class="link" href="{{ route('produccion.recetas.crear') }}">{{ __('Nueva receta') }} <x-icon name="arrow" size="sm" /></a></p></div></div>
    @endif

    @error('orden')<div class="notice caja-alerta" style="margin-bottom:17px" role="alert" data-test="error-orden" data-error-validacion><x-icon name="status-alert" /><div><p>{{ $message }}</p></div></div>@enderror

    <form wire:submit="guardar" class="stack">
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="receipt" /></span>{{ __('Datos de la orden') }}</div></div>
            <div class="fields">
                <x-campo etiqueta="Producto" campo="producto_id">
                    @if ($orden)
                        <input id="producto_id" class="input" type="text" value="{{ $orden->producto->nombre }}" disabled>
                    @else
                        <select id="producto_id" class="select" wire:model.live="producto_id" data-test="orden-producto">
                            <option value="">{{ __('Elige el producto a fabricar') }}</option>
                            @foreach ($productos as $producto)
                                <option value="{{ $producto->id }}">{{ $producto->nombre }}{{ $producto->sku ? ' · '.$producto->sku : '' }}</option>
                            @endforeach
                        </select>
                    @endif
                </x-campo>
                <x-campo etiqueta="Sucursal" campo="sucursal_id">
                    <select id="sucursal_id" class="select" wire:model.live="sucursal_id" data-test="orden-sucursal">
                        @foreach ($sucursales as $sucursal)
                            <option value="{{ $sucursal->id }}">{{ $sucursal->nombre }}</option>
                        @endforeach
                    </select>
                </x-campo>
                <x-campo etiqueta="Cantidad a producir" campo="cantidad">
                    <div class="row" style="gap:8px;align-items:center">
                        <input id="cantidad" class="input" type="text" inputmode="decimal" wire:model.live.debounce.400ms="cantidad" placeholder="{{ __('Ej. 100') }}" data-test="orden-cantidad" required>
                        @if ($vista['producto'])<span class="subtle">{{ $vista['producto']->unidad->abreviatura }}</span>@endif
                    </div>
                </x-campo>
                <x-campo etiqueta="Fecha programada" campo="fecha_programada">
                    <input id="fecha_programada" class="input" type="date" wire:model="fecha_programada" data-test="orden-fecha" required>
                </x-campo>
                <x-campo etiqueta="Nota (opcional)" campo="nota" style="grid-column:1/-1">
                    <input id="nota" class="input" type="text" maxlength="250" wire:model="nota" placeholder="{{ __('Ej. Pedido del cliente mayorista') }}">
                </x-campo>
            </div>
        </section>

        <section class="card card-pad" data-test="orden-materias">
            <div class="card-head">
                <div>
                    <div class="heading"><span class="heading-icon"><x-icon name="table" /></span>{{ __('Materias primas') }}</div>
                    @if ($vista['base'] !== null && $vista['producto'])
                        <p class="subtle" style="margin:9px 0 0">{{ __('Receta para :cantidad :unidad. Las que se usan en unidades enteras se redondean hacia arriba.', ['cantidad' => Decimal::formatear($vista['base']), 'unidad' => $vista['producto']->unidad->abreviatura]) }}</p>
                    @endif
                </div>
            </div>
            @if ($vista['lineas'] === [])
                <div class="empty">{{ __('Elige un producto para ver las materias primas de su receta.') }}</div>
            @else
                @php($conFaltantes = collect($vista['lineas'])->contains(fn ($l) => $l['falta'] !== null))
                @if ($conFaltantes)
                    <div class="notice mini-note caja-alerta" style="margin-bottom:14px" data-test="aviso-faltantes"><x-icon name="status-alert" /><div>{{ __('No alcanza el stock de algunas materias primas en esta sucursal. Puedes emitir la orden, pero para aprobarla tiene que haber stock.') }}</div></div>
                @endif
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Materia prima') }}</th><th>{{ __('En la receta') }}</th><th>{{ __('Necesario') }}</th><th>{{ __('Disponible') }}</th></tr></thead>
                        <tbody>
                            @foreach ($vista['lineas'] as $linea)
                                <tr wire:key="materia-{{ $linea['producto_id'] }}">
                                    <td data-label="{{ __('Materia prima') }}"><b>{{ $linea['nombre'] }}</b></td>
                                    <td data-label="{{ __('En la receta') }}">{{ Decimal::formatear($linea['cantidad_receta']) }} {{ $linea['unidad'] }}</td>
                                    <td data-label="{{ __('Necesario') }}">{{ $linea['necesaria'] === null ? '—' : Decimal::formatear($linea['necesaria']).' '.$linea['unidad'] }}</td>
                                    <td data-label="{{ __('Disponible') }}">
                                        @if ($linea['falta'] !== null)
                                            <span class="danger-text">{{ Decimal::formatear($linea['disponible']) }} {{ $linea['unidad'] }}</span>
                                            <small style="display:block;margin-top:3px" class="danger-text">{{ __('Faltan :cantidad', ['cantidad' => Decimal::formatear($linea['falta'])]) }}</small>
                                        @elseif ($linea['necesaria'] !== null)
                                            <span class="success-text">{{ __('Alcanza') }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <div class="row" style="justify-content:flex-end;gap:10px">
            <a class="btn ghost" href="{{ $orden ? route('produccion.ordenes.ver', $orden) : route('produccion.ordenes') }}">{{ __('Cancelar') }}</a>
            <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar" data-test="guardar-orden" @disabled($sucursales->isEmpty())>
                <x-icon name="check" /> {{ $orden ? __('Guardar cambios') : __('Emitir orden') }}
            </button>
        </div>
    </form>
</div>
