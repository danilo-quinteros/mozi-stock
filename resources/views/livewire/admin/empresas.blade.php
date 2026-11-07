<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Superadministración') }}</div>
            <h1>{{ __('Empresas') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Control de acceso exclusivamente manual.') }}</p>
        </div>
        <div class="page-actions">
            <button class="btn ghost" type="button" wire:click="descargarCatalogoCyclone" wire:loading.attr="disabled" title="{{ __('Descargar catálogo central desde la API de Cyclone') }}">
                <x-icon name="history" /> {{ __('Descargar catálogo Cyclone') }}
            </button>
            <button class="btn primary" type="button" wire:click="crear"><x-icon name="plus" /> {{ __('Nueva empresa') }}</button>
        </div>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="store" /></span>{{ __('Empresas registradas') }}</div></div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar empresa, correo, NIT...') }}" aria-label="{{ __('Buscar empresas') }}">
            </div>
            <select class="select" wire:model.live="estado" aria-label="{{ __('Filtrar por acceso') }}">
                <option value="">{{ __('Todas las empresas') }}</option>
                <option value="activa">{{ __('Activas') }}</option>
                <option value="inactiva">{{ __('Inactivas') }}</option>
            </select>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Empresa') }}</th><th>{{ __('Correo') }}</th><th>{{ __('Mensualidad') }}</th><th>{{ __('Vencimiento') }}</th><th>{{ __('Acceso') }}</th><th>{{ __('Facturación SIAT') }}</th><th>{{ __('Revendedora Cyclone') }}</th><th>{{ __('Acciones') }}</th></tr></thead>
                <tbody>
                    @forelse ($empresas as $empresa)
                        <tr wire:key="empresa-{{ $empresa->id }}">
                            <td><b>{{ $empresa->nombre }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ $empresa->identificador() }}@if ($empresa->nit) · NIT {{ $empresa->nit }}@endif</small></td>
                            <td>{{ $empresa->email ?? '—' }}</td>
                            @if ($empresa->suscripcion)
                                <td>{{ \App\Support\Decimal::bs((string) $empresa->suscripcion->precio_mensual) }}</td>
                                <td>{{ $empresa->suscripcion->vencimiento->format('d/m/Y') }}@if ($empresa->suscripcion->enPrueba()) <span class="badge warning">{{ __('Prueba gratis') }}</span>@endif</td>
                            @else
                                <td colspan="2"><span class="badge info">{{ __('Sin plan configurado') }}</span></td>
                            @endif
                            <td><button type="button" class="switch" role="switch" aria-checked="{{ $empresa->activa ? 'true' : 'false' }}"
                                aria-label="{{ __('Activar o desactivar :nombre', ['nombre' => $empresa->nombre]) }}" wire:click="confirmarAcceso({{ $empresa->id }})"></button></td>
                            <td><span class="row" style="gap:8px;flex-wrap:nowrap">
                                <button type="button" class="switch" role="switch" aria-checked="{{ $empresa->siat_habilitado ? 'true' : 'false' }}"
                                    aria-label="{{ __('Habilitar o deshabilitar la facturación de :nombre', ['nombre' => $empresa->nombre]) }}" wire:click="confirmarSiat({{ $empresa->id }})"></button>
                                <a class="btn sm ghost" href="{{ route('admin.empresas.facturacion', $empresa) }}" wire:navigate title="{{ __('Datos fiscales de :nombre', ['nombre' => $empresa->nombre]) }}"><x-icon name="receipt" size="sm" /> {{ __('Datos') }}</a>
                            </span></td>
                            <td>
                                @php($esCyclone = isset($revendedorasCyclone[$empresa->id]))
                                <span class="row" style="gap:8px;flex-wrap:nowrap">
                                    <button type="button" class="switch" role="switch" aria-checked="{{ $esCyclone ? 'true' : 'false' }}"
                                        aria-label="{{ __('Vincular o desvincular a :nombre como revendedora de Cyclone', ['nombre' => $empresa->nombre]) }}" wire:click="confirmarCyclone({{ $empresa->id }})"></button>
                                    @if ($esCyclone)
                                        <button class="btn sm ghost" type="button" wire:click="sincronizarCyclone({{ $empresa->id }})" title="{{ __('Sincronizar productos ahora') }}">
                                            <x-icon name="history" size="sm" />
                                        </button>
                                    @endif
                                </span>
                            </td>
                            <td><span class="row" style="gap:6px;flex-wrap:nowrap">
                                <button class="btn sm ghost" type="button" wire:click="editar({{ $empresa->id }})"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</button>
                                @if ($empresa->activa && isset($administradores[$empresa->id]))
                                    <form method="POST" action="{{ route('impersonar', $administradores[$empresa->id]) }}">@csrf
                                        <button class="btn sm ghost" type="submit" title="{{ __('Entrar como el administrador de :nombre', ['nombre' => $empresa->nombre]) }}"><x-icon name="login" size="sm" /> {{ __('Entrar') }}</button>
                                    </form>
                                @endif
                            </span></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="empty">{{ __('No hay empresas que coincidan con el filtro.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($empresas->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $empresas->currentPage(), 'lastpage' => $empresas->lastPage()]) }}</span>
                <span class="row">
                    @unless ($empresas->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($empresas->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
        <div class="divider"></div>
        <div class="notice"><x-icon name="info" /><div><b>{{ __('Acceso manual, nunca automático') }}</b><p>{{ __('Vencimientos, recordatorios y comprobantes NO desactivan empresas. Solo este interruptor, con permisos de superadministrador, cambia el acceso.') }}</p></div></div>
    </section>

    @if ($formularioAbierto)
        <x-modal :titulo="$editandoId ? 'Editar empresa' : 'Registrar empresa'" cerrar="cerrarFormulario"
            :descripcion="$editandoId ? 'El acceso se cambia con el interruptor de la tabla.' : 'Los registros pertenecerán a un tenant aislado. Se crea también su primer administrador.'">
            <form wire:submit="guardar">
                <div class="fields">
                    <x-campo etiqueta="Nombre comercial" campo="nombre">
                        <input id="nombre" class="input" type="text" wire:model="nombre" maxlength="150" placeholder="{{ __('Nombre de la empresa') }}" required autofocus>
                    </x-campo>
                    <x-campo etiqueta="Razón social (opcional)" campo="razon_social">
                        <input id="razon_social" class="input" type="text" wire:model="razon_social" maxlength="200">
                    </x-campo>
                    <x-campo etiqueta="Correo de contacto" campo="email">
                        <input id="email" class="input" type="email" wire:model="email" maxlength="255" placeholder="{{ __('contacto@empresa.com') }}">
                    </x-campo>
                    <x-campo etiqueta="Teléfono (opcional)" campo="telefono">
                        <input id="telefono" class="input" type="tel" wire:model="telefono" maxlength="40">
                    </x-campo>
                    <x-campo etiqueta="NIT (opcional)" campo="nit">
                        <input id="nit" class="input" type="text" wire:model="nit" maxlength="30">
                    </x-campo>
                    @unless ($editandoId)
                        <x-campo etiqueta="Tipo de negocio" campo="rubro">
                            <select id="rubro" class="select" wire:model="rubro" required>
                                <option value="">{{ __('Elige el rubro…') }}</option>
                                @foreach ($rubros as $opcion)
                                    <option value="{{ $opcion->value }}">{{ $opcion->etiqueta() }} · {{ $opcion->descripcion() }}</option>
                                @endforeach
                            </select>
                        </x-campo>
                    @endunless
                </div>
                <div class="divider"></div>
                <div class="heading" style="margin-bottom:6px">{{ __('Suscripción mensual') }}</div>
                <p class="subtle" style="margin:0 0 14px">
                    @if ($vencimientoGuardado)
                        {{ __('El acceso no depende del vencimiento. Un precio nuevo no cambia comprobantes ya enviados.') }}
                    @else
                        {{ __('Opcional ahora. Sin plan, la empresa no recibe avisos ni puede enviar comprobantes.') }}
                    @endif
                </p>
                <div style="margin-bottom:12px">
                    <x-campo etiqueta="Plan de suscripción" campo="plan_id">
                        <select id="plan_id" class="select" wire:model.live="plan_id">
                            <option value="">{{ __('Personalizado / Sin plan fijo') }}</option>
                            @foreach ($planes as $p)
                                <option value="{{ $p->id }}">{{ $p->titulo }} ({{ \App\Support\Decimal::bs((string) $p->precio_mensual) }}/mes) · {{ $p->siat_habilitado ? __('Con SIAT') : __('Solo inventarios') }}</option>
                            @endforeach
                        </select>
                    </x-campo>
                </div>
                <div class="fields">
                    <x-campo etiqueta="Precio mensual (Bs)" campo="precio_mensual">
                        <input id="precio_mensual" class="input" type="text" inputmode="decimal" wire:model="precio_mensual" placeholder="149,00">
                    </x-campo>
                    <x-campo etiqueta="{{ $vencimientoGuardado ? __('Próximo vencimiento') : __('Primer vencimiento') }}" campo="vencimiento">
                        <input id="vencimiento" class="input" type="date" wire:model.live="vencimiento">
                    </x-campo>
                </div>
                @if ($this->cambiaVencimiento())
                    <x-campo etiqueta="Motivo del cambio de vencimiento" campo="motivo_vencimiento" style="margin-top:14px">
                        <textarea id="motivo_vencimiento" class="input" rows="2" wire:model="motivo_vencimiento" maxlength="300" placeholder="{{ __('Ej.: corrección por pago registrado fuera del sistema') }}"></textarea>
                    </x-campo>
                    <p class="subtle" style="margin:8px 0 0">{{ __('El día del nuevo vencimiento pasa a ser el día del ciclo mensual.') }}</p>
                @endif
                @unless ($editandoId)
                    <div class="divider"></div>
                    <div class="heading" style="margin-bottom:14px">{{ __('Administrador de la empresa') }}</div>
                    <div class="fields">
                        <x-campo etiqueta="Nombre" campo="admin_nombre">
                            <input id="admin_nombre" class="input" type="text" wire:model="admin_nombre" maxlength="255" required>
                        </x-campo>
                        <x-campo etiqueta="Correo de acceso" campo="admin_email">
                            <input id="admin_email" class="input" type="email" wire:model="admin_email" maxlength="255" placeholder="{{ __('admin@empresa.com') }}" required>
                        </x-campo>
                        <x-campo etiqueta="Contraseña inicial" campo="admin_password">
                            <input id="admin_password" class="input" type="password" wire:model="admin_password" autocomplete="new-password" required>
                        </x-campo>
                        <x-campo etiqueta="Confirmar contraseña" campo="admin_password_confirmation">
                            <input id="admin_password_confirmation" class="input" type="password" wire:model="admin_password_confirmation" autocomplete="new-password" required>
                        </x-campo>
                    </div>
                    <p class="subtle" style="margin:12px 0 0">{{ __('Mínimo 12 caracteres, con letras y números. Compártela por un canal seguro; el administrador puede cambiarla en Mi cuenta.') }}</p>
                @endunless
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrarFormulario">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar"><x-icon name="check" /> {{ __('Guardar') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($confirmando)
        <x-modal :titulo="__($confirmando->activa ? 'Desactivar acceso de :empresa' : 'Reactivar acceso de :empresa', ['empresa' => $confirmando->nombre])" cerrar="cancelarAcceso"
            :descripcion="$confirmando->activa
                ? 'Sus usuarios no podrán ingresar ni operar, incluso con una sesión abierta. No se borra ningún dato y puedes reactivarla cuando quieras.'
                : 'Sus usuarios activos podrán volver a ingresar. Todos sus datos se conservaron.'">
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cancelarAcceso">{{ __('Cancelar') }}</button>
                <button type="button" class="btn primary" wire:click="cambiarAcceso" wire:loading.attr="disabled">{{ $confirmando->activa ? __('Desactivar acceso') : __('Reactivar acceso') }}</button>
            </div>
        </x-modal>
    @endif

    @if ($confirmandoSiat)
        <x-modal :titulo="__($confirmandoSiat->siat_habilitado ? 'Deshabilitar la facturación de :empresa' : 'Habilitar la facturación de :empresa', ['empresa' => $confirmandoSiat->nombre])" cerrar="cancelarSiat"
            :descripcion="$confirmandoSiat->siat_habilitado
                ? 'Su administrador dejará de ver Facturación y no se consultará al SIN. Los datos fiscales, el token y los códigos se conservan.'
                : 'Su administrador podrá cargar el NIT, el token delegado, las sucursales y los puntos de venta del SIN. No sustituye la autorización del SIN.'">
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cancelarSiat">{{ __('Cancelar') }}</button>
                <button type="button" class="btn primary" wire:click="cambiarSiat" wire:loading.attr="disabled">{{ $confirmandoSiat->siat_habilitado ? __('Deshabilitar') : __('Habilitar') }}</button>
            </div>
        </x-modal>
    @endif

    @if ($confirmandoCyclone)
        @php($esRevendedora = isset($revendedorasCyclone[$confirmandoCyclone->id]))
        <x-modal :titulo="__($esRevendedora ? 'Desvincular :empresa de Cyclone' : 'Vincular :empresa como revendedora de Cyclone', ['empresa' => $confirmandoCyclone->nombre])" cerrar="cancelarCyclone"
            :descripcion="$esRevendedora
                ? 'La empresa dejará de recibir actualizaciones automáticas del catálogo de Cyclone. Sus productos, precios y existencias locales se conservan intactos.'
                : 'La empresa importará los productos del catálogo central de Cyclone. Sus precios y existencias locales permanecen bajo su propio control y nunca se comparten con Cyclone.'">
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cancelarCyclone">{{ __('Cancelar') }}</button>
                <button type="button" class="btn primary" wire:click="cambiarCyclone" wire:loading.attr="disabled">{{ $esRevendedora ? __('Desvincular') : __('Vincular e importar') }}</button>
            </div>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
