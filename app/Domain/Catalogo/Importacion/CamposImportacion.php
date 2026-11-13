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
        // Opcional; en farmacias suele venir como laboratorio.
        'marca' => ['etiqueta' => 'Marca', 'obligatorio' => false, 'sinonimos' => [
            'marca', 'marcas', 'marca laboratorio', 'laboratorio', 'lab', 'fabricante', 'marca comercial',
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
        // Productos sin control de stock (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 3): sí o no; vacío = sí.
        'controla_stock' => ['etiqueta' => 'Controla stock', 'obligatorio' => false, 'sinonimos' => [
            'controla stock', 'control de stock', 'control stock', 'controlar stock', 'maneja stock', 'lleva stock', 'inventariable',
        ]],
        // Lotes y vencimientos (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 7): solo con la capacidad activa (CAMPOS_LOTES).
        'controla_vencimiento' => ['etiqueta' => 'Controla lote y vencimiento', 'obligatorio' => false, 'sinonimos' => [
            'controla lote y vencimiento', 'controla vencimiento', 'controla lote', 'control de lote', 'control de vencimiento', 'lleva lote',
        ]],
        'lote' => ['etiqueta' => 'N.º de lote', 'obligatorio' => false, 'sinonimos' => [
            'lote', 'n lote', 'no lote', 'nro lote', 'numero de lote', 'numero lote', 'lote n', 'lote nro', 'batch',
        ]],
        'vencimiento' => ['etiqueta' => 'Vencimiento del lote', 'obligatorio' => false, 'sinonimos' => [
            'vencimiento', 'fecha de vencimiento', 'fecha vencimiento', 'vencimiento del lote', 'vence', 'vto', 'f venc', 'fecha venc',
            'caducidad', 'fecha de caducidad', 'expiracion', 'exp',
        ]],
    ];

    /** Campos que solo se ofrecen y se leen con «Lotes y vencimientos» activa. */
    public const CAMPOS_LOTES = ['controla_vencimiento', 'lote', 'vencimiento'];

    /**
     * Valores aceptados para «Controla stock», ya normalizados con clave().
     */
    private const SI = ['si', 's', 'yes', 'y', 'x', '1', 'true', 'verdadero'];

    private const NO = ['no', 'n', '0', 'false', 'falso'];

    /**
     * Para la búsqueda por palabras: los campos más específicos primero, así «precio mayorista»
     * no termina en el precio consumidor ni «código de barras» en el código.
     */
    private const ORDEN_PARCIAL = ['codigo_barras', 'precio_mayorista', 'precio_minorista', 'controla_vencimiento', 'controla_stock', 'stock_minimo', 'precio_consumidor',
        'vencimiento', 'lote', 'sku', 'marca', 'nombre', 'categoria', 'unidad', 'contenido', 'stock', 'descripcion'];

    /**
     * Texto comparable: minúsculas, sin tildes ni signos y con espacios simples.
     */
    public static function clave(string $texto): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($texto))));
    }

    /**
     * Lee una celda de «Controla stock»: vacía = sí (el valor por defecto); null si no es sí ni no.
     */
    public static function siNo(string $texto): ?bool
    {
        $clave = self::clave($texto);

        return match (true) {
            $clave === '', in_array($clave, self::SI, true) => true,
            in_array($clave, self::NO, true) => false,
            default => null,
        };
    }

    /**
     * @return array<string, string> campo => etiqueta
     */
    public static function etiquetas(bool $conLotes = false): array
    {
        return array_map(fn (array $campo) => $campo['etiqueta'], self::campos($conLotes));
    }

    /**
     * Sin la capacidad, la empresa no ve las columnas de lote (lotes § 3).
     *
     * @return array<string, array{etiqueta: string, obligatorio: bool, sinonimos: list<string>}>
     */
    public static function campos(bool $conLotes): array
    {
        return $conLotes ? self::CAMPOS : array_diff_key(self::CAMPOS, array_flip(self::CAMPOS_LOTES));
    }

    /**
     * Propone qué columna corresponde a cada campo a partir de los encabezados.
     *
     * @param  array<int, string>  $encabezados  índice de columna => texto
     * @return array<string, int> campo => índice de columna
     */
    public static function detectar(array $encabezados, bool $conLotes = false): array
    {
        $claves = array_map(self::clave(...), $encabezados);
        $campos = self::campos($conLotes);
        $asignados = [];

        // 1.º coincidencia exacta con un sinónimo.
        foreach ($claves as $indice => $clave) {
            foreach ($campos as $campo => $definicion) {
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
                if (isset($asignados[$campo]) || ! isset($campos[$campo])) {
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
