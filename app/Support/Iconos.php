<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Catálogo único de iconos SVG (trazo 24×24, estilo de assets/app.js). Se usan en Blade con
 * <x-icon name="..." /> o, fuera de Blade, con Iconos::svg('...'). Agregar un icono aquí lo
 * deja disponible en todo el sistema; no usar símbolos Unicode ni emojis como iconos.
 */
final class Iconos
{
    public const TRAZOS = [
        // Acciones
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'download' => '<path d="M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4"/>',
        'edit' => '<path d="m14 4 6 6M4 20l5-.7L20 8a2 2 0 0 0-4-4L5 15Z"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'filter' => '<path d="M3 5h18l-7 8v6l-4 2v-8L3 5Z"/>',
        'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'minus' => '<path d="M5 12h14"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'print' => '<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="7" rx="1"/>',
        'scan' => '<path d="M3 7V4h3M18 4h3v3M21 17v3h-3M6 20H3v-3M7 8v8M10 8v8M13 8v8M17 8v8"/>',
        'sidebar' => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M9 4v16M15.5 10 13.5 12l2 2"/>',
        'trash' => '<path d="M3 6h18M19 6l-1 15H6L5 6M9 6V3h6v3M10 10v7M14 10v7"/>',
        'search' => '<circle cx="10.8" cy="10.8" r="7"/><path d="m16 16 5 5"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"/>',
        'upload' => '<path d="M12 16V4m-5 5 5-5 5 5M4 16v5h16v-5"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        // Estado y avisos
        'status-ok' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.8 2.8L16.8 9"/>',
        'status-soon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'status-alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 4h.01"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 11v6M12 7h.01"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'shield' => '<path d="m12 2 9 4v6c0 5-3.5 8-9 10-5.5-2-9-5-9-10V6l9-4ZM8 12l3 3 5-6"/>',
        // Módulos
        'box' => '<path d="m21 8-9-5-9 5v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'building' => '<path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5M9 9h.01M15 9h.01M9 13h.01M15 13h.01"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/>',
        'cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .6h9.6a1 1 0 0 0 1-.8L21 7H6"/>',
        'cash' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 10v4M18 10v4"/>',
        'chart' => '<path d="M4 20V4M4 20h17M8 15l4-5 3 3 5-7"/>',
        'credit' => '<rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20M6 15h4"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'expense' => '<path d="M12 3v14m-5-5 5 5 5-5"/><path d="M4 21h16"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 3"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
        'pos' => '<rect x="3" y="3" width="18" height="12" rx="2"/><path d="M7 19h10M12 15v4M7 7h5M7 11h3M16 7v4"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 17h4v4h-4"/>',
        'receipt' => '<path d="M4 3h16v18l-4-2-4 2-4-2-4 2V3ZM8 8h8M8 12h8"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19 9l2-2-3-3-2 2a8 8 0 0 0-2-1V2h-4v3a8 8 0 0 0-2 1L6 4 3 7l2 2a8 8 0 0 0-1 2H2v4h2a8 8 0 0 0 1 2l-2 2 3 3 2-2a8 8 0 0 0 2 1v1h4v-1a8 8 0 0 0 2-1l2 2 3-3-2-2a8 8 0 0 0 1-2h2v-4h-2a8 8 0 0 0-1-2Z"/>',
        'store' => '<path d="m3 10 2-7h14l2 7v10H3V10ZM3 10c2 2 4 2 6 0 2 2 4 2 6 0 2 2 4 2 6 0M9 20v-7h6v7"/>',
        'subscription' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4M8 14l2.5 2.5L16 12"/>',
        'swap' => '<path d="M7 4 3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/>',
        'truck' => '<path d="M2 5h12v11H2zM14 9h4l4 4v3h-8"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M18 15a5 5 0 0 1 3 5"/>',
        'wallet' => '<path d="M20 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h15v14H5a2 2 0 0 1-2-2V5"/><path d="M16 14h.01"/>',
    ];

    public static function existe(string $nombre): bool
    {
        return isset(self::TRAZOS[$nombre]);
    }

    /**
     * SVG listo para imprimir. Un nombre desconocido usa «info» para no romper la vista.
     */
    public static function svg(string $nombre, string $clase = 'ico'): HtmlString
    {
        $trazo = self::TRAZOS[$nombre] ?? self::TRAZOS['info'];

        return new HtmlString('<svg viewBox="0 0 24 24" aria-hidden="true" class="'.e($clase).'">'.$trazo.'</svg>');
    }
}
