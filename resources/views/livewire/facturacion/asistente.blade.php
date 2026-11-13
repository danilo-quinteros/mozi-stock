{{-- Asistente de conexión con el SIN. Los estados salen de App\Domain\Facturacion\VerificacionSiat: nada se marca a mano.
     «Verificado con el SIN» solo cuando el SIN lo confirmó; «Correcto» cuando solo MoziStock pudo revisarlo. --}}
@php
    $zona = config('mozistock.business_timezone');
    $pasos = [
        'modalidad' => ['titulo' => __('Modalidad'), 'sub' => __('Cómo factura tu empresa')],
        'token' => ['titulo' => __('Sistema y token'), 'sub' => __('Código y token delegado')],
        'firma' => ['titulo' => __('Firma digital'), 'sub' => __('Llave y certificado')],
        'revisar' => ['titulo' => __('Revisar'), 'sub' => __('Todo listo para facturar')],
    ];
    $hecho = fn (string $clave): bool => in_array($estado['pasos'][$clave]['estado'], ['verificado', 'correcto', 'no_aplica'], true);
    $insignias = [
        'verificado' => ['success', __('Verificado con el SIN')],
        'correcto' => ['success', __('Correcto')],
        'error' => ['danger', __('Revisar')],
        'pendiente' => ['warning', __('Pendiente')],
        'no_aplica' => ['', __('No aplica')],
    ];
    $sistema = $estado['sistema'];
    $electronica = $modalidad === 'electronica';
    $verificadoSin = $estado['sin'] !== null && $estado['sin']['resultado'] === 'aceptado';
@endphp
<div class="stack">
    <section class="card asis" aria-labelledby="asis-titulo">
        <header class="asis-top">
            <span class="asis-sello"><x-icon name="receipt" /></span>
            <div class="grow">
                <h2 id="asis-titulo">{{ __('Conecta tu empresa con Impuestos Nacionales') }}</h2>
                <p>{{ $configuracion?->razon_social ?: $empresa->nombre }}@if ($configuracion?->nit) · NIT {{ $configuracion->nit }}@endif · {{ __('Datos que te entregó el SIAT para facturar desde MoziStock.') }}</p>
            </div>
            @if ($estado['listo'] && $verificadoSin)
                <span class="badge success"><x-icon name="status-ok" size="sm" /> {{ __('Verificada con el SIN') }}</span>
            @elseif ($estado['listo'])
                <span class="badge success">{{ __('Configuración correcta') }}</span>
            @else
                <span class="badge warning">{{ __('Configuración pendiente') }}</span>
            @endif
        </header>

        <div class="asis-cuerpo">
            <nav class="asis-pasos" aria-label="{{ __('Pasos de la conexión con el SIN') }}">
                @foreach ($pasos as $clave => $info)
                    @php($estadoPaso = $estado['pasos'][$clave]['estado'])
                    <button type="button" wire:key="paso-{{ $clave }}" wire:click="ir('{{ $clave }}')"
                        @class(['asis-paso', 'active' => $paso === $clave, 'error' => $estadoPaso === 'error'])
                        @if ($paso === $clave) aria-current="step" @endif>
                        <span @class(['asis-num', 'done' => $hecho($clave), 'error' => $estadoPaso === 'error'])>
                            @if ($estadoPaso === 'error')
                                <x-icon name="x" size="sm" />
                            @elseif ($hecho($clave))
                                <x-icon name="check" size="sm" />
                            @else
                                {{ $loop->iteration }}
                            @endif
                        </span>
                        <span class="grow">
                            <b>{{ $info['titulo'] }}</b>
                            <small>{{ $estadoPaso === 'error' ? __('Hay que revisarlo') : ($clave === 'firma' && $estadoPaso === 'no_aplica' ? __('No hace falta') : $info['sub']) }}</small>
                        </span>
                    </button>
                @endforeach
            </nav>

            <div class="asis-main">
                <div class="asis-panel">
                    <fieldset @disabled(! $puedeConfigurar)>
                    @if ($paso === 'modalidad')
                        <h3>{{ __('Paso 1 · Modalidad') }}</h3>
                        <p class="asis-lead">{{ __('Es la que figura en tu registro del SIAT. Si no la sabes, está en la constancia de inscripción a la facturación en línea.') }}</p>

                        <div class="asis-opciones" role="radiogroup" aria-label="{{ __('Modalidad de facturación') }}">
                            @foreach ([
                                'electronica' => [__('Electrónica en línea'), __('Cada factura se firma digitalmente antes de enviarse a Impuestos.'), __('Pide firma digital')],
                                'computarizada' => [__('Computarizada en línea'), __('La factura se envía sin firma. Alcanza con el código de sistema y el token.'), __('Sin firma')],
                            ] as $valor => [$titulo, $texto, $etiqueta])
                                <button type="button" role="radio" aria-checked="{{ $modalidad === $valor ? 'true' : 'false' }}" wire:click="elegirModalidad('{{ $valor }}')" @class(['asis-opcion', 'active' => $modalidad === $valor])>
                                    <b>{{ $titulo }}</b>
                                    <span>{{ $texto }}</span>
                                    <em>{{ $etiqueta }}</em>
                                </button>
                            @endforeach
                        </div>

                        @if ($configuracion === null || $configuracion->modalidad->value !== $modalidad)
                            <div class="asis-nota"><x-icon name="info" size="sm" /> {{ __('El cambio se guarda en el paso siguiente.') }}</div>
                        @endif
                    @elseif ($paso === 'token')
                        <h3>{{ __('Paso 2 · Sistema y token') }}</h3>
                        <p class="asis-lead">{{ __('En el portal SIAT, asocia tu NIT al sistema de MoziStock y genera un token delegado para ese sistema. Al guardar lo verificamos con Impuestos.') }}</p>

                        <div class="asis-datos">
                            <div class="asis-dato">
                                <small>{{ __('Código de sistema') }}</small>
                                @if ($sistema)
                                    <code>{{ $sistema->codigoSistema }}</code>
                                @else
                                    <b class="danger-text">{{ __('Sin configurar') }}</b>
                                @endif
                            </div>
                            <div class="asis-dato">
                                <small>{{ __('Ambiente') }}</small>
                                <b>{{ $sistema ? \App\Domain\Facturacion\VerificacionSiat::ambiente($sistema) : '—' }}</b>
                            </div>
                            <div class="asis-dato">
                                <small>{{ __('Modalidad elegida') }}</small>
                                <b>{{ \App\Enums\ModalidadFacturacion::from($modalidad)->etiqueta() }}</b>
                            </div>
                        </div>

                        <form wire:submit="guardarConexion" id="asis-form-token">
                            <div class="fields">
                                <x-campo etiqueta="NIT" campo="nit">
                                    <input id="nit" class="input" type="text" inputmode="numeric" wire:model="nit" maxlength="13" required>
                                </x-campo>
                                <x-campo etiqueta="Razón social" campo="razon_social">
                                    <input id="razon_social" class="input" type="text" wire:model="razon_social" maxlength="200" required>
                                </x-campo>
                                @if ($sucursalPorVincular)
                                    <x-campo etiqueta="Municipio" campo="municipio">
                                        <input id="municipio" class="input" type="text" wire:model="municipio" maxlength="100" required placeholder="{{ __('Ej.: Santa Cruz') }}" aria-describedby="municipio-ayuda">
                                        <small class="subtle" id="municipio-ayuda">{{ __('«:sucursal» queda como la casa matriz (sucursal 0) del SIN. Sale en la factura.', ['sucursal' => $sucursalPorVincular->nombre]) }}</small>
                                    </x-campo>
                                @endif
                                <x-campo :etiqueta="$configuracion?->tieneToken() ? 'Reemplazar token (opcional)' : 'Token delegado'" campo="token" class="asis-ancho">
                                    <input id="token" class="input" type="password" wire:model="token" autocomplete="off" spellcheck="false" placeholder="{{ $configuracion?->tieneToken() ? __('Déjalo vacío para mantener el token …:sufijo', ['sufijo' => $configuracion->token_sufijo]) : __('Pega aquí el token del portal SIAT') }}">
                                </x-campo>
                            </div>
                        </form>

                        @if ($configuracion?->tieneToken())
                            @php($pasoToken = $estado['pasos']['token'])
                            @php($faltanCatalogos = $verificadoSin && $estado['catalogos'] !== [])
                            <div @class(['asis-resultado', $pasoToken['estado']])>
                                <x-icon :name="$pasoToken['estado'] === 'error' ? 'status-alert' : ($pasoToken['estado'] === 'verificado' ? 'status-ok' : 'info')" />
                                <div class="grow">
                                    <b>{{ match (true) {
                                        $faltanCatalogos => __('El SIN aceptó tu NIT y el token, pero faltan catálogos'),
                                        $pasoToken['estado'] === 'verificado' => __('El SIN aceptó tu NIT, el token y la modalidad'),
                                        $pasoToken['estado'] === 'error' => __('El token no funciona todavía'),
                                        default => __('Token cargado, sin confirmar con el SIN'),
                                    } }}</b>
                                    <p>
                                        @if ($pasoToken['estado'] === 'error')
                                            {{ $pasoToken['detalle'] }}
                                        @elseif ($faltanCatalogos)
                                            {{ __('No se sincronizaron: :catalogos. Sin ellos no se puede facturar.', ['catalogos' => implode(', ', $estado['catalogos'])]) }}
                                        @else
                                            {{ __('Termina en …:sufijo', ['sufijo' => $configuracion->token_sufijo]) }}@if ($configuracion->token_vence_en) · {{ __('vence el :fecha', ['fecha' => $configuracion->token_vence_en->timezone($zona)->format('d/m/Y')]) }}@endif.
                                            @if ($pasoToken['estado'] === 'correcto')
                                                {{ $estado['habilitada'] ? __('Usa «Verificar con el SIN» en Revisar para confirmarlo.') : __('Se verificará cuando MoziStudios habilite la facturación.') }}
                                            @elseif ($pasoToken['estado'] === 'verificado')
                                                {{ __('Catálogos del SIN sincronizados.') }}
                                            @endif
                                        @endif
                                    </p>
                                </div>
                                @if ($faltanCatalogos && $puedeConfigurar)
                                    <button type="button" class="btn soft sm" wire:click="reintentarCatalogos" wire:loading.attr="disabled" wire:target="reintentarCatalogos">
                                        <span wire:loading.remove wire:target="reintentarCatalogos"><x-icon name="refresh" size="sm" /> {{ __('Reintentar') }}</span>
                                        <span wire:loading wire:target="reintentarCatalogos">{{ __('Sincronizando…') }}</span>
                                    </button>
                                @endif
                            </div>
                        @endif
                        <p class="subtle asis-pie-nota">{{ __('El token se guarda cifrado y nunca se vuelve a mostrar; solo verás sus últimos 4 caracteres.') }}</p>
                    @elseif ($paso === 'firma')
                        <h3>{{ __('Paso 3 · Firma digital') }}</h3>
                        @if (! $electronica)
                            <p class="asis-lead">{{ __('Tu modalidad es computarizada: las facturas se envían sin firma. No tienes que cargar nada aquí.') }}</p>
                            <div class="asis-resultado no_aplica"><x-icon name="info" /><div><b>{{ __('No hace falta firma digital') }}</b><p>{{ __('Si tu empresa pasa a electrónica en línea, cambia la modalidad en el paso 1.') }}</p></div></div>
                        @else
                            <p class="asis-lead">{{ __('Sube el archivo .p12 (o .pfx) de tu firma digital y su contraseña. MoziStock comprueba que abra y esté vigente; Impuestos valida la firma con la primera factura.') }}</p>

                            @if ($certificado)
                                <div @class(['asis-resultado', $certificado['valido'] ? 'correcto' : 'error'])>
                                    <x-icon :name="$certificado['valido'] ? 'status-ok' : 'status-alert'" />
                                    <div>
                                        <b>{{ $certificado['valido'] ? __('Certificado correcto') : __('El certificado no sirve todavía') }}</b>
                                        <p>
                                            {{ $configuracion->certificado_nombre ?? 'certificado.p12' }}
                                            @if ($certificado['titular']) · {{ $certificado['titular'] }}@endif
                                            @if ($certificado['vence_en']) · {{ __('vence el :fecha', ['fecha' => $certificado['vence_en']->timezone($zona)->format('d/m/Y')]) }}@endif
                                            @unless ($certificado['valido']) · {{ $certificado['error'] }}@endunless
                                        </p>
                                    </div>
                                </div>
                            @endif

                            <form wire:submit="guardarFirma" id="asis-form-firma">
                                <div class="fields">
                                    <x-campo :etiqueta="$certificado ? 'Reemplazar archivo .p12 (opcional)' : 'Archivo .p12'" campo="certificado">
                                        <input id="certificado" class="input" type="file" wire:model="certificado" accept=".p12,.pfx">
                                        <div wire:loading wire:target="certificado" class="subtle" style="font-size:11px;margin-top:4px">{{ __('Cargando archivo…') }}</div>
                                    </x-campo>
                                    <x-campo etiqueta="Contraseña del certificado" campo="certificado_pass">
                                        <input id="certificado_pass" class="input" type="password" wire:model="certificado_pass" autocomplete="new-password" placeholder="{{ $certificado ? __('Déjala vacía para mantener la actual') : __('Contraseña del archivo .p12') }}">
                                    </x-campo>
                                </div>
                            </form>
                            <p class="subtle asis-pie-nota">{{ __('El archivo se guarda en almacenamiento privado y la contraseña, cifrada.') }}</p>
                        @endif
                    @else
                        <h3>{{ __('Paso 4 · Revisar') }}</h3>
                        <p class="asis-lead">{{ __('Lo que se puede comprobar con Impuestos se comprueba con el SIN; lo demás lo revisa MoziStock.') }}</p>

                        <div @class(['asis-resultado', 'grande', $estado['listo'] ? ($verificadoSin ? 'verificado' : 'correcto') : 'pendiente'])>
                            <x-icon :name="$estado['listo'] ? 'status-ok' : 'status-soon'" />
                            <div>
                                <b>{{ $estado['listo'] ? ($verificadoSin ? __('Todo listo: el SIN aceptó tu configuración') : __('Tu configuración está correcta')) : __('Todavía falta completar la configuración') }}</b>
                                <p>
                                    @if ($estado['listo'] && $verificadoSin)
                                        {{ __('Verificado el :fecha.', ['fecha' => $estado['sin']['en']?->timezone($zona)->format('d/m/Y H:i')]) }}
                                    @elseif ($estado['listo'])
                                        {{ $estado['habilitada'] ? __('Falta confirmarla con el SIN.') : __('Se confirmará con el SIN cuando MoziStudios habilite la facturación.') }}
                                    @elseif ($estado['pasos']['revisar']['detalle'] === __('Falta sucursal o actividad'))
                                        {{ __('Sin la sucursal registrada en el SIN o sin actividad y producto SIN, las ventas quedan con la factura pendiente.') }}
                                    @else
                                        {{ __('Los pasos marcados en la izquierda indican qué falta.') }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="asis-revision">
                            @foreach ($estado['revision'] as $punto)
                                @php([$clase, $texto] = $insignias[$punto['estado']])
                                <div class="asis-fila" wire:key="rev-{{ $punto['clave'] }}">
                                    <div class="grow"><b>{{ $punto['punto'] }}</b><small>{{ $punto['detalle'] }}</small></div>
                                    @if ($punto['clave'] === 'sucursales' && $punto['estado'] !== 'correcto' && $puedeConfigurar)
                                        {{-- En la guía, la sucursal se registra en su popup; en Facturación, en su pestaña. --}}
                                        @if ($embebido)
                                            <button type="button" class="btn soft sm" wire:click="$parent.abrir('sucursal-siat')">{{ __('Registrar') }} <x-icon name="arrow" size="sm" /></button>
                                        @else
                                            <button type="button" class="btn soft sm" wire:click="$parent.$set('seccion', 'puntos')">{{ __('Registrar') }} <x-icon name="arrow" size="sm" /></button>
                                        @endif
                                    @elseif ($punto['clave'] === 'actividad' && $punto['estado'] !== 'correcto' && $configuracion?->nit !== null && $puedeConfigurar)
                                        <button type="button" class="btn soft sm" x-on:click="document.getElementById('valores-factura')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); document.getElementById('actividad_economica')?.focus({ preventScroll: true })">{{ __('Elegir') }} <x-icon name="arrow" size="sm" /></button>
                                    @else
                                        <span class="badge {{ $clase }}">{{ $texto }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    </fieldset>
                </div>

                <div class="asis-acciones">
                    @php($orden = array_keys($pasos))
                    @php($indice = array_search($paso, $orden, true))
                    @if ($indice > 0)
                        <button type="button" class="btn ghost" wire:click="ir('{{ $orden[$indice - 1] }}')"><x-icon name="arrow-left" size="sm" /> {{ $pasos[$orden[$indice - 1]]['titulo'] }}</button>
                    @else
                        <span></span>
                    @endif

                    @if ($paso === 'modalidad')
                        <button type="button" class="btn primary" wire:click="ir('token')">{{ __('Siguiente: sistema y token') }} <x-icon name="arrow" size="sm" /></button>
                    @elseif ($paso === 'token' && $puedeConfigurar)
                        <button type="submit" form="asis-form-token" class="btn primary" wire:loading.attr="disabled" wire:target="guardarConexion">
                            <span wire:loading.remove wire:target="guardarConexion"><x-icon name="shield" size="sm" /> {{ __('Guardar y verificar') }}</span>
                            <span wire:loading wire:target="guardarConexion">{{ __('Verificando con el SIN…') }}</span>
                        </button>
                    @elseif ($paso === 'firma' && $electronica && $puedeConfigurar)
                        <button type="submit" form="asis-form-firma" class="btn primary" wire:loading.attr="disabled" wire:target="guardarFirma,certificado">
                            <span wire:loading.remove wire:target="guardarFirma"><x-icon name="lock" size="sm" /> {{ __('Guardar y comprobar firma') }}</span>
                            <span wire:loading wire:target="guardarFirma">{{ __('Comprobando…') }}</span>
                        </button>
                    @elseif ($paso === 'firma' || ($paso === 'token' && ! $puedeConfigurar))
                        <button type="button" class="btn primary" wire:click="ir('{{ $orden[$indice + 1] }}')">{{ __('Siguiente: :paso', ['paso' => mb_strtolower($pasos[$orden[$indice + 1]]['titulo'])]) }} <x-icon name="arrow" size="sm" /></button>
                    @elseif ($paso === 'revisar' && $puedeConfigurar && $estado['habilitada'] && $configuracion?->tieneToken())
                        <button type="button" class="btn primary" wire:click="verificarAhora" wire:loading.attr="disabled" wire:target="verificarAhora">
                            <span wire:loading.remove wire:target="verificarAhora"><x-icon name="refresh" size="sm" /> {{ __('Verificar con el SIN') }}</span>
                            <span wire:loading wire:target="verificarAhora">{{ __('Verificando…') }}</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </section>

    @if ($configuracion?->nit !== null)
        <section class="card card-pad asis-valores" id="valores-factura">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="settings" /></span>{{ __('Valores de la factura') }}</div></div>
            <form wire:submit="guardarPreferencias">
                <fieldset @disabled(! $puedeConfigurar) style="border:0;padding:0;margin:0;min-width:0">
                    <div class="fields">
                        <x-campo etiqueta="Modo de facturación" campo="modo">
                            <select id="modo" class="select" wire:model="modo">
                                @foreach ($modos as $opcion)
                                    <option value="{{ $opcion->value }}">{{ $opcion->etiqueta() }} — {{ $opcion->descripcion() }}</option>
                                @endforeach
                            </select>
                        </x-campo>
                        <x-campo etiqueta="Actividad económica" campo="actividad_economica">
                            <select id="actividad_economica" class="select" wire:model.live="actividad_economica" @disabled($actividades->isEmpty())>
                                <option value="">{{ $actividades->isEmpty() ? __('Se habilita al sincronizar los catálogos') : __('Sin definir') }}</option>
                                @foreach ($actividades as $actividad)
                                    <option value="{{ $actividad->codigo }}">{{ $actividad->codigo }} · {{ $actividad->descripcion }}</option>
                                @endforeach
                            </select>
                        </x-campo>
                        <x-campo etiqueta="Producto SIN" campo="producto_sin">
                            <select id="producto_sin" class="select" wire:model="producto_sin" @disabled($productos->isEmpty())>
                                <option value="">{{ $actividad_economica === '' ? __('Elige primero la actividad') : ($productos->isEmpty() ? __('Sin productos para esta actividad') : __('Sin definir')) }}</option>
                                @foreach ($productos as $producto)
                                    <option value="{{ $producto->codigo }}">{{ $producto->codigo }} · {{ $producto->descripcion }}</option>
                                @endforeach
                            </select>
                        </x-campo>
                    </div>
                    <p class="subtle" style="margin:14px 0 0">{{ __('Actividad y producto SIN se usan por defecto en las líneas de la factura. La unidad de medida de cada producto se configura en Productos → Unidades y medidas.') }}</p>
                    @if ($puedeConfigurar)
                        <div class="row" style="margin-top:18px;justify-content:flex-end">
                            <button class="btn" type="submit" wire:loading.attr="disabled" wire:target="guardarPreferencias"><x-icon name="check" size="sm" /> {{ __('Guardar valores') }}</button>
                        </div>
                    @endif
                </fieldset>
            </form>
        </section>
    @endif

    @if ($impersonando)
        <div class="notice"><x-icon name="lock" /><div><b>{{ __('Modo «Entrar como»') }}</b><p>{{ __('Los datos fiscales, el token, las sucursales y los puntos de venta solo los cambia el administrador de la empresa o el superadministrador desde su propia cuenta.') }}</p></div></div>
    @endif
    @if (! $estado['habilitada'])
        <div class="notice"><x-icon name="info" /><div><b>{{ __('Facturación no habilitada') }}</b><p>{{ __('Puedes cargar los datos y MoziStock los revisa, pero no se consultará al SIN hasta que MoziStudios habilite la facturación de esta empresa.') }}</p></div></div>
    @endif
    @if (! $estado['servidor'])
        <div class="notice"><x-icon name="status-alert" /><div><b>{{ __('Configuración del servidor incompleta') }}</b><p>{{ __('Falta el código de sistema SIAT o el ambiente no corresponde a la URL del SIN. Avisa a MoziStudios.') }}</p></div></div>
    @endif
    @unless ($embebido)
    <div class="notice"><x-icon name="info" /><div><b>{{ __('Facturar no es cobrar') }}</b><p>{{ __('La factura documenta la venta ante el SIN. Los cobros, la caja y los créditos siguen registrándose como hasta ahora.') }}</p></div></div>
    @endunless

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" :tipo="$avisoTipo" />
</div>
