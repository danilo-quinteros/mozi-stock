<?php

namespace App\Domain\Tenancy;

/**
 * Validación y normalización de los datos generales de una empresa,
 * compartidas por el panel global y la configuración de la propia empresa.
 */
final class DatosEmpresa
{
    /**
     * @return array<string, list<string>>
     */
    public static function reglas(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'razon_social' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'nit' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function atributos(): array
    {
        return [
            'nombre' => __('nombre comercial'),
            'razon_social' => __('razón social'),
            'email' => __('correo'),
            'telefono' => __('teléfono'),
            'nit' => 'NIT',
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{nombre: string, razon_social: string|null, nit: string|null, email: string|null, telefono: string|null}
     */
    public static function normalizar(array $datos): array
    {
        $opcional = function (string $campo) use ($datos): ?string {
            $valor = trim((string) ($datos[$campo] ?? ''));

            return $valor === '' ? null : $valor;
        };

        return [
            'nombre' => trim((string) ($datos['nombre'] ?? '')),
            'razon_social' => $opcional('razon_social'),
            'nit' => $opcional('nit'),
            'email' => $opcional('email'),
            'telefono' => $opcional('telefono'),
        ];
    }
}
