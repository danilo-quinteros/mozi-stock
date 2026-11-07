<?php

namespace App\Domain\AgenteMozi\Lienzo;

/**
 * Valida el HTML, CSS y JS de un artefacto sandbox_web antes de guardarlo (§ 6.4.1). Es defensa en
 * profundidad: la seguridad real la dan el iframe sin allow-same-origin y la CSP de DocumentoSandbox.
 * Una lista de prohibiciones textual puede evadirse con ofuscación; por eso también se rechazan los
 * escapes y accesos por cadena que la facilitan, y el riesgo residual está documentado en
 * docs/AGENTE_MOZI_INTEGRACION.md § 5.
 */
final class ValidadorSandboxWeb
{
    /** Identificadores prohibidos en cualquier posición: red, navegación, almacenamiento y ejecución dinámica. */
    private const JS_IDENTIFICADORES = [
        'fetch', 'XMLHttpRequest', 'WebSocket', 'EventSource', 'sendBeacon', 'RTCPeerConnection',
        'webkitRTCPeerConnection', 'RTCDataChannel', 'WebTransport', 'importScripts', 'Worker', 'SharedWorker',
        'serviceWorker', 'eval', 'Function', 'constructor', 'location', 'opener', 'postMessage', 'cookie',
        'localStorage', 'sessionStorage', 'indexedDB', 'caches', 'globalThis', 'fromCharCode', 'fromCodePoint',
        'unescape', 'execCommand', 'createContextualFragment', 'srcdoc', 'import', 'require', 'history',
        'navigator', 'Reflect', 'write', 'writeln', 'innerHTML', 'outerHTML', 'insertAdjacentHTML',
        'setHTMLUnsafe', 'parseHTMLUnsafe', 'DOMParser', 'Image', 'Audio',
    ];

    /** Ventanas ajenas: prohibidas como variable global, permitidas como propiedad (style.top). */
    private const JS_GLOBALES = ['top', 'parent', 'frames', 'open', 'frameElement'];

    private const HTML_ETIQUETAS = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'meta', 'base', 'link',
        'form', 'portal', 'style', 'fencedframe',
    ];

    private const HTML_ATRIBUTOS = [
        'href', 'src', 'srcset', 'action', 'formaction', 'xlink:href', 'poster', 'background', 'ping',
        'data', 'codebase', 'cite', 'longdesc', 'manifest', 'lowsrc', 'dynsrc', 'http-equiv',
    ];

    /**
     * @param  array<string, mixed>  $spec
     * @return array{html: string, css: string, js: string}
     */
    public function validar(array $spec): array
    {
        if (! (bool) config('agente_mozi.sandbox_web')) {
            throw new ArtefactoInvalido('sandbox_web está deshabilitado.');
        }

        $html = $this->codigo($spec, 'html', (int) config('agente_mozi.limites.sandbox_html_bytes'));
        $css = $this->codigo($spec, 'css', (int) config('agente_mozi.limites.sandbox_css_bytes'));
        $js = $this->codigo($spec, 'js', (int) config('agente_mozi.limites.sandbox_js_bytes'));

        if (trim($html) === '') {
            throw new ArtefactoInvalido('sandbox_web requiere HTML.');
        }

        $this->html($html);
        $this->css($css);
        $this->js($js);

        return ['html' => $html, 'css' => $css, 'js' => $js];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function codigo(array $spec, string $campo, int $maximo): string
    {
        $valor = $spec[$campo] ?? '';

        if (! is_string($valor) || ! mb_check_encoding($valor, 'UTF-8')) {
            throw new ArtefactoInvalido("sandbox_web.{$campo} debe ser texto UTF-8.");
        }

        if (strlen($valor) > $maximo) {
            throw new ArtefactoInvalido("sandbox_web.{$campo} supera {$maximo} bytes.");
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $valor) === 1) {
            throw new ArtefactoInvalido("sandbox_web.{$campo} contiene caracteres de control.");
        }

        return $valor;
    }

    private function html(string $html): void
    {
        $this->comunes($html, 'html');
        $etiquetas = implode('|', self::HTML_ETIQUETAS);

        if (preg_match('#<\s*/?\s*(?:'.$etiquetas.')\b#i', $html) === 1) {
            throw new ArtefactoInvalido('sandbox_web.html contiene una etiqueta no permitida.');
        }

        // Manejadores en línea (onclick=…): serían JS fuera del validador de JS.
        if (preg_match('/[\s"\'\/]on[a-z]+\s*=/i', $html) === 1) {
            throw new ArtefactoInvalido('sandbox_web.html contiene manejadores de eventos en línea.');
        }

        $atributos = implode('|', array_map(fn (string $a) => preg_quote($a, '/'), self::HTML_ATRIBUTOS));

        if (preg_match('/[\s"\'\/](?:'.$atributos.')\s*=/i', $html) === 1) {
            throw new ArtefactoInvalido('sandbox_web.html contiene un atributo de enlace o recurso externo.');
        }

        $this->urls($html, 'html');
    }

    private function css(string $css): void
    {
        $this->comunes($css, 'css');

        if (preg_match('/@import|expression\s*\(|behavior\s*:|-moz-binding|<\s*\/?\s*style/i', $css) === 1) {
            throw new ArtefactoInvalido('sandbox_web.css contiene una construcción no permitida.');
        }

        $this->urls($css, 'css');
    }

    private function js(string $js): void
    {
        $this->comunes($js, 'js');
        $prohibidos = implode('|', array_map(fn (string $p) => preg_quote($p, '/'), self::JS_IDENTIFICADORES));

        if (preg_match('/(?<![\w$])(?:'.$prohibidos.')(?![\w$])/', $js, $coincidencia) === 1) {
            throw new ArtefactoInvalido("sandbox_web.js usa «{$coincidencia[0]}», que no está permitido.");
        }

        $globales = implode('|', self::JS_GLOBALES);

        if (preg_match('/(?<![\w$.])(?:'.$globales.')(?![\w$])|(?:window|self|document)\s*\.\s*(?:'.$globales.')(?![\w$])/', $js, $coincidencia) === 1) {
            throw new ArtefactoInvalido("sandbox_web.js usa «{$coincidencia[0]}», que no está permitido.");
        }

        // Acceso por cadena y escapes: las formas habituales de esconder un identificador prohibido.
        if (preg_match('/(?:window|self|document|this)\s*\[|\\\\x[0-9a-f]{2}|\\\\u[0-9a-f{]|<\s*\/\s*script/i', $js) === 1) {
            throw new ArtefactoInvalido('sandbox_web.js usa acceso por cadena o secuencias de escape.');
        }

        // Elementos que navegan o cargan recursos, creados desde JS.
        if (preg_match('/[\'"`]\s*(?:a|area|script|iframe|frame|object|embed|meta|base|link|form|portal|img|audio|video|source)\s*[\'"`]/i', $js) === 1) {
            throw new ArtefactoInvalido('sandbox_web.js crea un elemento no permitido.');
        }

        $this->urls($js, 'js');
    }

    private function comunes(string $codigo, string $campo): void
    {
        if (preg_match('/javascript\s*:|vbscript\s*:|data\s*:\s*text\/html|http-equiv/i', $codigo) === 1) {
            throw new ArtefactoInvalido("sandbox_web.{$campo} contiene un esquema no permitido.");
        }
    }

    /**
     * Ninguna URL externa ni relativa al servidor: solo datos embebidos (data:image/…) y fragmentos.
     */
    private function urls(string $codigo, string $campo): void
    {
        if (preg_match('#(?:https?|wss?|ftp|file|blob)\s*:|(?<![:\w])//[\w.-]|url\s*\(\s*[\'"]?\s*(?!data:image/|\#)#i', $codigo) === 1) {
            throw new ArtefactoInvalido("sandbox_web.{$campo} contiene una URL no permitida.");
        }
    }
}
