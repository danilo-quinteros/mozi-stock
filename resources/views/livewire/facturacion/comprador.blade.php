{{-- Datos fiscales del comprador y método de pago SIN (catálogos sincronizados). Lo usan la confirmación de
     venta y el detalle de la venta; el componente expone $factura y valida con DatosFactura::reglas('factura.'). --}}
@php($tarjetas = $metodosPago->filter(fn ($m) => str_contains(mb_strtoupper($m->descripcion), 'TARJETA'))->pluck('codigo')->all())
<div class="fields">
    <x-campo etiqueta="Tipo de documento" campo="factura.tipo_documento">
        <select id="factura.tipo_documento" class="select" wire:model.live="factura.tipo_documento">
            <option value="">{{ __('Elige…') }}</option>
            @foreach ($tiposDocumento as $tipo)
                <option value="{{ $tipo->codigo }}">{{ $tipo->descripcion }}</option>
            @endforeach
        </select>
    </x-campo>
    <x-campo etiqueta="Número de documento" campo="factura.numero_documento">
        <input id="factura.numero_documento" class="input" type="text" maxlength="20" wire:model="factura.numero_documento" autocomplete="off">
    </x-campo>
    <x-campo etiqueta="Nombre o razón social" campo="factura.razon_social">
        <input id="factura.razon_social" class="input" type="text" maxlength="500" wire:model="factura.razon_social" autocomplete="off">
    </x-campo>
    <x-campo etiqueta="Complemento (CI)" campo="factura.complemento">
        <input id="factura.complemento" class="input" type="text" maxlength="5" wire:model="factura.complemento" autocomplete="off">
    </x-campo>
    <x-campo etiqueta="Método de pago fiscal" campo="factura.metodo_pago" style="grid-column:1/-1">
        <select id="factura.metodo_pago" class="select" wire:model.live="factura.metodo_pago">
            <option value="">{{ __('Elige…') }}</option>
            @foreach ($metodosPago as $metodo)
                <option value="{{ $metodo->codigo }}">{{ $metodo->codigo }} · {{ $metodo->descripcion }}</option>
            @endforeach
        </select>
    </x-campo>
    @if (in_array((string) ($factura['metodo_pago'] ?? ''), $tarjetas, true))
        <x-campo etiqueta="Tarjeta: primeros 4 dígitos" campo="factura.tarjeta_inicio">
            <input id="factura.tarjeta_inicio" class="input" type="text" inputmode="numeric" maxlength="4" wire:model="factura.tarjeta_inicio" autocomplete="off">
        </x-campo>
        <x-campo etiqueta="Tarjeta: últimos 4 dígitos" campo="factura.tarjeta_fin">
            <input id="factura.tarjeta_fin" class="input" type="text" inputmode="numeric" maxlength="4" wire:model="factura.tarjeta_fin" autocomplete="off">
        </x-campo>
    @endif
    @if ((string) ($factura['tipo_documento'] ?? '') === (string) \App\Domain\Facturacion\DatosFactura::TIPO_NIT && ($factura['numero_documento'] ?? '') !== \App\Domain\Facturacion\DatosFactura::SIN_DATOS_DOCUMENTO)
        <label class="row" style="grid-column:1/-1"><input type="checkbox" wire:model="factura.nit_sin_validar"><span>{{ __('Emitir aunque el SIN no reconozca el NIT (código de excepción)') }}</span></label>
    @endif
</div>
<p class="subtle" style="margin:8px 0 0">{{ __('Sin datos del comprador: NIT 99002 y «CONTROL TRIBUTARIO» (control tributario). Solo se guardan los primeros y últimos 4 dígitos de una tarjeta.') }}</p>
