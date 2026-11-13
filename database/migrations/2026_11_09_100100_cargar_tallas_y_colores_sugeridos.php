<?php

use App\Domain\Catalogo\ListasSugeridas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lista sugerida de tallas y colores para las empresas que ya tenían «Tallas y colores» activa antes de la
 * etapa V1 (por ejemplo, las registradas como Ropa desde R0). ESPECIFICACION_TALLAS_Y_COLORES.md § 3.1: la lista
 * se crea al activar el rubro; desde V1 la crean el alta de la empresa y Funciones del negocio, y esta migración
 * cubre a las que la activaron antes.
 *
 * Solo carga la lista en empresas sin ningún grupo de tallas ni color (ListasSugeridas::crearSiVacias), así que
 * volver a correrla no duplica ni pisa lo que el administrador ya creó. Una transacción por empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        $listas = app(ListasSugeridas::class);
        $empresas = DB::table('empresa_capacidades')
            ->where('capacidad', 'variantes')
            ->where('activa', true)
            ->orderBy('empresa_id')
            ->pluck('empresa_id');

        // foreach y no Collection::each: crearSiVacias devuelve false en una empresa que ya tiene listas, y each
        // se detendría ahí sin cargar las siguientes.
        foreach ($empresas as $empresaId) {
            DB::transaction(fn (): bool => $listas->crearSiVacias((int) $empresaId));
        }
    }

    public function down(): void
    {
        // Sin reversa: las listas pueden estar en uso o editadas. Si se revierte la migración anterior, sus tablas
        // se eliminan junto con estos datos.
    }
};
