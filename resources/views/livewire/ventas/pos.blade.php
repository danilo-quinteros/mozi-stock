@use('App\Support\Decimal')
@use('App\Enums\TipoPrecio')
@use('App\Enums\ModoFacturacion')
@use('App\Domain\Catalogo\Consultas\ListadoProductos')
@assets
@vite(['resources/css/pos.css', 'resources/js/pos.js'])
@endassets
@php
    // Color estable por producto para la miniatura sin imagen.
    $tonos = ['#e58b43', '#73c596', '#5cadd0', '#d0a35e', '#c56b42', '#9a86d6'];
    $tono = fn ($producto) => $tonos[$producto->id % count($tonos)];
    $iniciales = fn ($producto) => mb_strtoupper(mb_substr($producto->nombre, 0, 2));
    $articulos = array_reduce($filas, fn (string $suma, array $fila) => Decimal::sumar($suma, $fila['cantidad'] ?? '0'), '0');
    $mensajes = array_values(array_unique(array_merge($erroresVivos, $errors->all())));
    $listo = $filas !== [] && $erroresVivos === [] && $pago !== null;
@endphp
<div class="pos" data-pos x-data="{ atajos: false }">
    <div class="pos-head">
        <div>
            <div class="eyebrow">{{ __('Vender es más fácil con MoziStock') }}</div>
            <h1>{{ __('Punto de venta') }} <span class="accent-text">POS</span></h1>
            <p class="lead">{{ __('Encuentra tus productos, cobra y sigue vendiendo. Todo en una sola pantalla.') }}</p>
        </div>
        <div class="pos-head-right">
            <button type="button" class="pos-pill" @click="atajos = true"><x-icon name="info" size="sm" /> {{ __('Atajos') }}</button>
            <label class="pos-pill pos-branch">
                <x-icon name="store" size="sm" />
                @if ($administra && $sucursales->count() > 1)
                    <select wire:model.live="sucursal_id" aria-label="{{ __('Sucursal de venta') }}">
                        @foreach ($sucursales as $opcion)
                            <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                        @endforeach
                    </select>
                @else
                    <span>{{ $sucursales->first()->nombre ?? __('Sin sucursal') }}</span>
                @endif
            </label>
        </div>
    </div>
    @error('sucursal_id')<div class="notice" style="margin-bottom:16px" role="alert"><x-icon name="status-alert" /><div><p>{{ $message }}</p></div></div>@enderror

    @if ($ultimaVenta)
        <div class="pos-last" role="status">
            <x-icon name="status-ok" />
            <span>{{ __('Última venta :codigo · :total', ['codigo' => $ultimaVenta->codigo(), 'total' => Decimal::bs($ultimaVenta->total)]) }}@if (Decimal::comparar($ultimaVenta->saldo_pendiente, '0', 2) > 0) · {{ __('saldo a crédito :saldo', ['saldo' => Decimal::bs($ultimaVenta->saldo_pendiente)]) }}@endif</span>
            <a class="btn ghost sm" href="{{ route('ventas.ver', $ultimaVenta) }}">{{ __('Ver venta') }}</a>
        </div>
    @endif

    <div class="pos-layout">
        <div class="pos-products">
            <section class="card" aria-labelledby="pos-catalogo">
                <div class="pos-toolbar">
                    <div class="pos-toolbar-head">
                        <div><div class="pos-title" id="pos-catalogo">{{ __('Catálogo de productos') }}</div><div class="pos-sub">{{ __('Selecciona o escanea para agregar a la venta') }}</div></div>
                        <span class="pos-count">{{ trans_choice(':count producto|:count productos', $totalProductos) }}</span>
                    </div>
                    {{-- Escáner: Enter agrega por código de barras o SKU; las lecturas van en cola (ver inventario/partials/buscador). --}}
                    <div class="pos-search"
                        x-data="{
                            texto: @js($busqueda),
                            cola: [],
                            ocupado: false,
                            agregar() {
                                const valor = this.texto.trim();
                                if (valor === '') return;
                                this.texto = '';
                                this.cola.push(valor);
                                this.procesar();
                            },
                            async procesar() {
                                if (this.ocupado) return;
                                this.ocupado = true;
                                while (this.cola.length > 0) {
                                    const valor = this.cola.shift();
                                    const agregado = await $wire.agregarDesdeBuscador(valor);
                                    if (! agregado && this.texto === '') this.texto = valor;
                                }
                                this.ocupado = false;
                            },
                            limpiar() {
                                this.texto = '';
                                $wire.set('busqueda', '');
                            },
                        }">
                        <label class="pos-searchbox">
                            <x-icon name="search" />
                            <input x-ref="buscador" data-pos-buscar type="search" autocomplete="off" spellcheck="false" autofocus
                                placeholder="{{ __('Buscar por producto, código de barras...') }}" aria-label="{{ __('Buscar o escanear producto') }}"
                                x-model="texto" @input.debounce.250ms="$wire.set('busqueda', texto)"
                                @keydown.enter.prevent="agregar()" @keydown.escape.prevent="limpiar()">
                            <span class="pos-kbd">F2</span>
                        </label>
                        <button type="button" class="pos-scan" title="{{ __('Enfocar búsqueda para escanear') }}" aria-label="{{ __('Enfocar búsqueda para escanear') }}" @click="$refs.buscador.focus()"><x-icon name="scan" size="lg" /></button>
                    </div>
                    @if ($mensajeCaptura)<p class="pos-capture" role="status">{{ $mensajeCaptura }}</p>@endif
                    <div class="pos-categories" role="group" aria-label="{{ __('Categorías de productos') }}">
                        <button type="button" class="pos-category {{ $categoria === null ? 'active' : '' }}" aria-pressed="{{ $categoria === null ? 'true' : 'false' }}" wire:click="filtrarCategoria">{{ __('Todos') }}</button>
                        @foreach ($categorias as $opcion)
                            <button type="button" class="pos-category {{ $categoria === (string) $opcion->id ? 'active' : '' }}" aria-pressed="{{ $categoria === (string) $opcion->id ? 'true' : 'false' }}" wire:key="pos-categoria-{{ $opcion->id }}" wire:click="filtrarCategoria({{ $opcion->id }})">{{ $opcion->nombre }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="pos-grid" aria-live="polite" wire:loading.class="is-loading" wire:target="busqueda,filtrarCategoria,sucursal_id,verMas">
                    @forelse ($productos as $producto)
                        @php
                            $alerta = ListadoProductos::alerta((string) $producto->stock, (string) $producto->minimo);
                        @endphp
                        <button type="button" class="pos-product" wire:key="pos-producto-{{ $producto->id }}" wire:click="agregarProducto({{ $producto->id }})"
                            @disabled($alerta === 'sin_stock') aria-label="{{ __('Agregar :producto', ['producto' => $producto->nombre]) }}">
                            <span class="pos-stock {{ $alerta }}"><i></i> {{ $alerta === 'sin_stock' ? __('Sin stock') : __(':cantidad disp.', ['cantidad' => Decimal::formatear($producto->stock)]) }}</span>
                            <span class="pos-visual" style="--tono:{{ $tono($producto) }}">
                                @if ($producto->imagen_path)
                                    <img src="{{ route('productos.imagen', $producto) }}" alt="" loading="lazy">
                                @else
                                    <span class="pos-initials">{{ $iniciales($producto) }}</span>
                                @endif
                            </span>
                            <span class="pos-name">{{ $producto->nombre }}</span>
                            <span class="pos-sku">{{ $producto->categoria->nombre ?? __('Sin categoría') }} · {{ $producto->sku }}</span>
                            <span class="pos-bottom">
                                <span class="pos-price"><small>Bs </small>{{ Decimal::formatear($producto->precio_consumidor, 2, 2) }}</span>
                                <span class="pos-add"><x-icon name="plus" size="sm" /></span>
                            </span>
                        </button>
                    @empty
                        <div class="pos-empty"><x-icon name="search" /><b>{{ __('No encontramos productos') }}</b><span>{{ __('Prueba con otro nombre o código.') }}</span></div>
                    @endforelse
                </div>
                @if ($productos->count() < $totalProductos)
                    <div class="pos-more"><button type="button" class="btn ghost sm" wire:click="verMas">{{ __('Ver más productos') }}</button></div>
                @endif
            </section>
            <div class="pos-tip"><x-icon name="info" size="sm" /><span>{!! __('<b>Consejo:</b> usa un lector de código de barras o presiona <b>F2</b> para buscar. Si escaneas el mismo producto, se suma al carrito.') !!}</span></div>
        </div>

        <section class="card pos-checkout" aria-labelledby="pos-venta">
            <div class="pos-check-head">
                <div>
                    <div class="pos-title" id="pos-venta">{{ __('Venta actual') }}</div>
                    <div class="pos-receipt">{{ __('El número se asigna al confirmar') }} <span style="color:var(--border)">·</span> <span class="ok">{{ __('Nueva venta') }}</span></div>
                </div>
                <button type="button" class="pos-clear" wire:click="vaciar" @disabled($filas === [])><x-icon name="trash" size="sm" /> {{ __('Vaciar') }}</button>
            </div>

            <div class="pos-client">
                <span class="pos-client-icon"><x-icon name="user" /></span>
                <div class="pos-client-body">
                    <label class="pos-client-label" for="pos-cliente">{{ __('CLIENTE') }}</label>
                    @if ($cliente)
                        <b>{{ $cliente->nombre }}</b>
                        @if ($cliente->documento)<small>{{ __('CI/NIT :documento', ['documento' => $cliente->documento]) }}</small>@endif
                    @else
                        <input id="pos-cliente" type="search" autocomplete="off" placeholder="{{ __('Buscar por nombre o CI/NIT (Consumidor final)...') }}" wire:model.live.debounce.300ms="buscarCliente">
                    @endif
                </div>
                @if ($cliente)
                    <button type="button" class="icon-action" wire:click="quitarCliente" aria-label="{{ __('Quitar cliente') }}" title="{{ __('Quitar cliente') }}"><x-icon name="x" size="sm" /></button>
                @else
                    <button type="button" class="icon-action" wire:click="nuevoCliente" aria-label="{{ __('Registrar cliente') }}" title="{{ __('Registrar cliente') }}"><x-icon name="plus" size="sm" /></button>
                @endif
                @if (! $cliente && $clientesEncontrados->isNotEmpty())
                    <div class="search-results open" role="listbox">
                        @foreach ($clientesEncontrados as $opcion)
                            <button type="button" class="search-result" wire:key="pos-cliente-{{ $opcion->id }}" wire:click="seleccionarCliente({{ $opcion->id }})">
                                <span><b>{{ $opcion->nombre }}</b><small>{{ $opcion->documento ? __('CI/NIT :documento', ['documento' => $opcion->documento]) : __('Sin documento') }}</small></span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
            @error('cliente_id')<p class="auth-error pos-client-error" role="alert">{{ $message }}</p>@enderror

            <div class="pos-cart-head"><b>{{ __('Productos agregados') }}</b><span class="pos-cart-count">{{ __(':cantidad artículos', ['cantidad' => Decimal::formatear($articulos)]) }}</span></div>
            <div class="pos-items" aria-live="polite">
                @forelse ($filas as $fila)
                    @php
                        $i = $fila['indice'];
                        $producto = $fila['producto'];
                        $neto = $calculo['por_indice'][$i]['total'] ?? null;
                        $falta = $fila['cantidad'] !== null && Decimal::comparar($fila['stock'], $fila['cantidad']) < 0;
                    @endphp
                    <div class="pos-item" wire:key="pos-linea-{{ $i }}-{{ $producto->id }}">
                        <span class="pos-item-img" style="--tono:{{ $tono($producto) }}">
                            @if ($producto->imagen_path)
                                <img src="{{ route('productos.imagen', $producto) }}" alt="" loading="lazy">
                            @else
                                <span>{{ $iniciales($producto) }}</span>
                            @endif
                        </span>
                        <div class="pos-item-info">
                            <div class="pos-item-name"><b>{{ $producto->nombre }}</b><strong>{{ $neto === null ? '—' : Decimal::bs($neto) }}</strong></div>
                            <div class="pos-item-unit">{{ $fila['precio'] === null ? __('Sin precio en esta lista') : __(':precio c/u', ['precio' => Decimal::bs($fila['precio'])]) }} · {{ $producto->unidad->abreviatura }}</div>
                            <div class="pos-item-mini">
                                <select class="pos-tiny" aria-label="{{ __('Lista de precio de :producto', ['producto' => $producto->nombre]) }}" wire:model.live="lineas.{{ $i }}.tipo_precio">
                                    @foreach (TipoPrecio::cases() as $tipo)
                                        <option value="{{ $tipo->value }}" @disabled($producto->{$tipo->columna()} === null)>{{ $tipo->etiqueta() }}</option>
                                    @endforeach
                                </select>
                                <div class="pos-qty">
                                    <button type="button" wire:click="sumar({{ $i }}, -1)" aria-label="{{ __('Disminuir cantidad de :producto', ['producto' => $producto->nombre]) }}">−</button>
                                    <input type="text" inputmode="decimal" aria-label="{{ __('Cantidad de :producto', ['producto' => $producto->nombre]) }}" wire:model.live.debounce.400ms="lineas.{{ $i }}.cantidad">
                                    <button type="button" wire:click="sumar({{ $i }}, 1)" aria-label="{{ __('Aumentar cantidad de :producto', ['producto' => $producto->nombre]) }}">+</button>
                                </div>
                            </div>
                            @if ($falta)<span class="pos-item-warn">{{ __('Stock disponible: :cantidad', ['cantidad' => Decimal::formatear($fila['stock'])]) }}</span>@endif
                        </div>
                    </div>
                @empty
                    <div class="pos-cart-empty"><x-icon name="cart" /><b>{{ __('Tu venta está vacía') }}</b><span>{{ __('Selecciona productos del catálogo para empezar.') }}</span></div>
                @endforelse
            </div>

            <div class="pos-below">
                <div class="pos-line"><span>{{ __('Subtotal') }}</span><strong>{{ Decimal::bs($calculo['subtotal'] ?? '0') }}</strong></div>
                <div class="pos-discount">
                    <span>{{ __('Descuento global') }}</span>
                    <div class="pos-discount-controls">
                        <select aria-label="{{ __('Tipo de descuento global') }}" wire:model.live="descuento_global_tipo">
                            <option value="bs">{{ __('Bs') }}</option>
                            <option value="pct">%</option>
                        </select>
                        <input type="text" inputmode="decimal" aria-label="{{ __('Valor del descuento global') }}" wire:model.live.debounce.400ms="descuento_global_valor">
                    </div>
                </div>
                @if ($calculo && Decimal::comparar($calculo['descuento_global'], '0', 2) > 0)
                    <div class="pos-line"><span>{{ __('Descuento') }}</span><strong>− {{ Decimal::bs($calculo['descuento_global']) }}</strong></div>
                @endif
                <div class="pos-grand"><span>{{ __('Total de la venta') }}</span><strong>{{ Decimal::bs($calculo['total'] ?? '0') }}</strong></div>

                <div class="pos-pay-title"><strong>{{ __('Método de pago') }}</strong><span>{{ __('Cobro rápido') }}</span></div>
                <div class="pos-methods" role="group" aria-label="{{ __('Método de pago') }}">
                    @foreach (['efectivo' => ['Efectivo', 'cash'], 'electronico' => ['Electrónico', 'credit'], 'mixto' => ['Mixto', 'swap']] as $clave => [$titulo, $icono])
                        <button type="button" class="pos-method {{ $pago_metodo === $clave ? 'active' : '' }}" aria-pressed="{{ $pago_metodo === $clave ? 'true' : 'false' }}" wire:click="elegirMetodo('{{ $clave }}')"><x-icon :name="$icono" />{{ __($titulo) }}</button>
                    @endforeach
                </div>

                <div class="pos-toggle">
                    <div class="pos-toggle-copy"><x-icon name="history" size="sm" /><div><b>{{ __('Venta a crédito') }}</b><small>{{ __('Registrar cuota inicial y saldo pendiente') }}</small></div></div>
                    <button type="button" class="switch" role="switch" aria-checked="{{ $pago_en_cuotas ? 'true' : 'false' }}" aria-label="{{ __('Venta a crédito') }}" wire:click="alternarCredito"></button>
                </div>
                @if ($pago_en_cuotas)
                    <div class="pos-fields" style="margin-top:0">
                        <div class="pos-field">
                            <label for="pago_cuota_inicial">{{ __('Cuota inicial (Bs)') }}</label>
                            <input id="pago_cuota_inicial" type="text" inputmode="decimal" wire:model.live.debounce.400ms="pago_cuota_inicial">
                        </div>
                        <div class="pos-field"><span>{{ __('Saldo pendiente') }}</span><div class="pos-figure orange">{{ $saldo === null ? '—' : Decimal::bs($saldo) }}</div></div>
                    </div>
                @endif

                @if ($pago_metodo === 'mixto')
                    <div class="pos-fields">
                        <div class="pos-field">
                            <label for="pago_efectivo">{{ __('Monto efectivo (Bs)') }}</label>
                            <input id="pago_efectivo" type="text" inputmode="decimal" wire:model.live.debounce.400ms="pago_efectivo">
                        </div>
                        <div class="pos-field">
                            <label for="pago_electronico">{{ __('Monto electrónico (Bs)') }}</label>
                            <input id="pago_electronico" type="text" inputmode="decimal" wire:model.live.debounce.400ms="pago_electronico">
                        </div>
                    </div>
                @elseif ($pago_metodo === 'efectivo')
                    <div class="pos-fields">
                        <div class="pos-field">
                            <label for="recibido">{{ __('Efectivo recibido (Bs)') }}</label>
                            <input id="recibido" type="text" inputmode="decimal" placeholder="{{ $pago ? Decimal::formatear($pago['monto'], 2, 2) : '' }}" wire:model.live.debounce.400ms="recibido">
                        </div>
                        <div class="pos-field"><span>{{ __('Cambio') }}</span><div class="pos-figure green">{{ $cambio === null ? '—' : Decimal::bs($cambio) }}</div></div>
                    </div>
                @else
                    <div class="pos-fields">
                        <div class="pos-field" style="grid-column:1/-1"><span>{{ __('Monto del cobro electrónico') }}</span><div class="pos-figure">{{ $pago ? Decimal::bs($pago['monto']) : '—' }}</div></div>
                    </div>
                @endif

                @if ($mensajes !== [])
                    <div class="pos-status error" role="alert">@foreach ($mensajes as $mensaje){{ $mensaje }}@if (! $loop->last)<br>@endif @endforeach</div>
                @elseif ($pago !== null)
                    <div class="pos-status ok">{{ $saldo !== null && Decimal::comparar($saldo, '0', 2) > 0 ? __('Saldo pendiente del crédito: :saldo.', ['saldo' => Decimal::bs($saldo)]) : __('Cobro listo para confirmar.') }}</div>
                @else
                    <div class="pos-status">{{ __('Agrega productos para comenzar la venta.') }}</div>
                @endif

                <button type="button" class="pos-complete" data-pos-confirmar wire:click="confirmar" wire:loading.attr="disabled" wire:target="confirmar" @disabled(! $listo)>
                    <x-icon name="check" /> {{ __('Confirmar venta') }} <span>F4</span>
                </button>
                <p class="pos-note">{!! __('Al confirmar se descuenta el stock de la sucursal y se registran <strong>únicamente los pagos recibidos</strong>.') !!}</p>
            </div>
        </section>
    </div>

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

    <div class="modal-backdrop" :class="{ open: atajos }" @click.self="atajos = false" @keydown.escape.window="atajos = false">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="pos-atajos" style="width:min(430px,100%)">
            <div class="modal-head">
                <div><h2 id="pos-atajos">{{ __('Atajos de venta') }}</h2><p>{{ __('Trabaja más rápido con el teclado y el lector de códigos.') }}</p></div>
                <button type="button" class="icon-action" @click="atajos = false" aria-label="{{ __('Cerrar') }}"><x-icon name="x" size="sm" /></button>
            </div>
            <div class="pos-shortcut"><span>{{ __('Buscar o escanear producto') }}</span><kbd>F2</kbd></div>
            <div class="pos-shortcut"><span>{{ __('Agregar el producto escaneado') }}</span><kbd>Enter</kbd></div>
            <div class="pos-shortcut"><span>{{ __('Confirmar venta') }}</span><kbd>F4</kbd></div>
            <div class="pos-shortcut"><span>{{ __('Limpiar la búsqueda') }}</span><kbd>Esc</kbd></div>
            <div class="modal-actions"><button type="button" class="btn primary" @click="atajos = false">{{ __('Entendido') }}</button></div>
        </div>
    </div>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" :tipo="$avisoTipo" />
</div>
