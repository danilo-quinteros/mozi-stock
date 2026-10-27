<div>
    @php($zona = config('mozistock.business_timezone'))

    @if ($errorSin)
        <div class="notice" role="alert" style="margin-bottom:18px"><x-icon name="status-alert" /><div><b>{{ __('La sincronización no terminó bien') }}</b><p>{{ $errorSin }}</p></div></div>
    @endif

    <section class="card card-pad" style="margin-bottom:18px">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="history" /></span>{{ __('Catálogos de la empresa (según NIT)') }}</div>
            <button class="btn primary sm" type="button" wire:click="sincronizar" wire:loading.attr="disabled" wire:target="sincronizar">
                <x-icon name="download" size="sm" /> <span wire:loading.remove wire:target="sincronizar">{{ __('Sincronizar ahora') }}</span><span wire:loading wire:target="sincronizar">{{ __('Sincronizando…') }}</span>
            </button>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Catálogo') }}</th><th>{{ __('Registros') }}</th><th>{{ __('Última sincronización') }}</th><th>{{ __('Estado') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($catalogos as $catalogo)
                        @php($estado = $estados[$catalogo->name] ?? null)
                        <tr wire:key="catalogo-{{ $catalogo->name }}">
                            <td><b>{{ __($catalogo->etiqueta()) }}</b></td>
                            <td>{{ $estado?->sincronizado_en ? number_format($estado->cantidad, 0, ',', '.') : '—' }}</td>
                            <td>{{ $estado?->sincronizado_en?->timezone($zona)->format('d/m/Y H:i') ?? '—' }}</td>
                            <td>
                                @if ($estado?->error)
                                    <span class="badge danger" title="{{ $estado->error }}">{{ __('Error') }}</span>
                                    <small>{{ $estado->fallo_en?->timezone($zona)->format('d/m/Y H:i') }} · {{ \Illuminate\Support\Str::limit($estado->error, 80) }}</small>
                                @elseif ($estado?->sincronizado_en)
                                    <span class="badge success">{{ __('Al día') }}</span>
                                @else
                                    <span class="badge info">{{ __('Pendiente') }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($estado?->sincronizado_en)
                                    <button class="btn ghost sm" type="button" wire:click="ver('{{ $catalogo->name }}')"><x-icon name="eye" size="sm" /> {{ __('Ver') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="divider"></div>
        <div class="notice"><x-icon name="info" /><div><b>{{ __('Catálogos propios de la empresa') }}</b><p>{{ __('Estos catálogos corresponden a las actividades económicas, productos/servicios y leyendas autorizadas por el SIN para tu NIT. Las paramétricas generales (monedas, métodos de pago, unidades de medida, etc.) son globales y se gestionan desde el panel de Superadministración.') }}</p></div></div>
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
