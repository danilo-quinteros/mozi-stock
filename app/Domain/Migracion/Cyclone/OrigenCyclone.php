<?php

namespace App\Domain\Migracion\Cyclone;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Lectura (solo SELECT) del respaldo del sistema anterior de Cyclone, cargado en una base MySQL aparte. Nunca escribe
 * en esa base. Los importes de origen son FLOAT: se leen como DECIMAL(18,2) desde SQL para no calcular dinero con float.
 */
final class OrigenCyclone
{
    public const CONEXION = 'cyclone_origen';

    public const TABLAS = ['sucursal', 'unidad_presentacion', 'linea_produccion', 'producto', 'stock_sucursal', 'cliente', 'proveedor'];

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Conexión a otra base del mismo servidor y con las mismas credenciales que la conexión principal.
     */
    public static function desdeBase(string $base): self
    {
        $principal = config('database.connections.'.config('database.default'));
        config(['database.connections.'.self::CONEXION => array_merge($principal, ['database' => $base, 'url' => null])]);
        DB::purge(self::CONEXION);

        return new self(DB::connection(self::CONEXION));
    }

    /**
     * Tablas del respaldo que no se pueden leer (no existen o la base no responde).
     *
     * @return list<string>
     */
    public function tablasFaltantes(): array
    {
        $faltantes = [];

        foreach (self::TABLAS as $tabla) {
            try {
                $this->db->table($tabla)->limit(1)->get();
            } catch (QueryException) {
                $faltantes[] = $tabla;
            }
        }

        return $faltantes;
    }

    /** @return list<object{id: int, nombre: string, codigo_siat: int, direccion: ?string, telefono: ?string}> */
    public function sucursales(): array
    {
        return $this->db->table('sucursal')->orderBy('id')->get(['id', 'nombre', 'codigo_siat', 'direccion', 'telefono'])->all();
    }

    /** @return list<object{id: int, nombre: string, unidad_medida_siat: ?string}> */
    public function unidades(): array
    {
        return $this->db->table('unidad_presentacion')->orderBy('id')->get(['id', 'nombre', 'unidad_medida_siat'])->all();
    }

    /** @return list<object{id: int, nombre: string}> */
    public function lineas(): array
    {
        return $this->db->table('linea_produccion')->orderBy('id')->get(['id', 'nombre'])->all();
    }

    /**
     * Precios y costo ya convertidos a DECIMAL(18,2) por el motor (texto, sin pasar por float en PHP).
     *
     * @return list<object>
     */
    public function productos(): array
    {
        return $this->db->table('producto')->orderBy('id')->select([
            'id', 'codigo', 'nombre', 'foto', 'unidad_presentacion', 'linea_produccion', 'stock_minimo', 'stock_maximo',
            'descripcion', 'estado',
        ])->selectRaw('CAST(costo_produccion AS DECIMAL(18,2)) AS costo')
            ->selectRaw('CAST(precio_consumidor AS DECIMAL(18,2)) AS precio_consumidor')
            ->selectRaw('CAST(precio_minorista AS DECIMAL(18,2)) AS precio_minorista')
            ->selectRaw('CAST(precio_mayorista AS DECIMAL(18,2)) AS precio_mayorista')
            ->get()->all();
    }

    /** @return list<object{producto: int, sucursal: int, stock: ?int}> */
    public function stock(): array
    {
        return $this->db->table('stock_sucursal')->orderBy('id')->get(['producto', 'sucursal', 'stock'])->all();
    }

    /** @return list<object> */
    public function clientes(): array
    {
        return $this->db->table('cliente')->orderBy('id')
            ->get(['id', 'nombre_completo', 'razon_social', 'nit', 'email', 'telefono', 'direccion', 'estado'])->all();
    }

    /** @return list<object> */
    public function proveedores(): array
    {
        return $this->db->table('proveedor')->orderBy('id')
            ->get(['id', 'nombre_completo', 'email', 'telefono', 'direccion', 'estado'])->all();
    }
}
