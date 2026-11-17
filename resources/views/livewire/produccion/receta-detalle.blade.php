@use('App\Support\Decimal')
{{-- Receta de un producto (ESPECIFICACION_PRODUCCION.md § 5): versión vigente e historial de solo lectura. --}}
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción · Recetas') }}</div>
            <h1>{{ $producto->nombre }}<span class="accent-text">.</span></h1>
            <p class="lead">
                @if ($vigente)
                    {{ __('Versión vigente :version · produce :cantidad :unidad.', ['version' => $vigente->version, 'cantidad' => Decimal::formatear((string) $vigente->cantidad_base), 'unidad' => $producto->unidad->abreviatura]) }}
                    @if ($vigente->activa)<span class="badge success">{{ __('Activa') }}</span>@else<span class="badge">{{ __('Desactivada') }}</span>@endif
                @endif
                @foreach ($avisos as $aviso)<span class="badge warn">{{ $aviso }}</span>@endforeach
            </p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('produccion.recetas') }}"><x-icon name="arrow-left" /> {{ __('Recetas') }}</a>
            @if ($vigente)
                @if ($vigente->activa)
                    <button class="btn ghost" type="button" wire:click="desactivar" wire:confirm="{{ __('¿Desactivar la receta? No se podrán emitir órdenes nuevas con ella hasta que la actives.') }}" data-test="desactivar-receta">{{ __('Desactivar') }}</button>
                @else
                    <button class="btn ghost" type="button" wire:click="activar" data-test="activar-receta">{{ __('Activar') }}</button>
                @endif
                <a class="btn ghost" href="{{ route('produccion.recetas.editar', $producto) }}" data-test="editar-receta"><x-icon name="edit" /> {{ __('Editar receta') }}</a>
                @if ($vigente->activa)
                    <a class="btn primary" href="{{ route('produccion.ordenes.crear', ['producto' => $producto->id]) }}" data-test="producir-receta"><x-icon name="plus" /> {{ __('Nueva orden') }}</a>
                @endif
            @endif
        </div>
    </div>

    <div class="stack">
        @if ($mirada)
            <section class="card card-pad" data-test="receta-version">
                <div class="card-head">
                    <div>
                        <div class="heading"><span class="heading-icon"><x-icon name="table" /></span>{{ __('Versión :version', ['version' => $mirada->version]) }}</div>
                        <p class="subtle" style="margin:9px 0 0">
                            {{ __('Para :cantidad :unidad · guardada el :fecha por :usuario.', [
                                'cantidad' => Decimal::formatear((string) $mirada->cantidad_base), 'unidad' => $producto->unidad->abreviatura,
                                'fecha' => $mirada->created_at->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i'), 'usuario' => $mirada->usuario->name,
                            ]) }}
                        </p>
                    </div>
                    @unless ($mirada->vigente)
                        <span class="badge" data-test="version-anterior">{{ __('Versión anterior · solo lectura') }}</span>
                    @endunless
                </div>
                @if ($mirada->nota)
                    <p style="margin:0 0 14px">{{ $mirada->nota }}</p>
                @endif
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Materia prima') }}</th><th>{{ __('Código') }}</th><th>{{ __('Cantidad') }}</th></tr></thead>
                        <tbody>
                            @foreach ($mirada->items as $item)
                                <tr wire:key="item-{{ $item->id }}">
                                    <td data-label="{{ __('Materia prima') }}"><b>{{ $item->producto->nombre }}</b>@unless ($item->producto->activo) <span class="badge">{{ __('Inactiva') }}</span>@endunless</td>
                                    <td data-label="{{ __('Código') }}">{{ $item->producto->sku ?? '—' }}</td>
                                    <td data-label="{{ __('Cantidad') }}">{{ Decimal::formatear((string) $item->cantidad) }} {{ $item->producto->unidad->abreviatura }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section class="card card-pad" data-test="receta-historial">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="history" /></span>{{ __('Historial de versiones') }}</div></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Versión') }}</th><th>{{ __('Fecha') }}</th><th>{{ __('Usuario') }}</th><th>{{ __('Cantidad base') }}</th><th>{{ __('Materias primas') }}</th><th><span class="sr-only">{{ __('Acción') }}</span></th></tr></thead>
                    <tbody>
                        @foreach ($versiones as $version)
                            <tr wire:key="version-{{ $version->id }}" @if ($mirada?->id === $version->id) class="rapid-selected" @endif>
                                <td data-label="{{ __('Versión') }}"><b>v{{ $version->version }}</b>@if ($version->vigente) <span class="badge success">{{ __('Vigente') }}</span>@endif</td>
                                <td data-label="{{ __('Fecha') }}">{{ $version->created_at->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</td>
                                <td data-label="{{ __('Usuario') }}">{{ $version->usuario->name }}</td>
                                <td data-label="{{ __('Cantidad base') }}">{{ Decimal::formatear((string) $version->cantidad_base) }} {{ $producto->unidad->abreviatura }}</td>
                                <td data-label="{{ __('Materias primas') }}">{{ $version->items_count }}</td>
                                <td data-label="{{ __('Acción') }}">
                                    @if ($mirada?->id !== $version->id)
                                        <button class="btn ghost sm" type="button" wire:click="$set('version', '{{ $version->vigente ? '' : $version->version }}')"><x-icon name="eye" size="sm" /> {{ __('Ver') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
