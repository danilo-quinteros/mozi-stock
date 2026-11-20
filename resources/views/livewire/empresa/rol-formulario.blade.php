<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Seguridad · Roles') }}</div>
            <h1>{{ $rolId ? __('Editar rol') : __('Nuevo rol') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Marca lo que pueden hacer los usuarios con este rol. Los permisos que otro necesita se marcan solos.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('usuarios.index', ['pestana' => 'roles']) }}">{{ __('Volver a Usuarios') }}</a>
        </div>
    </div>

    <form class="card card-pad" wire:submit="guardar">
        <div class="fields">
            <x-campo etiqueta="Nombre" campo="nombre">
                <input id="nombre" class="input" type="text" wire:model="nombre" minlength="2" maxlength="40" required autofocus>
            </x-campo>
            <x-campo etiqueta="Descripción (opcional)" campo="descripcion">
                <input id="descripcion" class="input" type="text" wire:model="descripcion" maxlength="160">
            </x-campo>
        </div>
        @if ($rolId && $usuarios > 0)
            <p class="subtle" style="margin:12px 0 0" data-test="rol-usuarios">{{ trans_choice('{1} Lo usa 1 usuario: sus permisos cambian al guardar.|[2,*] Lo usan :n usuarios: sus permisos cambian al guardar.', $usuarios, ['n' => $usuarios]) }}</p>
        @endif
        @error('permisos')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
        @error('permisos.*')<p class="auth-error" role="alert">{{ $message }}</p>@enderror

        @include('livewire.empresa.partials.casillas-permisos', ['modelo' => 'permisos', 'marcados' => $permisos, 'delRol' => [], 'requeridos' => $requeridos, 'grupos' => $grupos, 'ocultos' => $ocultos])

        <div class="notice" style="margin-top:16px"><x-icon name="shield" /><div><b>{{ __('Lo exclusivo del Administrador') }}</b><p>{{ __('Usuarios y roles, datos de la empresa, sucursales y cajas, suscripción, facturación electrónica y migración de datos no se pueden asignar a un rol.') }}</p></div></div>
        <div class="modal-actions" style="margin-top:16px">
            <a class="btn ghost" href="{{ route('usuarios.index', ['pestana' => 'roles']) }}">{{ __('Cancelar') }}</a>
            <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar"><x-icon name="check" /> {{ __('Guardar rol') }}</button>
        </div>
    </form>

    <x-aviso :mensaje="$aviso" :numero="$aviso ? 1 : 0" />
</div>
