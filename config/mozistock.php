<?php

return [
    // Configuración exclusiva del servidor. No autoriza acceso a ninguna empresa.
    'deployment_mode' => env('MOZISTOCK_MODE', 'saas'),
    'business_timezone' => 'America/La_Paz',
    // Hosting compartido sin procesos permanentes: el cron de schedule:run (cada minuto) también
    // vacía la cola. En un VPS con supervisor y `queue:work` permanente, dejarlo en false.
    'cola_desde_cron' => (bool) env('MOZISTOCK_COLA_DESDE_CRON', false),
    'currency' => 'BOB',
    // Idiomas de la interfaz (código => nombre propio). El primero es el predeterminado.
    'idiomas' => ['es' => 'Español', 'en' => 'English'],
    // Días de prueba gratis que anuncia el sitio público (registro y activación: pendiente).
    'prueba_gratis_dias' => 15,
];
