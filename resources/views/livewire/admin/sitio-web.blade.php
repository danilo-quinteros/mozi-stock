@php
    $largo = fn (string $valor): int => mb_strlen(trim($valor));
    $tituloLargo = $largo($titulo_seo !== '' ? $titulo_seo : $pagina->titulo);
    $descLargo = $largo($descripcion_seo !== '' ? $descripcion_seo : $pagina->descripcion);
@endphp
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Superadministración') }}</div>
            <h1>{{ __('Sitio web') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('SEO de cada página pública de mozistock.com. Un campo vacío usa el texto por defecto.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('sitio.sitemap') }}" target="_blank" rel="noopener"><x-icon name="globe" /> {{ __('Ver sitemap.xml') }}</a>
            <button class="btn primary" type="submit" form="seo-form" wire:loading.attr="disabled" wire:target="guardar,imagen"><x-icon name="check" /> {{ __('Guardar SEO') }}</button>
        </div>
    </div>

    <div class="wide-left">
        <div class="stack">
            <section class="card card-pad">
                <div class="card-head">
                    <div class="heading"><span class="heading-icon"><x-icon name="globe" /></span>{{ $pagina->nombre }}</div>
                    <a class="btn ghost sm" href="{{ route($pagina->nombreRuta()) }}" target="_blank" rel="noopener"><x-icon name="eye" /> {{ __('Ver página') }}</a>
                </div>
                <div class="field" style="margin-bottom:14px">
                    <label class="field-label" for="sw-pagina">{{ __('Página') }}</label>
                    <select id="sw-pagina" class="select" wire:change="seleccionar($event.target.value)">
                        @foreach ($grupos as $grupo => $paginas)
                            <optgroup label="{{ __(\App\Domain\SitioWeb\Pagina::GRUPOS[$grupo]) }}">
                                @foreach ($paginas as $opcion)
                                    <option value="{{ $opcion->clave }}" @selected($opcion->clave === $clave)>{{ $opcion->nombre }} · /{{ ltrim($opcion->ruta, '/') }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>

                <form id="seo-form" wire:submit="guardar">
                    <div class="fields" style="grid-template-columns:1fr">
                        <x-campo etiqueta="Título SEO (title)" campo="titulo_seo">
                            <input id="titulo_seo" class="input" type="text" wire:model.live.debounce.400ms="titulo_seo" maxlength="70" placeholder="{{ $pagina->titulo }}">
                            <div class="sw-contador"><span>{{ __('Ideal: 50 a 60 caracteres. Incluye «Bolivia» y la palabra clave.') }}</span><span @class(['largo' => $tituloLargo > 60])>{{ $tituloLargo }}/60</span></div>
                        </x-campo>
                        <x-campo etiqueta="Meta descripción" campo="descripcion_seo">
                            <textarea id="descripcion_seo" class="textarea" rows="3" wire:model.live.debounce.400ms="descripcion_seo" maxlength="170" placeholder="{{ $pagina->descripcion }}"></textarea>
                            <div class="sw-contador"><span>{{ __('Ideal: 120 a 160 caracteres. Es el texto bajo el título en Google.') }}</span><span @class(['largo' => $descLargo > 160])>{{ $descLargo }}/160</span></div>
                        </x-campo>
                    </div>

                    <div class="divider"></div>
                    <p class="field-label" style="margin:0 0 10px">{{ __('Redes sociales y WhatsApp (Open Graph)') }}</p>
                    <div class="fields">
                        <x-campo etiqueta="Título para redes (og:title)" campo="og_titulo">
                            <input id="og_titulo" class="input" type="text" wire:model.live.debounce.400ms="og_titulo" maxlength="95" placeholder="{{ __('Igual al título SEO') }}">
                        </x-campo>
                        <x-campo etiqueta="Descripción para redes (og:description)" campo="og_descripcion">
                            <input id="og_descripcion" class="input" type="text" wire:model.live.debounce.400ms="og_descripcion" maxlength="200" placeholder="{{ __('Igual a la meta descripción') }}">
                        </x-campo>
                    </div>
                    <div class="product-upload" style="margin-top:13px">
                        <label class="upload-box" for="imagen">
                            <x-icon name="image" size="lg" style="width:28px;height:28px;color:var(--brand-bright)" />
                            <b>{{ __('Imagen para redes (og:image)') }}</b>
                            <small wire:loading.remove wire:target="imagen">{{ $fila?->og_imagen_path && ! $quitarImagen ? __('Esta página tiene su propia imagen. Haz clic para cambiarla.') : __('Usa la imagen general del sitio. Haz clic para subir una propia.') }}</small>
                            <small wire:loading wire:target="imagen">{{ __('Subiendo…') }}</small>
                            <small>{{ __('JPG, PNG o WebP · ideal 1200 × 630 px · máx. 2 MB') }}</small>
                        </label>
                        <input id="imagen" type="file" accept="image/png,image/jpeg,image/webp" wire:model="imagen" hidden>
                        @error('imagen')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                        @if ($imagen || ($fila?->og_imagen_path && ! $quitarImagen))
                            <button class="btn ghost sm" type="button" wire:click="quitar">{{ __('Usar la imagen general') }}</button>
                        @endif
                    </div>

                    <div class="divider"></div>
                    <p class="field-label" style="margin:0 0 10px">{{ __('Avanzado') }}</p>
                    <div class="fields">
                        <x-campo etiqueta="URL canónica" campo="canonical_url">
                            <input id="canonical_url" class="input" type="url" wire:model.live.debounce.400ms="canonical_url" maxlength="255" placeholder="{{ $pagina->url() }}">
                        </x-campo>
                        <x-campo etiqueta="Mensaje de WhatsApp de esta página" campo="mensaje_whatsapp">
                            <input id="mensaje_whatsapp" class="input" type="text" wire:model="mensaje_whatsapp" maxlength="300" placeholder="{{ $pagina->mensajeWhatsapp }}">
                        </x-campo>
                    </div>
                    <label class="row" style="cursor:pointer;margin-top:14px">
                        <input type="checkbox" class="checkbox" wire:model.live="indexar">
                        <span><b>{{ __('Mostrar en Google (index)') }}</b><small style="display:block;color:var(--faint)">{{ __('Si lo desactivas, la página lleva noindex y sale del sitemap.xml.') }}</small></span>
                    </label>
                    <p class="footnote">{{ __('Deja la URL canónica vacía salvo que esta página duplique a otra. Una canónica distinta también la saca del sitemap.') }}</p>

                    <div class="divider"></div>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between">
                        <button class="btn ghost" type="button" wire:click="restablecer" wire:confirm="{{ __('¿Volver esta página a los valores por defecto?') }}" @disabled($fila === null)>{{ __('Restablecer valores por defecto') }}</button>
                        <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar,imagen"><x-icon name="check" /> {{ __('Guardar SEO') }}</button>
                    </div>
                </form>
            </section>

            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="eye" /></span>{{ __('Vista previa') }}</div></div>
                <div class="sw-previas">
                    <div>
                        <p class="field-label" style="margin:0 0 8px">{{ __('Google') }}</p>
                        <div class="sw-previa">
                            <span class="url">{{ $vista['url'] }}</span>
                            <span class="titulo">{{ $vista['titulo'] }}</span>
                            <span class="desc">{{ $vista['descripcion'] }}</span>
                        </div>
                        @unless ($indexar)
                            <p class="subtle" style="margin:8px 0 0">{{ __('Con noindex, Google no mostrará esta página.') }}</p>
                        @endunless
                    </div>
                    <div>
                        <p class="field-label" style="margin:0 0 8px">{{ __('Facebook y WhatsApp') }}</p>
                        <div class="sw-social">
                            <img src="{{ $vista['imagen'] }}" alt="{{ __('Imagen para redes') }}">
                            <div><small>{{ parse_url($vista['url'], PHP_URL_HOST) }}</small><b>{{ $vista['ogTitulo'] }}</b><span>{{ $vista['ogDescripcion'] }}</span></div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="stack">
            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="table" /></span>{{ __('Páginas') }}</div><span class="badge info">{{ count(\App\Domain\SitioWeb\CatalogoPaginas::todas()) }}</span></div>
                <div class="sw-lista">
                    @foreach ($grupos as $grupo => $paginas)
                        <p class="sw-grupo">{{ __(\App\Domain\SitioWeb\Pagina::GRUPOS[$grupo]) }}</p>
                        @foreach ($paginas as $opcion)
                            @php($personal = $filas->get($opcion->clave))
                            <button class="sw-item" type="button" wire:click="seleccionar('{{ $opcion->clave }}')" wire:key="sw-{{ $opcion->clave }}" aria-current="{{ $opcion->clave === $clave ? 'true' : 'false' }}">
                                <span>{{ $opcion->nombre }}<small>/{{ ltrim($opcion->ruta, '/') }}</small></span>
                                <span class="sw-marcas">
                                    @if ($personal && ! $personal->indexar)<span class="badge warning">noindex</span>@endif
                                    @if ($personal)<span class="badge success">{{ __('Editado') }}</span>@endif
                                </span>
                            </button>
                        @endforeach
                    @endforeach
                </div>
            </section>

            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="settings" /></span>{{ __('Ajustes del sitio') }}</div></div>
                <form wire:submit="guardarAjustes">
                    <div class="fields" style="grid-template-columns:1fr">
                        <x-campo etiqueta="Número de WhatsApp" campo="sitio_whatsapp">
                            <input id="sitio_whatsapp" class="input" type="tel" inputmode="tel" wire:model="sitio_whatsapp" maxlength="20" placeholder="591 71234567">
                            <small class="subtle">{{ __('Código de país + número. Sin número, los botones llevan a la página de contacto.') }}</small>
                        </x-campo>
                        <x-campo etiqueta="Verificación de Google Search Console" campo="sitio_verificacion_google">
                            <input id="sitio_verificacion_google" class="input" type="text" wire:model="sitio_verificacion_google" maxlength="300" placeholder="{{ __('Pega el código o la etiqueta meta') }}">
                            <small class="subtle">{{ __('Agrega la meta google-site-verification en todas las páginas.') }}</small>
                        </x-campo>
                    </div>
                    <div class="product-upload" style="margin-top:13px">
                        <label class="upload-box" for="imagenGeneral">
                            @if ($imagenGeneral && $imagenGeneral->isPreviewable())
                                <img src="{{ $imagenGeneral->temporaryUrl() }}" alt="{{ __('Vista previa') }}" style="max-width:100%;max-height:120px;object-fit:contain;border-radius:9px">
                            @elseif ($ajustes->sitio_og_imagen_path && ! $quitarImagenGeneral)
                                <img src="{{ \App\Domain\SitioWeb\Seo::urlImagen($ajustes->sitio_og_imagen_path) }}" alt="{{ __('Imagen general actual') }}" style="max-width:100%;max-height:120px;object-fit:contain;border-radius:9px">
                            @else
                                <img src="{{ asset(\App\Domain\SitioWeb\Seo::IMAGEN_POR_DEFECTO) }}" alt="{{ __('Imagen incluida en el sistema') }}" style="max-width:100%;max-height:120px;object-fit:contain;border-radius:9px">
                            @endif
                            <b>{{ __('Imagen general para redes') }}</b>
                            <small>{{ __('Se usa en las páginas sin imagen propia · 1200 × 630 px · máx. 2 MB') }}</small>
                        </label>
                        <input id="imagenGeneral" type="file" accept="image/png,image/jpeg,image/webp" wire:model="imagenGeneral" hidden>
                        @error('imagenGeneral')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                        @if ($imagenGeneral || ($ajustes->sitio_og_imagen_path && ! $quitarImagenGeneral))
                            <button class="btn ghost sm" type="button" wire:click="quitarGeneral">{{ __('Usar la imagen incluida') }}</button>
                        @endif
                    </div>
                    <div class="divider"></div>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardarAjustes,imagenGeneral"><x-icon name="check" /> {{ __('Guardar ajustes') }}</button>
                </form>
            </section>
        </div>
    </div>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
