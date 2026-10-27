@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Control financiero') }}</div>
            <h1>{{ __('Egresos') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Gastos operativos y categorías, sin duplicar compras.') }}</p>
        </div>
        <div class="page-actions">
            <button class="btn primary" type="button" wire:click="nuevoEgreso"><x-icon name="plus" /> {{ __('Nuevo egreso') }}</button>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Egresos del mes') }}</span><div class="stat-value">{{ Decimal::bs($delMes) }}</div><div class="stat-foot">{{ __('Gastos operativos') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Categorías activas') }}</span><div class="stat-value">{{ $categorias->where('activa', true)->count() }}</div><div class="stat-foot">{{ __('Sin compras duplicadas') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Registrados hoy') }}</span><div class="stat-value">{{ Decimal::bs($deHoy) }}</div><div class="stat-foot">{{ __('Por fecha real de pago') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="lock" /></span>{{ __('Egresos y categorías') }}</div></div>
        <div class="tabs" role="tablist">
            @foreach (['movimientos' => 'Movimientos', 'categorias' => 'Categorías de egresos'] as $opcionPestana => $etiqueta)
                <button type="button" role="tab" class="tab {{ $pestana === $opcionPestana ? 'active' : '' }}" aria-selected="{{ $pestana === $opcionPestana ? 'true' : 'false' }}" wire:click="$set('pestana', '{{ $opcionPestana }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>

        @if ($pestana === 'movimientos')
            <div class="tool-row">
                <div class="search">
                    <x-icon name="search" />
                    <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar egreso, concepto...') }}" aria-label="{{ __('Buscar egresos') }}">
                </div>
                <select class="select" wire:model.live="sucursal" @disabled($sucursalFija) aria-label="{{ __('Filtrar sucursal') }}">
                    <option value="">{{ __('Todas las sucursales') }}</option>
                    @foreach ($sucursales as $opcion)
                        <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                    @endforeach
                </select>
                <a class="btn ghost sm" href="{{ route('egresos.exportar', array_filter(['q' => trim($buscar), 'sucursal' => $sucursal])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Categoría') }}</th><th>{{ __('Descripción') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Medio') }}</th><th>{{ __('Monto') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                    <tbody>
                        @forelse ($egresos as $egreso)
                            <tr wire:key="egreso-{{ $egreso->id }}">
                                <td>{{ \Illuminate\Support\Carbon::parse($egreso->fecha, 'UTC')->timezone(config('mozistock.business_timezone'))->format('d/m/Y') }}</td>
                                <td>{{ $egreso->categoria }}</td>
                                <td>{{ $egreso->descripcion }}</td>
                                <td>{{ $egreso->sucursal }}</td>
                                <td>{{ $egreso->metodo === 'efectivo' ? __('Efectivo') : __('Electrónico') }}</td>
                                <td><b>{{ Decimal::bs((string) $egreso->monto) }}</b></td>
                                <td><button class="btn sm ghost" type="button" wire:click="ver({{ $egreso->id }})">{{ __('Ver') }} <x-icon name="arrow" size="sm" /></button></td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><div class="empty">{{ trim($buscar) === '' && $sucursal === '' ? __('Aún no registras egresos.') : __('No hay egresos que coincidan con el filtro.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($egresos->hasPages())
                <div class="table-footer">
                    <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $egresos->currentPage(), 'lastpage' => $egresos->lastPage()]) }}</span>
                    <span class="row">
                        @unless ($egresos->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                        @if ($egresos->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                    </span>
                </div>
            @endif
        @else
            <button class="btn soft sm" type="button" wire:click="nuevaCategoria"><x-icon name="plus" size="sm" /> {{ __('Nueva categoría') }}</button>
            <div style="height:12px"></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Categoría') }}</th><th>{{ __('Descripción') }}</th><th>{{ __('Egresos') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                    <tbody>
                        @forelse ($categorias as $item)
                            <tr wire:key="categoria-{{ $item->id }}">
                                <td>{{ $item->nombre }}</td>
                                <td>{{ $item->descripcion ?? '—' }}</td>
                                <td>{{ $item->egresos_count }}</td>
                                <td><span class="badge {{ $item->activa ? 'success' : 'danger' }}">{{ $item->activa ? __('Activa') : __('Inactiva') }}</span></td>
                                <td><button class="btn sm ghost" type="button" wire:click="editarCategoria({{ $item->id }})"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty">{{ __('Aún no hay categorías.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
        <div class="divider"></div>
        <div class="notice"><x-icon name="info" /><div><b>{{ __('Las compras no son egresos manuales') }}</b><p>{{ __('Una compra pagada ya registra su salida de dinero. Aquí solo van sueldos, alquiler, servicios y otros gastos.') }}</p></div></div>
    </section>

    @if ($formEgreso)
        <x-modal titulo="Nuevo egreso" descripcion="Se registra como salida de caja con la fecha y hora actuales. No registres aquí compras de productos." cerrar="cerrar">
            <form wire:submit="registrarEgreso">
                <div class="fields">
                    <x-campo etiqueta="Categoría" campo="categoria_id">
                        <select id="categoria_id" class="select" wire:model="categoria_id" required>
                            <option value="">{{ __('Selecciona una categoría') }}</option>
                            @foreach ($categorias->where('activa', true) as $categoria)
                                <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                            @endforeach
                        </select>
                    </x-campo>
                    <x-campo etiqueta="Sucursal" campo="sucursal_id">
                        <select id="sucursal_id" class="select" wire:model="sucursal_id">
                            @foreach ($sucursales->where('activa', true) as $opcion)
                                <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                            @endforeach
                        </select>
                    </x-campo>
                    <x-campo etiqueta="Descripción" campo="descripcion" style="grid-column:1/-1">
                        <input id="descripcion" class="input" type="text" maxlength="150" wire:model="descripcion" placeholder="{{ __('Ej. Internet de octubre') }}" required>
                    </x-campo>
                    <x-campo etiqueta="Medio" campo="metodo">
                        <select id="metodo" class="select" wire:model="metodo">
                            <option value="efectivo">{{ __('Efectivo') }}</option>
                            <option value="electronico">{{ __('Electrónico') }}</option>
                        </select>
                    </x-campo>
                    <x-campo etiqueta="Monto (Bs)" campo="monto">
                        <input id="monto" class="input" type="text" inputmode="decimal" wire:model="monto" placeholder="0,00" required>
                    </x-campo>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="registrarEgreso"><x-icon name="check" /> {{ __('Registrar egreso') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($viendo)
        <x-modal :titulo="'Egreso '.$viendo->codigo()" descripcion="Los egresos registrados no se modifican ni se eliminan." cerrar="cerrar">
            <div class="list-line"><span class="subtle">{{ __('Fecha') }}</span><b>{{ $viendo->fecha->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</b></div>
            <div class="list-line"><span class="subtle">{{ __('Categoría') }}</span><b>{{ $viendo->categoria->nombre }}</b></div>
            <div class="list-line"><span class="subtle">{{ __('Descripción') }}</span><b>{{ $viendo->descripcion }}</b></div>
            <div class="list-line"><span class="subtle">{{ __('Sucursal') }}</span><b>{{ $viendo->sucursal->nombre }}</b></div>
            <div class="list-line"><span class="subtle">{{ __('Medio') }}</span><b>{{ $viendo->metodo === 'efectivo' ? __('Efectivo') : __('Electrónico') }}</b></div>
            <div class="list-line"><span class="subtle">{{ __('Registró') }}</span><b>{{ $viendo->usuario->name }}</b></div>
            <div class="list-line"><span>{{ __('Monto') }}</span><b class="accent-text">{{ Decimal::bs($viendo->monto) }}</b></div>
            <div class="modal-actions"><button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cerrar') }}</button></div>
        </x-modal>
    @endif

    @if ($formCategoria)
        <x-modal :titulo="$categoriaId ? 'Editar categoría' : 'Nueva categoría'" cerrar="cerrar">
            <form wire:submit="guardarCategoria">
                <div class="fields" style="grid-template-columns:1fr">
                    <x-campo etiqueta="Nombre" campo="categoriaNombre">
                        <input id="categoriaNombre" class="input" type="text" maxlength="80" wire:model="categoriaNombre" required autofocus>
                    </x-campo>
                    <x-campo etiqueta="Descripción (opcional)" campo="categoriaDescripcion">
                        <input id="categoriaDescripcion" class="input" type="text" maxlength="150" wire:model="categoriaDescripcion">
                    </x-campo>
                </div>
                @if ($categoriaId)
                    <label class="row" style="margin-top:16px"><input type="checkbox" wire:model="categoriaActiva"><span>{{ __('Categoría activa') }}</span></label>
                @endif
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled"><x-icon name="check" /> {{ __('Guardar') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
