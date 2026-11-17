<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Empresas que ya estaban vinculadas (activas) como distribuidoras de Cyclone: se les registra Cyclone como proveedor
 * de compras, con la misma regla que RegistrarProveedorDeCompras al vincular:
 * - con ese NIT ya registrado no se duplica ni se pisan datos (si estaba inactivo, se reactiva);
 * - uno creado a mano sin NIT y con el mismo nombre («Cyclone», «Cyclone …») se completa solo en lo que falta;
 * - si no hay ninguno, se crea.
 * Consultas siempre por empresa. Reejecutable: una segunda pasada no cambia nada. Los datos están aquí (y no en
 * config) para que la migración no cambie si el config cambia después.
 */
return new class extends Migration
{
    private const DATOS = [
        'nombre' => 'Cyclone',
        'documento' => '7687402012',
        'ciudad' => 'Santa Cruz de la Sierra',
        'telefono' => '77092733',
        'correo' => 'ventas@cyclonelimpieza.com',
    ];

    public function up(): void
    {
        $empresas = DB::table('empresa_proveedor_externo as v')
            ->join('proveedores_externos as p', 'p.id', '=', 'v.proveedor_externo_id')
            ->where('p.codigo', 'cyclone')
            ->where('v.activo', true)
            ->orderBy('v.empresa_id')
            ->pluck('v.empresa_id')
            ->unique();

        foreach ($empresas as $empresaId) {
            DB::transaction(fn () => $this->registrar((int) $empresaId));
        }
    }

    public function down(): void
    {
        // Sin reversa: el proveedor puede tener compras registradas.
    }

    private function registrar(int $empresaId): void
    {
        $deEmpresa = fn () => DB::table('proveedores')->where('empresa_id', $empresaId);
        $proveedor = $deEmpresa()->where('documento', self::DATOS['documento'])->lockForUpdate()->first()
            ?? $deEmpresa()->whereNull('documento')
                ->where(fn ($q) => $q->where('nombre', self::DATOS['nombre'])->orWhere('nombre', 'like', self::DATOS['nombre'].' %'))
                ->orderBy('id')->lockForUpdate()->first();

        if ($proveedor === null) {
            DB::table('proveedores')->insert(['empresa_id' => $empresaId, ...self::DATOS, 'activo' => true,
                'created_at' => now(), 'updated_at' => now()]);

            return;
        }

        $cambios = [];

        foreach (['documento', 'ciudad', 'telefono', 'correo'] as $campo) {
            if (trim((string) $proveedor->{$campo}) === '') {
                $cambios[$campo] = self::DATOS[$campo];
            }
        }

        if (! (bool) $proveedor->activo) {
            $cambios['activo'] = true;
        }

        if ($cambios !== []) {
            $deEmpresa()->where('id', $proveedor->id)->update($cambios + ['updated_at' => now()]);
        }
    }
};
