{{-- Una sola raíz siempre: con el @if fuera de ella Livewire pierde el componente al actualizarlo dentro del popup de la guía. --}}
<div @class(['wide-left' => $parte === 'todo'])>
    @if ($parte === 'datos')
        @include('livewire.facturacion.partials.formulario-datos-fiscales')
    @elseif ($parte === 'token')
        @include('livewire.facturacion.partials.formulario-token')
    @else
    <div class="stack">
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="receipt" /></span>{{ __('Datos fiscales') }}</div></div>
            @include('livewire.facturacion.partials.formulario-datos-fiscales')
        </section>

        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="lock" /></span>{{ __('Token delegado del SIN') }}</div></div>
            @include('livewire.facturacion.partials.formulario-token')
        </section>
    </div>

    <div class="stack">
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="shield" /></span>{{ __('Estado') }}</div></div>
            <div class="list-line">
                <span class="subtle">{{ __('Habilitación') }}</span>
                <span class="badge {{ $empresa->siat_habilitado ? 'success' : 'warning' }}">{{ $empresa->siat_habilitado ? __('Habilitada') : __('No habilitada') }}</span>
            </div>
            <div class="list-line">
                <span class="subtle">{{ __('Ambiente') }}</span>
                <b>{{ $sistema === null ? '—' : ($sistema->ambiente === \MoziStudios\Siat\Constantes\Ambiente::Produccion ? __('Producción') : __('Pruebas (piloto del SIN)')) }}</b>
            </div>
            <div class="list-line">
                <span class="subtle">{{ __('Código de sistema') }}</span>
                <b>{{ $sistema?->codigoSistema ?? '—' }}</b>
            </div>
            <div class="list-line">
                <span class="subtle">{{ __('Token') }}</span>
                @if (! $configuracion?->tieneToken())
                    <span class="badge info">{{ __('Sin cargar') }}</span>
                @elseif ($configuracion->tokenVencido())
                    <span class="badge danger">{{ __('Vencido') }}</span>
                @elseif ($configuracion->tokenPorVencer())
                    <span class="badge warning">{{ __('Por vencer') }}</span>
                @else
                    <span class="badge success">{{ __('Vigente') }}</span>
                @endif
            </div>
            <div class="list-line">
                <span class="subtle">{{ __('Modalidad') }}</span>
                <span class="badge {{ $configuracion?->esElectronica() ? 'info' : 'secondary' }}">
                    {{ $configuracion?->modalidad?->etiqueta() ?? __('Computarizada en Línea') }}
                </span>
            </div>
            @if ($configuracion?->esElectronica())
                <div class="list-line">
                    <span class="subtle">{{ __('Firma digital') }}</span>
                    @if (! $configuracion->tieneCertificado())
                        <span class="badge danger">{{ __('Sin certificado .p12') }}</span>
                    @elseif ($configuracion->certificadoVencido())
                        <span class="badge danger">{{ __('Certificado vencido') }}</span>
                    @elseif ($configuracion->certificadoPorVencer())
                        <span class="badge warning">{{ __('Por vencer') }}</span>
                    @else
                        <span class="badge success">{{ __('Certificado activo') }}</span>
                    @endif
                </div>
            @endif
        </section>

        @if ($impersonando)
            <div class="notice"><x-icon name="lock" /><div><b>{{ __('Modo «Entrar como»') }}</b><p>{{ __('Los datos fiscales, el token, las sucursales y los puntos de venta solo los cambia el administrador de la empresa o el superadministrador desde su propia cuenta.') }}</p></div></div>
        @endif
        @if (! $empresa->siat_habilitado)
            <div class="notice"><x-icon name="info" /><div><b>{{ __('Facturación no habilitada') }}</b><p>{{ __('Puedes cargar los datos, pero no se consultará al SIN hasta que MoziStudios habilite la facturación de esta empresa.') }}</p></div></div>
        @endif
        @if ($sistema === null)
            <div class="notice"><x-icon name="status-alert" /><div><b>{{ __('Configuración del servidor incompleta') }}</b><p>{{ __('Falta el código de sistema SIAT o el ambiente no corresponde a la URL del SIN. Avisa a MoziStudios.') }}</p></div></div>
        @endif
        @if ($configuracion?->tokenVencido())
            <div class="notice"><x-icon name="status-alert" /><div><b>{{ __('Token vencido') }}</b><p>{{ __('No se pueden obtener códigos ni facturar hasta cargar un token nuevo.') }}</p></div></div>
        @elseif ($configuracion?->tokenPorVencer())
            <div class="notice"><x-icon name="status-alert" /><div><b>{{ __('El token vence pronto') }}</b><p>{{ __('Genera uno nuevo en el portal SIAT y cárgalo antes del vencimiento.') }}</p></div></div>
        @endif
        <div class="notice"><x-icon name="info" /><div><b>{{ __('Facturar no es cobrar') }}</b><p>{{ __('La factura documenta la venta ante el SIN. Los cobros, la caja y los créditos siguen registrándose como hasta ahora.') }}</p></div></div>
    </div>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
