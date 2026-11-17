<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Catálogo externo de Cyclone. El token y la URL guardados en el proveedor (Superadmin) tienen
    // prioridad; estos valores son el respaldo del servidor. Leídos aquí para que funcionen con config:cache.
    'cyclone' => [
        'base_url' => env('CYCLONE_API_BASE_URL', 'https://cyclonelimpieza.com/sistema/api/v1'),
        'token' => env('CYCLONE_API_TOKEN'),
        // Proveedor de compras que se registra en cada empresa vinculada como distribuidora de Cyclone.
        'proveedor' => [
            'nombre' => 'Cyclone',
            'documento' => '7687402012',
            'ciudad' => 'Santa Cruz de la Sierra',
            'telefono' => '77092733',
            'correo' => 'ventas@cyclonelimpieza.com',
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
