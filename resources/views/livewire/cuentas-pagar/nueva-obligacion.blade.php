<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Cuentas por pagar') }}</div>
            <h1>{{ __('Nueva obligación') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Deuda que no nace de una compra ni de un egreso registrados aquí. No mueve stock ni dinero.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('cuentas-pagar.index') }}"><x-icon name="arrow-left" /> {{ __('Volver sin guardar') }}</a>
        </div>
    </div>

    @if ($proveedores->isEmpty())
        <div class="notice" style="margin-bottom:17px"><x-icon name="info" /><div><b>{{ __('Primero registra al acreedor') }}</b><p>{{ __('Toda obligación tiene un proveedor o acreedor.') }} <a class="link" href="{{ route('proveedores.index') }}">{{ __('Ir a Proveedores') }} <x-icon name="arrow" size="sm" /></a></p></div></div>
    @endif

    <section class="card card-pad">
        <form wire:submit="guardar">
            <div class="fields">
                <x-campo etiqueta="Proveedor o acreedor" campo="proveedor_id">
                    <select id="proveedor_id" class="select" wire:model="proveedor_id" required>
                        <option value="">{{ __('Selecciona un acreedor') }}</option>
                        @foreach ($proveedores as $proveedor)
                            <option value="{{ $proveedor->id }}">{{ $proveedor->nombre }}</option>
                        @endforeach
                    </select>
                </x-campo>
                <x-campo etiqueta="Concepto" campo="concepto" style="grid-column:1/-1">
                    <input id="concepto" class="input" type="text" maxlength="150" wire:model="concepto" placeholder="{{ __('Ej. Saldo pendiente con proveedor antes de MoziStock') }}" required>
                </x-campo>
                <x-campo etiqueta="Sucursal" campo="sucursal_id">
                    <select id="sucursal_id" class="select" wire:model="sucursal_id">
                        @foreach ($sucursales as $sucursal)
                            <option value="{{ $sucursal->id }}">{{ $sucursal->nombre }}</option>
                        @endforeach
                    </select>
                </x-campo>
                <x-campo etiqueta="Fecha de registro" campo="fecha">
                    <input id="fecha" class="input" type="date" wire:model="fecha" required>
                </x-campo>
                <x-campo etiqueta="Importe total (Bs)" campo="importe">
                    <input id="importe" class="input" type="text" inputmode="decimal" wire:model="importe" placeholder="0,00" required>
                </x-campo>
                <x-campo etiqueta="Ya pagado antes (Bs, opcional)" campo="pagado">
                    <input id="pagado" class="input" type="text" inputmode="decimal" wire:model="pagado" placeholder="0,00">
                </x-campo>
                <x-campo etiqueta="Vencimiento" campo="vencimiento">
                    <input id="vencimiento" class="input" type="date" wire:model="vencimiento" required>
                </x-campo>
                <x-campo etiqueta="Respaldo (opcional)" campo="respaldo">
                    <input id="respaldo" class="input" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" wire:model="respaldo">
                </x-campo>
                <x-campo etiqueta="Nota (opcional)" campo="nota" style="grid-column:1/-1">
                    <input id="nota" class="input" type="text" maxlength="250" wire:model="nota">
                </x-campo>
            </div>
            <div class="notice" style="margin-top:16px"><x-icon name="info" /><div><b>{{ __('No es una salida de dinero') }}</b><p>{{ __('Lo pagado antes solo reduce el saldo inicial. Los pagos que hagas desde hoy se registran en la cuenta, con su fecha real.') }}</p></div></div>
            <div class="modal-actions">
                <a class="btn ghost" href="{{ route('cuentas-pagar.index') }}">{{ __('Cancelar') }}</a>
                <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar,respaldo" @disabled($proveedores->isEmpty())><x-icon name="check" /> {{ __('Registrar obligación') }}</button>
            </div>
        </form>
    </section>
</div>
