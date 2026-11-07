<?php

namespace App\Domain\AgenteMozi\Ia\Proveedores;

use stdClass;

/**
 * Ajuste común de los esquemas JSON de herramientas antes de enviarlos a un proveedor: al decodificar
 * o armar arreglos PHP, un objeto vacío ({}) queda como lista vacía ([]) y las APIs lo rechazan.
 */
final class EsquemaJson
{
    /**
     * Esquema JSON con «properties» vacías como objeto ({}), no como lista.
     *
     * @param  array<string, mixed>  $esquema
     * @return array<string, mixed>|stdClass
     */
    public static function objetos(array $esquema): array|stdClass
    {
        if ($esquema === []) {
            return new stdClass;
        }

        foreach ($esquema as $clave => $valor) {
            if ($clave === 'properties' && is_array($valor)) {
                $esquema[$clave] = $valor === [] ? new stdClass : array_map(fn ($sub) => is_array($sub) ? self::objetos($sub) : $sub, $valor);
            } elseif (is_array($valor) && in_array($clave, ['items', 'additionalProperties'], true)) {
                $esquema[$clave] = self::objetos($valor);
            } elseif ($clave === 'anyOf' && is_array($valor)) {
                $esquema[$clave] = array_map(fn ($sub) => is_array($sub) ? self::objetos($sub) : $sub, $valor);
            }
        }

        return $esquema;
    }
}
