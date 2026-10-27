@use('App\Support\Decimal')
@use('App\Enums\TipoOperacionInventario')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Historial') }}</div>
            <h1>{{ __('Ajustes y traspasos') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Consulta el historial y crea nuevos movimientos desde una sola pantalla.') }}</p>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <a class="card stat quick-entry" href="{{ route('inventario.ajuste') }}">
            <div class="row between"><span class="stat-label">{{ __('Nueva entrada, salida o corrección') }}</span><span class="heading-icon"><x-icon name="edit" /></span></div>
            <div class="stat-value" style="font-size:23px">{{ __('Nuevo ajuste') }} <x-icon name="arrow" /></div>
            <div class="stat-foot">{{ __('Indica tipo, sucursal, cantidades y nota') }}</div>
        </a>
        <a class="card stat quick-entry" href="{{ route('inventario.traspaso') }}">
            <div class="row between"><span class="stat-label">{{ __('Movimiento entre sucursales') }}</span><span class="heading-icon"><x-icon name="swap" /></span></div>
            <div class="stat-value" style="font-size:23px">{{ __('Nuevo traspaso') }} <x-icon name="arrow" /></div>
            <div class="stat-foot">{{ __('Origen, destino y productos en una sola operación') }}</div>
        </a>
        <article class="card stat">
            <span class="stat-label">{{ __('Control de inventario') }}</span>
            <div class="stat-value" style="font-size:23px">{{ __('Historial único') }}</div>
            <div class="stat-foot">{{ __('El stock conserva trazabilidad por usuario y sucursal') }}</div>
        </article>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="history" /></span>{{ __('Historial de ajustes y traspasos') }}</div>
            <a class="btn ghost sm" href="{{ route('inventario.exportar', array_filter(['q' => trim($buscar), 'tipo' => $tipo, 'sucursal' => $sucursal])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
        </div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar producto, usuario, nota o referencia') }}" aria-label="{{ __('Buscar movimientos') }}">
            </div>
            <select class="select" wire:model.live="tipo" aria-label="{{ __('Filtrar tipo') }}">
                <option value="">{{ __('Todos') }}</option>
                @foreach (TipoOperacionInventario::cases() as $opcion)
                    <option value="{{ $opcion->value }}">{{ $opcion->etiqueta() }}</option>
                @endforeach
            </select>
            <select class="select" wire:model.live="sucursal" @disabled($sucursalFija) aria-label="{{ __('Filtrar sucursal') }}">
                <option value="">{{ __('Todas las sucursales') }}</option>
                @foreach ($sucursales as $opcion)
                    <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Tipo') }}</th><th>{{ __('Producto') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Cantidad') }}</th><th>{{ __('Saldo') }}</th><th>{{ __('Usuario') }}</th><th>{{ __('Nota') }}</th></tr></thead>
                <tbody>
                    @forelse ($movimientos as $movimiento)
                        @php
                            $tipoFila = TipoOperacionInventario::from($movimiento->tipo);
                            $esTraspaso = $tipoFila === TipoOperacionInventario::Traspaso;
                            $positivo = Decimal::comparar((string) $movimiento->cantidad, '0') > 0;
                            $cantidad = Decimal::formatear(ltrim((string) $movimiento->cantidad, '-'));
                        @endphp
                        <tr wire:key="movimiento-{{ $movimiento->id }}">
                            <td>{{ \Illuminate\Support\Carbon::parse($movimiento->created_at, 'UTC')->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</td>
                            <td><span class="badge {{ $tipoFila === TipoOperacionInventario::Salida ? 'warning' : 'info' }}">{{ $tipoFila->etiqueta() }}</span><small style="display:block;color:var(--faint);margin-top:5px">{{ $movimiento->serie }}-{{ str_pad((string) $movimiento->numero, 5, '0', STR_PAD_LEFT) }}</small></td>
                            <td><b>{{ $movimiento->producto }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ $movimiento->sku }}</small></td>
                            <td>{{ $movimiento->sucursal }}{{ $esTraspaso ? ' → '.$movimiento->destino : '' }}</td>
                            <td @class(['success-text' => ! $esTraspaso && $positivo, 'danger-text' => ! $esTraspaso && ! $positivo])>{{ $esTraspaso ? '−'.$cantidad.' / +'.$cantidad : ($positivo ? '+' : '−').$cantidad }}</td>
                            <td>{{ Decimal::formatear((string) $movimiento->saldo_anterior) }} → {{ Decimal::formatear((string) $movimiento->saldo_posterior) }}</td>
                            <td>{{ $movimiento->usuario }}</td>
                            <td>{{ TipoOperacionInventario::etiquetaMotivo($movimiento->motivo) }}{{ $movimiento->motivo && $movimiento->nota ? ' · ' : '' }}{{ $movimiento->nota }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="empty">{{ __('Todavía no hay movimientos que coincidan con el filtro.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($movimientos->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $movimientos->currentPage(), 'lastpage' => $movimientos->lastPage()]) }}</span>
                <span class="row">
                    @unless ($movimientos->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($movimientos->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>
    <p class="subtle" style="margin-top:14px">{{ __('En un traspaso el saldo mostrado es el de la sucursal de origen. Las compras se registrarán desde Compras, sin duplicar movimientos manuales.') }}</p>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
