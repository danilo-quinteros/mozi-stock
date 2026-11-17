<?php

namespace App\Domain\CatalogoExterno\Actions;

use App\Models\Empresa;
use App\Models\Proveedor;
use App\Models\ProveedorExterno;

/**
 * Una empresa distribuidora de un proveedor externo (Cyclone) le compra a ese proveedor: al vincularla queda
 * registrado como proveedor de compras con sus datos (config `services.<codigo>.proveedor`).
 *
 * - Si la empresa ya lo tiene con ese NIT, no se duplica ni se pisan sus datos; si estaba desactivado, se reactiva.
 * - Si lo había creado a mano sin NIT y con el mismo nombre («Cyclone», «Cyclone Bolivia»), se completa solo lo que
 *   falta (NIT, ciudad, teléfono, correo); el nombre que puso la empresa se respeta.
 * - Si no, se crea.
 *
 * Solo la llama VincularEmpresaProveedor, ya autorizada y dentro de su transacción. Todas las consultas van
 * filtradas por la empresa vinculada.
 */
final class RegistrarProveedorDeCompras
{
    public function ejecutar(Empresa $empresa, ProveedorExterno $externo): ?Proveedor
    {
        $datos = config('services.'.$externo->codigo.'.proveedor');
        $datos = is_array($datos) ? $datos : [];
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $documento = trim((string) ($datos['documento'] ?? ''));

        // Sin datos configurados para este proveedor externo: no se registra nada.
        if ($nombre === '' || $documento === '') {
            return null;
        }
        $deEmpresa = fn () => Proveedor::withoutGlobalScopes()->where('empresa_id', $empresa->id);

        $proveedor = $deEmpresa()->where('documento', $documento)->lockForUpdate()->first()
            ?? $deEmpresa()->whereNull('documento')
                ->where(fn ($q) => $q->where('nombre', $nombre)->orWhere('nombre', 'like', $nombre.' %'))
                ->orderBy('id')->lockForUpdate()->first();

        if ($proveedor === null) {
            $proveedor = new Proveedor;
            $proveedor->empresa_id = $empresa->id;
            $proveedor->nombre = $nombre;
        }

        foreach (['documento', 'ciudad', 'telefono', 'correo'] as $campo) {
            $valor = trim((string) ($datos[$campo] ?? ''));

            if ($valor !== '' && trim((string) $proveedor->{$campo}) === '') {
                $proveedor->{$campo} = $campo === 'correo' ? mb_strtolower($valor) : $valor;
            }
        }

        $proveedor->activo = true;

        if (! $proveedor->exists || $proveedor->isDirty()) {
            $proveedor->save();
        }

        return $proveedor;
    }
}
