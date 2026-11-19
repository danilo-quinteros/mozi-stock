<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Inicio') }}</div>
            <h1>{{ __('Agente Mozi') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Tu asistente con inteligencia artificial que conoce los datos de tu negocio.') }}</p>
        </div>
    </div>

    <x-plan-requerido icono="sparkles"
        :titulo="__('Agente Mozi no está incluido en tu plan')"
        :descripcion="__('Cambia a un plan con Agente Mozi para hacerle preguntas sobre tu negocio y recibir respuestas con tus propias cifras.')"
        :beneficios="[
            __('Pregunta en tus palabras cómo van tus ventas, cobros y caja.'),
            __('Revisa stock bajo, cuentas por cobrar y productos más vendidos.'),
            __('Obtén tablas y gráficos listos para decidir.'),
        ]" />
</div>
