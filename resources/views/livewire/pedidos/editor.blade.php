@use('App\Support\Decimal')
@use('App\Enums\TipoPrecio')
<div>
    <div class="title-row">
        <div>
            <div class="eyebrow">{{ __('Espacio de ventas') }}</div>
            <h1>{{ $pedido ? __('Pedido :codigo', ['codigo' => $pedido->codigo()]) : __('Nuevo pedido') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Prepara una cotización o confirma una venta con todos los detalles bajo control.') }}</p>
        </div>
        @if ($pedido)
            <span class="badge {{ $pedido->estado->insignia() }}">{{ $pedido->estado->etiqueta() }}</span>
        @endif
    </div>

    @if ($soloLectura)
        <div class="notice" style="margin-bottom:18px"><x-icon name="info" /><div><b>{{ __('Pedido :estado', ['estado' => mb_strtolower($pedido->estado->etiqueta())]) }}</b><p>{{ __('Se conserva como registro. No movió stock ni dinero y ya no puede modificarse.') }}</p></div></div>
    @endif

    <fieldset @disabled($soloLectura) style="border:0;padding:0;margin:0;min-width:0">
        <section class="card meta-card" aria-labelledby="datos-titulo">
            <div class="section-header"><div class="section-heading" id="datos-titulo"><span class="heading-icon"><x-icon name="info" size="sm" /></span>{{ __('Datos del pedido') }}</div><div class="muted-caption">{{ __('Información general') }}</div></div>
            <div class="meta-grid">
                <div class="field">
                    <span class="field-label">{{ __('N.º de pedido') }}</span>
                    <input class="input" type="text" readonly value="{{ $pedido?->codigo() ?? __('Se asigna al guardar') }}">
                </div>
                <x-campo etiqueta="Sucursal" campo="sucursal_id">
                    <select id="sucursal_id" class="select" wire:model.live="sucursal_id" @disabled(! $administra)>
                        @foreach ($sucursales as $opcion)
                            <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                        @endforeach
                    </select>
                </x-campo>
                <div class="field" style="position:relative">
                    <label class="field-label" for="buscarCliente">{{ __('Cliente') }}</label>
                    @if ($cliente)
                        <div class="cliente-elegido">
                            <span><b>{{ $cliente->nombre }}</b>@if ($cliente->documento)<small>{{ __('CI/NIT :documento', ['documento' => $cliente->documento]) }}</small>@endif</span>
                            <button type="button" class="icon-btn" wire:click="quitarCliente" aria-label="{{ __('Quitar cliente') }}" title="{{ __('Quitar cliente') }}"><x-icon name="x" size="sm" /></button>
                        </div>
                    @else
                        <div class="row" style="gap:6px">
                            <input id="buscarCliente" class="input" type="search" autocomplete="off" placeholder="{{ __('Consumidor final · buscar cliente') }}" wire:model.live.debounce.300ms="buscarCliente">
                            <button type="button" class="btn ghost sm" wire:click="nuevoCliente" title="{{ __('Registrar cliente') }}" aria-label="{{ __('Registrar cliente') }}"><x-icon name="plus" size="sm" /></button>
                        </div>
                        @if ($clientesEncontrados->isNotEmpty())
                            <div class="search-results open" role="listbox">
                                @foreach ($clientesEncontrados as $opcion)
                                    <button type="button" class="search-result" wire:key="cliente-{{ $opcion->id }}" wire:click="seleccionarCliente({{ $opcion->id }})">
                                        <span><b>{{ $opcion->nombre }}</b><small>{{ $opcion->documento ? __('CI/NIT :documento', ['documento' => $opcion->documento]) : __('Sin documento') }}</small></span>
                                    </button>
                                @endforeach
                            </div>
                        @elseif (mb_strlen(trim($buscarCliente)) >= 2)
                            <div class="search-results open" role="status" data-test="sin-clientes">
                                <div class="search-result search-result-vacio"><span><b>{{ __('Cliente no encontrado') }}</b><small>{{ __('Revisa el nombre o el CI/NIT, o regístralo con +.') }}</small></span></div>
                            </div>
                        @endif
                    @endif
                    @error('cliente_id')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <x-campo etiqueta="Vendedor" campo="vendedor_id">
                    @if ($administra)
                        <select id="vendedor_id" class="select" wire:model="vendedor_id">
                            @foreach ($vendedores as $opcion)
                                <option value="{{ $opcion->id }}">{{ $opcion->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <input id="vendedor_id" class="input" type="text" readonly value="{{ $pedido?->vendedor->name ?? auth()->user()->name }}">
                    @endif
                </x-campo>
                <div class="field">
                    <span class="field-label">{{ __('Fecha') }}</span>
                    <input class="input" type="text" readonly value="{{ ($pedido?->created_at ?? now())->timezone(config('mozistock.business_timezone'))->format('d/m/Y') }}">
                </div>
            </div>
        </section>

        <div class="work-grid">
            <div class="left-column">
                <section class="card products-card" aria-labelledby="productos-titulo">
                    <div class="section-header">
                        <div>
                            <div class="section-heading" id="productos-titulo"><span class="heading-icon"><x-icon name="store" size="sm" /></span>{{ __('Productos del pedido') }}</div>
                            <div class="section-sub">{{ __('Elige precios, cantidades y descuentos sin salir de esta pantalla.') }}</div>
                        </div>
                        <span class="muted-caption">{{ trans_choice(':count producto|:count productos', count($filas)) }}</span>
                    </div>
                    @unless ($soloLectura)
                        @include('livewire.inventario.partials.buscador', ['sugerencias' => $sugerencias, 'placeholder' => 'Buscar producto por nombre, código o escanear...'])
                        @if ($mensajeCaptura)<p class="subtle" style="margin:-4px 0 12px" role="status">{{ $mensajeCaptura }}</p>@endif
                        @include('livewire.catalogo.partials.selector-variante')
                    @endunless
                    <div class="table-scroll">
                        <table class="order-table">
                            <thead><tr><th>{{ __('PRODUCTO') }}</th><th>{{ __('CANT.') }}</th><th>{{ __('LISTA DE PRECIO') }}</th><th>{{ __('PRECIO / U.') }}</th><th>{{ __('DESCUENTO') }}</th><th>{{ __('SUBTOTAL') }}</th><th aria-label="{{ __('Acciones') }}"></th></tr></thead>
                            <tbody>
                                @forelse ($filas as $fila)
                                    @php
                                        $i = $fila['indice'];
                                        $producto = $fila['producto'];
                                        $neto = $calculo['por_indice'][$i]['total'] ?? null;
                                        $falta = $fila['cantidad'] !== null && $fila['stock'] !== null && Decimal::comparar($fila['stock'], $fila['cantidad']) < 0;
                                    @endphp
                                    <tr wire:key="pedido-linea-{{ $i }}-{{ $producto->id }}">
                                        <td>
                                            <b style="font-size:12px">{{ $producto->nombre }}</b>
                                            @if ($fila['stock'] === null)
                                                <span class="stock-hint">@if ($producto->marca){{ $producto->marca }} · @endif{{ $producto->sku }} · {{ __('Sin control') }}</span>
                                            @else
                                                <span class="stock-hint @if ($falta) falta @endif">@if ($producto->marca){{ $producto->marca }} · @endif{{ $producto->sku }} · {{ __('Stock') }} {{ Decimal::formatear($fila['stock']) }} {{ $producto->unidad->abreviatura }}@if ($falta) · {{ __('insuficiente') }} @endif</span>
                                            @endif
                                        </td>
                                        <td><input class="input table-input" type="text" inputmode="decimal" aria-label="{{ __('Cantidad de :producto', ['producto' => $producto->nombre]) }}" wire:model.live.debounce.400ms="lineas.{{ $i }}.cantidad"></td>
                                        <td>
                                            <select class="select table-select" aria-label="{{ __('Lista de precio de :producto', ['producto' => $producto->nombre]) }}" wire:model.live="lineas.{{ $i }}.tipo_precio">
                                                @foreach (TipoPrecio::cases() as $tipo)
                                                    <option value="{{ $tipo->value }}" @disabled($producto->{$tipo->columna()} === null && ! isset($cotizados[$producto->id.':'.$tipo->value]))>{{ $tipo->etiqueta() }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td><div class="price-col">{{ $fila['precio'] === null ? '—' : Decimal::bs($fila['precio']) }}</div></td>
                                        <td>
                                            <div class="discount-inline">
                                                <input class="input table-input" type="text" inputmode="decimal" aria-label="{{ __('Descuento de :producto', ['producto' => $producto->nombre]) }}" wire:model.live.debounce.400ms="lineas.{{ $i }}.descuento_valor">
                                                <select class="select type-select" aria-label="{{ __('Modalidad del descuento') }}" wire:model.live="lineas.{{ $i }}.descuento_tipo">
                                                    <option value="bs">{{ __('Bs') }}</option>
                                                    <option value="pct">%</option>
                                                </select>
                                            </div>
                                        </td>
                                        <td><b class="price-col">{{ $neto === null ? '—' : Decimal::bs($neto) }}</b></td>
                                        <td><button type="button" class="icon-btn" wire:click="quitarIndice({{ $i }})" aria-label="{{ __('Quitar :producto', ['producto' => $producto->nombre]) }}" title="{{ __('Quitar producto') }}"><x-icon name="x" size="sm" /></button></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="empty-table">{{ __('Todavía no hay productos. Búscalos arriba o escanea su código.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">{{ __('Los precios salen de la lista elegida. Los descuentos se aplican por línea y sobre el total.') }}</div>
                </section>

                <section class="card notes-card" aria-labelledby="notas-titulo">
                    <div class="section-header" style="margin-bottom:15px"><div class="section-heading" id="notas-titulo"><span class="heading-icon"><x-icon name="edit" size="sm" /></span>{{ __('Notas del pedido') }}</div><span class="muted-caption">{{ __('Opcional') }}</span></div>
                    <textarea class="input note-input" maxlength="800" wire:model.blur="nota" placeholder="{{ __('Por ejemplo: entregar el viernes por la tarde...') }}"></textarea>
                </section>
            </div>

            <div class="right-column">
                <section class="card summary-card" aria-labelledby="resumen-titulo">
                    <div class="section-header" style="margin-bottom:22px"><div class="section-heading" id="resumen-titulo"><span class="heading-icon"><x-icon name="check" size="sm" /></span>{{ __('Resumen') }}</div></div>
                    <div class="summary-line"><span>{{ __('Subtotal productos') }}</span><b>{{ Decimal::bs($calculo['subtotal'] ?? '0') }}</b></div>
                    <div class="summary-line negative"><span>{{ __('Descuentos por producto') }}</span><b>− {{ Decimal::bs($calculo['descuento_lineas'] ?? '0') }}</b></div>
                    <div class="summary-divider"></div>
                    <label class="field-label" for="descuento_global_valor">{{ __('Descuento global') }}</label>
                    <div class="global-discount" style="margin-top:8px">
                        <input id="descuento_global_valor" class="input" type="text" inputmode="decimal" wire:model.live.debounce.400ms="descuento_global_valor">
                        <select class="select" aria-label="{{ __('Tipo de descuento global') }}" wire:model.live="descuento_global_tipo">
                            <option value="bs">{{ __('Bs') }}</option>
                            <option value="pct">%</option>
                        </select>
                    </div>
                    <div class="total-box"><div class="total-label">{{ __('Total del pedido') }}</div><strong class="total-number">{{ Decimal::bs($calculo['total'] ?? '0') }}</strong></div>
                </section>

                <section class="card payment-card" aria-labelledby="pago-titulo">
                    <div class="section-heading" id="pago-titulo"><span class="heading-icon"><x-icon name="lock" size="sm" /></span>{{ __('Forma de pago') }}</div>
                    <div class="payment-methods" role="group" aria-label="{{ __('Método de pago') }}">
                        @foreach (['efectivo' => ['Efectivo', 'Pago en caja'], 'electronico' => ['Electrónico', 'QR o transferencia'], 'mixto' => ['Mixto', 'Dos medios']] as $clave => [$titulo, $detalle])
                            <button type="button" class="payment-option {{ $pago_metodo === $clave ? 'active' : '' }}" aria-pressed="{{ $pago_metodo === $clave ? 'true' : 'false' }}" wire:click="elegirMetodo('{{ $clave }}')">
                                <div><span>{{ __($titulo) }}</span><small>{{ __($detalle) }}</small></div>
                            </button>
                        @endforeach
                    </div>
                    <div class="installment-row">
                        <div class="toggle-copy"><b>{{ __('Pago en cuotas') }}</b><small>{{ __('Permite registrar una cuota inicial desde Bs 0.') }}</small></div>
                        <button type="button" class="switch" role="switch" aria-checked="{{ $pago_en_cuotas ? 'true' : 'false' }}" aria-label="{{ __('Pago en cuotas') }}" wire:click="$toggle('pago_en_cuotas')"></button>
                    </div>
                    <div class="payment-fields">
                        @if ($pago_en_cuotas)
                            <x-campo etiqueta="Cuota inicial (Bs)" campo="pago_cuota_inicial">
                                <input id="pago_cuota_inicial" class="input" type="text" inputmode="decimal" wire:model.live.debounce.400ms="pago_cuota_inicial">
                            </x-campo>
                            <x-campo etiqueta="Vencimiento del saldo (opcional)" campo="pago_vencimiento">
                                <input id="pago_vencimiento" class="input" type="date" wire:model="pago_vencimiento" min="{{ now()->timezone(config('mozistock.business_timezone'))->format('Y-m-d') }}">
                            </x-campo>
                        @endif
                        @if ($pago_metodo === 'mixto')
                            <div class="payment-grid">
                                <x-campo etiqueta="Monto en efectivo" campo="pago_efectivo">
                                    <input id="pago_efectivo" class="input" type="text" inputmode="decimal" wire:model.live.debounce.400ms="pago_efectivo">
                                </x-campo>
                                <x-campo etiqueta="Monto electrónico" campo="pago_electronico">
                                    <input id="pago_electronico" class="input" type="text" inputmode="decimal" wire:model.live.debounce.400ms="pago_electronico">
                                </x-campo>
                            </div>
                            <p class="payment-hint">{{ $pago_en_cuotas ? __('En pago mixto, ambos importes deben sumar la cuota inicial.') : __('En pago mixto, ambos importes deben sumar el total del pedido.') }}</p>
                        @elseif (! $pago_en_cuotas)
                            <p class="payment-hint">{{ $pago_metodo === 'efectivo' ? __('Al confirmar se registrará como cobrado el total del pedido en efectivo.') : __('Al confirmar se registrará como cobrado el total del pedido en medio electrónico.') }}</p>
                        @endif
                    </div>
                    @if (! $pago_en_cuotas || ($pago && Decimal::comparar($pago['monto'], '0', 2) > 0))
                        <div class="payment-fields">@include('livewire.partials.caja', ['metodo' => $pago_metodo])</div>
                    @endif
                    @php($saldo = $calculo && $pago ? Decimal::restar($calculo['total'], $pago['monto'], 2) : null)
                    <div class="payment-summary">
                        <span>{{ $pago_en_cuotas ? __('Saldo pendiente (crédito)') : __('Cobro al confirmar') }}</span>
                        <b class="{{ $pago_en_cuotas ? 'orange' : 'green' }}">{{ $saldo === null ? '—' : Decimal::bs($pago_en_cuotas ? $saldo : $pago['monto']) }}</b>
                    </div>
                    @php($mensajes = array_values(array_unique(array_merge($erroresVivos, $errors->all()))))
                    @if ($mensajes !== [])
                        <div class="validation show" role="alert"><span>@foreach ($mensajes as $mensaje){{ $mensaje }}@if (! $loop->last)<br>@endif @endforeach</span></div>
                    @endif
                </section>

                <div class="rule-card">
                    <div class="rule-icon"><x-icon name="shield" size="sm" /></div>
                    <div><b>{{ __('Cobros precisos, reportes confiables') }}</b><p>{{ __('Una cotización no genera ingresos. En ventas a crédito, solo se contabiliza lo efectivamente pagado, en la fecha de cada cobro.') }}</p></div>
                </div>
            </div>
        </div>
    </fieldset>

    @unless ($soloLectura)
        <footer class="action-bar" aria-label="{{ __('Acciones del pedido') }}">
            <div class="action-left">
                <button class="btn ghost" type="button" wire:click="guardar" wire:loading.attr="disabled" @disabled($filas === [])>{{ __('Guardar cotización') }}</button>
                <span class="action-note">{{ $pedido ? __('Guardado como :codigo', ['codigo' => $pedido->codigo()]) : __('Sin guardar') }}</span>
            </div>
            <div class="action-right">
                @if ($pedido)
                    <button class="btn ghost" type="button" wire:click="$set('confirmandoCancelacion', true)">{{ __('Cancelar pedido') }}</button>
                @endif
                <button class="btn" type="button" wire:click="imprimir" wire:loading.attr="disabled" @disabled($filas === [])><x-icon name="print" /> {{ __('Imprimir cotización') }}</button>
                <button class="btn primary" type="button" wire:click="revisar" wire:loading.attr="disabled" @disabled($filas === [])>{{ __('Confirmar venta') }}</button>
            </div>
        </footer>
    @else
        <div class="action-bar"><div class="action-left"><a class="btn ghost" href="{{ route('pedidos.index') }}"><x-icon name="arrow-left" /> {{ __('Volver a pedidos') }}</a></div><div class="action-right"><a class="btn" href="{{ route('pedidos.imprimir', $pedido) }}" target="_blank"><x-icon name="print" /> {{ __('Imprimir') }}</a></div></div>
    @endunless

    @if ($formCliente)
        <x-modal titulo="Registrar cliente" descripcion="Datos mínimos para identificarlo. Obligatorio en ventas a crédito." cerrar="$set('formCliente', false)">
            <form wire:submit="crearCliente">
                <div class="fields">
                    <x-campo etiqueta="Nombre o razón social" campo="clienteNombre" style="grid-column:1/-1">
                        <input id="clienteNombre" class="input" type="text" maxlength="150" wire:model="clienteNombre" required autofocus>
                    </x-campo>
                    <x-campo etiqueta="CI / NIT" campo="clienteDocumento">
                        <input id="clienteDocumento" class="input" type="text" maxlength="30" wire:model="clienteDocumento">
                    </x-campo>
                    <x-campo etiqueta="Teléfono" campo="clienteTelefono">
                        <input id="clienteTelefono" class="input" type="tel" maxlength="40" wire:model="clienteTelefono">
                    </x-campo>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="$set('formCliente', false)">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled"><x-icon name="check" /> {{ __('Registrar') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($confirmando && $calculo && $pago)
        <x-modal titulo="Confirmar venta" descripcion="Revisa los importes antes de continuar." cerrar="$set('confirmando', false)">
            <p class="modal-info">{{ __('Importe total') }}</p>
            <div class="confirm-amount">{{ Decimal::bs($calculo['total']) }}</div>
            <div class="quote-line"><span>{{ __('Cliente') }}</span><strong>{{ $cliente->nombre ?? __('Consumidor final') }}</strong></div>
            <div class="quote-line"><span>{{ __('Cobro inicial') }}</span><strong>{{ Decimal::bs($pago['monto']) }}</strong></div>
            @if ($pago['metodo']?->value === 'mixto')
                <div class="quote-line"><span>{{ __('Desglose') }}</span><strong>{{ __('Efectivo :efectivo · Electrónico :electronico', ['efectivo' => Decimal::bs($pago['efectivo']), 'electronico' => Decimal::bs($pago['electronico'])]) }}</strong></div>
            @endif
            <div class="quote-line"><span>{{ __('Saldo a crédito') }}</span><strong>{{ Decimal::bs(Decimal::restar($calculo['total'], $pago['monto'], 2)) }}</strong></div>
            <div class="quote-line"><span>{{ __('Forma de pago') }}</span><strong>{{ $pago['metodo']?->etiqueta() ?? __('Sin cobro inicial') }}</strong></div>
            @if ($fiscal)
                <div class="divider"></div>
                @if ($fiscal['modo'] === \App\Enums\ModoFacturacion::Manual)
                    <label class="row"><input type="checkbox" wire:model.live="emitirFactura"><span>{{ __('Emitir factura SIAT') }}</span></label>
                @else
                    <p class="modal-info" style="margin:0"><b>{{ __('Factura SIAT') }}</b> · {{ __('se envía al SIN al confirmar.') }}</p>
                @endif
                @if ($emitirFactura || $fiscal['modo'] === \App\Enums\ModoFacturacion::Automatica)
                    @if ($fiscal['metodosPago']->isEmpty() || $fiscal['tiposDocumento']->isEmpty())
                        <div class="notice" style="margin-top:10px"><x-icon name="status-alert" /><div><p>{{ __('Faltan los catálogos del SIN: la factura quedará bloqueada hasta sincronizarlos en Facturación. La venta se confirma igual.') }}</p></div></div>
                    @else
                        <div style="margin-top:10px">@include('livewire.facturacion.comprador', ['tiposDocumento' => $fiscal['tiposDocumento'], 'metodosPago' => $fiscal['metodosPago'], 'factura' => $factura])</div>
                    @endif
                    @if ($fiscal['vecinos'])
                        <div class="notice" style="margin-top:10px"><x-icon name="status-alert" /><div>
                            <b>{{ __('Este total no se puede facturar exacto') }}</b>
                            <p>{{ __('Con el IVA del SIN solo cuadran :menor o :mayor. La venta se confirma igual y la factura quedará bloqueada; no se ajustan precios ni IVA.', ['menor' => Decimal::bs($fiscal['vecinos'][0]), 'mayor' => Decimal::bs($fiscal['vecinos'][1])]) }}</p>
                        </div></div>
                    @endif
                @endif
            @endif
            @if ($flujoEntrega !== null)
                <div class="divider"></div>
                @if ($flujoEntrega === \App\Enums\Capacidad::Cocina)
                    <x-campo etiqueta="Nombre o mesa" campo="nombreMesa" style="margin-bottom:12px">
                        <input id="nombreMesa" class="input" type="text" maxlength="40" autocomplete="off" placeholder="{{ __('Ej.: Juan, Mesa 4') }}" wire:model="nombreMesa" data-nombre-mesa>
                    </x-campo>
                @endif
                @include('livewire.despachos.casilla-entregado')
            @endif
            <p class="modal-info" style="margin-top:17px">{!! __('Al confirmar se descontará el stock de la sucursal y se registrarán <strong>únicamente los pagos recibidos</strong>.') !!}</p>
            <div class="modal-actions">
                @if ($flujoEntrega !== null)
                    @include('livewire.despachos.estado-resultante')
                @endif
                <button type="button" class="btn ghost" wire:click="$set('confirmando', false)">{{ __('Volver') }}</button>
                <button type="button" class="btn primary" wire:click="confirmar" wire:loading.attr="disabled" wire:target="confirmar"><x-icon name="check" /> {{ __('Confirmar venta') }}</button>
            </div>
        </x-modal>
    @endif

    @if ($confirmandoCancelacion)
        <x-modal titulo="Cancelar pedido" descripcion="El pedido se conserva como cancelado. No se mueve stock ni dinero." cerrar="$set('confirmandoCancelacion', false)">
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="$set('confirmandoCancelacion', false)">{{ __('Volver') }}</button>
                <button type="button" class="btn primary" wire:click="cancelarPedido" wire:loading.attr="disabled">{{ __('Cancelar pedido') }}</button>
            </div>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
