{{--
    Casillas del catálogo de permisos agrupadas (§ 4.2). Solo es la pantalla: GuardarRol y GuardarUsuarioEmpresa
    validan cada clave y agregan los requeridos en el servidor.
    $modelo: propiedad Livewire (lista de claves) · $marcados: claves marcadas · $delRol: list<Permiso> que ya da el rol
    $requeridos: clave => permiso que la necesita · $grupos: catálogo visible · $ocultos: etiqueta => funciones
--}}
<div class="rol-grupos">
    @foreach ($grupos as $grupo => $permisosGrupo)
        <fieldset class="rol-grupo" wire:key="grupo-{{ $modelo }}-{{ $loop->index }}">
            <legend class="heading">{{ $grupo }}</legend>
            @foreach ($permisosGrupo as $permiso)
                @php($deRol = in_array($permiso, $delRol, true))
                @php($requerido = ! $deRol && isset($requeridos[$permiso->value]) && in_array($permiso->value, $marcados, true))
                <label class="rol-permiso {{ $deRol || $requerido ? 'fijo' : '' }}" wire:key="permiso-{{ $modelo }}-{{ $permiso->value }}">
                    @if ($deRol)
                        <input type="checkbox" checked disabled>
                    @else
                        <input type="checkbox" value="{{ $permiso->value }}" wire:model.live="{{ $modelo }}" @disabled($requerido) data-permiso="{{ $permiso->value }}">
                    @endif
                    <span>
                        <b>{{ $permiso->etiqueta() }}
                            @if ($deRol)<span class="badge secondary">{{ __('Del rol') }}</span>@endif
                        </b>
                        <small>{{ $permiso->descripcion() }}</small>
                        @if ($requerido)<small class="rol-requerido">{{ __('Lo necesita «:permiso».', ['permiso' => $requeridos[$permiso->value]]) }}</small>@endif
                    </span>
                </label>
            @endforeach
        </fieldset>
    @endforeach
</div>
@if ($ocultos !== [])
    <p class="subtle" style="margin:14px 0 0" data-test="permisos-ocultos">
        {{ __('Ocultos porque la función está apagada:') }}
        @foreach ($ocultos as $etiqueta => $funciones){{ $etiqueta }} ({{ __('requiere la función :funcion', ['funcion' => $funciones]) }}){{ $loop->last ? '.' : ' · ' }}@endforeach
    </p>
@endif
