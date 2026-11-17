"""Marca MoziStock: tres barras en forma de M (laterales iguales y altas, la del medio más baja).

Geometría en un cuadrado de 400 unidades. Todo se dibuja con supermuestreo y se reduce con LANCZOS.
"""
import math
from PIL import Image, ImageDraw, ImageChops

C1 = (0xF3, 0xA1, 0x5D)  # arriba-izquierda
C2 = (0xC8, 0x70, 0x30)  # abajo-derecha
BARRA = (0x29, 0x18, 0x0E)

LADO = 400
RADIO = 0.27  # del lado
ANCHO = 62
HUECO = 36
BASE = 304       # borde inferior de las barras laterales
ALTO_LAT = 200   # barras laterales
ALTO_MED = 124   # barra del medio
SESGO = 0        # grados; 0 = barra del medio recta (antes skewY(-23deg))


def columnas():
    x0 = (LADO - (3 * ANCHO + 2 * HUECO)) / 2
    return [x0 + i * (ANCHO + HUECO) for i in range(3)]


def degradado(w, h):
    """linear-gradient(145deg, C1, C2) sobre un rectángulo w×h."""
    ang = math.radians(145)
    dx, dy = math.sin(ang), -math.cos(ang)
    largo = abs(w * dx) + abs(h * dy)
    img = Image.new('RGB', (w, h))
    px = img.load()
    for y in range(h):
        for x in range(w):
            t = ((x - w / 2) * dx + (y - h / 2) * dy) / largo + 0.5
            t = min(1, max(0, t))
            px[x, y] = tuple(round(C1[i] + (C2[i] - C1[i]) * t) for i in range(3))
    return img


def capa_barras(escala, sesgo=SESGO, alto_med=ALTO_MED):
    """Máscara L (LADO*escala) con las tres barras."""
    n = round(LADO * escala)
    mascara = Image.new('L', (n, n), 0)
    for i, x in enumerate(columnas()):
        alto = alto_med if i == 1 else ALTO_LAT
        # Barra del medio centrada en la misma línea media que las laterales, para que la M quede asentada.
        y1 = BASE if i != 1 else BASE
        y0 = y1 - alto
        barra = Image.new('L', (n, n), 0)
        d = ImageDraw.Draw(barra)
        r_sup = 0.32 * ANCHO * escala
        r_inf = 0.13 * ANCHO * escala
        caja = [x * escala, y0 * escala, (x + ANCHO) * escala, y1 * escala]
        # Esquinas de arriba más redondeadas que las de abajo (como border-radius 3px 3px 1px 1px).
        d.rounded_rectangle(caja, radius=r_inf, fill=255)
        mitad = [caja[0], caja[1], caja[2], (caja[1] + caja[3]) / 2]
        d.rectangle(mitad, fill=0)
        d.rounded_rectangle(mitad, radius=r_sup, fill=255, corners=(True, True, False, False))
        if i == 1 and sesgo:
            # skewY(-sesgo) alrededor del centro de la barra: y' = y - tan(sesgo)*(x - cx)
            t = math.tan(math.radians(sesgo))
            cx = (x + ANCHO / 2) * escala
            # Image.transform usa la inversa: (x, y) destino <- (x, y + t*(x-cx)) origen
            barra = barra.transform((n, n), Image.AFFINE, (1, 0, 0, t, 1, -t * cx), resample=Image.BICUBIC)
        mascara = ImageChops.lighter(mascara, barra)
    return mascara


def icono(px, sesgo=SESGO, alto_med=ALTO_MED, redondeado=True, margen=0.0):
    """Icono RGBA de px×px. margen: fracción del lienzo libre a cada lado (transparente)."""
    s = 4  # supermuestreo
    n = px * s
    interior = round(n * (1 - 2 * margen))
    off = (n - interior) // 2
    escala = interior / LADO
    fondo = degradado(interior // 4 + 1, interior // 4 + 1).resize((interior, interior), Image.BICUBIC)
    lienzo = Image.new('RGBA', (n, n), (0, 0, 0, 0))
    forma = Image.new('L', (interior, interior), 0)
    if redondeado:
        ImageDraw.Draw(forma).rounded_rectangle([0, 0, interior - 1, interior - 1], radius=RADIO * interior, fill=255)
    else:
        forma.paste(255, [0, 0, interior, interior])
    cuadro = fondo.convert('RGBA')
    barras = capa_barras(escala, sesgo, alto_med)
    cuadro.paste(Image.new('RGBA', (interior, interior), BARRA + (255,)), (0, 0), barras)
    cuadro.putalpha(forma)
    lienzo.paste(cuadro, (off, off), cuadro)
    return lienzo.resize((px, px), Image.BOX)


def svg(sesgo=SESGO, alto_med=ALTO_MED):
    """favicon.svg con las mismas medidas."""
    t = math.tan(math.radians(sesgo))
    partes = []
    for i, x in enumerate(columnas()):
        alto = alto_med if i == 1 else ALTO_LAT
        y0 = BASE - alto
        rs, ri = round(0.32 * ANCHO, 2), round(0.13 * ANCHO, 2)
        d = (f'M{x:g},{y0 + rs} a{rs},{rs} 0 0 1 {rs},{-rs} h{round(ANCHO - 2 * rs, 2)} a{rs},{rs} 0 0 1 {rs},{rs} '
             f'v{round(alto - rs - ri, 2)} a{ri},{ri} 0 0 1 {-ri},{ri} h{round(-(ANCHO - 2 * ri), 2)} a{ri},{ri} 0 0 1 {-ri},{-ri} z')
        attr = ''
        if i == 1 and sesgo:
            cx = x + ANCHO / 2
            attr = f' transform="matrix(1 {-t:.4f} 0 1 0 {t * cx:.3f})"'
        partes.append(f'<path d="{d}"{attr}/>')
    radio = round(RADIO * LADO)
    return (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400">'
        '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        '<stop offset="0" stop-color="#f3a15d"/><stop offset="1" stop-color="#c87030"/></linearGradient></defs>'
        f'<rect width="400" height="400" rx="{radio}" fill="url(#g)"/>'
        f'<g fill="#29180e">{"".join(partes)}</g></svg>\n'
    )
