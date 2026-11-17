"""Genera favicons y actualiza el icono dentro de los logos existentes. Uso: python -I generar.py <salida>"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import marca  # noqa: E402
from PIL import Image, ImageDraw  # noqa: E402

PUBLIC = r'D:\NetbeansProjects\mozi-stock\app\public'
salida = sys.argv[1]
os.makedirs(os.path.join(salida, 'img', 'sitio'), exist_ok=True)
os.makedirs(os.path.join(salida, 'img', 'marca'), exist_ok=True)


def parche(base, centro, lado, angulo=0.0, borde=1.5):
    """Pinta el icono nuevo sobre el viejo, dejando intacto un borde de `borde` px (antialias del fondo)."""
    s = 8
    n = round(lado * s)
    nuevo = marca.icono(n)
    mascara = Image.new('L', (n, n), 0)
    ins = borde * s
    ImageDraw.Draw(mascara).rounded_rectangle([ins, ins, n - 1 - ins, n - 1 - ins], radius=marca.RADIO * (n - 2 * ins), fill=255)
    if angulo:
        nuevo = nuevo.rotate(angulo, resample=Image.BICUBIC, expand=True)
        mascara = mascara.rotate(angulo, resample=Image.BICUBIC, expand=True)
    w, h = nuevo.size
    final = (round(w / s), round(h / s))
    nuevo = nuevo.resize(final, Image.BOX)
    mascara = mascara.resize(final, Image.BOX)
    x = round(centro[0] - final[0] / 2)
    y = round(centro[1] - final[1] / 2)
    modo = base.mode
    lienzo = base.convert('RGBA')
    capa = lienzo.crop((x, y, x + final[0], y + final[1]))
    capa.paste(nuevo, (0, 0), mascara)
    lienzo.paste(capa, (x, y))
    return lienzo if modo == 'RGBA' else lienzo.convert(modo)


# 1) favicon.svg
with open(os.path.join(salida, 'favicon.svg'), 'w', encoding='utf-8', newline='\n') as f:
    f.write(marca.svg())

# 2) favicon.ico con 16, 32 y 48 px (cada tamaño dibujado aparte, no reducido de uno grande).
tamanos = [16, 32, 48]
imagenes = [marca.icono(t) for t in tamanos]
imagenes[-1].save(os.path.join(salida, 'favicon.ico'), format='ICO', sizes=[(t, t) for t in tamanos], append_images=imagenes[:-1])

# 3) apple-touch-icon 166×166: a sangre y sin transparencia; iOS redondea las esquinas.
marca.icono(166, redondeado=False).convert('RGB').save(os.path.join(salida, 'apple-touch-icon.png'), optimize=True)

# 4) Icono suelto 512 (mismo margen que el anterior: 56 px).
marca.icono(512, margen=56 / 512).save(os.path.join(salida, 'img', 'sitio', 'logo-mozistock.png'), optimize=True)

# 5) Logos con texto: solo se cambia el icono.
for ruta, centro, lado, angulo in [
    (r'img\marca\logo-mozistock-oscuro.png', (96, 96), 192, 0),
    (r'img\marca\logo-mozistock-claro.png', (96, 96), 192, 0),
    (r'img\marca\logo-mozistock-correo.png', (120, 120), 192, 0),
    (r'img\sitio\og-mozistock.png', (100.5, 146.5), 59.5, 4),
]:
    base = Image.open(os.path.join(PUBLIC, ruta))
    parche(base, centro, lado, angulo).save(os.path.join(salida, ruta), optimize=True)

print('listo')
