<?php

namespace App\Domain\Migracion\Cyclone;

use App\Domain\CatalogoExterno\InterpretePresentacion;
use App\Support\Decimal;
use Illuminate\Support\Str;

/**
 * Traduce el respaldo de Cyclone a registros de MoziStock, sin escribir nada: lo usan la vista previa y la migración.
 *
 * - Sucursales: la de código SIAT 0 es la casa matriz (reutiliza «Casa Matriz», código CM); las demás, código S<SIAT>.
 * - Productos: la presentación («900ml», «4.5L», «Litros») se interpreta igual que en el catálogo que reciben las
 *   distribuidoras (InterpretePresentacion): contenido + unidad, y venta por unidad o por medida. La línea es la
 *   categoría. El código es el SKU. Los códigos SIAT por producto no se migran (decisión del propietario, 20/11/2026).
 * - Stock: cada fila positiva de stock_sucursal es una línea de la entrada «Inventario inicial» de su sucursal. El
 *   stock negativo entra como 0 y queda en el informe.
 * - Clientes: el nombre es la razón social si existe; si no, el nombre completo (decisión del propietario).
 *
 * Lo que no se puede guardar tal cual va a `observaciones` (informe, no detiene la migración); lo que impediría una
 * migración correcta va a `errores` (la detiene).
 */
final class PlanMigracionCyclone
{
    public const TITULOS = [
        'presentacion' => 'Presentación sin medida reconocida: el producto queda en «und.» sin contenido',
        'precio_cero' => 'Precio consumidor en 0: revisar antes de vender',
        'precio_opcional' => 'Precio minorista o mayorista en 0: queda vacío',
        'stock_maximo' => 'Stock máximo menor que el mínimo: queda vacío',
        'stock_negativo' => 'Stock negativo en Cyclone: entra como 0',
        'stock_inactivo' => 'Stock de productos inactivos: se carga igual (el producto sigue inactivo)',
        'stock_huerfano' => 'Stock de productos que ya no existen en Cyclone: no se migra',
        'stock_repetido' => 'Producto repetido en la misma sucursal: se suman las cantidades',
        'cliente_sin_nombre' => 'Cliente sin nombre: no se migra',
        'cliente_duplicado' => 'Cliente repetido (mismo NIT y nombre): se guarda una sola vez',
        'nit_repetido' => 'NIT que ya usa otro cliente: este cliente queda sin NIT',
        'nit_sin_numero' => 'NIT sin número válido («0», «no tiene»): queda vacío',
        'correo_invalido' => 'Correo no válido: queda vacío',
        'telefono_largo' => 'Teléfono de más de 40 caracteres: queda vacío',
        'texto_recortado' => 'Texto recortado al largo máximo de MoziStock',
    ];

    /**
     * Informe en texto de lo que no se migra tal cual (lo escriben el comando y la pantalla de Superadmin).
     *
     * @param  array<string, mixed>  $plan
     */
    public static function informe(string $destino, array $plan): string
    {
        $texto = "Migración de Cyclone a {$destino} - ".now()->format('d/m/Y H:i')."\n";

        foreach ($plan['errores'] as $error) {
            $texto .= "ERROR: {$error}\n";
        }

        foreach ($plan['observaciones'] as $clave => $detalles) {
            $texto .= "\n== ".(self::TITULOS[$clave] ?? $clave).' ('.count($detalles).")\n";
            $texto .= implode("\n", array_map(fn (string $d): string => "- {$d}", $detalles))."\n";
        }

        return $texto;
    }

    /** @var list<string> */
    private array $errores = [];

    /** @var array<string, list<string>> */
    private array $observaciones = [];

    /**
     * @return array{
     *     sucursales: list<array{origen_id: int, nombre: string, codigo: string, direccion: ?string, telefono: ?string, matriz: bool}>,
     *     productos: list<array<string, mixed>>,
     *     stock: array<int, list<array{producto_origen: int, cantidad: string}>>,
     *     clientes: list<array{nombre: string, documento: ?string, telefono: ?string, correo: ?string, direccion: ?string, activo: bool}>,
     *     proveedores: list<array{nombre: string, telefono: ?string, correo: ?string, direccion: ?string, activo: bool}>,
     *     errores: list<string>,
     *     observaciones: array<string, list<string>>
     * }
     */
    public function construir(OrigenCyclone $origen): array
    {
        $this->errores = [];
        $this->observaciones = [];

        $sucursales = $this->sucursales($origen->sucursales());
        $productos = $this->productos($origen->productos(), $origen->unidades(), $origen->lineas());
        $stock = $this->stock($origen->stock(), $productos, $sucursales);

        return [
            'sucursales' => $sucursales,
            'productos' => $productos,
            'stock' => $stock,
            'clientes' => $this->clientes($origen->clientes()),
            'proveedores' => $this->proveedores($origen->proveedores()),
            'errores' => $this->errores,
            'observaciones' => $this->observaciones,
        ];
    }

    /**
     * @param  list<object>  $filas
     * @return list<array{origen_id: int, nombre: string, codigo: string, direccion: ?string, telefono: ?string, matriz: bool}>
     */
    private function sucursales(array $filas): array
    {
        $plan = [];
        $codigos = [];
        $hayMatriz = false;

        foreach ($filas as $fila) {
            $nombre = $this->texto($fila->nombre, 120, "Sucursal {$fila->id}");

            if ($nombre === null) {
                $this->errores[] = "La sucursal {$fila->id} de Cyclone no tiene nombre.";

                continue;
            }

            $matriz = ! $hayMatriz && (int) $fila->codigo_siat === 0;
            $hayMatriz = $hayMatriz || $matriz;
            $codigo = $matriz ? 'CM' : 'S'.(int) $fila->codigo_siat;

            if (isset($codigos[$codigo])) {
                $codigo = 'S-'.$fila->id;
            }

            $codigos[$codigo] = true;

            $plan[] = [
                'origen_id' => (int) $fila->id,
                'nombre' => $nombre,
                'codigo' => $codigo,
                'direccion' => $this->texto($fila->direccion, 255, "Dirección de {$nombre}"),
                'telefono' => $this->telefono($fila->telefono, $nombre),
                'matriz' => $matriz,
            ];
        }

        if ($plan === []) {
            $this->errores[] = 'El respaldo no tiene sucursales.';
        }

        return $plan;
    }

    /**
     * @param  list<object>  $filas
     * @param  list<object>  $unidades
     * @param  list<object>  $lineas
     * @return list<array<string, mixed>>
     */
    private function productos(array $filas, array $unidades, array $lineas): array
    {
        $presentaciones = collect($unidades)->keyBy('id');
        $categorias = collect($lineas)->keyBy('id');
        $plan = [];
        $skus = [];

        foreach ($filas as $fila) {
            $etiqueta = trim((string) $fila->codigo).' '.trim((string) $fila->nombre);
            $sku = Str::upper(trim((string) $fila->codigo));
            $presentacion = $presentaciones->get($fila->unidad_presentacion);

            if ($sku === '' || mb_strlen($sku) > 40) {
                $this->errores[] = "Producto {$fila->id} ({$etiqueta}): el código debe tener entre 1 y 40 caracteres.";

                continue;
            }

            if (isset($skus[$sku])) {
                $this->errores[] = "El código {$sku} está repetido en los productos {$skus[$sku]} y {$fila->id}.";

                continue;
            }

            $skus[$sku] = $fila->id;

            if ($presentacion === null) {
                $this->errores[] = "{$etiqueta}: su unidad de presentación ({$fila->unidad_presentacion}) no existe.";

                continue;
            }

            $medida = InterpretePresentacion::interpretar($presentacion->nombre, (string) $fila->nombre, $fila->descripcion, $presentacion->unidad_medida_siat);

            if (! $medida['interpretada']) {
                $this->observar('presentacion', "{$etiqueta}: «{$presentacion->nombre}»");
            }

            $nombre = $this->texto($medida['nombre_limpio'], 150, $etiqueta);

            if ($nombre === null) {
                $this->errores[] = "Producto {$fila->id} ({$sku}): no tiene nombre.";

                continue;
            }

            $consumidor = Decimal::normalizar((string) $fila->precio_consumidor, Decimal::ESCALA_DINERO);
            $costo = Decimal::normalizar((string) $fila->costo, Decimal::ESCALA_DINERO);

            if ($consumidor === null || Decimal::comparar($consumidor, '0') < 0 || $costo === null || Decimal::comparar($costo, '0') < 0) {
                $this->errores[] = "{$etiqueta}: precio consumidor o costo no válido.";

                continue;
            }

            if (Decimal::comparar($consumidor, '0') === 0) {
                $this->observar('precio_cero', $etiqueta);
            }

            $minimo = max(0, (int) $fila->stock_minimo);
            $maximo = $fila->stock_maximo === null ? null : (int) $fila->stock_maximo;

            if ($maximo !== null && $maximo < $minimo) {
                $this->observar('stock_maximo', "{$etiqueta}: mínimo {$minimo}, máximo {$maximo}");
                $maximo = null;
            }

            $linea = $categorias->get($fila->linea_produccion);

            $plan[] = [
                'origen_id' => (int) $fila->id,
                'sku' => $sku,
                'nombre' => $nombre,
                'descripcion' => filled($fila->descripcion) ? trim((string) $fila->descripcion) : null,
                'categoria' => $linea === null ? null : $this->texto($linea->nombre, 80, 'Línea '.$linea->id),
                'unidad' => $medida['unidad_abreviatura'],
                'tipo_venta' => $medida['tipo_venta'],
                'contenido' => $medida['contenido'],
                'precio_consumidor' => $consumidor,
                'precio_minorista' => $this->precioOpcional($fila->precio_minorista, $etiqueta, 'minorista'),
                'precio_mayorista' => $this->precioOpcional($fila->precio_mayorista, $etiqueta, 'mayorista'),
                'costo' => $costo,
                'stock_minimo' => $minimo.'.000',
                'stock_maximo' => $maximo === null ? null : $maximo.'.000',
                'activo' => (bool) $fila->estado,
                'foto' => filled($fila->foto) ? trim((string) $fila->foto) : null,
            ];
        }

        return $plan;
    }

    /**
     * @param  list<object>  $filas
     * @param  list<array<string, mixed>>  $productos
     * @param  list<array{origen_id: int, nombre: string}>  $sucursales
     * @return array<int, list<array{producto_origen: int, cantidad: string}>>
     */
    private function stock(array $filas, array $productos, array $sucursales): array
    {
        $porId = collect($productos)->keyBy('origen_id');
        $nombresSucursal = collect($sucursales)->pluck('nombre', 'origen_id');
        $cantidades = [];

        foreach ($filas as $fila) {
            $producto = $porId->get((int) $fila->producto);
            $sucursal = $nombresSucursal->get((int) $fila->sucursal);
            $cantidad = (int) $fila->stock;

            if ($producto === null || $sucursal === null) {
                if ($cantidad !== 0) {
                    $this->observar('stock_huerfano', "Producto {$fila->producto}, sucursal {$fila->sucursal}: {$cantidad}");
                }

                continue;
            }

            $etiqueta = "{$producto['sku']} {$producto['nombre']} en {$sucursal}";

            if ($cantidad < 0) {
                $this->observar('stock_negativo', "{$etiqueta}: {$cantidad}");

                continue;
            }

            if ($cantidad === 0) {
                continue;
            }

            if (! $producto['activo']) {
                $this->observar('stock_inactivo', "{$etiqueta}: {$cantidad}");
            }

            if (isset($cantidades[(int) $fila->sucursal][$producto['origen_id']])) {
                $this->observar('stock_repetido', $etiqueta);
            }

            $cantidades[(int) $fila->sucursal][$producto['origen_id']] = ($cantidades[(int) $fila->sucursal][$producto['origen_id']] ?? 0) + $cantidad;
        }

        $plan = [];

        foreach ($cantidades as $sucursalId => $lineas) {
            foreach ($lineas as $productoId => $cantidad) {
                $plan[$sucursalId][] = ['producto_origen' => $productoId, 'cantidad' => $cantidad.'.000'];
            }
        }

        return $plan;
    }

    /**
     * @param  list<object>  $filas
     * @return list<array{nombre: string, documento: ?string, telefono: ?string, correo: ?string, direccion: ?string, activo: bool}>
     */
    private function clientes(array $filas): array
    {
        $plan = [];
        $documentos = [];

        foreach ($filas as $fila) {
            $nombre = $this->texto(filled($fila->razon_social) ? $fila->razon_social : $fila->nombre_completo, 150, "Cliente {$fila->id}");

            if ($nombre === null) {
                $this->observar('cliente_sin_nombre', "Cliente {$fila->id}");

                continue;
            }

            $documento = trim((string) $fila->nit);

            if ($documento !== '' && preg_match('/[1-9]/', $documento) !== 1) {
                $this->observar('nit_sin_numero', "{$nombre}: «{$documento}»");
                $documento = '';
            }

            if (mb_strlen($documento) > 30) {
                $this->observar('texto_recortado', "NIT de {$nombre}: «{$documento}» (queda vacío)");
                $documento = '';
            }

            if ($documento !== '' && isset($documentos[$documento])) {
                $otro = $plan[$documentos[$documento]]['nombre'];

                if (mb_strtolower($otro) === mb_strtolower($nombre)) {
                    $this->observar('cliente_duplicado', "{$nombre} (NIT {$documento})");

                    continue;
                }

                $this->observar('nit_repetido', "{$nombre}: NIT {$documento} ya es de «{$otro}»");
                $documento = '';
            }

            $plan[] = [
                'nombre' => $nombre,
                'documento' => $documento === '' ? null : $documento,
                'telefono' => $this->telefono($fila->telefono, $nombre),
                'correo' => $this->correo($fila->email, $nombre),
                'direccion' => $this->texto($fila->direccion, 300, "Dirección de {$nombre}"),
                'activo' => (bool) $fila->estado,
            ];

            if ($documento !== '') {
                $documentos[$documento] = array_key_last($plan);
            }
        }

        return $plan;
    }

    /**
     * Sin estado en Cyclone (NULL) el proveedor queda activo.
     *
     * @param  list<object>  $filas
     * @return list<array{nombre: string, telefono: ?string, correo: ?string, direccion: ?string, activo: bool}>
     */
    private function proveedores(array $filas): array
    {
        $plan = [];

        foreach ($filas as $fila) {
            $nombre = $this->texto($fila->nombre_completo, 150, "Proveedor {$fila->id}");

            if ($nombre === null) {
                continue;
            }

            $plan[] = [
                'nombre' => $nombre,
                'telefono' => $this->telefono($fila->telefono, $nombre),
                'correo' => $this->correo($fila->email, $nombre),
                'direccion' => $this->texto($fila->direccion, 300, "Dirección de {$nombre}"),
                'activo' => $fila->estado === null || (bool) $fila->estado,
            ];
        }

        return $plan;
    }

    private function precioOpcional(mixed $valor, string $etiqueta, string $tipo): ?string
    {
        $precio = Decimal::normalizar((string) $valor, Decimal::ESCALA_DINERO);

        if ($precio === null || Decimal::comparar($precio, '0') <= 0) {
            $this->observar('precio_opcional', "{$etiqueta}: precio {$tipo}");

            return null;
        }

        return $precio;
    }

    private function telefono(mixed $valor, string $de): ?string
    {
        $telefono = trim((string) $valor);

        if (mb_strlen($telefono) > 40) {
            $this->observar('telefono_largo', "{$de}: {$telefono}");

            return null;
        }

        return $telefono === '' ? null : $telefono;
    }

    private function correo(mixed $valor, string $de): ?string
    {
        $correo = mb_strtolower(trim((string) $valor));

        if ($correo === '') {
            return null;
        }

        if (mb_strlen($correo) > 150 || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            $this->observar('correo_invalido', "{$de}: {$correo}");

            return null;
        }

        return $correo;
    }

    private function texto(mixed $valor, int $maximo, string $contexto): ?string
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > $maximo) {
            $this->observar('texto_recortado', "{$contexto}: «{$texto}»");
            $texto = rtrim(mb_substr($texto, 0, $maximo));
        }

        return $texto;
    }

    private function observar(string $clave, string $detalle): void
    {
        $this->observaciones[$clave][] = $detalle;
    }
}
