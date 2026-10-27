<div>
    @if ($embebido)
        @include('livewire.empresa.partials.formulario-sucursal')
    @else
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Configuración') }}</div>
            <h1>{{ __('Sucursales') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Inventario y actividad separados por sucursal.') }}</p>
        </div>
        <div class="page-actions">
            <button class="btn primary" type="button" wire:click="crear"><x-icon name="plus" /> {{ __('Nueva sucursal') }}</button>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat">
            <span class="stat-label">{{ __('Sucursales activas') }}</span>
            <div class="stat-value">{{ $sucursales->where('activa', true)->count() }}</div>
            <div class="stat-foot">{{ __('De :count registradas en tu empresa', ['count' => $sucursales->count()]) }}</div>
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Personal asignado') }}</span>
            <div class="stat-value">{{ $sucursales->sum('usuarios_count') }}</div>
            <div class="stat-foot">{{ __('Vendedores con sucursal fija') }}</div>
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Stock por sucursal') }}</span>
            <div class="stat-value">{{ __('Separado') }}</div>
            <div class="stat-foot">{{ __('Cada sucursal tendrá su propio inventario') }}</div>
        </article>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="store" /></span>{{ __('Sucursales registradas') }}</div>
        </div>
        @if ($sucursales->isEmpty())
            <div class="empty">
                <p>{{ __('Aún no registras sucursales. Crea la primera para asignar vendedores y, más adelante, inventario.') }}</p>
                <button class="btn soft sm" type="button" wire:click="crear"><x-icon name="plus" size="sm" /> {{ __('Nueva sucursal') }}</button>
            </div>
        @else
            <div class="grid three">
                @foreach ($sucursales as $sucursal)
                    <article class="card card-pad hover-lift" style="background:var(--input)" wire:key="sucursal-{{ $sucursal->id }}">
                        <div class="row between">
                            <div class="token"><x-icon name="store" /></div>
                            <span class="badge {{ $sucursal->activa ? 'success' : 'danger' }}">{{ $sucursal->activa ? __('Activa') : __('Inactiva') }}</span>
                        </div>
                        <h3 style="margin:17px 0 8px;font-size:17px">{{ $sucursal->nombre }}</h3>
                        <p class="subtle">{{ $sucursal->direccion ?? __('Sin dirección registrada') }}</p>
                        <div class="divider"></div>
                        <div class="row between"><span class="subtle">{{ __('Código') }}</span><b>{{ $sucursal->codigo }}</b></div>
                        <div class="row between" style="margin-top:12px"><span class="subtle">{{ __('Personal') }}</span><b>{{ $sucursal->usuarios_count }} {{ $sucursal->usuarios_count === 1 ? 'usuario' : 'usuarios' }}</b></div>
                        <div class="row" style="margin-top:18px;flex-wrap:wrap">
                            <button class="btn ghost sm" type="button" wire:click="editar({{ $sucursal->id }})"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</button>
                            <button class="btn ghost sm" type="button" wire:click="confirmarEstado({{ $sucursal->id }})">{{ $sucursal->activa ? __('Desactivar') : __('Activar') }}</button>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @if ($formularioAbierto)
        <x-modal :titulo="$editandoId ? 'Editar sucursal' : 'Nueva sucursal'" descripcion="Pertenece únicamente a tu empresa. El código no puede repetirse dentro de ella." cerrar="cerrarFormulario">
            @include('livewire.empresa.partials.formulario-sucursal')
        </x-modal>
    @endif

    @if ($confirmando)
        <x-modal :titulo="__($confirmando->activa ? 'Desactivar :sucursal' : 'Activar :sucursal', ['sucursal' => $confirmando->nombre])" cerrar="cancelarEstado"
            :descripcion="$confirmando->activa
                ? 'No se borra ningún dato. Una sucursal inactiva no podrá elegirse para nuevas operaciones ni para asignar vendedores.'
                : 'La sucursal volverá a estar disponible para operaciones y asignación de vendedores.'">
            @error('sucursal')<div class="notice" role="alert"><x-icon name="info" /><div><b>{{ __('No se pudo cambiar el estado') }}</b><p>{{ $message }}</p></div></div>@enderror
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cancelarEstado">{{ __('Cancelar') }}</button>
                <button type="button" class="btn primary" wire:click="cambiarEstado" wire:loading.attr="disabled">{{ $confirmando->activa ? __('Desactivar') : __('Activar') }}</button>
            </div>
        </x-modal>
    @endif

    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
