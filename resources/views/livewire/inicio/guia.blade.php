{{-- Guía de configuración inicial (onboarding.html). Los estados salen de App\Domain\Tenancy\GuiaConfiguracion: nada se marca a mano.
     La tarjeta SIAT empieza oculta; al volver de otra pestaña (productos, inventario) la guía se recalcula.
     x-show muestra la tarjeta en el siguiente cuadro de animación: el scroll espera ese cuadro, si no apuntaría a un elemento oculto. --}}
<div x-data="{ siat: false, verSiat() { this.siat = true; this.$nextTick(() => requestAnimationFrame(() => document.getElementById('configuracion-siat')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))) } }"
    x-on:visibilitychange.document="document.visibilityState === 'visible' && $wire.$refresh()">
    @if ($guia)
        @php($total = count($guia['pasos']))
        @php($actual = collect($guia['pasos'])->search(fn (array $paso): bool => ! $paso['listo']))
        @php($siat = $guia['siat'] !== null && ! $guia['siat']['listo'] ? $guia['siat'] : null)

        <section class="card guia" aria-labelledby="guia-titulo">
            <div class="guia-head">
                <div>
                    <h2 id="guia-titulo">{{ __('Configura tu negocio') }}</h2>
                    <p>{{ __('Avanza a tu ritmo. Puedes explorar el sistema y completar la configuración más adelante.') }}</p>
                </div>
                <div class="guia-progreso">
                    <span>{{ __(':hechos de :total completados', ['hechos' => $guia['completados'], 'total' => $total]) }}</span>
                    <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $guia['completados'] }}" aria-label="{{ __('Progreso de configuración') }}"><span style="width:{{ intdiv($guia['completados'] * 100, $total) }}%"></span></div>
                </div>
            </div>

            <div class="guia-lista">
                @foreach ($guia['pasos'] as $i => $paso)
                    <div class="guia-paso {{ $paso['listo'] ? 'done' : ($i === $actual ? 'current' : '') }}" data-paso="{{ $paso['clave'] }}" wire:key="paso-{{ $paso['clave'] }}">
                        <span class="guia-icono"><x-icon :name="$paso['listo'] ? 'check' : $paso['icono']" /></span>
                        <div class="grow"><b>{{ $paso['titulo'] }}</b><small>{{ $paso['detalle'] }}</small></div>
                        @if ($paso['listo'])
                            <span class="guia-listo"><x-icon name="check" size="sm" /> {{ __('Listo') }}</span>
                        @elseif ($paso['clave'] === 'siat')
                            <button type="button" class="guia-accion" x-on:click="verSiat()"><span>{{ $paso['accion'] }}</span><x-icon name="arrow" size="sm" /></button>
                        @elseif ($paso['url'] === null)
                            <button type="button" class="guia-accion" wire:click="abrir('{{ $paso['clave'] }}')"><span>{{ $paso['accion'] }}</span><x-icon name="arrow" size="sm" /></button>
                        @else
                            {{-- Productos e inventario se abren en otra pestaña; la venta, en esta. --}}
                            <a class="guia-accion" href="{{ $paso['url'] }}" @if ($paso['clave'] !== 'venta') target="_blank" rel="noopener" @endif><span>{{ $paso['accion'] }}</span><x-icon name="arrow" size="sm" /></a>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="guia-pie">
                <p><x-icon name="info" size="sm" /> {{ __('Los pasos se marcan como completados cuando guardas los datos reales en MoziStock. Esta guía desaparece al terminar.') }}</p>
                @if ($siat)
                    <button type="button" class="btn primary" x-on:click="verSiat()">{{ __('Configurar SIAT') }} <x-icon name="arrow" size="sm" /></button>
                @endif
            </div>
        </section>

        @if ($siat)
            {{-- Mismos puntos que Facturación → Facturas «Preparación para facturar». La clave cambia con el paso pendiente: así el paso activo avanza solo al cerrar un popup. --}}
            @php($pantallas = [
                ['icono' => 'lock', 'titulo' => __('Asociación y token delegado'), 'texto' => __('Asocia tu empresa con el sistema proveedor MoziStock en el portal SIAT, genera el token delegado y cárgalo aquí. Al guardarlo se sincronizan los catálogos del SIN.'), 'boton' => __('Cargar token delegado'), 'popup' => 'token'],
                ['icono' => 'building', 'titulo' => __('Datos fiscales de tu empresa'), 'texto' => __('Registra el NIT y la razón social con los que tu negocio está inscrito en Impuestos Nacionales, y elige la actividad y el producto SIN que se usarán por defecto.'), 'boton' => __('Abrir Datos fiscales'), 'popup' => 'datos-fiscales'],
                ['icono' => 'store', 'titulo' => __('Sucursal y punto de venta'), 'texto' => __('Registra tu sucursal con el código y el municipio del padrón del SIN. Al registrarla se crea su punto de venta 0.'), 'boton' => __('Registrar sucursal en el SIN'), 'popup' => 'sucursal-siat'],
                ['icono' => 'shield', 'titulo' => __('Verifica tu configuración'), 'texto' => __('MoziStock revisa que los requisitos estén listos para emitir facturas. Es la misma revisión que ves en Facturación.'), 'boton' => __('Comprobar configuración'), 'popup' => null],
            ])
            @php($ultimo = count($siat['pasos']) - 1)

            <section class="card guia-siat" id="configuracion-siat" aria-labelledby="siat-titulo" wire:key="siat-{{ $siat['actual'] }}"
                x-data="{ paso: {{ $siat['actual'] }} }" x-show="siat" x-cloak>
                <div class="siat-top">
                    <div class="siat-name">
                        <span class="heading-icon"><x-icon name="receipt" /></span>
                        <div><h2 id="siat-titulo">{{ __('Configuración de facturación SIAT') }}</h2><p>{{ __('Configura los datos fiscales y prepara la facturación en línea de tu empresa.') }}</p></div>
                    </div>
                    <span class="badge warning">{{ __('Pendiente de configurar') }}</span>
                </div>

                <div class="siat-layout">
                    <nav class="siat-sidebar" aria-label="{{ __('Pasos de configuración SIAT') }}">
                        <div class="siat-label">{{ __('Pasos de activación') }}</div>
                        @foreach ($siat['pasos'] as $i => $paso)
                            <button type="button" class="siat-step" :class="{ active: paso === {{ $i }} }" :aria-current="paso === {{ $i }} ? 'step' : null" x-on:click="paso = {{ $i }}">
                                <span class="siat-num {{ $paso['listo'] ? 'done' : '' }}">@if ($paso['listo'])<x-icon name="check" size="sm" />@else{{ $i + 1 }}@endif</span>
                                <span><b>{{ $paso['titulo'] }}</b><small>{{ $paso['detalle'] }}</small></span>
                            </button>
                        @endforeach
                        <aside class="siat-info"><x-icon name="shield" /><p>{{ __('Solo el administrador puede administrar la configuración fiscal. La habilitación debe ser autorizada por el SIN y por el superadministrador.') }}</p></aside>
                    </nav>

                    <div class="siat-main">
                        @foreach ($siat['pasos'] as $i => $paso)
                            @php($pantalla = $pantallas[$i])
                            <div x-show="paso === {{ $i }}" @if ($i !== $siat['actual']) x-cloak @endif>
                                <h3 class="heading"><x-icon :name="$pantalla['icono']" class="accent-text" /> {{ $pantalla['titulo'] }}</h3>
                                <p class="siat-lead">{{ $pantalla['texto'] }}</p>

                                @if ($i === $ultimo)
                                    <div class="siat-verify {{ $paso['listo'] ? 'ok' : '' }}">
                                        <span class="heading-icon"><x-icon :name="$paso['listo'] ? 'status-ok' : 'shield'" /></span>
                                        <div><b>{{ $paso['listo'] ? __('Todo listo para facturar') : __('Todavía falta completar la configuración') }}</b><p>{{ __('La emisión no se habilita hasta que la autorización y la configuración sean válidas.') }}</p></div>
                                    </div>
                                @endif

                                <div class="siat-facts">
                                    @if ($i === $ultimo)
                                        @foreach (array_slice($siat['pasos'], 0, $ultimo) as $anterior)
                                            <div class="siat-fact"><b>{{ $anterior['titulo'] }}</b><span class="badge {{ $anterior['listo'] ? 'success' : 'warning' }}">{{ $anterior['listo'] ? __('Listo') : __('Pendiente') }}</span></div>
                                        @endforeach
                                    @endif
                                    @foreach ($paso['puntos'] as $punto)
                                        <div class="siat-fact">
                                            <div class="grow"><b>{{ $punto['punto'] }}</b><small>{{ $punto['detalle'] }}</small></div>
                                            @if ($punto['listo'])
                                                <span class="badge success">{{ __('Listo') }}</span>
                                            @elseif ($punto['automatico'])
                                                <span class="badge info">{{ __('Automático') }}</span>
                                            @elseif ($punto['clave'] === 'catalogos' && $paso['puntos'][0]['listo'])
                                                {{-- El token ya está y aun así falta algún catálogo: se reintenta en Facturación. --}}
                                                <a class="btn soft sm" href="{{ route('facturacion', ['seccion' => 'catalogos']) }}" target="_blank" rel="noopener">{{ __('Sincronizar') }} <x-icon name="arrow" size="sm" /></a>
                                            @else
                                                <span class="badge warning">{{ __('Pendiente') }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                @if ($pantalla['popup'])
                                    <button type="button" class="btn {{ $paso['listo'] ? '' : 'primary' }}" style="margin-top:18px" wire:click="abrir('{{ $pantalla['popup'] }}')">{{ $pantalla['boton'] }} <x-icon name="arrow" size="sm" /></button>
                                @else
                                    <button type="button" class="btn {{ $paso['listo'] ? '' : 'primary' }}" style="margin-top:18px" wire:click="$refresh" wire:loading.attr="disabled"><x-icon name="history" size="sm" /> {{ $pantalla['boton'] }}</button>
                                @endif
                            </div>
                        @endforeach

                        <div class="siat-actions">
                            @foreach ($siat['pasos'] as $i => $paso)
                                <span class="subtle" x-show="paso === {{ $i }}" @if ($i !== $siat['actual']) x-cloak @endif>{{ __('Paso :paso de :total', ['paso' => $i + 1, 'total' => $ultimo + 1]) }}</span>
                            @endforeach
                            <div class="row">
                                <button type="button" class="btn" x-on:click="paso--" :disabled="paso === 0">{{ __('Anterior') }}</button>
                                <button type="button" class="btn" x-on:click="paso++" :disabled="paso === {{ $ultimo }}">{{ __('Siguiente') }} <x-icon name="arrow" size="sm" /></button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        {{-- Popups: los componentes reales en modo embebido. Cerrar recalcula la guía. --}}
        @if ($popup === 'empresa')
            <x-modal titulo="Datos de tu empresa" descripcion="Los mismos datos de Mi empresa. Guarda y cierra para continuar." cerrar="cerrar">
                <livewire:empresa.configuracion :embebido="true" :key="'guia-empresa'" />
            </x-modal>
        @elseif ($popup === 'sucursal')
            <x-modal titulo="Configura tu sucursal" descripcion="Revisa los datos de tu sucursal principal. Puedes registrar más sucursales en Sucursales." cerrar="cerrar">
                <livewire:empresa.sucursales :embebido="true" :key="'guia-sucursal'" />
            </x-modal>
        @elseif ($popup === 'token')
            <x-modal titulo="Token delegado del SIN" descripcion="Al guardarlo se sincronizan los catálogos del SIN." cerrar="cerrar">
                <livewire:facturacion.datos :empresa-id="$empresaId" parte="token" :key="'guia-token'" />
            </x-modal>
        @elseif ($popup === 'datos-fiscales')
            <x-modal titulo="Datos fiscales" descripcion="NIT, razón social, actividad y producto SIN por defecto." cerrar="cerrar">
                <livewire:facturacion.datos :empresa-id="$empresaId" parte="datos" :key="'guia-datos-fiscales'" />
            </x-modal>
        @elseif ($popup === 'sucursal-siat')
            <x-modal titulo="Sucursal en el SIN" descripcion="Datos tal como figuran en el padrón del SIN (0 = casa matriz)." cerrar="cerrar">
                <livewire:facturacion.puntos :empresa-id="$empresaId" :embebido="true" :key="'guia-sucursal-siat'" />
            </x-modal>
        @endif
    @else
        {{-- El último paso se terminó en esta misma visita: el Inicio muestra el panel al recargar. --}}
        <section class="card card-pad guia-completa">
            <div class="inicio-ok"><x-icon name="check" /><div><b>{{ __('¡Tu negocio está listo!') }}</b><p>{{ __('Terminaste la configuración inicial. Tu panel ya muestra los indicadores del negocio.') }}</p>
                <a class="btn primary sm" style="margin-top:12px" href="{{ route('dashboard') }}">{{ __('Ver mi panel') }} <x-icon name="arrow" size="sm" /></a></div></div>
        </section>
    @endif
</div>
