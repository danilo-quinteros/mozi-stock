<?php

namespace App\Domain\Catalogo;

use Illuminate\Support\Facades\DB;

/**
 * Lista sugerida de tallas y colores (ESPECIFICACION_TALLAS_Y_COLORES.md § 3.1 y § 3.2). Se crea al activar
 * «Tallas y colores» (alta de la empresa con el rubro Ropa o Funciones del negocio) y desde la pantalla de listas
 * cuando todavía están vacías. Solo se crea si la empresa no tiene ningún grupo de tallas ni ningún color, así que
 * nunca duplica ni pisa lo que el administrador ya editó. Inserción en bloque, sin una consulta por talla.
 */
final class ListasSugeridas
{
    /** @var array<string, list<string>> grupo => tallas en orden */
    public const GRUPOS = [
        'Letras' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
        'Calzado' => ['34', '35', '36', '37', '38', '39', '40', '41', '42', '43', '44'],
        'Pantalón' => ['28', '30', '32', '34', '36', '38', '40'],
        'Niños' => ['2', '4', '6', '8', '10', '12', '14', '16'],
    ];

    /** @var list<array{0: string, 1: string, 2: string}> nombre, código y muestra */
    public const COLORES = [
        ['Negro', 'NEG', '#1F1F1F'],
        ['Blanco', 'BLA', '#FFFFFF'],
        ['Gris', 'GRI', '#8C8C8C'],
        ['Azul', 'AZU', '#2F6FD6'],
        ['Azul marino', 'AZM', '#1F2D55'],
        ['Rojo', 'ROJ', '#C9302C'],
        ['Verde', 'VER', '#2E8B57'],
        ['Beige', 'BEI', '#D8C7A5'],
        ['Café', 'CAF', '#6F4E37'],
        ['Rosado', 'ROS', '#F0A6BE'],
    ];

    public function vacias(int $empresaId): bool
    {
        return ! DB::table('grupos_talla')->where('empresa_id', $empresaId)->exists()
            && ! DB::table('colores')->where('empresa_id', $empresaId)->exists();
    }

    /**
     * @return bool true si se crearon
     */
    public function crearSiVacias(int $empresaId): bool
    {
        if (! $this->vacias($empresaId)) {
            return false;
        }

        $ahora = now();
        $orden = 0;

        // insertOrIgnore: dos activaciones simultáneas no fallan; los únicos de la base evitan duplicados.
        DB::table('grupos_talla')->insertOrIgnore(array_map(function (string $grupo) use ($empresaId, $ahora, &$orden): array {
            return ['empresa_id' => $empresaId, 'nombre' => $grupo, 'orden' => ++$orden, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora];
        }, array_keys(self::GRUPOS)));

        $grupos = DB::table('grupos_talla')->where('empresa_id', $empresaId)->whereIn('nombre', array_keys(self::GRUPOS))->pluck('id', 'nombre');
        $tallas = [];

        foreach (self::GRUPOS as $grupo => $nombres) {
            foreach ($nombres as $indice => $nombre) {
                $tallas[] = ['empresa_id' => $empresaId, 'grupo_talla_id' => $grupos[$grupo], 'nombre' => $nombre, 'orden' => $indice + 1,
                    'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora];
            }
        }

        DB::table('tallas')->insertOrIgnore($tallas);
        DB::table('colores')->insertOrIgnore(array_map(fn (array $color): array => [
            'empresa_id' => $empresaId, 'nombre' => $color[0], 'codigo' => $color[1], 'muestra_hex' => $color[2],
            'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
        ], self::COLORES));

        return true;
    }
}
