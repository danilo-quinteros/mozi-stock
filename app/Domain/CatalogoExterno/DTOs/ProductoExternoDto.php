<?php

namespace App\Domain\CatalogoExterno\DTOs;

/**
 * Representación inmutable y segura de un producto recibido de un proveedor externo.
 * LISTA BLANCA ESTRICTA: los campos de costo, precios y existencias NO existen en este DTO.
 */
final readonly class ProductoExternoDto
{
    public function __construct(
        public string $externoId,
        public ?string $codigo,
        public string $nombre,
        public ?string $descripcion,
        public ?string $fotoUrl,
        public ?string $presentacion,
        public ?string $linea,
        public bool $activo,
        public ?string $origenUpdatedAt,
        public ?string $unidadSiat = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = (string) ($data['id'] ?? $data['externo_id'] ?? '');

        // Soporta respuesta directa o anidada (ej. ['presentacion' => ['nombre' => '350gr']] o ['presentacion' => '350gr'])
        $presentacion = null;
        if (isset($data['presentacion'])) {
            $presentacion = is_array($data['presentacion'])
                ? ($data['presentacion']['nombre'] ?? null)
                : (string) $data['presentacion'];
        }

        $unidadSiat = null;
        if (isset($data['unidad_siat']) || isset($data['unidad_medida_siat'])) {
            $val = $data['unidad_siat'] ?? $data['unidad_medida_siat'];
            $unidadSiat = filled($val) ? trim((string) $val) : null;
        } elseif (isset($data['presentacion']) && is_array($data['presentacion']) && isset($data['presentacion']['unidad_medida_siat'])) {
            $unidadSiat = trim((string) $data['presentacion']['unidad_medida_siat']);
        }

        $linea = null;
        if (isset($data['linea'])) {
            $linea = is_array($data['linea'])
                ? ($data['linea']['nombre'] ?? null)
                : (string) $data['linea'];
        }

        return new self(
            externoId: $id,
            codigo: filled($data['codigo'] ?? null) ? trim((string) $data['codigo']) : null,
            nombre: trim((string) ($data['nombre'] ?? '')),
            descripcion: filled($data['descripcion'] ?? null) ? trim((string) $data['descripcion']) : null,
            fotoUrl: filled($data['foto_url'] ?? $data['foto'] ?? null) ? trim((string) ($data['foto_url'] ?? $data['foto'])) : null,
            presentacion: $presentacion !== null ? trim($presentacion) : null,
            linea: $linea !== null ? trim($linea) : null,
            activo: (bool) ($data['estado'] ?? $data['activo'] ?? true),
            origenUpdatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
            unidadSiat: $unidadSiat,
        );
    }
}
