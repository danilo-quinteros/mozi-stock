<?php

namespace App\Domain\Catalogo\Importacion;

use Illuminate\Support\Str;

/**
 * Campos de la ficha de producto que se pueden tomar de una hoja de cálculo y los
 * encabezados con los que se reconocen. El usuario siempre puede corregir la asignación.
 */
final class CamposImportacion
{
    /**
     * Orden de presentación. Los sinónimos se comparan ya normalizados con clave().
     *
     * @var array<string, array{etiqueta: string, obligatorio: bool, sinonimos: list<string>}>
     */
    public const CAMPOS = [
        'nombre' => ['etiqueta' => 'Nombre', 'obligatorio' => true, 'sinonimos' => [
            'nombre', 'nombre producto', 'nombre del producto', 'producto', 'productos', 'articulo', 'item', 'descripcion del producto',
        ]],
        'sku' => ['etiqueta' => 'Código', 'obligatorio' => false, 'sinonimos' => [
            'codigo', 'cod', 'sku', 'codigo interno', 'codigo producto', 'cod producto', 'referencia', 'ref', 'codigo articulo',
        ]],
        'codigo_barras' => ['etiqueta' => 'Código de barras', 'obligatorio' => false, 'sinonimos' => [
            'codigo de barras', 'codigo barras', 'cod barras', 'cod de barras', 'barras', 'codbarras', 'ean', 'ean13', 'upc', 'gtin',
        ]],
        'categoria' => ['etiqueta' => 'Categoría', 'obligatorio' => false, 'sinonimos' => [
            'categoria', 'categorias', 'rubro', 'familia', 'linea', 'grupo', 'seccion',
        ]],
        'unidad' => ['etiqueta' => 'Unidad de medida', 'obligatorio' => false, 'sinonimos' => [
            'unidad', 'unidad de medida', 'unidad medida', 'um', 'u m', 'und', 'medida',
        ]],
        'contenido' => ['etiqueta' => 'Contenido', 'obligatorio' => false, 'sinonimos' => [
            'contenido', 'contenido neto', 'presentacion', 'tamano', 'volumen', 'peso neto',
        ]],
        'precio_consumidor' => ['etiqueta' => 'Precio consumidor', 'obligatorio' => true, 'sinonimos' => [
            'precio', 'precio consumidor', 'precio venta', 'precio de venta', 'pvp', 'precio publico', 'precio al publico',
            'precio final', 'p venta', 'venta', 'precio unitario', 'pu', 'precio bs',
        ]],
        'precio_minorista' => ['etiqueta' => 'Precio minorista', 'obligatorio' => false, 'sinonimos' => [
            'precio minorista', 'minorista', 'precio por menor', 'precio menor', 'precio tienda',
        ]],
        'precio_mayorista' => ['etiqueta' => 'Precio mayorista', 'obligatorio' => false, 'sinonimos' => [
            'precio mayorista', 'mayorista', 'precio por mayor', 'precio mayor', 'por mayor',
        ]],
        'stock_minimo' => ['etiqueta' => 'Stock mínimo', 'obligatorio' => false, 'sinonimos' => [
            'stock minimo', 'minimo', 'stock min', 'min', 'existencia minima', 'cantidad minima',
        ]],
        'stock' => ['etiqueta' => 'Stock actual', 'obligatorio' => false, 'sinonimos' => [
            'stock', 'stock actual', 'existencia', 'existencias', 'inventario', 'saldo', 'cantidad', 'cant', 'stock inicial',
        ]],
        'descripcion' => ['etiqueta' => 'Descripción', 'obligatorio' => false, 'sinonimos' => [
            'descripcion', 'detalle', 'observaciones', 'observacion', 'notas',
        ]],
    ];

    /**
     * Para la búsqueda por palabras: los campos más específicos primero, así «precio mayorista»
     * no termina en el precio consumidor ni «código de barras» en el código.
     */
    private const ORDEN_PARCIAL = ['codigo_barras', 'precio_mayorista', 'precio_minorista', 'stock_minimo', 'precio_consumidor',
        'sku', 'nombre', 'categoria', 'unidad', 'contenido', 'stock', 'descripcion'];

    /**
     * Texto comparable: minúsculas, sin tildes ni signos y con espacios simples.
     */
    public static function clave(string $texto): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($texto))));
    }

    /**
     * @return array<string, string> campo => etiqueta
     */
    public static function etiquetas(): array
    {
        return array_map(fn (array $campo) => $campo['etiqueta'], self::CAMPOS);
    }

    /**
     * Propone qué columna corresponde a cada campo a partir de los encabezados.
     *
     * @param  array<int, string>  $encabezados  índice de columna => texto
     * @return array<string, int> campo => índice de columna
     */
    public static function detectar(array $encabezados): array
    {
        $claves = array_map(self::clave(...), $encabezados);
        $asignados = [];

        // 1.º coincidencia exacta con un sinónimo.
        foreach ($claves as $indice => $clave) {
            foreach (self::CAMPOS as $campo => $definicion) {
                if ($clave !== '' && ! isset($asignados[$campo]) && in_array($clave, $definicion['sinonimos'], true)) {
                    $asignados[$campo] = $indice;

                    continue 2;
                }
            }
        }

        // 2.º el encabezado contiene un sinónimo como palabras completas («Precio de venta Bs»).
        foreach ($claves as $indice => $clave) {
            if ($clave === '' || in_array($indice, $asignados, true)) {
                continue;
            }

            foreach (self::ORDEN_PARCIAL as $campo) {
                if (isset($asignados[$campo])) {
                    continue;
                }

                foreach (self::CAMPOS[$campo]['sinonimos'] as $sinonimo) {
                    if (mb_strlen($sinonimo) >= 4 && str_contains(" {$clave} ", " {$sinonimo} ")) {
                        $asignados[$campo] = $indice;

                        continue 3;
                    }
                }
            }
        }

        // Muchas listas llaman «Descripción» al nombre del producto.
        if (! isset($asignados['nombre']) && isset($asignados['descripcion'])) {
            $asignados['nombre'] = $asignados['descripcion'];
            unset($asignados['descripcion']);
        }

        return $asignados;
    }
}
