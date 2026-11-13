<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Mi empresa') }}</div>
            <h1>{{ __('Tallas y colores') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Las listas de tu empresa para armar modelos. Una talla o un color en uso no se borra: se desactiva.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('productos.index') }}"><x-icon name="box" /> {{ __('Ir a productos') }}</a>
        </div>
    </div>

    <x-empresa-nav />

    @if ($grupos->isEmpty() && $colores->isEmpty())
        <section class="card card-pad variantes-vacio" data-test="listas-vacias">
            <div class="notice"><x-icon name="info" /><div>
                <b>{{ __('Todavía no tienes tallas ni colores') }}</b>
                <p>{{ __('Carga la lista sugerida (Letras, Calzado, Pantalón, Niños y 10 colores) y edítala a tu gusto, o crea tus propias listas.') }}</p>
            </div></div>
            <div class="row" style="margin-top:14px;gap:9px;flex-wrap:wrap">
                <button class="btn primary" type="button" wire:click="cargarSugeridas" wire:loading.attr="disabled"><x-icon name="check" /> {{ __('Cargar lista sugerida') }}</button>
                <button class="btn ghost" type="button" wire:click="nuevoGrupo"><x-icon name="plus" /> {{ __('Nuevo grupo de tallas') }}</button>
                <button class="btn ghost" type="button" wire:click="nuevoColor"><x-icon name="plus" /> {{ __('Nuevo color') }}</button>
            </div>
        </section>
    @else
        <div class="variantes-listas">
            <section class="card card-pad">
                <div class="card-head">
                    <div><div class="heading"><span class="heading-icon"><x-icon name="tag" /></span>{{ __('Grupos de tallas') }}</div>
                        <p class="subtle" style="margin:9px 0 0">{{ __('Un modelo usa un grupo y elige sus tallas. Toca una talla para renombrarla o cambiar su orden.') }}</p></div>
                    <button class="btn soft sm" type="button" wire:click="nuevoGrupo"><x-icon name="plus" size="sm" /> {{ __('Nuevo grupo') }}</button>
                </div>
                <div class="stack" style="gap:12px">
                    @forelse ($grupos as $grupo)
                        <div class="talla-grupo {{ $grupo->activo ? '' : 'is-inactivo' }}" wire:key="grupo-{{ $grupo->id }}" data-test="grupo-{{ $grupo->id }}">
                            <div class="talla-grupo-head">
                                <b>{{ $grupo->nombre }}</b>
                                @unless ($grupo->activo)<span class="badge danger">{{ __('Inactivo') }}</span>@endunless
                                <span class="subtle">{{ trans_choice(':count talla|:count tallas', $grupo->tallas->count()) }}</span>
                                <button class="btn ghost sm" type="button" wire:click="editarGrupo({{ $grupo->id }})" style="margin-left:auto"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</button>
                            </div>
                            <div class="talla-chips">
                                @foreach ($grupo->tallas as $talla)
                                    <button type="button" class="talla-chip {{ $talla->activa ? '' : 'is-inactiva' }}" wire:key="talla-{{ $talla->id }}" wire:click="editarTalla({{ $talla->id }})"
                                        title="{{ isset($porTalla[$talla->id]) ? trans_choice('En :count combinación|En :count combinaciones', (int) $porTalla[$talla->id]) : __('Sin uso') }}">
                                        {{ $talla->nombre }}@isset($porTalla[$talla->id])<small>{{ $porTalla[$talla->id] }}</small>@endisset
                                    </button>
                                @endforeach
                                <form class="talla-agregar" wire:submit="agregarTalla({{ $grupo->id }})">
                                    <input class="input" type="text" maxlength="15" wire:model="nuevaTalla.{{ $grupo->id }}" placeholder="{{ __('Nueva talla') }}" aria-label="{{ __('Nueva talla en :grupo', ['grupo' => $grupo->nombre]) }}">
                                    <button class="btn soft sm" type="submit" wire:loading.attr="disabled"><x-icon name="plus" size="sm" /> {{ __('Agregar') }}</button>
                                </form>
                            </div>
                            @error('nuevaTalla.'.$grupo->id)<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    @empty
                        <div class="empty">{{ __('Aún no hay grupos de tallas.') }}</div>
                    @endforelse
                </div>
            </section>

            <section class="card card-pad">
                <div class="card-head">
                    <div><div class="heading"><span class="heading-icon"><x-icon name="image" /></span>{{ __('Colores') }}</div>
                        <p class="subtle" style="margin:9px 0 0">{{ __('El código corto forma el SKU sugerido de cada combinación (POL001-M-NEG).') }}</p></div>
                    <button class="btn soft sm" type="button" wire:click="nuevoColor"><x-icon name="plus" size="sm" /> {{ __('Nuevo color') }}</button>
                </div>
                <div class="table-wrap">
                    <table class="variantes-tabla">
                        <thead><tr><th>{{ __('Color') }}</th><th>{{ __('Código') }}</th><th>{{ __('Combinaciones') }}</th><th>{{ __('Estado') }}</th><th><span class="sr-only">{{ __('Acción') }}</span></th></tr></thead>
                        <tbody>
                            @forelse ($colores as $color)
                                <tr wire:key="color-{{ $color->id }}">
                                    <td><span class="color-nombre"><span class="color-muestra" @if ($color->muestra_hex) style="background:{{ $color->muestra_hex }}" @endif aria-hidden="true"></span>{{ $color->nombre }}</span></td>
                                    <td><code>{{ $color->codigo }}</code></td>
                                    <td>{{ $porColor[$color->id] ?? 0 }}</td>
                                    <td><span class="badge {{ $color->activo ? 'success' : 'danger' }}">{{ $color->activo ? __('Activo') : __('Inactivo') }}</span></td>
                                    <td><button class="btn sm ghost" type="button" wire:click="editarColor({{ $color->id }})"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</button></td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><div class="empty">{{ __('Aún no hay colores.') }}</div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    @endif

    @if ($formGrupo)
        <x-modal :titulo="$grupoId ? 'Editar grupo de tallas' : 'Nuevo grupo de tallas'" cerrar="cerrar">
            <form wire:submit="guardarGrupo" class="stack" style="gap:14px">
                <x-campo etiqueta="Nombre del grupo" campo="grupoNombre">
                    <input id="grupoNombre" class="input" type="text" maxlength="40" wire:model="grupoNombre" placeholder="{{ __('Ej. Letras o Calzado') }}" autofocus>
                </x-campo>
                @if ($grupoId)
                    <label class="row"><input type="checkbox" wire:model="grupoActivo"><span>{{ __('Activo (los inactivos no se ofrecen para modelos nuevos)') }}</span></label>
                @endif
                <div class="modal-actions">
                    @if ($grupoId)
                        <button type="button" class="btn ghost danger-text" wire:click="eliminarGrupo({{ $grupoId }})" wire:confirm="{{ __('¿Quitar este grupo? Si ya se usa en algún modelo, solo se desactiva.') }}" style="margin-right:auto"><x-icon name="trash" size="sm" /> {{ __('Quitar') }}</button>
                    @endif
                    <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cancelar') }}</button>
                    <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="guardarGrupo"><x-icon name="check" /> {{ __('Guardar') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($formTalla && $tallaEditada)
        <x-modal titulo="Editar talla" :descripcion="__('Grupo :grupo. Al renombrarla, sus combinaciones toman el nombre nuevo; las ventas pasadas conservan el anterior.', ['grupo' => $tallaEditada->grupo->nombre])" cerrar="cerrar">
            <form wire:submit="guardarTalla" class="stack" style="gap:14px">
                <div class="fields two">
                    <x-campo etiqueta="Talla" campo="tallaNombre">
                        <input id="tallaNombre" class="input" type="text" maxlength="15" wire:model="tallaNombre" autofocus>
                    </x-campo>
                    <x-campo etiqueta="Orden" campo="tallaOrden">
                        <input id="tallaOrden" class="input" type="text" inputmode="numeric" wire:model="tallaOrden">
                    </x-campo>
                </div>
                <label class="row"><input type="checkbox" wire:model="tallaActiva"><span>{{ __('Activa (las inactivas no se usan en combinaciones nuevas)') }}</span></label>
                <div class="modal-actions">
                    <button type="button" class="btn ghost danger-text" wire:click="eliminarTalla({{ $tallaEditada->id }})" wire:confirm="{{ __('¿Quitar esta talla? Si ya está en alguna combinación, solo se desactiva.') }}" style="margin-right:auto"><x-icon name="trash" size="sm" /> {{ __('Quitar') }}</button>
                    <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cancelar') }}</button>
                    <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="guardarTalla"><x-icon name="check" /> {{ __('Guardar') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($formColor)
        <x-modal :titulo="$colorId ? 'Editar color' : 'Nuevo color'" cerrar="cerrar">
            <form wire:submit="guardarColor" class="stack" style="gap:14px">
                <div class="fields two">
                    <x-campo etiqueta="Nombre" campo="colorNombre">
                        <input id="colorNombre" class="input" type="text" maxlength="40" wire:model.live.debounce.300ms="colorNombre" placeholder="{{ __('Ej. Azul marino') }}" autofocus>
                    </x-campo>
                    <x-campo etiqueta="Código corto" campo="colorCodigo">
                        <input id="colorCodigo" class="input" type="text" maxlength="6" wire:model="colorCodigo" placeholder="{{ __('Ej. AZM') }}" style="text-transform:uppercase">
                    </x-campo>
                </div>
                <div class="field">
                    <label class="row"><input type="checkbox" wire:model.live="colorConMuestra"><span>{{ __('Mostrar una muestra de color') }}</span></label>
                    @if ($colorConMuestra)
                        <div class="row" style="gap:10px;margin-top:8px">
                            <input type="color" class="color-selector" wire:model="colorMuestra" aria-label="{{ __('Muestra de color') }}">
                            <small class="subtle">{{ __('Se ve como un círculo junto al nombre.') }}</small>
                        </div>
                    @endif
                    @error('colorMuestra')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                </div>
                @if ($colorId)
                    <label class="row"><input type="checkbox" wire:model="colorActivo"><span>{{ __('Activo (los inactivos no se usan en combinaciones nuevas)') }}</span></label>
                    <p class="subtle" style="margin:0">{{ __('Al renombrarlo, sus combinaciones toman el nombre nuevo; las ventas pasadas conservan el anterior. Cambiar el código no cambia los SKU ya creados.') }}</p>
                @endif
                <div class="modal-actions">
                    @if ($colorId)
                        <button type="button" class="btn ghost danger-text" wire:click="eliminarColor({{ $colorId }})" wire:confirm="{{ __('¿Quitar este color? Si ya está en alguna combinación, solo se desactiva.') }}" style="margin-right:auto"><x-icon name="trash" size="sm" /> {{ __('Quitar') }}</button>
                    @endif
                    <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cancelar') }}</button>
                    <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="guardarColor"><x-icon name="check" /> {{ __('Guardar') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
