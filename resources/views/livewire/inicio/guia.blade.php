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
            {{-- El mismo asistente de Facturación → Datos fiscales, embebido: cada guardado avisa a la guía para que se recalcule. --}}
            <section class="guia-siat-asistente" id="configuracion-siat" aria-label="{{ __('Configuración de facturación SIAT') }}" x-show="siat" x-cloak>
                <livewire:facturacion.asistente :empresa-id="$empresaId" :embebido="true" :key="'guia-asistente-siat'" />
            </section>
        @endif

        {{-- Popups: los componentes reales en modo embebido. Cerrar recalcula la guía. --}}
        @if ($popup === 'empresa')
            <x-modal titulo="Datos de tu empresa" descripcion="Los mismos datos de Mi empresa. Guarda y cierra para continuar." cerrar="cerrar">
                <livewire:empresa.configuracion :embebido="true" :key="'guia-empresa'" />
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
