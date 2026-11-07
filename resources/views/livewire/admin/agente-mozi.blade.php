<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Superadministración') }}</div>
            <h1>{{ __('Agente Mozi') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Motor de respuestas, proveedor de IA, cuotas por empresa y uso.') }}</p>
        </div>
        <div class="page-actions">
            <button class="btn primary" type="submit" form="agente-form" wire:loading.attr="disabled" wire:target="guardar"><x-icon name="check" /> {{ __('Guardar configuración') }}</button>
        </div>
    </div>

    <div class="wide-left">
        <div class="stack">
            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="sparkles" /></span>{{ __('Motor de respuestas') }}</div></div>
                <form id="agente-form" wire:submit="guardar">
                    <div class="fields">
                        <x-campo etiqueta="Motor activo" campo="motor">
                            <select id="motor" class="select" wire:model.live="motor">
                                @foreach ($motores as $id => $nombre)
                                    <option value="{{ $id }}">{{ $nombre }}{{ isset($proveedores[$id]) && ! $proveedores[$id]['configurado'] ? ' · '.__('sin clave') : '' }}</option>
                                @endforeach
                            </select>
                        </x-campo>
                        <x-campo etiqueta="Modelo" campo="modelo">
                            <select id="modelo" class="select" wire:model="modelo" @disabled(! isset($proveedores[$motor]))>
                                @foreach ($proveedores[$motor]['modelos'] ?? [] as $opcion)
                                    <option value="{{ $opcion['id'] }}">{{ $opcion['id'] }}</option>
                                @endforeach
                                @unless (isset($proveedores[$motor]))
                                    <option value="{{ $modelo }}">{{ __('No aplica en modo demostración') }}</option>
                                @endunless
                            </select>
                        </x-campo>
                        <x-campo etiqueta="Consultas con IA por empresa y día" campo="mensajesDiarios">
                            <input id="mensajesDiarios" class="input" type="number" min="1" max="100000" step="1" wire:model="mensajesDiarios">
                        </x-campo>
                        <x-campo etiqueta="Tokens por empresa y día" campo="tokensDiarios">
                            <input id="tokensDiarios" class="input" type="number" min="1000" max="1000000000" step="1000" wire:model="tokensDiarios">
                        </x-campo>
                    </div>
                    <p class="subtle" style="margin:10px 0 0">{{ __('Los días se cuentan en hora de La Paz. Las respuestas fallidas no cuentan como consulta, pero sí sus tokens.') }}</p>
                </form>
            </section>

            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="chart" /></span>{{ __('Uso con IA · últimos 7 días') }}</div></div>
                @if ($uso === [])
                    <p class="subtle" style="margin:0">{{ __('Todavía no hay consultas respondidas por un proveedor de IA.') }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>{{ __('Empresa') }}</th><th class="right">{{ __('Hoy') }}</th><th class="right">{{ __('7 días') }}</th><th class="right">{{ __('Fallidas') }}</th><th class="right">{{ __('Tokens') }}</th><th class="right">{{ __('Latencia media') }}</th></tr></thead>
                            <tbody>
                                @foreach ($uso as $fila)
                                    <tr>
                                        <td>{{ $fila->nombre }}</td>
                                        <td class="right">{{ number_format((int) $fila->hoy, 0, ',', '.') }}</td>
                                        <td class="right">{{ number_format((int) $fila->semana, 0, ',', '.') }}</td>
                                        <td class="right">{{ number_format((int) $fila->fallidas, 0, ',', '.') }}</td>
                                        <td class="right">{{ number_format((int) $fila->tokens, 0, ',', '.') }}</td>
                                        <td class="right">{{ $fila->latencia === null ? '—' : number_format((float) $fila->latencia / 1000, 1, ',', '.').' s' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <div class="stack">
            @foreach ($proveedores as $id => $proveedor)
                <section class="card card-pad" wire:key="proveedor-{{ $id }}">
                    <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="shield" /></span>{{ $proveedor['nombre'] }}</div></div>
                    <div class="list-line">
                        <span>{{ __('Clave en el servidor') }}</span>
                        @if ($proveedor['configurado'])
                            <span class="badge success">{{ __('Configurada') }}</span>
                        @else
                            <span class="badge warning">{{ __('Sin configurar') }}</span>
                        @endif
                    </div>
                    @foreach ($proveedor['modelos'] as $opcion)
                        <div class="list-line">
                            <div><b>{{ $opcion['id'] }}</b><div class="subtle">{{ __('Herramientas: :h · visión: :v · streaming de texto: :s', [
                                'h' => $opcion['capacidades']->herramientas ? __('sí') : __('no'),
                                'v' => $opcion['capacidades']->vision ? __('sí') : __('no verificada'),
                                's' => $opcion['capacidades']->streaming ? __('sí') : __('no usado'),
                            ]) }}</div></div>
                        </div>
                    @endforeach
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px">
                        <button class="btn soft sm" type="button" wire:click="probar('{{ $id }}')" wire:loading.attr="disabled" wire:target="probar" @disabled(! $proveedor['configurado'])>
                            <x-icon name="refresh" size="sm" /> {{ __('Probar conexión') }}
                        </button>
                        <span class="subtle" wire:loading wire:target="probar">{{ __('Probando…') }}</span>
                    </div>
                    @if ($prueba !== null && $prueba['proveedor'] === $id)
                        <p class="{{ $prueba['ok'] ? 'subtle' : 'auth-error' }}" role="status" style="margin:10px 0 0">{{ $prueba['texto'] }}</p>
                    @endif
                    @unless ($proveedor['configurado'])
                        <p class="subtle" style="margin:10px 0 0">{{ __('Define la clave en el archivo .env del servidor; nunca se guarda en la base de datos ni se muestra aquí.') }}</p>
                    @endunless
                </section>
            @endforeach

            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="info" /></span>{{ __('Datos que recibe la IA') }}</div></div>
                <div class="notice"><x-icon name="info" /><div><b>{{ __('Solo datos sintéticos en esta etapa') }}</b><p>{{ __('El proveedor recibe la pregunta, el historial de la charla, la fecha, el idioma y el rol del usuario, y los resultados de herramientas con datos sintéticos. No recibe el nombre de la empresa, usuarios ni datos comerciales reales.') }}</p></div></div>
                <div class="list-line">
                    <span>{{ __('Vistas personalizadas (sandbox_web)') }}</span>
                    <span class="badge {{ $sandboxWeb ? 'success' : '' }}">{{ $sandboxWeb ? __('Activas') : __('Desactivadas') }}</span>
                </div>
                <div class="list-line">
                    <span>{{ __('Código de vistas escrito por la IA') }}</span>
                    <span class="badge {{ $sandboxIa ? 'warning' : '' }}">{{ $sandboxIa ? __('Permitido') : __('No permitido') }}</span>
                </div>
                <p class="footnote">{{ __('Se controlan con AGENTE_MOZI_SANDBOX_WEB y AGENTE_MOZI_SANDBOX_IA en el servidor.') }}</p>
            </section>
        </div>
    </div>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
