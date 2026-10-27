<?php

/*
| Facturación SIAT del SIN (paquete mozistudios/siat, en packages/siat).
| Datos del sistema informático: son los mismos para todas las empresas y no son secretos.
| El NIT y el token delegado de cada empresa NO van aquí: se guardan cifrados por empresa (fase 2).
*/

return [
    // Código de sistema asignado en la solicitud de autorización (Sistema Proveedor).
    'codigo_sistema' => env('SIAT_CODIGO_SISTEMA'),

    // 2 = pruebas (piloto del SIN), 1 = producción. La URL base debe corresponder al ambiente.
    'ambiente' => (int) env('SIAT_AMBIENTE', 2),

    'url_base' => env('SIAT_URL_BASE', 'https://pilotosiatservicios.impuestos.gob.bo/sfd/'),

    // Consulta pública de facturas (el QR de la representación gráfica). La de producción la comunica el SIN al autorizar.
    'url_qr' => env('SIAT_URL_QR', 'https://pilotosiat.impuestos.gob.bo/consulta/QR'),

    'segundos_conexion' => (int) env('SIAT_SEGUNDOS_CONEXION', 15),
    'segundos_respuesta' => (int) env('SIAT_SEGUNDOS_RESPUESTA', 60),

    // Solo para `mozistock:siat-diagnostico` en el piloto; las empresas usan su propia configuración fiscal
    // (Facturación → Datos fiscales). Se definen en .env del servidor; nunca en .env.example, en Git ni en el chat.
    'pruebas' => [
        'nit' => env('SIAT_PRUEBAS_NIT'),
        'token' => env('SIAT_PRUEBAS_TOKEN'),
    ],
];
