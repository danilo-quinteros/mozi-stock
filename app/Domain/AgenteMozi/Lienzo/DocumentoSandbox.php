<?php

namespace App\Domain\AgenteMozi\Lienzo;

use App\Models\AgenteArtefacto;

/**
 * Documento aislado de un artefacto sandbox_web (SandboxedCanvasDocumentBuilder, § 6.4.1). Se sirve
 * con la cabecera CSP (incluida la directiva sandbox, que solo vale como cabecera): origen opaco,
 * sin red, sin formularios, sin ventanas y sin eval. El código validado se ejecuta aquí y en ningún
 * otro lugar; el Lienzo solo conoce la URL y le entrega los datos autorizados por postMessage.
 */
final class DocumentoSandbox
{
    private const POLITICA = "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; "
        ."font-src data:; connect-src 'none'; media-src 'none'; object-src 'none'; frame-src 'none'; child-src 'none'; "
        ."worker-src 'none'; manifest-src 'none'; form-action 'none'; base-uri 'none'";

    /** Cabecera completa: sandbox (origen opaco) y frame-ancestors solo funcionan como cabecera HTTP. */
    public const CSP = 'sandbox allow-scripts; '.self::POLITICA."; frame-ancestors 'self'";

    /**
     * @return array<string, string>
     */
    public static function cabeceras(): array
    {
        return [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), display-capture=()',
        ];
    }

    public function construir(AgenteArtefacto $artefacto): string
    {
        $spec = $artefacto->spec;
        $html = (string) ($spec['html'] ?? '');
        // Ningún contenido puede cerrar antes de tiempo la etiqueta que lo contiene.
        $css = (string) preg_replace('#</(style)#i', '<\\/$1', (string) ($spec['css'] ?? ''));
        $js = (string) preg_replace(['#</(script)#i', '#<!--#'], ['<\\/$1', '<\\!--'], (string) ($spec['js'] ?? ''));
        $idioma = e(str_replace('_', '-', app()->getLocale()));
        $titulo = e($artefacto->titulo);
        $politica = e(self::POLITICA);
        $base = self::BASE_CSS;
        $puente = self::PUENTE_JS;

        return <<<HTML
            <!doctype html>
            <html lang="{$idioma}" data-theme="dark">
            <head>
            <meta charset="utf-8">
            <meta http-equiv="Content-Security-Policy" content="{$politica}">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$titulo}</title>
            <style>{$base}</style>
            <script>{$puente}</script>
            <style>{$css}</style>
            </head>
            <body>
            {$html}
            <script>{$js}</script>
            </body>
            </html>
            HTML;
    }

    /** Tokens visuales de MoziStock para que la vista respete el tema claro/oscuro del Lienzo. */
    private const BASE_CSS = <<<'CSS'
        :root{color-scheme:dark;--bg:#211e19;--panel:#29251f;--panel-soft:#25211c;--text:#f7f1e9;--text-strong:#fff9ef;--muted:#aaa198;--border:#3b352d;--brand:#df8341;--brand-bright:#ffa456;--green:#6bca92;--red:#f08d81;--blue:#82b9ea;--ease:cubic-bezier(.22,1,.36,1)}
        html[data-theme="light"]{color-scheme:light;--bg:#fff;--panel:#fffaf4;--panel-soft:#faf7f3;--text:#40392f;--text-strong:#241d17;--muted:#81786d;--border:#e6ded4}
        *,*:before,*:after{box-sizing:border-box}
        html,body{margin:0;background:var(--bg);color:var(--text);font:13px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
        @media (prefers-reduced-motion:reduce){*,*:before,*:after{animation-duration:.01ms!important;animation-delay:0s!important;transition-duration:.01ms!important}}
        CSS;

    /**
     * Puente del lado aislado: acepta mensajes solo de la ventana padre con la sesión del fragmento
     * (#s=…), expone window.mozi y publica únicamente ready, height, interaction y error.
     */
    private const PUENTE_JS = <<<'JS'
        (function () {
          'use strict';
          var m = /(?:^#|&)s=([A-Za-z0-9_-]{16,64})(?:&|$)/.exec(window.location.hash);
          var sesion = m ? m[1] : '';
          var oyentes = [];
          var ultimo = null;
          var midiendo = false;
          function enviar(tipo, datos) {
            if (sesion) { window.parent.postMessage({ v: 1, s: sesion, type: tipo, payload: datos || null }, '*'); }
          }
          function medir() {
            if (midiendo) { return; }
            midiendo = true;
            requestAnimationFrame(function () {
              midiendo = false;
              enviar('height', { h: Math.ceil(document.documentElement.scrollHeight) });
            });
          }
          function entregar(oyente, datos) {
            try { oyente(datos); } catch (e) { enviar('error', { message: String((e && e.message) || e).slice(0, 200) }); }
          }
          window.addEventListener('message', function (e) {
            var d = e.data;
            if (e.source !== window.parent || !d || typeof d !== 'object' || d.v !== 1 || d.s !== sesion) { return; }
            if (d.type === 'mozi:data' || d.type === 'mozi:theme') {
              document.documentElement.setAttribute('data-theme', d.theme === 'light' ? 'light' : 'dark');
            }
            if (d.type === 'mozi:data') {
              ultimo = d;
              for (var i = 0; i < oyentes.length; i++) { entregar(oyentes[i], d); }
              medir();
            }
          });
          window.mozi = Object.freeze({
            onData: function (fn) {
              if (typeof fn !== 'function') { return; }
              oyentes.push(fn);
              if (ultimo) { entregar(fn, ultimo); }
            },
            interaction: function (nombre) { enviar('interaction', { name: String(nombre).slice(0, 60) }); },
            resize: medir
          });
          window.addEventListener('error', function (e) { enviar('error', { message: String(e.message || 'Error').slice(0, 200) }); });
          document.addEventListener('click', function (e) {
            if (e.target && e.target.closest && e.target.closest('a[href],area[href]')) { e.preventDefault(); }
          }, true);
          document.addEventListener('DOMContentLoaded', function () {
            if ('ResizeObserver' in window) { new ResizeObserver(medir).observe(document.body); }
            setTimeout(function () { enviar('ready'); medir(); }, 0);
          });
        })();
        JS;
}
