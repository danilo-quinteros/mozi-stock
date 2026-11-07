<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Seguridad') }}</div>
            <h1>{{ __('Usuarios y vendedores') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Permisos, accesos y trazabilidad por vendedor.') }}</p>
        </div>
        <div class="page-actions">
            <button class="btn primary" type="button" wire:click="crear"><x-icon name="plus" /> {{ __('Nuevo usuario') }}</button>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat">
            <span class="stat-label">{{ __('Usuarios activos') }}</span>
            <div class="stat-value">{{ (int) $conteo->activos }}</div>
            <div class="stat-foot">{{ __('Con acceso a tu empresa') }}</div>
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Administradores') }}</span>
            <div class="stat-value">{{ (int) $conteo->administradores }}</div>
            <div class="stat-foot">{{ __('Gestionan todas las sucursales') }}</div>
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Vendedores') }}</span>
            <div class="stat-value">{{ (int) $conteo->vendedores }}</div>
            <div class="stat-foot">{{ __('Cada uno con sucursal asignada') }}</div>
        </article>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="users" /></span>{{ __('Usuarios y vendedores') }}</div>
        </div>
        <div class="tabs" role="tablist">
            <button type="button" role="tab" class="tab {{ $pestana === 'usuarios' ? 'active' : '' }}" aria-selected="{{ $pestana === 'usuarios' ? 'true' : 'false' }}" wire:click="$set('pestana', 'usuarios')">{{ __('Usuarios') }}</button>
            <button type="button" role="tab" class="tab {{ $pestana === 'permisos' ? 'active' : '' }}" aria-selected="{{ $pestana === 'permisos' ? 'true' : 'false' }}" wire:click="$set('pestana', 'permisos')">{{ __('Permisos') }}</button>
        </div>

        @if ($pestana === 'usuarios')
            <div class="tool-row">
                <div class="search">
                    <x-icon name="search" />
                    <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar por usuario o sucursal...') }}" aria-label="{{ __('Buscar usuarios') }}">
                </div>
                <a class="btn ghost sm" href="{{ route('usuarios.exportar', array_filter(['buscar' => trim($buscar)])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Usuario') }}</th><th>{{ __('Correo') }}</th><th>{{ __('Rol') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Caja') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                    <tbody>
                        @forelse ($usuarios as $usuario)
                            <tr wire:key="usuario-{{ $usuario->id }}">
                                <td><div class="item-name"><span class="token">{{ $usuario->initials() }}</span><span><b>{{ $usuario->name }}</b>@if ($usuario->id === auth()->id())<small>{{ __('Tu cuenta') }}</small>@endif</span></div></td>
                                <td>{{ $usuario->email }}</td>
                                <td><span class="badge info">{{ $usuario->rol?->etiqueta() }}</span></td>
                                <td>{{ $usuario->sucursal->nombre ?? __('Todas') }}</td>
                                <td><span class="badge secondary">{{ $usuario->caja->nombre ?? ($usuario->esVendedor() ? __('Sin caja') : __('Supervisa')) }}</span></td>
                                <td><span class="badge {{ $usuario->activo ? 'success' : 'danger' }}">{{ $usuario->activo ? __('Activo') : __('Inactivo') }}</span></td>
                                <td><span class="row" style="gap:6px;flex-wrap:nowrap">
                                    <button class="btn sm ghost" type="button" wire:click="editar({{ $usuario->id }})"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</button>
                                    {{-- Misma regla que el Gate «impersonar», sin consultas por fila; el controlador la vuelve a validar. --}}
                                    @if ($usuario->esVendedor() && $usuario->activo)
                                        <form method="POST" action="{{ route('impersonar', $usuario) }}">@csrf
                                            <button class="btn sm ghost" type="submit" title="{{ __('Entrar como :name', ['name' => $usuario->name]) }}"><x-icon name="login" size="sm" /> {{ __('Entrar como') }}</button>
                                        </form>
                                    @endif
                                </span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><div class="empty">{{ __('No hay usuarios que coincidan con la búsqueda.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($usuarios->hasPages())
                <div class="table-footer">
                    <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $usuarios->currentPage(), 'lastpage' => $usuarios->lastPage()]) }}</span>
                    <span class="row">
                        @unless ($usuarios->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                        @if ($usuarios->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                    </span>
                </div>
            @endif
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Función') }}</th><th>{{ __('Administrador') }}</th><th>{{ __('Vendedor') }}</th></tr></thead>
                    <tbody>
                        <tr><td>{{ __('Usuarios y sucursales') }}</td><td>{{ __('Administrar') }}</td><td>{{ __('Sin acceso') }}</td></tr>
                        <tr><td>{{ __('Datos de la empresa') }}</td><td>{{ __('Editar') }}</td><td>{{ __('Sin acceso') }}</td></tr>
                        <tr><td>{{ __('Sucursal de trabajo') }}</td><td>{{ __('Elige entre las sucursales activas') }}</td><td>{{ __('Fija, asignada por el administrador') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="divider"></div>
            <div class="notice"><x-icon name="shield" /><div><b>{{ __('Permisos verificados en el servidor') }}</b><p>{{ __('El vendedor no puede cambiar su sucursal aunque modifique la página o la petición. Los permisos de los módulos comerciales se añadirán junto con cada módulo.') }}</p></div></div>
        @endif
    </section>

    @if ($formularioAbierto)
        <x-modal :titulo="$editandoId ? 'Editar usuario' : 'Nuevo usuario'" cerrar="cerrarFormulario"
            descripcion="El vendedor trabaja solo en la sucursal asignada. Los usuarios no se eliminan: se desactivan para conservar su historial.">
            <form wire:submit="guardar">
                <div class="fields">
                    <x-campo etiqueta="Nombre" campo="name">
                        <input id="name" class="input" type="text" wire:model="name" maxlength="255" required autofocus>
                    </x-campo>
                    <x-campo etiqueta="Correo de acceso" campo="email">
                        <input id="email" class="input" type="email" wire:model="email" maxlength="255" placeholder="{{ __('usuario@empresa.com') }}" required>
                    </x-campo>
                    <x-campo etiqueta="Rol" campo="rol">
                        <select id="rol" class="select" wire:model.live="rol" @disabled($editandoPropiaCuenta)>
                            @foreach (\App\Enums\Rol::asignablesPorEmpresa() as $opcion)
                                <option value="{{ $opcion->value }}">{{ $opcion->etiqueta() }}</option>
                            @endforeach
                        </select>
                    </x-campo>
                    <x-campo etiqueta="Sucursal" campo="sucursal_id">
                        @if ($rol === \App\Enums\Rol::Vendedor->value)
                            <select id="sucursal_id" class="select" wire:model.live="sucursal_id" required>
                                <option value="">{{ __('Selecciona una sucursal') }}</option>
                                @foreach ($sucursales as $sucursal)
                                    <option value="{{ $sucursal->id }}">{{ $sucursal->nombre }}</option>
                                @endforeach
                            </select>
                        @else
                            <select id="sucursal_id" class="select" disabled><option>{{ __('Todas las sucursales') }}</option></select>
                        @endif
                    </x-campo>
                    <x-campo etiqueta="Caja asignada" campo="caja_id">
                        @if ($rol === \App\Enums\Rol::Vendedor->value)
                            <select id="caja_id" class="select" wire:model="caja_id" required @disabled($cajasDisponibles->isEmpty())>
                                <option value="">{{ $cajasDisponibles->isEmpty() ? __('Sin cajas en esta sucursal') : __('Selecciona una caja') }}</option>
                                @foreach ($cajasDisponibles as $caja)
                                    <option value="{{ $caja->id }}">{{ $caja->nombre }} {{ $caja->es_principal ? __('(Principal)') : '' }}</option>
                                @endforeach
                            </select>
                            <small class="subtle" style="display:block;margin-top:6px">{{ __('Solo cajas de su sucursal. Varios vendedores pueden compartir una caja; si es compartida, trabajan en el mismo turno.') }}</small>
                        @else
                            <select id="caja_id" class="select" disabled><option>{{ __('Todas las cajas (supervisa)') }}</option></select>
                        @endif
                    </x-campo>
                    <x-campo :etiqueta="$editandoId ? 'Nueva contraseña (opcional)' : 'Contraseña inicial'" campo="password">
                        <input id="password" class="input" type="password" wire:model="password" autocomplete="new-password" @required(! $editandoId)>
                    </x-campo>
                    <x-campo etiqueta="Confirmar contraseña" campo="password_confirmation">
                        <input id="password_confirmation" class="input" type="password" wire:model="password_confirmation" autocomplete="new-password" @required(! $editandoId)>
                    </x-campo>
                </div>
                <p class="subtle" style="margin:12px 0 0">{{ __('Mínimo 12 caracteres, con letras y números.') }}</p>
                @if ($editandoId)
                    <label class="row" style="margin-top:16px">
                        <input type="checkbox" wire:model="activo" @disabled($editandoPropiaCuenta)>
                        <span>{{ __('Usuario activo') }}</span>
                    </label>
                    @if ($editandoPropiaCuenta)<p class="subtle" style="margin:8px 0 0">{{ __('No puedes cambiar tu propio rol ni desactivar tu cuenta.') }}</p>@endif
                @endif
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrarFormulario">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar"><x-icon name="check" /> {{ __('Guardar') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
