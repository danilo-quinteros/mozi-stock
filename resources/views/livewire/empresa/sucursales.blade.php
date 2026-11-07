<div>
    @if ($embebido)
        @include('livewire.empresa.partials.formulario-sucursal')
    @else
    @php
        $iniciales = fn (string $nombre): string => mb_strtoupper(collect(preg_split('/\s+/', trim($nombre)))->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join(''));
        $textoModalidad = fn (string $modalidad): string => $modalidad === 'compartida'
            ? __('Compartida · varios vendedores en el mismo turno')
            : __('Individual · solo cobra quien abre el turno');
    @endphp
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Gestión') }}</div>
            <h1>{{ __('Sucursales y cajas') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Cada sucursal con sus cajas y quién cobra en cada una.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('caja') }}"><x-icon name="history" /> {{ __('Turnos y arqueo') }}</a>
            <button class="btn primary" type="button" wire:click="crear"><x-icon name="plus" /> {{ __('Nueva sucursal') }}</button>
        </div>
    </div>

    @if ($sucursales->isEmpty())
        <section class="card card-pad">
            <div class="empty">
                <p>{{ __('Aún no registras sucursales. Al crear la primera se crea su Caja principal, lista para asignarle vendedores.') }}</p>
                <button class="btn soft sm" type="button" wire:click="crear"><x-icon name="plus" size="sm" /> {{ __('Nueva sucursal') }}</button>
            </div>
        </section>
    @else
        <div class="card sc-resumen">
            <div><b>{{ $resumen['sucursales'] }}</b>{{ trans_choice('sucursal activa|sucursales activas', $resumen['sucursales']) }}</div>
            <div><b>{{ $resumen['cajas'] }}</b>{{ trans_choice('caja activa|cajas activas', $resumen['cajas']) }}</div>
            <div><b>{{ $resumen['abiertas'] }}</b>{{ trans_choice('abierta ahora|abiertas ahora', $resumen['abiertas']) }}</div>
            <div @class(['alerta' => $resumen['sin_caja'] > 0])><b>{{ $resumen['sin_caja'] }}</b>{{ trans_choice('vendedor sin caja|vendedores sin caja', $resumen['sin_caja']) }}</div>
        </div>

        @if ($sinCaja->isNotEmpty())
            <div class="sc-avisos">
                @foreach ($sinCaja as $vendedor)
                    <div class="notice sc-aviso" wire:key="sin-caja-{{ $vendedor->id }}">
                        <x-icon name="status-alert" />
                        <div>
                            <b>{{ __(':nombre no tiene caja asignada', ['nombre' => $vendedor->name]) }}</b>
                            <p>{{ __('Es vendedor de :sucursal: no puede abrir caja ni vender en el POS hasta que le asignes una.', ['sucursal' => $sucursales->firstWhere('id', $vendedor->sucursal_id)?->nombre]) }}</p>
                        </div>
                        <button class="btn soft sm" type="button" wire:click="asignar({{ $vendedor->id }})">{{ __('Asignar caja') }}</button>
                    </div>
                @endforeach
            </div>
        @endif

        @foreach ($sucursales->where('activa', true) as $sucursal)
            @php
                $cajas = $cajasPorSucursal->get($sucursal->id, collect());
                $activas = $cajas->where('activa', true);
                $abiertas = $activas->filter(fn ($c) => $c->turnoActivo !== null)->count();
                $personal = $vendedoresPorSucursal->get($sucursal->id, collect())->count();
            @endphp
            <section class="card sc-sucursal" id="sucursal-{{ $sucursal->id }}" wire:key="sucursal-{{ $sucursal->id }}">
                <header class="sc-suc-head">
                    <div class="sc-suc-title">
                        <span class="token"><x-icon name="store" /></span>
                        <div>
                            <h2>{{ $sucursal->nombre }} <span class="badge success">{{ __('Activa') }}</span></h2>
                            <p>{{ $sucursal->codigo }}@if ($sucursal->direccion) · {{ $sucursal->direccion }}@endif</p>
                        </div>
                    </div>
                    <div class="sc-suc-actions">
                        <button class="btn ghost sm" type="button" wire:click="editar({{ $sucursal->id }})"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</button>
                        <button class="btn soft sm" type="button" wire:click="crearCaja({{ $sucursal->id }})"><x-icon name="plus" size="sm" /> {{ __('Agregar caja') }}</button>
                    </div>
                </header>
                <div class="sc-cajas">
                    @foreach ($cajas as $caja)
                        @php
                            $asignados = $vendedoresPorCaja->get($caja->id, collect());
                            $turno = $caja->turnoActivo;
                        @endphp
                        <div @class(['sc-caja', 'inactiva' => ! $caja->activa]) wire:key="caja-{{ $caja->id }}">
                            <div class="sc-caja-nombre">
                                <span class="sc-icono"><x-icon name="cash" size="sm" /></span>
                                <div>
                                    <b>{{ $caja->nombre }}</b>@if ($caja->es_principal)<span class="badge info">{{ __('Principal') }}</span>@endif
                                    <small>{{ $textoModalidad($caja->modalidad) }}</small>
                                </div>
                            </div>
                            <div>
                                @if (! $caja->activa)
                                    <span class="sc-estado"><span class="sc-dot"></span>{{ __('Inactiva') }}</span>
                                @elseif ($turno === null)
                                    <span class="sc-estado"><span class="sc-dot"></span>{{ __('Cerrada') }}</span>
                                @else
                                    @php
                                        $apertura = $turno->fecha_apertura->copy()->timezone(config('mozistock.business_timezone'));
                                        $anterior = $turno->esDelDiaAnterior();
                                    @endphp
                                    <span @class(['sc-estado', 'alerta' => $anterior, 'abierta' => ! $anterior])>
                                        <span class="sc-dot"></span>{{ $anterior ? __('Abierta desde el :fecha', ['fecha' => $apertura->format('d/m H:i')]) : __('Abierta hoy :hora', ['hora' => $apertura->format('H:i')]) }} · {{ $turno->usuarioApertura?->name }}
                                    </span>
                                    @if ($anterior)<small class="sc-turno-anterior">{{ __('Turno #:turno de un día anterior: conviene cerrarlo y arquearlo.', ['turno' => $turno->id]) }}</small>@endif
                                @endif
                            </div>
                            <div @class(['sc-vend', 'vacio' => $asignados->isEmpty() && $caja->activa])>
                                @if ($asignados->isNotEmpty())
                                    <span class="sc-pila">@foreach ($asignados as $vendedor)<span class="sc-mini" title="{{ $vendedor->name }}">{{ $iniciales($vendedor->name) }}</span>@endforeach</span>
                                    <span class="nombres">{{ $asignados->pluck('name')->join(', ') }}</span>
                                @else
                                    {{ $caja->activa ? __('Sin vendedores asignados') : '—' }}
                                @endif
                            </div>
                            <div class="sc-acc">
                                @if ($caja->activa)
                                    <button class="btn ghost sm" type="button" wire:click="elegirVendedores({{ $caja->id }})"><x-icon name="users" size="sm" /> {{ __('Vendedores') }}</button>
                                @endif
                                <button class="icon-action" type="button" wire:click="editarCaja({{ $caja->id }})" aria-label="{{ __('Editar :caja', ['caja' => $caja->nombre]) }}" title="{{ __('Editar caja') }}"><x-icon name="edit" size="sm" /></button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <footer class="sc-suc-foot">
                    <span>{{ trans_choice(':count caja activa|:count cajas activas', $activas->count()) }} · {{ trans_choice(':count abierta ahora|:count abiertas ahora', $abiertas) }} · {{ trans_choice(':count vendedor|:count vendedores', $personal) }}</span>
                    <a href="{{ route('caja', ['sucursal' => $sucursal->id]) }}">{{ __('Turnos y arqueo de :sucursal', ['sucursal' => $sucursal->nombre]) }} →</a>
                </footer>
            </section>
        @endforeach

        @php
            $inactivas = $sucursales->where('activa', false);
        @endphp
        @if ($inactivas->isNotEmpty())
            <details class="card sc-inactivas">
                <summary>{{ __('Sucursales inactivas (:count)', ['count' => $inactivas->count()]) }}</summary>
                @foreach ($inactivas as $sucursal)
                    <div class="sc-inactiva" wire:key="inactiva-{{ $sucursal->id }}">
                        <div>
                            <b>{{ $sucursal->nombre }}</b>
                            <small>{{ $sucursal->codigo }} · {{ trans_choice(':count caja|:count cajas', $cajasPorSucursal->get($sucursal->id, collect())->count()) }} · {{ __('no admite operaciones nuevas') }}</small>
                        </div>
                        <button class="btn ghost sm" type="button" wire:click="editar({{ $sucursal->id }})">{{ __('Ver o activar') }}</button>
                    </div>
                @endforeach
            </details>
        @endif
    @endif

    <p class="footnote">{{ __('Una caja pertenece a una sola sucursal y tiene un solo turno abierto a la vez. Cada vendedor usa una caja de su sucursal; varios pueden compartirla. Para crear vendedores o cambiarlos de sucursal ve a Usuarios.') }}</p>

    @if ($formularioAbierto)
        <x-modal :titulo="$editandoId ? 'Editar sucursal' : 'Nueva sucursal'" cerrar="cerrarFormulario"
            :descripcion="$editandoId ? 'El código no puede repetirse dentro de tu empresa.' : 'Al crearla se crea su Caja principal compartida, lista para asignarle vendedores.'">
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

    @if ($formularioCajaAbierto && $sucursalCaja)
        @php
            $turnoEditada = $cajaEditada?->turnoActivo;
            $asignadosEditada = $cajaEditada ? $vendedoresPorCaja->get($cajaEditada->id, collect())->count() : 0;
        @endphp
        <x-modal :titulo="$cajaEditada ? __('Editar :caja', ['caja' => $cajaEditada->nombre]) : 'Nueva caja'" cerrar="cerrarFormularioCaja"
            :descripcion="__('Pertenece a :sucursal. Una caja no se puede mover ni usar desde otra sucursal.', ['sucursal' => $sucursalCaja->nombre])">
            <form wire:submit="guardarCaja">
                <div class="fields">
                    <x-campo etiqueta="Nombre" campo="nombreCaja">
                        <input id="nombreCaja" class="input" type="text" wire:model="nombreCaja" maxlength="80" placeholder="{{ __('Ej.: Caja 2') }}" required autofocus>
                    </x-campo>
                    <x-campo etiqueta="Código (opcional)" campo="codigoCaja">
                        <input id="codigoCaja" class="input" type="text" wire:model="codigoCaja" maxlength="20" placeholder="{{ __('Ej.: CM-C3') }}">
                    </x-campo>
                </div>
                <div class="field-label sc-pregunta">{{ __('¿Cómo se usa esta caja?') }}</div>
                <div class="sc-opciones">
                    <label @class(['sc-opcion', 'bloqueada' => $turnoEditada !== null])>
                        <input type="radio" name="modalidad" value="compartida" wire:model="modalidad" @disabled($turnoEditada !== null)>
                        <span><b>{{ __('Compartida') }}</b><small>{{ __('Varios vendedores cobran en el mismo turno y se turnan sin cerrarlo. Cada venta guarda quién la hizo.') }}</small></span>
                    </label>
                    <label @class(['sc-opcion', 'bloqueada' => $turnoEditada !== null])>
                        <input type="radio" name="modalidad" value="individual" wire:model="modalidad" @disabled($turnoEditada !== null)>
                        <span><b>{{ __('Individual') }}</b><small>{{ __('Solo quien abre el turno cobra en él. Si dos personas cobran a la vez con su propio dinero, crea dos cajas.') }}</small></span>
                    </label>
                </div>
                @if ($turnoEditada !== null)
                    <p class="sc-nota"><x-icon name="lock" size="sm" /> {{ __('Tiene abierto el turno #:turno. Ciérralo para cambiar la modalidad.', ['turno' => $turnoEditada->id]) }}</p>
                @endif
                @error('modalidad')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                @if ($cajaEditada)
                    <div class="sc-peligro">
                        <div>
                            <b>{{ $cajaEditada->activa ? __('Desactivar caja') : __('Activar caja') }}</b>
                            <p>
                                @if (! $cajaEditada->activa)
                                    {{ __('Volverá a estar disponible para abrir turnos.') }}
                                @elseif ($turnoEditada !== null)
                                    {{ __('No se puede: el turno #:turno está abierto.', ['turno' => $turnoEditada->id]) }}
                                @elseif ($asignadosEditada > 0)
                                    {{ trans_choice('Su vendedor no podrá vender hasta que le asignes otra caja.|Sus :count vendedores no podrán vender hasta que les asignes otra caja.', $asignadosEditada) }}
                                @else
                                    {{ __('No se borra su historial.') }}
                                @endif
                            </p>
                        </div>
                        <button type="button" class="btn ghost sm" wire:click="confirmarEstadoCaja({{ $cajaEditada->id }})" @disabled($cajaEditada->activa && $turnoEditada !== null)>{{ $cajaEditada->activa ? __('Desactivar') : __('Activar') }}</button>
                    </div>
                @endif
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrarFormularioCaja">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardarCaja">{{ $cajaEditada ? __('Guardar cambios') : __('Crear caja') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($confirmandoCaja)
        <x-modal :titulo="__($confirmandoCaja->activa ? 'Desactivar :caja' : 'Activar :caja', ['caja' => $confirmandoCaja->nombre])" cerrar="cancelarEstadoCaja"
            :descripcion="$confirmandoCaja->activa
                ? 'No se borra ningún dato ni turno. Una caja inactiva no abre turnos nuevos y sus vendedores necesitan otra caja para vender.'
                : 'La caja volverá a estar disponible para abrir turnos y vender.'">
            @error('caja')<div class="notice" role="alert"><x-icon name="info" /><div><b>{{ __('No se pudo cambiar el estado') }}</b><p>{{ $message }}</p></div></div>@enderror
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cancelarEstadoCaja">{{ __('Cancelar') }}</button>
                <button type="button" class="btn primary" wire:click="cambiarEstadoCaja" wire:loading.attr="disabled">{{ $confirmandoCaja->activa ? __('Desactivar') : __('Activar') }}</button>
            </div>
        </x-modal>
    @endif

    @if ($cajaVendedores)
        @php
            $sucursalV = $sucursales->firstWhere('id', $cajaVendedores->sucursal_id);
            $candidatos = $vendedoresPorSucursal->get($cajaVendedores->sucursal_id, collect())
                ->sortByDesc(fn ($v) => (int) $v->caja_id === $cajaVendedores->id)->values();
            $elegidos = array_map('intval', $seleccion);
        @endphp
        <x-modal :titulo="__('Vendedores de :caja', ['caja' => $cajaVendedores->nombre])" cerrar="cerrarVendedores"
            :descripcion="__(':sucursal · :modalidad. Solo aparecen vendedores de :sucursal; cada uno usa una caja y una caja puede tener varios.', [
                'sucursal' => $sucursalV?->nombre,
                'modalidad' => $cajaVendedores->modalidad === 'compartida' ? __('Compartida: todos cobran en el mismo turno') : __('Individual: solo cobra quien abre el turno'),
            ])">
            <div class="sc-lista">
                @forelse ($candidatos as $vendedor)
                    @php
                        $aqui = (int) $vendedor->caja_id === $cajaVendedores->id;
                        $suTurno = $abrio->get($vendedor->id);
                        $texto = match (true) {
                            $suTurno !== null && (int) $suTurno->caja_id === $cajaVendedores->id => __('Abrió el turno #:turno de esta caja', ['turno' => $suTurno->id]),
                            $suTurno !== null => __('En :caja · abrió su turno #:turno: ciérralo para moverlo', ['caja' => $nombresCaja->get($suTurno->caja_id), 'turno' => $suTurno->id]),
                            $aqui => __('Usa esta caja · para quitarlo, muévelo desde otra caja'),
                            $vendedor->caja_id !== null => __('Ahora en :caja', ['caja' => $nombresCaja->get($vendedor->caja_id)]),
                            default => __('Sin caja · hoy no puede vender'),
                        };
                    @endphp
                    <label @class(['sc-fila', 'bloqueada' => $aqui || $suTurno !== null]) wire:key="candidato-{{ $vendedor->id }}">
                        @if ($aqui)
                            <input type="checkbox" checked disabled>
                        @elseif ($suTurno !== null)
                            <input type="checkbox" disabled>
                        @else
                            <input type="checkbox" value="{{ $vendedor->id }}" wire:model.live="seleccion">
                        @endif
                        <span class="sc-mini">{{ $iniciales($vendedor->name) }}</span>
                        <span class="sc-fila-txt"><b>{{ $vendedor->name }}</b><small>{{ $texto }}</small></span>
                        @if ($suTurno !== null && ! $aqui)<x-icon name="lock" size="sm" />@endif
                    </label>
                @empty
                    <div class="empty">{{ __('No hay vendedores activos en :sucursal.', ['sucursal' => $sucursalV?->nombre]) }}</div>
                @endforelse
            </div>
            @php
                $cambios = $candidatos->filter(fn ($v) => in_array($v->id, $elegidos, true));
            @endphp
            @if ($cambios->isNotEmpty())
                <div class="sc-cambios">
                    @foreach ($cambios as $vendedor)
                        <div><x-icon name="arrow" size="sm" /> <b>{{ $vendedor->name }}</b> {{ $vendedor->caja_id !== null ? __('pasa de :caja a esta caja', ['caja' => $nombresCaja->get($vendedor->caja_id)]) : __('empieza a usar esta caja') }}</div>
                    @endforeach
                </div>
            @endif
            @error('caja_id')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
            @error('sucursal_id')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
            <div class="sc-pie-modal">
                <a class="link" href="{{ route('usuarios.index') }}"><x-icon name="plus" size="sm" /> {{ __('Crear vendedor en Usuarios') }}</a>
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrarVendedores">{{ __('Cancelar') }}</button>
                    <button type="button" class="btn primary" wire:click="guardarVendedores" wire:loading.attr="disabled" wire:target="guardarVendedores">{{ __('Guardar') }}</button>
                </div>
            </div>
        </x-modal>
    @endif

    @if ($asignando)
        @php
            $opciones = $cajasDe((int) $asignando->sucursal_id);
            $sucursalA = $sucursales->firstWhere('id', $asignando->sucursal_id);
        @endphp
        <x-modal :titulo="__('Caja de :nombre', ['nombre' => $asignando->name])" cerrar="cerrarAsignar"
            :descripcion="__('Solo cajas activas de :sucursal, su sucursal.', ['sucursal' => $sucursalA?->nombre])">
            <div class="sc-lista">
                @forelse ($opciones as $caja)
                    @php
                        $cuantos = $vendedoresPorCaja->get($caja->id, collect())->count();
                    @endphp
                    <label class="sc-fila" wire:key="opcion-{{ $caja->id }}">
                        <input type="radio" name="cajaElegida" value="{{ $caja->id }}" wire:model="cajaElegida">
                        <span class="sc-icono"><x-icon name="cash" size="sm" /></span>
                        <span class="sc-fila-txt"><b>{{ $caja->nombre }}</b><small>{{ $textoModalidad($caja->modalidad) }} · {{ trans_choice(':count vendedor|:count vendedores', $cuantos) }}</small></span>
                    </label>
                @empty
                    <div class="empty">{{ __(':sucursal no tiene cajas activas. Crea una con «Agregar caja».', ['sucursal' => $sucursalA?->nombre]) }}</div>
                @endforelse
            </div>
            @error('caja_id')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cerrarAsignar">{{ __('Cancelar') }}</button>
                <button type="button" class="btn primary" wire:click="guardarAsignacion" wire:loading.attr="disabled" @disabled($opciones->isEmpty())>{{ __('Asignar') }}</button>
            </div>
        </x-modal>
    @endif

    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
