<div>
    @if ($embebido)
        @if (! $puedeConfigurar)
            <div class="notice"><x-icon name="lock" /><div><b>{{ __('Modo «Entrar como»') }}</b><p>{{ __('Los datos fiscales, el token, las sucursales y los puntos de venta solo los cambia el administrador de la empresa o el superadministrador desde su propia cuenta.') }}</p></div></div>
        @elseif ($editando)
            <p class="subtle" style="margin:0 0 14px">{{ __('Sucursal: :nombre', ['nombre' => $editando->nombre]) }}</p>
            @include('livewire.facturacion.partials.formulario-sucursal-siat')
        @else
            <div class="empty">{{ __('Registra primero una sucursal de tu empresa en Configuración → Sucursales.') }}</div>
        @endif
    @else
    @php($zona = config('mozistock.business_timezone'))

    @if ($errorSin)
        <div class="notice" role="alert" style="margin-bottom:18px"><x-icon name="status-alert" /><div><b>{{ __('No se pudo completar la operación') }}</b><p>{{ $errorSin }}</p></div></div>
    @endif

    <section class="card card-pad" style="margin-bottom:18px">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="store" /></span>{{ __('Sucursales en el padrón del SIN') }}</div></div>
        @if ($sucursales->isEmpty())
            <div class="empty">{{ __('Registra primero una sucursal de tu empresa en Configuración → Sucursales.') }}</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Sucursal') }}</th><th>{{ __('Código SIN') }}</th><th>{{ __('Municipio') }}</th><th>{{ __('Teléfono') }}</th><th>{{ __('Acciones') }}</th></tr></thead>
                    <tbody>
                        @foreach ($sucursales as $sucursal)
                            @php($registro = $siatSucursales[$sucursal->id] ?? null)
                            <tr wire:key="sucursal-{{ $sucursal->id }}">
                                <td><b>{{ $sucursal->nombre }}</b>@unless ($sucursal->activa)<small>{{ __('Inactiva') }}</small>@endunless</td>
                                @if ($registro)
                                    <td><b>{{ $registro->codigo }}</b>@if ($registro->codigo === 0)<small>{{ __('Casa matriz') }}</small>@endif</td>
                                    <td>{{ $registro->municipio }}</td>
                                    <td>{{ $registro->telefono ?? '—' }}</td>
                                @else
                                    <td colspan="3"><span class="badge info">{{ __('Sin registrar en el SIN') }}</span></td>
                                @endif
                                <td>
                                    @if ($puedeConfigurar)
                                        <button class="btn ghost sm" type="button" wire:click="editarSucursal({{ $sucursal->id }})">
                                            @if ($registro)<x-icon name="edit" size="sm" /> {{ __('Editar') }}@else<x-icon name="plus" size="sm" /> {{ __('Registrar') }}@endif
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="subtle" style="margin:14px 0 0">{{ __('Usa el mismo código y municipio con que la sucursal figura en el padrón del SIN (0 = casa matriz).') }}</p>
        @endif
    </section>

    @foreach ($siatSucursales->sortBy('codigo') as $registro)
        <section class="card card-pad" style="margin-bottom:18px" wire:key="siat-sucursal-{{ $registro->id }}">
            <div class="card-head">
                <div class="heading"><span class="heading-icon"><x-icon name="receipt" /></span>{{ __('Sucursal :codigo · :nombre', ['codigo' => $registro->codigo, 'nombre' => $sucursales->firstWhere('id', $registro->sucursal_id)?->nombre]) }}</div>
                <div class="row" style="gap:6px">
                    <button class="btn ghost sm" type="button" wire:click="importar({{ $registro->id }})" wire:loading.attr="disabled" wire:target="importar"><x-icon name="download" size="sm" /> {{ __('Traer del SIN') }}</button>
                    @if ($puedeConfigurar)
                        <button class="btn soft sm" type="button" wire:click="nuevoPunto({{ $registro->id }})"><x-icon name="plus" size="sm" /> {{ __('Nuevo punto de venta') }}</button>
                    @endif
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Punto de venta') }}</th><th>{{ __('CUIS') }}</th><th>{{ __('CUFD') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Acciones') }}</th></tr></thead>
                    <tbody>
                        @foreach ($puntos[$registro->id] ?? [] as $punto)
                            <tr wire:key="punto-{{ $punto->id }}">
                                <td><b>{{ $punto->codigo }} · {{ $punto->nombre }}</b>@if ($punto->tipo)<small>{{ $punto->tipo }}</small>@endif</td>
                                <td>
                                    @isset($cuis[$punto->id])
                                        <b>{{ $cuis[$punto->id]->codigo }}</b><small>{{ __('Vence el :fecha', ['fecha' => $cuis[$punto->id]->vence_en->timezone($zona)->format('d/m/Y')]) }}</small>
                                    @else
                                        <span class="badge info">{{ __('Sin CUIS') }}</span>
                                    @endisset
                                </td>
                                <td>
                                    @isset($cufd[$punto->id])
                                        <b>{{ __('Vigente') }}</b><small>{{ __('Hasta :fecha', ['fecha' => $cufd[$punto->id]->vence_en->timezone($zona)->format('d/m/Y H:i')]) }}</small>
                                    @else
                                        <span class="badge info">{{ __('Sin CUFD') }}</span>
                                    @endisset
                                </td>
                                <td><span class="badge {{ $punto->activo ? 'success' : 'danger' }}">{{ $punto->activo ? __('Activo') : __('Cerrado') }}</span></td>
                                <td>
                                    @if ($punto->activo)
                                        <span class="row" style="gap:6px;flex-wrap:nowrap">
                                            @unless (isset($cuis[$punto->id]))
                                                <button class="btn ghost sm" type="button" wire:click="obtenerCuis({{ $punto->id }})" wire:loading.attr="disabled" wire:target="obtenerCuis">{{ __('Obtener CUIS') }}</button>
                                            @endunless
                                            <button class="btn ghost sm" type="button" wire:click="renovarCufd({{ $punto->id }})" wire:loading.attr="disabled" wire:target="renovarCufd">{{ isset($cufd[$punto->id]) ? __('Renovar CUFD') : __('Obtener CUFD') }}</button>
                                            @if ($puedeConfigurar && $punto->codigo !== 0)
                                                <button class="btn ghost sm" type="button" wire:click="confirmarCierre({{ $punto->id }})"><x-icon name="x" size="sm" /> {{ __('Cerrar') }}</button>
                                            @endif
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    <div class="notice"><x-icon name="info" /><div><b>{{ __('Códigos del SIN') }}</b><p>{{ __('El CUIS dura un año y el CUFD, 24 horas. MoziStock renueva el CUFD de cada punto activo cada hora cuando le quedan menos de 2 horas; aquí puedes pedirlos a mano.') }}</p></div></div>

    @if ($editando)
        <x-modal :titulo="__('Sucursal :nombre en el SIN', ['nombre' => $editando->nombre])" descripcion="Datos tal como figuran en el padrón del SIN. El código no puede cambiar después de obtener códigos." cerrar="cerrarSucursal">
            @include('livewire.facturacion.partials.formulario-sucursal-siat')
        </x-modal>
    @endif

    @if ($registrandoEnId)
        <x-modal titulo="Nuevo punto de venta" descripcion="Se registra en el SIN y recibe su código. Necesita el CUIS de la sucursal, que se obtiene automáticamente." cerrar="cerrarPunto">
            @if ($errorSin)<div class="notice" role="alert" style="margin-bottom:14px"><x-icon name="status-alert" /><div><b>{{ __('El SIN no aceptó la solicitud') }}</b><p>{{ $errorSin }}</p></div></div>@endif
            <form wire:submit="registrarPunto">
                <div class="fields">
                    <x-campo etiqueta="Tipo" campo="tipo" style="grid-column:1/-1">
                        <select id="tipo" class="select" wire:model="tipo" required @disabled($tiposPunto->isEmpty())>
                            <option value="">{{ $tiposPunto->isEmpty() ? __('Sincroniza los catálogos para elegirlo') : __('Elige un tipo') }}</option>
                            @foreach ($tiposPunto as $opcion)
                                <option value="{{ $opcion->codigo }}">{{ $opcion->descripcion }}</option>
                            @endforeach
                        </select>
                    </x-campo>
                    <x-campo etiqueta="Nombre" campo="nombre">
                        <input id="nombre" class="input" type="text" wire:model="nombre" maxlength="120" placeholder="{{ __('Ej. Caja 2') }}" required>
                    </x-campo>
                    <x-campo etiqueta="Descripción" campo="descripcion">
                        <input id="descripcion" class="input" type="text" wire:model="descripcion" maxlength="255" required>
                    </x-campo>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrarPunto">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="registrarPunto"><x-icon name="check" /> {{ __('Registrar en el SIN') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($cerrando)
        <x-modal :titulo="__('Cerrar el punto de venta :codigo', ['codigo' => $cerrando->codigo])" descripcion="Se cierra en el SIN y ya no podrá emitir facturas. Su historial se conserva." cerrar="cancelarCierre">
            @if ($errorSin)<div class="notice" role="alert" style="margin-bottom:14px"><x-icon name="status-alert" /><div><b>{{ __('El SIN no aceptó la solicitud') }}</b><p>{{ $errorSin }}</p></div></div>@endif
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cancelarCierre">{{ __('Cancelar') }}</button>
                <button type="button" class="btn primary" wire:click="cerrarEnSin" wire:loading.attr="disabled">{{ __('Cerrar en el SIN') }}</button>
            </div>
        </x-modal>
    @endif

    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
