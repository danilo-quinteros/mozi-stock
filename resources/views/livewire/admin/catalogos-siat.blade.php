<div>
    @php($zona = config('mozistock.business_timezone'))

    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Superadministración') }}</div>
            <h1>{{ __('Catálogos paramétricos del SIN') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Tablas maestras y paramétricas globales del SIAT válidas para todo el territorio nacional.') }}</p>
        </div>
    </div>

    <section class="card card-pad" style="margin-bottom:18px">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="history" /></span>{{ __('Paramétricas globales') }}</div>
            <span class="subtle">{{ __(':total catálogos paramétricos', ['total' => count($catalogos)]) }}</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Catálogo') }}</th><th>{{ __('Códigos registrados') }}</th><th>{{ __('Última actualización') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($catalogos as $catalogo)
                        @php($cantidad = $conteos[$catalogo->name] ?? 0)
                        @php($ultima = $sincronizaciones[$catalogo->name] ?? null)
                        <tr wire:key="catalogo-{{ $catalogo->name }}">
                            <td><b>{{ __($catalogo->etiqueta()) }}</b></td>
                            <td>
                                @if ($cantidad > 0)
                                    <span class="badge success">{{ number_format($cantidad, 0, ',', '.') }} {{ __('códigos') }}</span>
                                @else
                                    <span class="badge info">{{ __('Sin registros') }}</span>
                                @endif
                            </td>
                            <td>{{ $ultima ? \Illuminate\Support\Carbon::parse($ultima)->timezone($zona)->format('d/m/Y H:i') : '—' }}</td>
                            <td style="text-align:right">
                                @if ($cantidad > 0)
                                    <button class="btn ghost sm" type="button" wire:click="ver('{{ $catalogo->name }}')"><x-icon name="eye" size="sm" /> {{ __('Ver catálogo') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="divider"></div>
        <div class="notice"><x-icon name="info" /><div><b>{{ __('Paramétricas estándar del SIN') }}</b><p>{{ __('Estos catálogos (unidades de medida, métodos de pago, monedas, motivos de anulación, etc.) son definidos por el SIN y son idénticos para todos los contribuyentes. Los catálogos específicos (actividades económicas y productos) se gestionan por empresa según su NIT.') }}</p></div></div>
    </section>

    @if ($visto)
        <x-modal :titulo="__($visto->etiqueta())" :descripcion="__(':total registros. Se muestran hasta :limite.', ['total' => number_format($total, 0, ',', '.'), 'limite' => $limite])" cerrar="cerrarVisor" style="max-width:760px">
            <div class="search" style="margin-bottom:14px">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar por código o descripción') }}" aria-label="{{ __('Buscar en el catálogo') }}" autofocus>
            </div>
            <div class="table-wrap" style="max-height:55vh;overflow:auto">
                <table>
                    <thead><tr><th>{{ __('Código') }}</th><th>{{ __('Descripción') }}</th></tr></thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr wire:key="item-{{ $loop->index }}"><td><b>{{ $item->codigo }}</b></td><td>{{ $item->descripcion }}</td></tr>
                        @empty
                            <tr><td colspan="2"><div class="empty">{{ __('Sin resultados.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
