<?php

return [
    // Configuración exclusiva del servidor. No autoriza acceso a ninguna empresa.
    'deployment_mode' => env('MOZISTOCK_MODE', 'saas'),
    'business_timezone' => 'America/La_Paz',
    'currency' => 'BOB',
    // Idiomas de la interfaz (código => nombre propio). El primero es el predeterminado.
    'idiomas' => ['es' => 'Español', 'en' => 'English'],
];
