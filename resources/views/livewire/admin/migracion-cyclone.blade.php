<div @if ($copiandoFotos) wire:poll.750ms="copiarFotos" @endif>
    @php($n = fn ($valor) => \App\Support\Decimal::formatear((string) $valor))
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Superadministración') }}</div>
            <h1>{{ __('Migrar desde Cyclone') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Sucursales, productos, stock inicial, clientes y proveedores desde el respaldo de su sistema anterior. Ventas, caja y créditos empiezan desde cero.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('admin.empresas') }}" wire:navigate><x-icon name="arrow-left" /> {{ __('Empresas') }}</a>
        </div>
    </div>

    <div class="grid two" style="margin-bottom:18px;align-items:start">
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="building" /></span>{{ __('1. Empresa que recibe los datos') }}</div></div>
            <x-campo etiqueta="Empresa" campo="empresaId">
                <select id="empresaId" class="select" wire:model.live="empresaId">
                    <option value="">{{ __('Elige la empresa…') }}</option>
                    @if ($empresa && ! $disponibles->contains('id', $empresa->id) && ! $yaMigradas->contains('id', $empresa->id))
                        <option value="{{ $empresa->id }}">{{ $empresa->nombre }} · {{ __('ya tiene datos') }}</option>
                    @endif
                    @if ($disponibles->isNotEmpty())
                        <optgroup label="{{ __('Sin datos todavía') }}">
                            @foreach ($disponibles as $opcion)
                                <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if ($yaMigradas->isNotEmpty())
                        <optgroup label="{{ __('Ya migradas') }}">
                            @foreach ($yaMigradas as $opcion)
                                <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            </x-campo>
            @if ($empresa && $administrador)
                <p class="subtle" style="margin:12px 0 0">{{ __('Queda registrada a nombre de su administrador :nombre (:correo).', ['nombre' => $administrador->name, 'correo' => $administrador->email]) }}</p>
            @elseif ($empresa)
                <p class="auth-error" role="alert">{{ __('La empresa no tiene un administrador activo.') }}</p>
            @else
                <p class="subtle" style="margin:12px 0 0">{{ __('Primero créala en Empresas › Nueva empresa, con su administrador y las sucursales extra que necesite su plan.') }}</p>
            @endif
        </section>

        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="upload" /></span>{{ __('2. Respaldo de Cyclone') }}</div></div>
            @if ($respaldo)
                <div class="list-line">
                    <div class="item-name">
                        <span class="token"><x-icon name="table" size="sm" /></span>
                        <div><b>{{ $respaldoNombre }}</b><small>{{ __('Se lee sin ejecutarlo y se borra al migrar.') }}</small></div>
                    </div>
                    <button class="btn sm ghost" type="button" wire:click="quitarRespaldo" wire:loading.attr="disabled"><x-icon name="trash" size="sm" /> {{ __('Quitar') }}</button>
                </div>
            @elseif ($migrada)
                <p class="subtle" style="margin:0">{{ __('Esta empresa ya recibió su migración: no hace falta subir el respaldo de nuevo.') }}</p>
            @else
                <label class="upload-box" for="archivo" wire:loading.class="drag-over" wire:target="archivo" style="min-height:150px">
                    <x-icon name="upload" />
                    <b wire:loading.remove wire:target="archivo">{{ __('Elige el respaldo .sql') }}</b>
                    <b wire:loading wire:target="archivo">{{ __('Leyendo el respaldo...') }}</b>
                    <small>{{ __('El archivo que exporta phpMyAdmin (por ejemplo cyclone_datos.sql) · hasta 10 MB') }}</small>
                </label>
                <input id="archivo" type="file" accept=".sql,.txt" wire:model="archivo" hidden>
            @endif
            @error('archivo')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
            @error('respaldo')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
        </section>
    </div>

    @if ($vista !== [])
        <section class="card card-pad" style="margin-bottom:18px">
            <div class="card-head">
                <div class="heading"><span class="heading-icon"><x-icon name="eye" /></span>{{ __('3. Vista previa: todavía no se guardó nada') }}</div>
                <button class="btn sm ghost" type="button" wire:click="descargarInforme" wire:loading.attr="disabled" wire:target="descargarInforme"><x-icon name="download" size="sm" /> {{ __('Descargar informe') }}</button>
            </div>

            <div class="grid four" style="margin-bottom:18px">
                <article class="card stat">
                    <span class="stat-label">{{ __('Productos') }}</span>
                    <div class="stat-value">{{ $n($vista['productos']) }}</div>
                    <div class="stat-foot">{{ __(':activos activos · :inactivos inactivos', ['activos' => $n($vista['activos']), 'inactivos' => $n($vista['productos'] - $vista['activos'])]) }}</div>
                </article>
                <article class="card stat">
                    <span class="stat-label">{{ __('Fotos') }}</span>
                    <div class="stat-value">{{ $n($vista['con_foto']) }}</div>
                    <div class="stat-foot">{{ __('Del catálogo central de Cyclone') }}</div>
                </article>
                <article class="card stat">
                    <span class="stat-label">{{ __('Clientes') }}</span>
                    <div class="stat-value">{{ $n($vista['clientes']) }}</div>
                    <div class="stat-foot">{{ __('Repetidos ya unificados') }}</div>
                </article>
                <article class="card stat">
                    <span class="stat-label">{{ __('Proveedores') }}</span>
                    <div class="stat-value">{{ $n($vista['proveedores']) }}</div>
                    <div class="stat-foot">{{ __('Activos e inactivos') }}</div>
                </article>
            </div>

            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Sucursal') }}</th><th>{{ __('Código') }}</th><th>{{ __('En MoziStock') }}</th><th>{{ __('Productos con stock') }}</th><th>{{ __('Unidades (stock inicial)') }}</th></tr></thead>
                    <tbody>
                        @foreach ($vista['sucursales'] as $sucursal)
                            <tr wire:key="sucursal-{{ $sucursal['codigo'] }}">
                                <td><b>{{ $sucursal['nombre'] }}</b></td>
                                <td>{{ $sucursal['codigo'] }}</td>
                                <td>
                                    @if ($sucursal['matriz'] && ($revision['matriz'] ?? null))
                                        <span class="badge info">{{ __('Reutiliza «:nombre»', ['nombre' => $revision['matriz']]) }}</span>
                                    @else
                                        <span class="badge success">{{ __('Nueva') }}</span>
                                    @endif
                                </td>
                                <td>{{ $n($sucursal['productos']) }}</td>
                                <td>{{ $n($sucursal['unidades']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if (($revision['unidades_nuevas'] ?? []) !== [] || ($revision['categorias_nuevas'] ?? []) !== [])
                <p class="subtle" style="margin:14px 0 0">
                    @if (($revision['unidades_nuevas'] ?? []) !== []){{ __('Unidades nuevas: :lista.', ['lista' => rtrim(implode(', ', $revision['unidades_nuevas']), '.')]) }}@endif
                    @if (($revision['categorias_nuevas'] ?? []) !== []){{ __('Categorías nuevas: :lista.', ['lista' => rtrim(implode(', ', $revision['categorias_nuevas']), '.')]) }}@endif
                </p>
            @endif

            @if ($vista['observaciones'] !== [])
                <div class="divider"></div>
                <div class="heading" style="margin-bottom:6px">{{ __('Lo que no entra tal cual (detalle en el informe)') }}</div>
                @foreach ($vista['observaciones'] as $observacion)
                    <div class="list-line"><span>{{ $observacion['titulo'] }}</span><span class="badge warning">{{ $n($observacion['cantidad']) }}</span></div>
                @endforeach
            @endif

            @php($problemas = array_merge($vista['errores'], $revision['problemas'] ?? []))
            @if ($problemas !== [])
                <div class="notice caja-peligro" role="alert" style="margin-top:16px"><x-icon name="info" /><div>
                    <b>{{ __('No se puede migrar todavía') }}</b>
                    @foreach ($problemas as $problema)<p>{{ $problema }}</p>@endforeach
                    @if (collect($problemas)->contains(fn ($p) => str_contains($p, 'sucursales extra')))
                        <p><a class="accent-text" href="{{ route('admin.suscripciones') }}" wire:navigate>{{ __('Ir a Suscripciones para agregar sucursales extra') }}</a></p>
                    @endif
                </div></div>
            @elseif (! $empresa)
                <div class="notice" style="margin-top:16px"><x-icon name="info" /><div><b>{{ __('Elige la empresa para terminar la revisión') }}</b><p>{{ __('Se revisa que esté vacía y que su plan admita las sucursales.') }}</p></div></div>
            @endif

            @error('migracion')
                <div class="notice caja-peligro" role="alert" style="margin-top:16px"><x-icon name="info" /><div><b>{{ $message }}</b></div></div>
            @enderror

            <div class="modal-actions">
                <button class="btn primary" type="button" wire:click="confirmar" @disabled(! $puedeMigrar)><x-icon name="check" /> {{ __('Migrar') }}</button>
            </div>
        </section>
    @else
        @error('migracion')
            <div class="notice caja-peligro" role="alert" style="margin-bottom:18px"><x-icon name="info" /><div><b>{{ $message }}</b></div></div>
        @enderror
    @endif

    @if ($resultado)
        <section class="card card-pad" style="margin-bottom:18px">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="status-ok" /></span>{{ __('Migración guardada') }}</div></div>
            <div class="table-wrap">
                <table>
                    <tbody>
                        <tr><td>{{ __('Sucursales') }}</td><td><b>{{ __(':total (:nuevas nuevas)', ['total' => $resultado['sucursales'], 'nuevas' => $resultado['sucursales_nuevas']]) }}</b></td></tr>
                        <tr><td>{{ __('Productos') }}</td><td><b>{{ $n($resultado['productos']) }}</b></td></tr>
                        <tr><td>{{ __('Entradas «Inventario inicial»') }}</td><td><b>{{ implode(', ', $resultado['operaciones_stock']) }}</b></td></tr>
                        <tr><td>{{ __('Unidades en stock') }}</td><td><b>{{ $n($resultado['unidades_stock']) }}</b></td></tr>
                        <tr><td>{{ __('Clientes') }}</td><td><b>{{ $n($resultado['clientes']) }}</b></td></tr>
                        <tr><td>{{ __('Proveedores') }}</td><td><b>{{ $n($resultado['proveedores']) }}</b></td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    @elseif ($migrada)
        <div class="notice" style="margin-bottom:18px"><x-icon name="status-ok" /><div><b>{{ __('Esta empresa ya fue migrada desde Cyclone') }}</b><p>{{ __('La migración se hace una sola vez. Aquí solo se pueden completar las fotos que falten.') }}</p></div></div>
    @endif

    @if ($copiandoFotos || $fotos['total'] > 0 || $fotosPendientes > 0)
        @php($procesadas = $fotos['copiadas'] + count($fotos['fallidas']))
        <section class="card card-pad" style="margin-bottom:18px">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="image" /></span>{{ __('Fotos de los productos') }}</div></div>
            @if ($copiandoFotos || $fotos['total'] > 0)
                <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $fotos['total'] }}" aria-valuenow="{{ $procesadas }}">
                    <span style="width:{{ $fotos['total'] > 0 ? min(100, intdiv($procesadas * 100, $fotos['total'])) : 100 }}%"></span>
                </div>
                <p class="subtle" style="margin:10px 0 0">
                    @if ($copiandoFotos)
                        {{ __('Copiando fotos: :hechas de :total. Deja esta página abierta.', ['hechas' => $procesadas, 'total' => $fotos['total']]) }}
                    @else
                        {{ __('Fotos copiadas: :copiadas de :total.', ['copiadas' => $fotos['copiadas'], 'total' => $fotos['total']]) }}
                    @endif
                </p>
                @if ($fotos['fallidas'] !== [])
                    <p class="subtle" style="margin:8px 0 0">{{ __('Sin copiar (:cantidad): :lista', ['cantidad' => count($fotos['fallidas']), 'lista' => implode(', ', array_slice($fotos['fallidas'], 0, 10))]) }}</p>
                @endif
            @endif
            @if (! $copiandoFotos && $fotosPendientes > 0)
                <div class="modal-actions">
                    <button class="btn soft" type="button" wire:click="iniciarFotos"><x-icon name="refresh" /> {{ __('Copiar las :cantidad fotos que faltan', ['cantidad' => $fotosPendientes]) }}</button>
                </div>
            @endif
        </section>
    @endif

    @if ($confirmando && $empresa)
        <x-modal :titulo="__('Migrar a :empresa', ['empresa' => $empresa->nombre])" cerrar="cancelar"
            :descripcion="__('Se crean :sucursales sucursales nuevas, :productos productos con su stock inicial, :clientes clientes y :proveedores proveedores. Se hace una sola vez: después no se puede repetir.', [
                'sucursales' => $revision['sucursales_nuevas'] ?? 0, 'productos' => $n($vista['productos']), 'clientes' => $n($vista['clientes']), 'proveedores' => $n($vista['proveedores']),
            ])">
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cancelar">{{ __('Cancelar') }}</button>
                <button type="button" class="btn primary" wire:click="migrar" wire:loading.attr="disabled" wire:target="migrar">
                    <span wire:loading.remove wire:target="migrar">{{ __('Migrar ahora') }}</span>
                    <span wire:loading wire:target="migrar">{{ __('Migrando...') }}</span>
                </button>
            </div>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
