@use('App\Support\Decimal')
@use('App\Models\CuentaPagar')
@use('App\Domain\CuentasPagar\Consultas\CuentasPorPagar')
@php($etiquetas = ['anulada' => ['', 'Anulada'], 'pagada' => ['success', 'Pagada'], 'vencida' => ['danger', 'Vencida'], 'parcial' => ['warning', 'Parcial'], 'pendiente' => ['info', 'Pendiente']])
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Ventas y finanzas') }}</div>
            <h1>{{ __('Cuentas por pagar') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Deudas con proveedores y acreedores: compras y egresos con saldo y obligaciones manuales.') }}</p>
        </div>
        <div class="page-actions">
            <button class="btn primary" type="button" wire:click="nuevaObligacion"><x-icon name="plus" /> {{ __('Nueva obligación') }}</button>
        </div>
    </div>

    <div class="grid four" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Total por pagar') }}</span><div class="stat-value">{{ Decimal::bs($resumen['pendiente']) }}</div><div class="stat-foot">{{ __('Saldo pendiente de todas las cuentas') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Vencido') }}</span><div class="stat-value accent-text">{{ Decimal::bs($resumen['vencido']) }}</div><div class="stat-foot">{{ __('Saldo con vencimiento pasado') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Por vencer') }}</span><div class="stat-value">{{ Decimal::bs($resumen['por_vencer']) }}</div><div class="stat-foot">{{ __('Vence hoy o más adelante') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Cuentas abiertas') }}</span><div class="stat-value">{{ $resumen['abiertas'] }}</div><div class="stat-foot">{{ __('Con saldo pendiente') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="receipt" /></span>{{ __('Obligaciones') }}</div>
            <a class="btn ghost sm" href="{{ route('cuentas-pagar.exportar', array_filter(['q' => trim($buscar), 'estado' => $estado === 'todas' ? null : $estado, 'sucursal' => $sucursal, 'proveedor' => $proveedor, 'origen' => $origen, 'desde' => $desde, 'hasta' => $hasta])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
        </div>
        <div class="tabs" role="tablist" aria-label="{{ __('Filtrar cuentas por estado') }}">
            @foreach (['todas' => 'Todas', 'pendientes' => 'Pendientes', 'parciales' => 'Parciales', 'vencidas' => 'Vencidas', 'pagadas' => 'Pagadas', 'anuladas' => 'Anuladas'] as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $estado === $clave ? 'active' : '' }}" aria-selected="{{ $estado === $clave ? 'true' : 'false' }}" wire:click="$set('estado', '{{ $clave }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar acreedor, concepto o número') }}" aria-label="{{ __('Buscar cuentas por pagar') }}">
            </div>
            <select class="select" wire:model.live="proveedor" aria-label="{{ __('Filtrar acreedor') }}">
                <option value="">{{ __('Todos los acreedores') }}</option>
                @foreach ($proveedores as $opcion)
                    <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                @endforeach
            </select>
            <select class="select" wire:model.live="origen" aria-label="{{ __('Filtrar origen') }}">
                <option value="">{{ __('Todo origen') }}</option>
                <option value="compra">{{ __('Compras') }}</option>
                <option value="egreso">{{ __('Egresos') }}</option>
                <option value="manual">{{ __('Manuales') }}</option>
            </select>
            <select class="select" wire:model.live="sucursal" aria-label="{{ __('Filtrar sucursal') }}">
                <option value="">{{ __('Todas las sucursales') }}</option>
                @foreach ($sucursales as $opcion)
                    <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                @endforeach
            </select>
            <label class="row" style="gap:6px"><span class="subtle">{{ __('Vence desde') }}</span><input class="input" type="date" wire:model.live="desde" aria-label="{{ __('Vencimiento desde') }}"></label>
            <label class="row" style="gap:6px"><span class="subtle">{{ __('hasta') }}</span><input class="input" type="date" wire:model.live="hasta" aria-label="{{ __('Vencimiento hasta') }}"></label>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Cuenta') }}</th><th>{{ __('Acreedor') }}</th><th>{{ __('Origen') }}</th><th>{{ __('Vencimiento') }}</th><th>{{ __('Importe') }}</th><th>{{ __('Pagado') }}</th><th>{{ __('Saldo') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                <tbody>
                    @forelse ($cuentas as $cuenta)
                        @php([$tono, $texto] = $etiquetas[$cuenta->anulado_at !== null ? 'anulada' : CuentaPagar::estadoDe((string) $cuenta->saldo_pendiente, (string) $cuenta->pagado, $cuenta->vencimiento)])
                        <tr wire:key="cxp-{{ $cuenta->id }}">
                            <td><b>CXP-{{ str_pad((string) $cuenta->numero, 5, '0', STR_PAD_LEFT) }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ $cuenta->concepto }}</small></td>
                            <td>{{ $cuenta->proveedor }}<small style="display:block;color:var(--faint);margin-top:4px">{{ $cuenta->sucursal }}</small></td>
                            <td>{{ CuentasPorPagar::documento($cuenta) }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($cuenta->vencimiento)->format('d/m/Y') }}</td>
                            <td>{{ Decimal::bs((string) $cuenta->total_documento) }}</td>
                            <td>{{ Decimal::bs((string) $cuenta->pagado) }}</td>
                            <td><b @class(['accent-text' => ! in_array($texto, ['Pagada', 'Anulada'], true)])>{{ Decimal::bs((string) $cuenta->saldo_pendiente) }}</b></td>
                            <td><span class="badge {{ $tono }}">{{ __($texto) }}</span></td>
                            <td><a class="btn soft sm" href="{{ route('cuentas-pagar.ver', $cuenta->id) }}">{{ in_array($texto, ['Pagada', 'Anulada'], true) ? __('Ver historial') : __('Ver y pagar') }} <x-icon name="arrow" size="sm" /></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><div class="empty">{{ __('No hay cuentas por pagar que coincidan con el filtro.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($cuentas->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $cuentas->currentPage(), 'lastpage' => $cuentas->lastPage()]) }}</span>
                <span class="row">
                    @unless ($cuentas->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($cuentas->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>
    <div class="notice" style="margin-top:18px"><x-icon name="info" /><div><b>{{ __('Una deuda no es una salida de dinero') }}</b><p>{{ __('Caja y reportes cuentan solo lo pagado, en la fecha real de cada pago. Pagar una cuenta no crea otro egreso ni otra compra.') }}</p></div></div>

    @if ($formObligacion)
        <x-modal titulo="Nueva obligación" descripcion="Deuda con un proveedor que no nace de una compra ni de un egreso registrados en MoziStock (saldo anterior, préstamo, impuesto). No mueve stock ni dinero." cerrar="cerrarObligacion">
            <form wire:submit="registrarObligacion">
                <div class="fields">
                    <x-campo etiqueta="Proveedor" campo="proveedor_id">
                        <select id="proveedor_id" class="select" wire:model="proveedor_id" required>
                            <option value="">{{ __('Selecciona el proveedor') }}</option>
                            @foreach ($proveedores->where('activo', true) as $opcion)
                                <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                            @endforeach
                        </select>
                        @include('livewire.partials.proveedor-rapido', ['parte' => 'ayuda'])
                    </x-campo>
                    <x-campo etiqueta="Sucursal" campo="sucursal_id">
                        <select id="sucursal_id" class="select" wire:model="sucursal_id">
                            @foreach ($sucursalesActivas as $opcion)
                                <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                            @endforeach
                        </select>
                    </x-campo>
                    @include('livewire.partials.proveedor-rapido', ['parte' => 'formulario'])
                    <x-campo etiqueta="Concepto" campo="concepto" style="grid-column:1/-1">
                        <input id="concepto" class="input" type="text" maxlength="150" wire:model="concepto" placeholder="{{ __('Ej. Saldo pendiente con proveedor antes de MoziStock') }}" required>
                    </x-campo>
                    <x-campo etiqueta="Fecha de registro" campo="fecha">
                        <input id="fecha" class="input" type="date" wire:model="fecha" required>
                    </x-campo>
                    <x-campo etiqueta="Vencimiento" campo="vencimiento">
                        <input id="vencimiento" class="input" type="date" wire:model="vencimiento" required>
                    </x-campo>
                    <x-campo etiqueta="Importe total (Bs)" campo="importe">
                        <input id="importe" class="input" type="text" inputmode="decimal" wire:model="importe" placeholder="0,00" required>
                    </x-campo>
                    <x-campo etiqueta="Ya pagado antes (Bs, opcional)" campo="pagado">
                        <input id="pagado" class="input" type="text" inputmode="decimal" wire:model="pagado" placeholder="0,00">
                    </x-campo>
                    <x-campo etiqueta="Respaldo (opcional)" campo="respaldo">
                        <input id="respaldo" class="input" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" wire:model="respaldo">
                    </x-campo>
                    <x-campo etiqueta="Nota (opcional)" campo="nota">
                        <input id="nota" class="input" type="text" maxlength="250" wire:model="nota">
                    </x-campo>
                </div>
                <p class="subtle" style="margin-top:12px;font-size:12px;line-height:1.6">{{ __('Lo pagado antes solo reduce el saldo inicial. Los pagos que hagas desde hoy se registran en la cuenta, con su fecha real.') }}</p>
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrarObligacion">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="registrarObligacion,respaldo"><x-icon name="check" /> {{ __('Registrar obligación') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
