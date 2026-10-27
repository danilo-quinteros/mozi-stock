<div>
    @if ($embebido)
        @include('livewire.empresa.partials.formulario-empresa')
        <div class="modal-actions">
            <button class="btn primary" type="submit" form="datos-empresa" wire:loading.attr="disabled" wire:target="guardar"><x-icon name="check" /> {{ __('Guardar cambios') }}</button>
        </div>
    @else
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Mi empresa') }}</div>
            <h1>{{ __('Configuración') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Datos del negocio y preferencias generales.') }}</p>
        </div>
        <div class="page-actions">
            <button class="btn primary" type="submit" form="datos-empresa" wire:loading.attr="disabled" wire:target="guardar"><x-icon name="check" /> {{ __('Guardar cambios') }}</button>
        </div>
    </div>

    <x-empresa-nav />

    <div class="wide-left">
        <div class="stack">
            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="store" /></span>{{ __('Datos del negocio') }}</div></div>
                @include('livewire.empresa.partials.formulario-empresa')
            </section>
            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="settings" /></span>{{ __('Preferencias de interfaz') }}</div></div>
                <div class="list-line">
                    <div><b>{{ __('Modo claro u oscuro') }}</b><p class="subtle" style="margin:5px 0 0">{{ __('Se recuerda en este navegador.') }}</p></div>
                    <button class="btn soft sm" type="button" data-theme-toggle>{{ __('Cambiar tema') }}</button>
                </div>
            </section>
        </div>
        <div class="stack">
            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="info" /></span>{{ __('Acerca del sistema') }}</div></div>
                <div class="list-line"><span class="subtle">{{ __('Empresa') }}</span><b>{{ $empresa->identificador() }}</b></div>
                <div class="list-line"><span class="subtle">{{ __('Producto') }}</span><b>{{ __('MoziStock') }}</b></div>
                <div class="list-line"><span class="subtle">{{ __('Desarrollador') }}</span><b>{{ __('MoziStudios') }}</b></div>
            </section>
            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="receipt" /></span>{{ __('Facturación electrónica') }}</div></div>
                @if ($empresa->siat_habilitado)
                    <div class="notice"><x-icon name="status-ok" /><div><b>{{ __('SIAT Bolivia · Habilitada') }}</b><p>{{ __('Carga el NIT, el token delegado, las sucursales, los puntos de venta y los catálogos del SIN. La pestaña «Facturas» muestra qué falta para emitir.') }}</p></div></div>
                    <a class="btn soft sm" href="{{ route('facturacion') }}" wire:navigate style="margin-top:14px"><x-icon name="receipt" size="sm" /> {{ __('Abrir facturación') }}</a>
                @else
                    <div class="notice"><x-icon name="info" /><div><b>{{ __('SIAT Bolivia · No habilitada') }}</b><p>{{ __('MoziStudios habilita la facturación de cada empresa cuando cuenta con su autorización del SIN.') }}</p></div></div>
                @endif
            </section>
        </div>
    </div>

    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
