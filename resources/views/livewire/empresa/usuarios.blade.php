<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Seguridad') }}</div>
            <h1>{{ __('Usuarios y vendedores') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Permisos, accesos y trazabilidad por vendedor.') }}</p>
        </div>
        <div class="page-actions">
            @if ($pestana === 'roles')
                <a class="btn primary" href="{{ route('usuarios.roles.crear') }}"><x-icon name="plus" /> {{ __('Nuevo rol') }}</a>
            @else
                <button class="btn primary" type="button" wire:click="crear"><x-icon name="plus" /> {{ __('Nuevo usuario') }}</button>
            @endif
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat">
            <span class="stat-label">{{ __('Usuarios activos') }}</span>
            <div class="stat-value">{{ (int) $conteo->activos }}</div>
            @if ($cupo['limite'] !== null)
                <div class="stat-foot" data-test="cupo-usuarios">
                    {{ __(':uso de :limite de tu plan', ['uso' => $cupo['uso'], 'limite' => $cupo['limite']]) }}
                    @if ($cupo['uso'] >= $cupo['limite'])
                        · <a class="link" href="{{ route('suscripcion') }}">{{ __('Sumar usuarios') }}</a>
                    @endif
                </div>
            @else
                <div class="stat-foot">{{ __('Con acceso a tu empresa') }}</div>
            @endif
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Administradores') }}</span>
            <div class="stat-value">{{ $administradores }}</div>
            <div class="stat-foot">{{ __('Todos los permisos y todas las sucursales') }}</div>
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Usuarios con rol') }}</span>
            <div class="stat-value">{{ (int) $conteo->con_rol }}</div>
            <div class="stat-foot">{{ trans_choice('{0} Sin roles creados|{1} 1 rol en tu empresa|[2,*] :n roles en tu empresa', $roles->count(), ['n' => $roles->count()]) }}</div>
        </article>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="users" /></span>{{ __('Usuarios y vendedores') }}</div>
        </div>
        <div class="tabs" role="tablist">
            <button type="button" role="tab" class="tab {{ $pestana === 'usuarios' ? 'active' : '' }}" aria-selected="{{ $pestana === 'usuarios' ? 'true' : 'false' }}" wire:click="$set('pestana', 'usuarios')">{{ __('Usuarios') }}</button>
            <button type="button" role="tab" class="tab {{ $pestana === 'roles' ? 'active' : '' }}" aria-selected="{{ $pestana === 'roles' ? 'true' : 'false' }}" wire:click="$set('pestana', 'roles')">{{ __('Roles') }}</button>
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
                                <td>
                                    <span class="badge info">{{ $usuario->nombreRol() }}</span>
                                    @if ($usuario->esUsuario() && $usuario->permisosExtra->isNotEmpty())
                                        @php($extras = collect(\App\Enums\Permiso::desdeClaves($usuario->permisosExtra->pluck('permiso')))->map(fn ($p) => $p->etiqueta())->implode(', '))
                                        <span class="badge secondary" title="{{ $extras }}" data-test="extras-{{ $usuario->id }}">{{ __('+:n extra', ['n' => $usuario->permisosExtra->count()]) }}</span>
                                    @endif
                                </td>
                                <td>{{ $usuario->sucursal->nombre ?? __('Todas') }}</td>
                                <td><span class="badge secondary">{{ $usuario->caja->nombre ?? ($usuario->esUsuario() ? __('Sin caja') : __('Supervisa')) }}</span></td>
                                <td><span class="badge {{ $usuario->activo ? 'success' : 'danger' }}">{{ $usuario->activo ? __('Activo') : __('Inactivo') }}</span></td>
                                <td><span class="row" style="gap:6px;flex-wrap:nowrap">
                                    <button class="btn sm ghost" type="button" wire:click="editar({{ $usuario->id }})"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</button>
                                    {{-- Misma regla que el Gate «impersonar», sin consultas por fila; el controlador la vuelve a validar. --}}
                                    @if ($usuario->esUsuario() && $usuario->activo)
                                        <form method="POST" action="{{ route('impersonar', $usuario) }}">@csrf
                                            <button class="btn sm ghost" type="submit" title="{{ __('Entrar como :name', ['name' => $usuario->name]) }}"><x-icon name="login" size="sm" /> {{ __('Entrar como') }}</button>
                                        </form>
                                    @endif
                                </span></td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><div class="empty">{{ __('No hay usuarios que coincidan con la búsqueda.') }}</div></td></tr>
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
            <div class="tool-row">
                <p class="subtle" style="margin:0">{{ __('Cada usuario tiene un rol. El administrador puede sumarle permisos extra desde su ficha.') }}</p>
            </div>
            @error('rol')<div class="notice" role="alert" style="margin-bottom:12px"><x-icon name="status-alert" /><div><p>{{ $message }}</p></div></div>@enderror
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Rol') }}</th><th>{{ __('Usuarios') }}</th><th>{{ __('Permisos') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                    <tbody>
                        <tr>
                            <td><b>{{ __('Administrador') }}</b><small style="display:block" class="subtle">{{ __('Fijo: no se edita ni se elimina') }}</small></td>
                            <td>{{ $administradores }}</td>
                            <td><span class="badge success">{{ __('Todos los permisos') }}</span></td>
                            <td>—</td>
                        </tr>
                        @foreach ($roles as $rol)
                            <tr wire:key="rol-{{ $rol->id }}">
                                <td>
                                    <b>{{ $rol->nombre }}</b>
                                    <small style="display:block" class="subtle">{{ $rol->descripcion ?: ($rol->plantilla ? __('Rol por defecto') : __('Creado por la empresa')) }}</small>
                                </td>
                                <td>{{ $rol->usuarios_count }}</td>
                                <td>{{ trans_choice('{0} Sin permisos|{1} 1 permiso|[2,*] :n permisos', $rol->permisos_count, ['n' => $rol->permisos_count]) }}</td>
                                <td><span class="row" style="gap:6px;flex-wrap:nowrap">
                                    <a class="btn sm ghost" href="{{ route('usuarios.roles.editar', $rol) }}"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</a>
                                    <button class="btn sm ghost" type="button" wire:click="duplicarRol({{ $rol->id }})" wire:loading.attr="disabled" wire:target="duplicarRol">{{ __('Duplicar') }}</button>
                                    @if ($rol->usuarios_count === 0)
                                        <button class="btn sm ghost" type="button" wire:click="eliminarRol({{ $rol->id }})" wire:confirm="{{ __('¿Eliminar el rol «:nombre»? No se puede deshacer.', ['nombre' => $rol->nombre]) }}">{{ __('Eliminar') }}</button>
                                    @endif
                                </span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="footnote">{{ __('Los permisos se verifican en el servidor. Solo un administrador gestiona usuarios, roles y permisos. Un rol con usuarios no se elimina: primero asígnales otro.') }}</p>
        @endif
    </section>

    @if ($formularioAbierto)
        <x-modal class="rol-modal" :titulo="$editandoId ? 'Editar usuario' : 'Nuevo usuario'" cerrar="cerrarFormulario"
            descripcion="Un usuario con rol trabaja en la sucursal asignada salvo que su rol le permita todas. Los usuarios no se eliminan: se desactivan para conservar su historial.">
            <form wire:submit="guardar">
                <div class="fields">
                    <x-campo etiqueta="Nombre" campo="name">
                        <input id="name" class="input" type="text" wire:model="name" maxlength="255" required autofocus>
                    </x-campo>
                    <x-campo etiqueta="Correo de acceso" campo="email">
                        <input id="email" class="input" type="email" wire:model="email" maxlength="255" placeholder="{{ __('usuario@empresa.com') }}" required>
                    </x-campo>
                    <x-campo etiqueta="Tipo" campo="tipo">
                        <select id="tipo" class="select" wire:model.live="tipo" @disabled($editandoPropiaCuenta)>
                            <option value="usuario">{{ __('Usuario con rol') }}</option>
                            <option value="administrador">{{ __('Administrador') }}</option>
                        </select>
                    </x-campo>
                    <x-campo etiqueta="Rol" campo="rol_id">
                        @if ($esUsuario)
                            <select id="rol_id" class="select" wire:model.live="rol_id" required>
                                <option value="">{{ $roles->isEmpty() ? __('Crea un rol primero') : __('Selecciona un rol') }}</option>
                                @foreach ($roles as $opcion)
                                    <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                                @endforeach
                            </select>
                        @else
                            <select id="rol_id" class="select" disabled><option>{{ __('Todos los permisos') }}</option></select>
                        @endif
                    </x-campo>
                    <x-campo etiqueta="Sucursal" campo="sucursal_id">
                        @if ($esUsuario)
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
                        @if ($esUsuario)
                            <select id="caja_id" class="select" wire:model="caja_id" @required($necesitaCaja) @disabled($cajasDisponibles->isEmpty())>
                                <option value="">{{ $cajasDisponibles->isEmpty() ? __('Sin cajas en esta sucursal') : ($necesitaCaja ? __('Selecciona una caja') : __('Sin caja (supervisa cajas)')) }}</option>
                                @foreach ($cajasDisponibles as $caja)
                                    <option value="{{ $caja->id }}">{{ $caja->nombre }} {{ $caja->es_principal ? __('(Principal)') : '' }}</option>
                                @endforeach
                            </select>
                            <small class="subtle" style="display:block;margin-top:6px">{{ $necesitaCaja
                                ? __('Su rol usa su caja: solo cajas de su sucursal. Varios usuarios pueden compartir una caja y trabajan en el mismo turno.')
                                : __('Opcional: su rol supervisa cajas o no usa caja.') }}</small>
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

                @if ($esUsuario)
                    {{-- wire:ignore.self conserva abierto/cerrado entre actualizaciones; el contenido sí se actualiza. --}}
                    <details class="permisos-extra" wire:ignore.self @if ($permisos_extra !== []) open @endif data-test="permisos-extra">
                        <summary>{{ __('Permisos extra') }} <small>{{ $permisos_extra === [] ? __('Solo suman a los de su rol') : trans_choice('{1} 1 extra|[2,*] :n extra', count($permisos_extra), ['n' => count($permisos_extra)]) }}</small></summary>
                        @error('permisos_extra')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                        @error('permisos_extra.*')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                        @include('livewire.empresa.partials.casillas-permisos', ['modelo' => 'permisos_extra', 'marcados' => $permisos_extra, 'delRol' => $delRol, 'requeridos' => $requeridos, 'grupos' => $grupos, 'ocultos' => $ocultos])
                    </details>
                @else
                    <p class="subtle" style="margin:12px 0 0">{{ __('El administrador tiene todos los permisos y gestiona usuarios, roles, sucursales y la suscripción.') }}</p>
                @endif

                @if ($editandoId)
                    <label class="row" style="margin-top:16px">
                        <input type="checkbox" wire:model="activo" @disabled($editandoPropiaCuenta)>
                        <span>{{ __('Usuario activo') }}</span>
                    </label>
                    @if ($editandoPropiaCuenta)<p class="subtle" style="margin:8px 0 0">{{ __('No puedes cambiar tu propio tipo ni desactivar tu cuenta.') }}</p>@endif
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
