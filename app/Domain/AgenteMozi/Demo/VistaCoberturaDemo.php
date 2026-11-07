<?php

namespace App\Domain\AgenteMozi\Demo;

/**
 * Código HTML/CSS/JS de demostración para un artefacto sandbox_web: lo escribió el equipo, no una IA.
 * Pasa por el mismo ValidadorSandboxWeb y el mismo documento aislado que usará el código generado
 * por IA en la Fase 2, así que también sirve de referencia de lo que el validador permite.
 */
final class VistaCoberturaDemo
{
    /**
     * @return array{html: string, css: string, js: string}
     */
    public static function spec(): array
    {
        $estados = [
            'critico' => __('Crítico'),
            'atencion' => __('Atención'),
            'ok' => __('Estable'),
            'sin' => __('Sin datos'),
        ];
        $leyenda = implode('', array_map(
            fn (string $clase, string $etiqueta) => '<li class="'.$clase.'"><i></i>'.e($etiqueta).' <b id="n-'.$clase.'">0</b></li>',
            array_keys($estados), $estados,
        ));
        $textos = json_encode([
            'sinDatos' => __('Sin datos'),
            'dias' => __('días'),
            'unidades' => __('unidades'),
            'demanda' => __('Demanda diaria'),
            'agotamiento' => __('Agotamiento probable'),
            'sinFecha' => __('Sin fecha estimada'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $html = '<main class="cobertura">'
            .'<p class="eyebrow">'.e(__('Vista personalizada · código de demostración')).'</p>'
            .'<h1>'.e(__('¿Cuántos días me alcanza el stock?')).'</h1>'
            .'<p class="sub">'.e(__('Toca un producto para ver su demanda. La línea vertical marca el horizonte de 7 días.')).'</p>'
            .'<div class="resumen" id="resumen" aria-hidden="true"></div>'
            .'<ul class="leyenda">'.$leyenda.'</ul>'
            .'<div class="lista" id="lista" role="list"></div>'
            .'</main>';

        return ['html' => $html, 'css' => self::CSS, 'js' => str_replace('__TEXTOS__', $textos, self::JS)];
    }

    private const CSS = <<<'CSS'
        .cobertura{padding:22px 22px 20px}
        .eyebrow{margin:0 0 7px;color:var(--brand-bright);font-size:10.5px;font-weight:750;letter-spacing:.16em;text-transform:uppercase}
        h1{margin:0;font-size:19px;line-height:1.25;letter-spacing:-.03em;color:var(--text-strong)}
        .sub{margin:6px 0 0;color:var(--muted);font-size:12px}
        .critico{--tono:var(--red)}.atencion{--tono:var(--brand-bright)}.ok{--tono:var(--green)}.sin{--tono:var(--muted)}
        .resumen{display:flex;gap:3px;height:8px;margin:18px 0 12px;border-radius:99px;overflow:hidden;background:var(--panel-soft)}
        .segmento{flex-basis:0;min-width:0;background:var(--tono);transition:flex-grow .7s var(--ease)}
        .leyenda{display:flex;flex-wrap:wrap;gap:6px 16px;margin:0 0 14px;padding:0;list-style:none;color:var(--muted);font-size:11.5px}
        .leyenda i{display:inline-block;width:8px;height:8px;margin-right:6px;border-radius:50%;background:var(--tono);box-shadow:0 0 8px var(--tono)}
        .leyenda b{margin-left:3px;color:var(--text-strong)}
        .lista{display:grid;gap:8px}
        .fila{display:grid;grid-template-columns:minmax(130px,1.1fr) minmax(120px,2fr) 82px;gap:6px 16px;align-items:center;width:100%;padding:11px 13px;border:1px solid var(--border);border-radius:12px;background:var(--panel);color:inherit;font:inherit;text-align:left;cursor:pointer;opacity:0;transform:translateY(6px);animation:entrar .5s var(--ease) forwards;animation-delay:var(--retraso);transition:border-color .2s,transform .2s var(--ease)}
        .fila:hover,.fila:focus-visible{border-color:color-mix(in srgb,var(--tono) 60%,var(--border));outline:none}
        .fila:focus-visible{box-shadow:0 0 0 3px color-mix(in srgb,var(--brand) 25%,transparent)}
        .nombre{display:grid;min-width:0}
        .nombre b{overflow:hidden;color:var(--text-strong);font-size:12.5px;text-overflow:ellipsis;white-space:nowrap}
        .nombre small{color:var(--muted);font-size:11px}
        .pista{position:relative;height:10px;border-radius:99px;background:var(--panel-soft)}
        .pista:after{content:"";position:absolute;left:35%;top:-5px;bottom:-5px;width:2px;border-radius:2px;background:var(--text-strong);opacity:.35}
        .barra{display:block;height:100%;width:var(--ancho);border-radius:99px;background:linear-gradient(90deg,color-mix(in srgb,var(--tono) 55%,transparent),var(--tono));box-shadow:0 0 14px color-mix(in srgb,var(--tono) 40%,transparent);animation:crecer 1s var(--ease) both;animation-delay:var(--retraso)}
        .sin .pista{background:repeating-linear-gradient(90deg,var(--border) 0 6px,transparent 6px 11px)}
        .valor{color:var(--tono);font-size:13px;text-align:right;white-space:nowrap}
        .extra{display:none;grid-column:1/-1;flex-wrap:wrap;gap:4px 18px;padding-top:6px;color:var(--muted);font-size:11.5px}
        .fila.abierta .extra{display:flex}
        @keyframes crecer{from{width:0}}
        @keyframes entrar{to{opacity:1;transform:none}}
        @media (max-width:520px){.cobertura{padding:18px 14px}.fila{grid-template-columns:1fr 74px}.pista{grid-column:1/-1;order:3}}
        CSS;

    private const JS = <<<'JS'
        (function () {
          'use strict';
          var T = __TEXTOS__;
          var HORIZONTE = 7;
          var MAXIMO = 20;
          var lista = document.getElementById('lista');
          var resumen = document.getElementById('resumen');
          function numero(v) { return v === null || v === undefined ? null : parseFloat(v); }
          function estado(fila) {
            var c = numero(fila.cobertura_dias);
            if (c === null) { return 'sin'; }
            if (c <= 3) { return 'critico'; }
            return c <= HORIZONTE ? 'atencion' : 'ok';
          }
          function texto(etiqueta, contenido, clase) {
            var el = document.createElement(etiqueta);
            if (clase) { el.className = clase; }
            el.textContent = contenido;
            return el;
          }
          function decimal(v) { return String(v).replace('.', ','); }
          function fecha(v) { var p = String(v).split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }
          mozi.onData(function (datos) {
            var filas = datos.rows.slice().sort(function (a, b) {
              var x = numero(a.cobertura_dias);
              var y = numero(b.cobertura_dias);
              if (x === null) { return 1; }
              if (y === null) { return -1; }
              return x - y;
            });
            var cuentas = { critico: 0, atencion: 0, ok: 0, sin: 0 };
            lista.textContent = '';
            filas.forEach(function (fila, i) {
              var tipo = estado(fila);
              var c = numero(fila.cobertura_dias);
              cuentas[tipo] += 1;
              var item = document.createElement('button');
              item.type = 'button';
              item.className = 'fila ' + tipo;
              item.setAttribute('role', 'listitem');
              item.setAttribute('aria-expanded', 'false');
              item.style.setProperty('--retraso', (i * 70) + 'ms');
              var nombre = texto('span', '', 'nombre');
              nombre.appendChild(texto('b', fila.producto));
              nombre.appendChild(texto('small', fila.sucursal + ' · ' + decimal(fila.disponible) + ' ' + T.unidades));
              var pista = texto('span', '', 'pista');
              var barra = texto('span', '', 'barra');
              barra.style.setProperty('--ancho', (c === null ? 0 : Math.min(c, MAXIMO) / MAXIMO * 100).toFixed(1) + '%');
              pista.appendChild(barra);
              var extra = texto('span', '', 'extra');
              extra.appendChild(texto('span', T.demanda + ': ' + (fila.demanda_diaria === null ? T.sinDatos : decimal(fila.demanda_diaria))));
              extra.appendChild(texto('span', T.agotamiento + ': ' + (fila.fecha_probable ? fecha(fila.fecha_probable) : T.sinFecha)));
              item.appendChild(nombre);
              item.appendChild(pista);
              item.appendChild(texto('strong', c === null ? T.sinDatos : decimal(fila.cobertura_dias) + ' ' + T.dias, 'valor'));
              item.appendChild(extra);
              item.addEventListener('click', function () {
                var abierta = item.classList.toggle('abierta');
                item.setAttribute('aria-expanded', abierta ? 'true' : 'false');
                mozi.interaction('detalle');
                mozi.resize();
              });
              lista.appendChild(item);
            });
            resumen.textContent = '';
            ['critico', 'atencion', 'ok', 'sin'].forEach(function (tipo) {
              var parte = texto('span', '', 'segmento ' + tipo);
              parte.style.flexGrow = String(cuentas[tipo]);
              resumen.appendChild(parte);
              var contador = document.getElementById('n-' + tipo);
              if (contador) { contador.textContent = String(cuentas[tipo]); }
            });
            mozi.resize();
          });
        })();
        JS;
}
