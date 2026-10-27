@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Ficha de proveedor') }}</div>
            <h1>{{ $proveedor->nombre }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ $proveedor->documento ? 'NIT/CI '.$proveedor->documento : __('Sin NIT/CI') }} · {{ $proveedor->ciudad ?? __('Sin ciudad') }} · {{ $proveedor->telefono ?? __('Sin teléfono') }}@if ($proveedor->correo) · {{ $proveedor->correo }}@endif</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('proveedores.index') }}"><x-icon name="arrow-left" /> {{ __('Proveedores') }}</a>
            <button class="btn soft" type="button" wire:click="editar"><x-icon name="edit" /> {{ __('Editar') }}</button>
            <button class="btn ghost" type="button" wire:click="$set('confirmandoEstado', true)">{{ $proveedor->activo ? __('Desactivar') : __('Activar') }}</button>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Total comprado') }}</span><div class="stat-value">{{ Decimal::bs($totalComprado) }}</div><div class="stat-foot">{{ __('Compras pagadas') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Compras') }}</span><div class="stat-value">{{ $compras->total() }}</div><div class="stat-foot">{{ __('Registradas con este proveedor') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Estado') }}</span><div class="stat-value" style="font-size:clamp(17px,1.8vw,22px)">{{ $proveedor->activo ? __('Activo') : __('Inactivo') }}</div><div class="stat-foot">{{ $proveedor->activo ? __('Disponible en nuevas compras') : __('No aparece en nuevas compras') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="store" /></span>{{ __('Compras asociadas') }}</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Compra') }}</th><th>{{ __('Fecha') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Artículos') }}</th><th>{{ __('Total pagado') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                <tbody>
                    @forelse ($compras as $compra)
                        <tr wire:key="compra-{{ $compra->id }}">
                            <td><b>COM-{{ str_pad((string) $compra->numero, 5, '0', STR_PAD_LEFT) }}</b></td>
                            <td>{{ \Illuminate\Support\Carbon::parse($compra->fecha, 'UTC')->timezone(config('mozistock.business_timezone'))->format('d/m/Y') }}</td>
                            <td>{{ $compra->sucursal }}</td>
                            <td>{{ $compra->articulos }}</td>
                            <td>{{ Decimal::bs((string) $compra->total) }}</td>
                            <td><a class="link" href="{{ route('compras.ver', $compra->id) }}">{{ __('Abrir') }} <x-icon name="arrow" size="sm" /></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty">{{ __('Aún no hay compras con este proveedor.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($compras->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $compras->currentPage(), 'lastpage' => $compras->lastPage()]) }}</span>
                <span class="row">
                    @unless ($compras->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($compras->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>

    @if ($formProveedor)
        @include('livewire.contactos.partials.formulario-proveedor', ['titulo' => 'Editar proveedor', 'accion' => 'guardar'])
    @endif

    @if ($confirmandoEstado)
        <x-modal :titulo="($proveedor->activo ? 'Desactivar ' : 'Activar ').$proveedor->nombre" cerrar="cerrar"
            :descripcion="$proveedor->activo ? 'No se borra ningún dato ni sus compras. No aparecerá al registrar nuevas compras.' : 'Volverá a estar disponible en nuevas compras.'">
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cancelar') }}</button>
                <button type="button" class="btn primary" wire:click="cambiarEstado" wire:loading.attr="disabled">{{ $proveedor->activo ? __('Desactivar') : __('Activar') }}</button>
            </div>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
