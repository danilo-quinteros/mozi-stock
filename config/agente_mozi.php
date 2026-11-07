<?php

return [
    // Agente Mozi (docs/AGENTE_MOZI_IA.md). Configuración exclusiva del servidor: nada de aquí llega al navegador.
    'habilitado' => (bool) env('AGENTE_MOZI_HABILITADO', true),

    // Motor predeterminado: 'demo' (respuestas sintéticas, sin llamadas externas) o el id de un proveedor
    // de 'proveedores'. El superadministrador puede cambiarlo en Superadministración › Agente Mozi.
    'motor' => env('AGENTE_MOZI_MOTOR', 'demo'),

    // Vistas HTML/CSS/JS aisladas en iframe. Con el motor demo solo ejecutan código de demostración propio.
    'sandbox_web' => (bool) env('AGENTE_MOZI_SANDBOX_WEB', true),

    // Permite que el proveedor de IA escriba el código de una vista sandbox_web. Queda apagado hasta que
    // el propietario acepte el riesgo residual del ejecutor (docs/AGENTE_MOZI_INTEGRACION.md § 5, D5).
    'sandbox_ia' => (bool) env('AGENTE_MOZI_SANDBOX_IA', false),

    'proveedores' => [
        'gemini' => [
            'nombre' => 'Google Gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'modelo' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
            'timeout' => (int) env('GEMINI_TIMEOUT', 45),

            // Modelos ofrecidos y sus capacidades, verificadas contra la API generateContent el 30/10/2026.
            // Solo se marcan las que el agente usa y se probaron: visión y streaming de texto quedan en
            // false hasta que una fase las necesite y las verifique. Un modelo fuera de esta lista no se usa.
            'modelos' => [
                'gemini-3.5-flash' => ['herramientas' => true, 'vision' => false, 'streaming' => false, 'contexto' => 1_048_576, 'salida' => 65_536, 'razonamiento' => 'low'],
                'gemini-3.8-flash' => ['herramientas' => true, 'vision' => false, 'streaming' => false, 'contexto' => 1_048_576, 'salida' => 65_536, 'razonamiento' => 'low'],
                'gemini-3.1-flash-lite' => ['herramientas' => true, 'vision' => false, 'streaming' => false, 'contexto' => 1_048_576, 'salida' => 65_536, 'razonamiento' => 'low'],
            ],
        ],

        'openai' => [
            'nombre' => 'OpenAI',
            'api_key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'modelo' => env('OPENAI_MODEL', 'gpt-6.1-terra'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 45),

            // API Responses. Capacidades tomadas de la documentación de OpenAI el 03/11/2026, todavía sin
            // verificar en vivo (no hay clave): la llamada a funciones exige la API Responses en estos
            // modelos. Precio por millón de tokens (entrada/salida): Terra 1/5 USD, Luna 0,10/0,50 USD.
            'modelos' => [
                'gpt-6.1-terra' => ['herramientas' => true, 'vision' => false, 'streaming' => false, 'contexto' => 1_050_000, 'salida' => 128_000, 'razonamiento' => 'low'],
                'gpt-6-luna' => ['herramientas' => true, 'vision' => false, 'streaming' => false, 'contexto' => 1_050_000, 'salida' => 128_000, 'razonamiento' => 'low'],
            ],
        ],
    ],

    // Límites de un turno con proveedor de IA (§ 5, regla 5).
    'ia' => [
        'rondas_herramientas' => 4,
        'herramientas_por_turno' => 6,
        'tokens_salida' => 8192,
        'filas_al_modelo' => 60,
        'mensajes_de_historial' => 10,
        'duracion_maxima' => 100,
        'reintentos' => 2,
    ],

    // Cuotas por empresa y día de La Paz. El superadministrador puede ajustarlas sin desplegar.
    'cuotas' => [
        'mensajes_diarios' => (int) env('AGENTE_MOZI_MENSAJES_DIARIOS', 100),
        'tokens_diarios' => (int) env('AGENTE_MOZI_TOKENS_DIARIOS', 400_000),
    ],

    'limites' => [
        'caracteres_mensaje' => 2000,
        'mensajes_por_minuto' => 20,
        'conversaciones_historial' => 30,
        'mensajes_por_conversacion' => 200,
        'artefactos_por_mensaje' => 8,
        'filas_dataset' => 500,
        'columnas_dataset' => 20,
        'series_grafico' => 6,
        'sandbox_html_bytes' => 20_000,
        'sandbox_css_bytes' => 20_000,
        'sandbox_js_bytes' => 40_000,
        'sandbox_altura_max' => 900,
    ],
];
