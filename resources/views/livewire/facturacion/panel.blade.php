<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ $panelGlobal ? __('Superadministración') : __('Mi empresa') }}</div>
            <h1>{{ __('Facturación') }}<span class="accent-text">.</span></h1>
            <p class="lead">
                @if ($panelGlobal)
                    {{ __('Facturación electrónica SIAT de :empresa.', ['empresa' => $empresa->nombre]) }}
                @else
                    {{ __('Datos fiscales, sucursales y puntos de venta del SIN.') }}
                @endif
            </p>
        </div>
        @if ($panelGlobal)
            <div class="page-actions">
                <a class="btn ghost" href="{{ route('admin.empresas') }}" wire:navigate><x-icon name="arrow-left" /> {{ __('Empresas') }}</a>
            </div>
        @endif
    </div>

    @unless ($panelGlobal)
        <x-empresa-nav />
    @endunless

    <nav class="tabs" aria-label="{{ __('Secciones de facturación') }}">
        @foreach (['datos' => ['Datos fiscales', 'receipt'], 'puntos' => ['Sucursales y puntos de venta', 'store'], 'catalogos' => ['Catálogos del SIN', 'history'], 'facturas' => ['Facturas', 'print']] as $clave => [$etiqueta, $icono])
            <button type="button" class="tab {{ $seccion === $clave ? 'active' : '' }}" wire:click="$set('seccion', '{{ $clave }}')" @if ($seccion === $clave) aria-current="page" @endif><x-icon :name="$icono" size="sm" /> {{ __($etiqueta) }}</button>
        @endforeach
    </nav>

    @if ($seccion === 'puntos')
        <livewire:facturacion.puntos :empresa-id="$empresaId" :key="'puntos-'.$empresaId" />
    @elseif ($seccion === 'facturas')
        <livewire:facturacion.facturas :empresa-id="$empresaId" :key="'facturas-'.$empresaId" />
    @elseif ($seccion === 'catalogos')
        <livewire:facturacion.catalogos :empresa-id="$empresaId" :key="'catalogos-'.$empresaId" />
    @else
        <livewire:facturacion.asistente :empresa-id="$empresaId" :key="'asistente-'.$empresaId" />
    @endif
</div>
