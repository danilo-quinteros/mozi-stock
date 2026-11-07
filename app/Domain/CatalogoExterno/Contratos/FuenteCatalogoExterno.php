<?php

namespace App\Domain\CatalogoExterno\Contratos;

use App\Domain\CatalogoExterno\DTOs\ProductoExternoDto;
use App\Models\ProveedorExterno;
use Generator;

interface FuenteCatalogoExterno
{
    /**
     * Obtiene el listado completo de productos desde la API del proveedor externo.
     * Generador que pagina de forma transparente para evitar saturar memoria.
     *
     * @return Generator<int, ProductoExternoDto>
     */
    public function obtenerProductos(ProveedorExterno $proveedor): Generator;

    /**
     * Descarga el contenido binario de una imagen desde la URL remota del proveedor.
     * Devuelve null si no se pudo descargar o si supera el límite de tamaño.
     */
    public function descargarImagen(string $url, ?string $token = null): ?string;
}
