<?php

namespace App\Domain\CatalogoExterno;

use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Support\Decimal;

/**
 * Interpreta presentaciones de proveedores externos (ej. Cyclone: "350gr", "500ml", "1kg", "Litros", "Kg.").
 * - Si tiene cantidad fija y unidad (ej. "350gr", "4.5L"): se comercializa "Por unidad" (envasado).
 * - Si es una unidad de medida sin número (ej. "Litros", "Kg.") o es a granel: se comercializa "Por peso o medida" (fraccionable).
 */
final class InterpretePresentacion
{
    /**
     * @return array{
     *     contenido: ?string,
     *     contenido_simple: ?string,
     *     unidad_abreviatura: string,
     *     unidad_medida_sin: ?int,
     *     tipo_venta: string,
     *     nombre_limpio: string,
     *     interpretada: bool,
     *     nombre_mostrado: string
     * }
     */
    public static function interpretar(?string $presentacion, string $nombreOriginal = '', ?string $descripcion = null, ?string $unidadSiat = null): array
    {
        $raw = trim((string) $presentacion);
        $nombreLimpio = trim($nombreOriginal);

        $contenido = null;
        $unidad = 'und.';
        $tipoVenta = 'unidad';
        $interpretada = false;

        if ($raw === '') {
            if (filled($descripcion) && preg_match('/(?:agranel|a\s+granel)/i', (string) $descripcion)) {
                $tipoVenta = 'medida';
                $unidad = preg_match('/(?:litro|lts|lt)/i', (string) $descripcion.' '.$nombreLimpio) ? 'L' : 'kg';
                $interpretada = true;
            }

            return [
                'contenido' => null,
                'contenido_simple' => null,
                'unidad_abreviatura' => $unidad,
                'unidad_medida_sin' => UnidadMedida::resolverCodigoSin($unidadSiat, $unidad),
                'tipo_venta' => $tipoVenta,
                'nombre_limpio' => $nombreLimpio,
                'interpretada' => $interpretada,
                'nombre_mostrado' => $nombreLimpio,
            ];
        }

        // Normalizar comas por puntos en números decimales (ej. "1,5 lt" -> "1.5 lt")
        $texto = str_replace(',', '.', $raw);
        $textoNorm = mb_strtolower(trim($texto, ". \t\n\r\0\x0B"));

        // CASO 1: Unidades puras sin número (venta a granel / por peso o medida)
        // En Cyclone, productos a granel tienen presentación "Litros" o "Kg." y se venden fraccionables
        $unidadGranel = match ($textoNorm) {
            'litro', 'litros', 'lt', 'lts', 'l' => 'L',
            'kg', 'kgs', 'kilo', 'kilos', 'kilogramo', 'kilogramos', 'k' => 'kg',
            'gramo', 'gramos', 'gr', 'grs', 'g' => 'g',
            'metro', 'metros', 'm', 'mt', 'mts' => 'm',
            default => null,
        };

        if ($unidadGranel !== null) {
            $unidad = $unidadGranel;
            $tipoVenta = 'medida';
            $interpretada = true;
        } elseif (preg_match('/^(\d+(?:\.\d+)?)\s*([a-zA-Z\.]+)$/u', $texto, $matches)) {
            // CASO 2: Envase específico con cantidad + unidad (producto envasado vendido por unidad)
            // Ej: "350gr", "500ml", "1L", "4.5L", "10L", "900ml"
            $numStr = $matches[1];
            $sufijo = mb_strtolower(trim($matches[2], '. '));

            $unidadMapeada = match ($sufijo) {
                'gr', 'grs', 'g', 'gramo', 'gramos' => 'g',
                'kg', 'kgs', 'kilo', 'kilos', 'kilogramo', 'kilogramos', 'k' => 'kg',
                'ml', 'mls', 'mililitro', 'mililitros', 'cc', 'c.c' => 'ml',
                'l', 'lt', 'lts', 'litro', 'litros' => 'L',
                'und', 'unds', 'unidad', 'unidades', 'pza', 'pzas' => 'und.',
                default => null,
            };

            if ($unidadMapeada !== null) {
                $cant = Decimal::normalizar($numStr, Decimal::ESCALA_CANTIDAD);
                if ($cant !== null && Decimal::comparar($cant, '0') > 0) {
                    $contenido = $cant;
                    $unidad = $unidadMapeada;
                    $tipoVenta = 'unidad';
                    $interpretada = true;
                }
            }
        } elseif (preg_match('/^(?:und|unidad|unidades|pza|pzas|caja|cajas|paquete|paquetes|pack|delivery)$/i', $texto)) {
            // CASO 3: Unidades, cajas o paquetes estándar
            $unidad = match ($textoNorm) {
                'caja', 'cajas' => 'cj.',
                'paquete', 'paquetes', 'pack' => 'paq.',
                default => 'und.',
            };
            $tipoVenta = 'unidad';
            $interpretada = true;
        } elseif (preg_match('/(?:agranel|a\s+granel)/i', $texto) || (filled($descripcion) && preg_match('/(?:agranel|a\s+granel)/i', (string) $descripcion))) {
            // CASO 4: Indicación explícita a granel
            $tipoVenta = 'medida';
            $unidad = preg_match('/(?:litro|lts|lt)/i', $texto.' '.(string) $descripcion.' '.$nombreLimpio) ? 'L' : 'kg';
            $interpretada = true;
        }

        // Si se interpretó contenido y unidad, limpiar el sufijo redundante en el nombre original si existe
        if ($interpretada && $contenido !== null && $nombreLimpio !== '') {
            $nombreLimpio = self::limpiarSufijoNombre($nombreLimpio, $raw, $contenido, $unidad);
        }

        $nombreMostrado = ($tipoVenta === 'unidad' && $contenido !== null)
            ? Producto::formatoNombreCompleto($nombreLimpio, $contenido, $unidad)
            : $nombreLimpio;

        return [
            'contenido' => $contenido,
            'contenido_simple' => $contenido !== null ? Decimal::simple($contenido) : null,
            'unidad_abreviatura' => $unidad,
            'unidad_medida_sin' => UnidadMedida::resolverCodigoSin($unidadSiat, $unidad),
            'tipo_venta' => $tipoVenta,
            'nombre_limpio' => $nombreLimpio,
            'interpretada' => $interpretada,
            'nombre_mostrado' => $nombreMostrado,
        ];
    }

    /**
     * Elimina del nombre sufijos redundantes como "Shampoo 350gr" o "Jabón - 500 ml".
     */
    private static function limpiarSufijoNombre(string $nombre, string $rawPresentacion, string $contenido, string $unidad): string
    {
        $numSimple = Decimal::simple($contenido);
        // Expresión regular que coincide al final del nombre con la presentación o variaciones
        $variaciones = preg_quote($rawPresentacion, '/');
        $numVariacion = preg_quote($numSimple, '/');

        // Regex para sufijo al final: opcional guion/barra + número + unidad (gr, g, ml, l, etc.)
        $patron = '/[\s\-_]*(?:'.$variaciones.'|'.$numVariacion.'\s*(?:gr|grs|g|kg|kgs|ml|cc|l|lt|lts))\s*$/ui';

        $limpio = preg_replace($patron, '', $nombre);

        return trim((string) $limpio) !== '' ? trim((string) $limpio) : $nombre;
    }
}
