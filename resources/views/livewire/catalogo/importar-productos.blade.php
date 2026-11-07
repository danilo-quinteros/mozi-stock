@use('App\Support\Decimal')
@use('App\Models\ImportacionProductoFila')
@use('App\Domain\Catalogo\Importacion\CamposImportacion')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Catálogo') }}</div>
            <h1>{{ __('Importar productos') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Sube tu lista en Excel o CSV e indica qué columna es cada dato. No necesitas una plantilla.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('productos.index') }}"><x-icon name="arrow-left" size="sm" /> {{ __('Volver a productos') }}</a>
        </div>
    </div>

    @php
        $pasos = ['archivo' => 'Archivo', 'columnas' => 'Columnas', 'valores' => 'Categorías y unidades', 'revision' => 'Revisión', 'resultado' => 'Resultado'];
        $posicion = array_search($paso, array_keys($pasos), true);
    @endphp
    <ol class="import-steps" aria-label="{{ __('Pasos de la importación') }}">
        @foreach (array_keys($pasos) as $i => $clave)
            <li class="{{ $i < $posicion ? 'done' : ($i === $posicion ? 'active' : '') }}" @if ($i === $posicion) aria-current="step" @endif>
                <span>{{ $i < $posicion ? '✓' : $i + 1 }}</span>{{ __($pasos[$clave]) }}
            </li>
        @endforeach
    </ol>

    @error('importacion')
        <div class="notice import-alert" role="alert" style="margin-bottom:17px"><x-icon name="info" /><div><b>{{ $message }}</b></div></div>
    @enderror

    @if ($paso === 'archivo')
        <div class="rapid-layout">
            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="upload" /></span>{{ __('Archivo de productos') }}</div></div>
                <label class="upload-box import-upload" for="archivo" wire:loading.class="drag-over" wire:target="archivo">
                    <x-icon name="table" />
                    <b wire:loading.remove wire:target="archivo">{{ __('Elige tu archivo de productos') }}</b>
                    <b wire:loading wire:target="archivo">{{ __('Leyendo el archivo...') }}</b>
                    <small>{{ __('Excel (.xlsx), LibreOffice (.ods) o CSV · hasta 10 MB y :filas filas', ['filas' => '5.000']) }}</small>
                </label>
                <input id="archivo" type="file" accept=".xlsx,.ods,.csv,.txt" wire:model="archivo" hidden>
                @error('archivo')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
            </section>
            <aside class="rapid-aside">
                <section class="card card-pad">
                    <div class="heading">{{ __('Antes de subir') }}</div>
                    <ul class="product-guide" style="margin-top:12px">
                        <li>{{ __('Solo se necesitan el nombre y el precio de venta. Código, categoría, unidad, precios minorista y mayorista y stock son opcionales.') }}</li>
                        <li>{{ __('La fila de títulos se reconoce sola. Si tu lista tiene un título arriba, en el siguiente paso puedes indicar la fila correcta.') }}</li>
                        <li>{{ __('Si tus códigos tienen ceros a la izquierda, da formato de Texto a esa columna en Excel antes de guardar.') }}</li>
                        <li>{{ __('Nada se guarda en tu catálogo hasta que confirmes en el último paso.') }}</li>
                    </ul>
                </section>
            </aside>
        </div>

        @if ($recientes->isNotEmpty())
            <section class="card card-pad" style="margin-top:17px">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="history" /></span>{{ __('Importaciones recientes') }}</div></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Archivo') }}</th><th>{{ __('Usuario') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Productos creados') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                        <tbody>
                            @foreach ($recientes as $reciente)
                                <tr wire:key="reciente-{{ $reciente->id }}">
                                    <td>{{ $reciente->created_at?->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</td>
                                    <td><b>{{ $reciente->archivo_nombre }}</b></td>
                                    <td>{{ $reciente->usuario?->name }}</td>
                                    <td>
                                        @if ($reciente->estado === \App\Models\ImportacionProducto::COMPLETADA)
                                            <span class="badge success">{{ __('Completada') }}</span>
                                        @else
                                            <span class="badge warning">{{ __('Sin terminar') }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $reciente->resumen['resultado']['creados'] ?? '—' }}</td>
                                    <td><a class="link" href="{{ route('productos.importar', ['id' => $reciente->id]) }}" wire:navigate>{{ $reciente->abierta() ? __('Continuar') : __('Ver') }}</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif

    @if ($paso === 'columnas')
        <section class="card card-pad">
            <div class="card-head">
                <div>
                    <div class="heading"><span class="heading-icon"><x-icon name="table" /></span>{{ __('¿Qué dato tiene cada columna?') }}</div>
                    <p class="subtle" style="margin:9px 0 0">{{ __('Revisamos los títulos de «:archivo» y propusimos una asignación. Corrige lo que haga falta; las columnas que no necesitas quedan en «No importar».', ['archivo' => $importacion->archivo_nombre]) }}</p>
                </div>
            </div>

            <div class="fields three" style="margin-bottom:18px">
                @if (count($importacion->hojas ?? []) > 1)
                    <x-campo etiqueta="Hoja" campo="hoja">
                        <select id="hoja" class="select" wire:model.live="hoja">
                            @foreach ($importacion->hojas as $opcion)
                                <option value="{{ $opcion['indice'] }}">{{ $opcion['nombre'] }}</option>
                            @endforeach
                        </select>
                    </x-campo>
                @endif
                <x-campo etiqueta="Fila de títulos" campo="fila_encabezados">
                    <select id="fila_encabezados" class="select" wire:model.live="fila_encabezados">
                        @foreach ($primerasFilas as $opcion)
                            <option value="{{ $opcion->fila }}">{{ __('Fila :n', ['n' => $opcion->fila]) }} · {{ \Illuminate\Support\Str::limit(implode(' · ', array_slice($opcion->celdas, 0, 3)), 48) }}</option>
                        @endforeach
                    </select>
                </x-campo>
                <x-campo etiqueta="Unidad si la fila no indica una" campo="unidad_defecto_id">
                    <select id="unidad_defecto_id" class="select" wire:model="unidad_defecto_id">
                        <option value="">{{ __('Elige una unidad') }}</option>
                        @foreach ($unidadesActivas as $opcion)
                            <option value="{{ $opcion->id }}">{{ $opcion->nombre }} ({{ $opcion->abreviatura }})</option>
                        @endforeach
                    </select>
                </x-campo>
            </div>

            @error('mapeo')
                <div class="notice import-alert" role="alert" style="margin-bottom:15px"><x-icon name="info" /><div><b>{{ $message }}</b></div></div>
            @enderror

            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Columna') }}</th><th>{{ __('Título en tu archivo') }}</th><th>{{ __('Ejemplos') }}</th><th>{{ __('Dato en MoziStock') }}</th></tr></thead>
                    <tbody>
                        @foreach ($columnas as $columna)
                            <tr wire:key="columna-{{ $columna['indice'] }}" @class(['import-ignorada' => ($mapeo[$columna['indice']] ?? '') === ''])>
                                <td><span class="badge">{{ $columna['letra'] }}</span></td>
                                <td><b>{{ $columna['encabezado'] }}</b></td>
                                <td class="subtle">{{ \Illuminate\Support\Str::limit(implode(' · ', $columna['ejemplos']), 70) ?: '—' }}</td>
                                <td>
                                    <select class="select" wire:model.live="mapeo.{{ $columna['indice'] }}" aria-label="{{ __('Dato de la columna :letra', ['letra' => $columna['letra']]) }}">
                                        <option value="">{{ __('No importar') }}</option>
                                        @foreach ($campos as $campo => $etiqueta)
                                            <option value="{{ $campo }}">{{ __($etiqueta) }}{{ CamposImportacion::CAMPOS[$campo]['obligatorio'] ? ' *' : '' }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="subtle" style="margin-top:12px">{{ __('* Obligatorio. El contenido («350» en «Detergente 350 g») se combina con la unidad para formar el nombre completo.') }}</p>

            @if ($columnaStock !== false)
                @php $tituloStock = collect($columnas)->firstWhere('indice', (int) $columnaStock)['encabezado'] ?? ''; @endphp
                <div class="notice import-stock" style="margin-top:18px">
                    <x-icon name="box" />
                    <div class="grow">
                        <b>{{ __('Tu archivo tiene una columna de stock («:columna»)', ['columna' => $tituloStock]) }}</b>
                        <p>{{ __('¿Quieres que esas cantidades sean el stock inicial de los productos nuevos?') }}</p>
                        <div class="import-opciones">
                            <label class="import-opcion"><input type="radio" value="si" wire:model.live="usar_stock"> {{ __('Sí, cargarlas como stock inicial') }}</label>
                            <label class="import-opcion"><input type="radio" value="no" wire:model.live="usar_stock"> {{ __('No, importar solo los productos') }}</label>
                        </div>
                        @error('usar_stock')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                        @if ($usar_stock === 'si')
                            @if ($sucursales->isEmpty())
                                <p class="auth-error">{{ __('Primero registra una sucursal: el stock siempre pertenece a una sucursal.') }}</p>
                            @else
                                <x-campo etiqueta="Sucursal del stock inicial" campo="sucursal_stock_id" class="import-sucursal">
                                    <select id="sucursal_stock_id" class="select" wire:model="sucursal_stock_id">
                                        <option value="">{{ __('Elige una sucursal') }}</option>
                                        @foreach ($sucursales as $opcion)
                                            <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                                        @endforeach
                                    </select>
                                </x-campo>
                            @endif
                            <p>{{ __('Se registra como una entrada «Inventario inicial» en Ajustes y traspasos, con tu usuario y la nota de la importación. Solo afecta a los productos nuevos.') }}</p>
                        @endif
                    </div>
                </div>
            @endif

            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cancelar" wire:confirm="{{ __('¿Cancelar la importación? No se guardará nada.') }}">{{ __('Cancelar importación') }}</button>
                <button type="button" class="btn primary" wire:click="guardarColumnas" wire:loading.attr="disabled" wire:target="guardarColumnas">{{ __('Continuar') }} <x-icon name="arrow" size="sm" /></button>
            </div>
        </section>
    @endif

    @if ($paso === 'valores')
        <section class="card card-pad">
            <div class="card-head">
                <div>
                    <div class="heading"><span class="heading-icon"><x-icon name="tag" /></span>{{ __('Categorías y unidades del archivo') }}</div>
                    <p class="subtle" style="margin:9px 0 0">{{ __('Relaciona cada valor de tu archivo con una categoría o unidad de tu empresa, o crea una nueva. Las nuevas solo se crean al confirmar.') }}</p>
                </div>
            </div>

            @if ($categorias !== [])
                <div class="heading" style="margin-bottom:10px">{{ __('Categorías') }}</div>
                <div class="table-wrap" style="margin-bottom:22px">
                    <table>
                        <thead><tr><th>{{ __('En tu archivo') }}</th><th>{{ __('Filas') }}</th><th>{{ __('Qué hacer') }}</th><th>{{ __('Nombre de la categoría nueva') }}</th></tr></thead>
                        <tbody>
                            @foreach ($categorias as $i => $categoria)
                                <tr wire:key="categoria-{{ $categoria['clave'] }}">
                                    <td><b>{{ $categoria['original'] }}</b></td>
                                    <td>{{ $categoria['filas'] }}</td>
                                    <td>
                                        <select class="select" wire:model.live="categorias.{{ $i }}.destino" aria-label="{{ __('Categoría para :valor', ['valor' => $categoria['original']]) }}">
                                            <option value="crear">{{ __('Crear categoría nueva') }}</option>
                                            <option value="ninguna">{{ __('Sin categoría') }}</option>
                                            <optgroup label="{{ __('Usar una existente') }}">
                                                @foreach ($categoriasExistentes as $opcion)
                                                    <option value="{{ $opcion->id }}">{{ $opcion->nombre }}{{ $opcion->activa ? '' : ' ('.__('inactiva').')' }}</option>
                                                @endforeach
                                            </optgroup>
                                        </select>
                                        @if ($errors->has('categorias.'.$categoria['clave']))<p class="auth-error" role="alert">{{ $errors->first('categorias.'.$categoria['clave']) }}</p>@endif
                                    </td>
                                    <td>
                                        @if ($categoria['destino'] === 'crear')
                                            <input class="input" type="text" maxlength="80" wire:model.blur="categorias.{{ $i }}.nombre" aria-label="{{ __('Nombre de la categoría nueva') }}">
                                        @else
                                            <span class="subtle">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($unidades !== [])
                <div class="heading" style="margin-bottom:10px">{{ __('Unidades de medida') }}</div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('En tu archivo') }}</th><th>{{ __('Filas') }}</th><th>{{ __('Qué hacer') }}</th><th>{{ __('Unidad nueva') }}</th></tr></thead>
                        <tbody>
                            @foreach ($unidades as $i => $unidad)
                                <tr wire:key="unidad-{{ $unidad['clave'] }}">
                                    <td><b>{{ $unidad['original'] }}</b></td>
                                    <td>{{ $unidad['filas'] }}</td>
                                    <td>
                                        <select class="select" wire:model.live="unidades.{{ $i }}.destino" aria-label="{{ __('Unidad para :valor', ['valor' => $unidad['original']]) }}">
                                            <option value="crear">{{ __('Crear unidad nueva') }}</option>
                                            <optgroup label="{{ __('Usar una existente') }}">
                                                @foreach ($unidadesActivas as $opcion)
                                                    <option value="{{ $opcion->id }}">{{ $opcion->nombre }} ({{ $opcion->abreviatura }})</option>
                                                @endforeach
                                            </optgroup>
                                        </select>
                                        @if ($errors->has('unidades.'.$unidad['clave']))<p class="auth-error" role="alert">{{ $errors->first('unidades.'.$unidad['clave']) }}</p>@endif
                                    </td>
                                    <td>
                                        @if ($unidad['destino'] === 'crear')
                                            <div class="import-unidad-nueva">
                                                <input class="input" type="text" maxlength="60" wire:model.blur="unidades.{{ $i }}.nombre" placeholder="{{ __('Nombre') }}" aria-label="{{ __('Nombre de la unidad nueva') }}">
                                                <input class="input" type="text" maxlength="15" wire:model.blur="unidades.{{ $i }}.abreviatura" placeholder="{{ __('Abreviatura') }}" aria-label="{{ __('Abreviatura de la unidad nueva') }}">
                                                <label class="import-opcion"><input type="checkbox" wire:model="unidades.{{ $i }}.decimales"> {{ __('Admite decimales') }}</label>
                                            </div>
                                        @else
                                            <span class="subtle">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="volverAColumnas"><x-icon name="arrow-left" size="sm" /> {{ __('Volver a columnas') }}</button>
                <button type="button" class="btn primary" wire:click="guardarValores" wire:loading.attr="disabled" wire:target="guardarValores">{{ __('Revisar productos') }} <x-icon name="arrow" size="sm" /></button>
            </div>
        </section>
    @endif

    @if ($paso === 'revision' || $paso === 'resultado')
        @php
            $usaStock = $importacion->usar_stock === true;
            $resultado = $resumen['resultado'] ?? null;
        @endphp

        @if ($paso === 'resultado')
            <section class="card card-pad" style="margin-bottom:17px">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="check" /></span>{{ __('Importación completada') }}</div></div>
                <div class="confirm-grid">
                    <div><span class="subtle">{{ __('Productos creados') }}</span><b>{{ $resultado['creados'] ?? 0 }}</b></div>
                    <div><span class="subtle">{{ __('Categorías creadas') }}</span><b>{{ $resultado['categorias_creadas'] ?? 0 }}</b></div>
                    <div><span class="subtle">{{ __('Unidades creadas') }}</span><b>{{ $resultado['unidades_creadas'] ?? 0 }}</b></div>
                    <div><span class="subtle">{{ __('Filas omitidas') }}</span><b>{{ ($resultado['omitidas_error'] ?? 0) + ($resultado['omitidas_existentes'] ?? 0) }}</b></div>
                </div>
                @if (! empty($resultado['stock']))
                    <div class="notice"><x-icon name="box" /><div>
                        <b>{{ __('Stock inicial cargado en :sucursal para :total productos', ['sucursal' => $resultado['stock']['sucursal'], 'total' => $resultado['stock']['productos']]) }}</b>
                        <p>{{ __('Operación :codigos en Ajustes y traspasos.', ['codigos' => implode(', ', $resultado['stock']['operaciones'])]) }} <a class="link" href="{{ route('inventario.movimientos') }}">{{ __('Ver historial') }} <x-icon name="arrow" size="sm" /></a></p>
                    </div></div>
                @endif
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="nuevaImportacion"><x-icon name="upload" size="sm" /> {{ __('Importar otro archivo') }}</button>
                    <a class="btn primary" href="{{ route('productos.index') }}">{{ __('Ver productos') }} <x-icon name="arrow" size="sm" /></a>
                </div>
            </section>
        @else
            <div class="grid four" style="margin-bottom:17px">
                <article class="card stat"><span class="stat-label">{{ __('Filas leídas') }}</span><div class="stat-value">{{ $resumen['total'] ?? 0 }}</div><div class="stat-foot">{{ $importacion->archivo_nombre }}</div></article>
                <article class="card stat"><span class="stat-label">{{ __('Listas para importar') }}</span><div class="stat-value success-text">{{ $resumen['validas'] ?? 0 }}</div><div class="stat-foot">{{ __(':n con advertencias', ['n' => $resumen['advertencias'] ?? 0]) }}</div></article>
                <article class="card stat"><span class="stat-label">{{ __('Con errores') }}</span><div class="stat-value {{ ($resumen['errores'] ?? 0) > 0 ? 'danger-text' : '' }}">{{ $resumen['errores'] ?? 0 }}</div><div class="stat-foot">{{ __('No se importan') }}</div></article>
                <article class="card stat"><span class="stat-label">{{ __('Ya existen') }}</span><div class="stat-value">{{ $resumen['existentes'] ?? 0 }}</div><div class="stat-foot">{{ __('Mismo código o código de barras') }}</div></article>
            </div>
        @endif

        <section class="card card-pad">
            <div class="card-head">
                <div>
                    <div class="heading"><span class="heading-icon"><x-icon name="eye" /></span>{{ $paso === 'resultado' ? __('Detalle por fila') : __('Revisa antes de importar') }}</div>
                    @if ($paso === 'revision')
                        <p class="subtle" style="margin:9px 0 0">
                            {{ implode(', ', array_filter([
                                __('Se crearán :productos productos', ['productos' => $resumen['validas'] ?? 0]),
                                ($resumen['categorias_nuevas'] ?? 0) > 0 ? __(':n categorías nuevas', ['n' => $resumen['categorias_nuevas']]) : null,
                                ($resumen['unidades_nuevas'] ?? 0) > 0 ? __(':n unidades nuevas', ['n' => $resumen['unidades_nuevas']]) : null,
                            ])) }}.
                            @if ($usaStock)
                                {{ __('Stock inicial: :n productos en :sucursal.', ['n' => $resumen['con_stock'] ?? 0, 'sucursal' => $sucursalStock]) }}
                            @else
                                {{ __('Sin stock inicial.') }}
                            @endif
                        </p>
                    @endif
                </div>
            </div>

            <div class="tabs" role="tablist">
                @foreach (['todas' => ['Todas', $resumen['total'] ?? 0], 'validas' => ['Correctas', $resumen['validas'] ?? 0], 'advertencias' => ['Con advertencias', $resumen['advertencias'] ?? 0], 'errores' => ['Con errores', $resumen['errores'] ?? 0], 'existentes' => ['Ya existen', $resumen['existentes'] ?? 0]] as $clave => [$etiqueta, $cuenta])
                    <button type="button" role="tab" class="tab {{ $filtro === $clave ? 'active' : '' }}" aria-selected="{{ $filtro === $clave ? 'true' : 'false' }}" wire:click="$set('filtro', '{{ $clave }}')">{{ __($etiqueta) }} · {{ $cuenta }}</button>
                @endforeach
            </div>

            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>{{ __('Fila') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Producto / código') }}</th><th>{{ __('Categoría') }}</th><th>{{ __('Unidad') }}</th>
                        <th>{{ __('Consumidor') }}</th><th>{{ __('Minorista') }}</th><th>{{ __('Mayorista') }}</th>@if ($usaStock)<th>{{ __('Stock inicial') }}</th>@endif<th>{{ __('Observaciones') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            @php $d = $fila->normalizado ?? []; @endphp
                            <tr wire:key="fila-{{ $fila->id }}">
                                <td>{{ $fila->fila }}</td>
                                <td>
                                    @if ($fila->estado === ImportacionProductoFila::ERROR)
                                        <span class="badge danger">{{ __('Error') }}</span>
                                    @elseif ($fila->estado === ImportacionProductoFila::EXISTENTE)
                                        <span class="badge info">{{ __('Ya existe') }}</span>
                                    @elseif ($paso === 'resultado' && $fila->producto_id)
                                        <span class="badge success">{{ __('Creado') }}</span>
                                    @else
                                        <span class="badge success">{{ __('Correcta') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <b>{{ \App\Models\Producto::formatoNombreCompleto((string) ($d['nombre'] ?? '—'), $d['contenido'] ?? null, $d['contenido'] ?? null ? ($d['unidad_texto'] ?? '') : '') }}</b>
                                    <small>{{ implode(' · ', array_filter([$d['sku'] ?? null, $d['codigo_barras'] ?? null])) ?: __('Sin código') }}</small>
                                </td>
                                <td>{{ $d['categoria_texto'] ?? '—' }}</td>
                                <td>{{ $d['unidad_texto'] ?? '—' }}@if (($d['tipo_venta'] ?? 'unidad') === 'medida')<small>{{ __('Por medida') }}</small>@endif</td>
                                <td>{{ isset($d['precio_consumidor']) ? Decimal::bs($d['precio_consumidor']) : '—' }}</td>
                                <td>{{ isset($d['precio_minorista']) ? Decimal::bs($d['precio_minorista']) : '—' }}</td>
                                <td>{{ isset($d['precio_mayorista']) ? Decimal::bs($d['precio_mayorista']) : '—' }}</td>
                                @if ($usaStock)<td>{{ isset($d['stock']) ? Decimal::formatear($d['stock']) : '—' }}</td>@endif
                                <td class="import-mensajes">
                                    @foreach ($fila->errores ?? [] as $mensaje)<span class="danger-text">{{ $mensaje }}</span>@endforeach
                                    @foreach ($fila->advertencias ?? [] as $mensaje)<span class="subtle">{{ $mensaje }}</span>@endforeach
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $usaStock ? 10 : 9 }}"><div class="empty">{{ __('No hay filas en esta vista.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $filas->links(data: ['scrollTo' => false]) }}

            @if ($paso === 'revision')
                @error('confirmar')
                    <div class="notice import-alert" role="alert" style="margin-top:17px"><x-icon name="info" /><div><b>{{ $message }}</b></div></div>
                @enderror

                @if (($resumen['validas'] ?? 0) === 0)
                    <div class="notice import-alert" style="margin-top:17px"><x-icon name="info" /><div>
                        <b>{{ __('No hay filas listas para importar') }}</b>
                        <p>{{ __('Corrige los errores en tu archivo y vuelve a subirlo, o revisa la asignación de columnas.') }}</p>
                    </div></div>
                @elseif (($resumen['errores'] ?? 0) > 0)
                    <div class="notice import-alert" style="margin-top:17px"><x-icon name="info" /><div>
                        <b>{{ trans_choice('{1} Hay 1 fila con errores. ¿Qué quieres hacer?|[2,*] Hay :n filas con errores. ¿Qué quieres hacer?', $resumen['errores'], ['n' => $resumen['errores']]) }}</b>
                        <p>{{ __('Puedes importar ahora solo las filas correctas y luego subir las demás corregidas, o corregir el archivo completo y volver a subirlo.') }}</p>
                    </div></div>
                @endif

                <div class="modal-actions import-decision">
                    <button type="button" class="btn ghost" wire:click="cancelar" wire:confirm="{{ __('¿Cancelar la importación? No se guardará nada.') }}">{{ __('Cancelar importación') }}</button>
                    <button type="button" class="btn ghost" wire:click="volverAColumnas"><x-icon name="arrow-left" size="sm" /> {{ __('Columnas') }}</button>
                    @if (($importacion->valores['categorias'] ?? []) !== [] || ($importacion->valores['unidades'] ?? []) !== [])
                        <button type="button" class="btn ghost" wire:click="volverAValores"><x-icon name="arrow-left" size="sm" /> {{ __('Categorías y unidades') }}</button>
                    @endif
                    @if (($resumen['errores'] ?? 0) > 0)
                        <button type="button" class="btn ghost" wire:click="descargarErrores"><x-icon name="download" size="sm" /> {{ __('Descargar filas con errores') }}</button>
                    @endif
                    @if (($resumen['validas'] ?? 0) > 0)
                        <button type="button" class="btn primary" wire:click="pedirConfirmacion({{ ($resumen['errores'] ?? 0) > 0 ? 'true' : 'false' }})">
                            <x-icon name="check" size="sm" />
                            {{ ($resumen['errores'] ?? 0) > 0
                                ? trans_choice('{1} Importar solo la fila correcta|[2,*] Importar solo las :n correctas', $resumen['validas'], ['n' => $resumen['validas']])
                                : trans_choice('{1} Importar 1 producto|[2,*] Importar :n productos', $resumen['validas'], ['n' => $resumen['validas']]) }}
                        </button>
                    @endif
                </div>
            @endif
        </section>
    @endif

    @if ($confirmando && $paso === 'revision')
        <x-modal titulo="Confirmar importación" descripcion="Al confirmar se crean los productos en una sola operación. Esta pantalla no puede deshacerla." cerrar="$set('confirmando', false)">
            <div class="confirm-grid">
                <div><span class="subtle">{{ __('Productos nuevos') }}</span><b>{{ $resumen['validas'] ?? 0 }}</b></div>
                <div><span class="subtle">{{ __('Categorías nuevas') }}</span><b>{{ $resumen['categorias_nuevas'] ?? 0 }}</b></div>
                <div><span class="subtle">{{ __('Unidades nuevas') }}</span><b>{{ $resumen['unidades_nuevas'] ?? 0 }}</b></div>
                <div><span class="subtle">{{ __('Stock inicial') }}</span><b>{{ $importacion->usar_stock && ($resumen['con_stock'] ?? 0) > 0 ? $sucursalStock : __('No') }}</b></div>
            </div>
            @if (($resumen['errores'] ?? 0) + ($resumen['existentes'] ?? 0) > 0)
                <p class="subtle">{{ __('Se omitirán :errores filas con errores y :existentes que ya existen en tu catálogo.', ['errores' => $resumen['errores'] ?? 0, 'existentes' => $resumen['existentes'] ?? 0]) }}</p>
            @endif
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="$set('confirmando', false)">{{ __('Volver') }}</button>
                <button type="button" class="btn primary" wire:click="confirmar" wire:loading.attr="disabled" wire:target="confirmar"><x-icon name="check" /> {{ __('Importar') }}</button>
            </div>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
