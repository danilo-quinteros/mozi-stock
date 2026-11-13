<?php

namespace App\Domain\Catalogo\Importacion;

use App\Domain\Inventario\DatosLote;
use App\Domain\Inventario\LibroLotes;
use App\Models\ImportacionProducto;
use App\Models\ImportacionProductoFila;
use App\Models\Producto;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Valida todas las filas de una importación con las mismas reglas de la ficha de producto
 * (GuardarProducto / ProductoFormulario) y guarda el resultado por lotes. Las búsquedas de
 * códigos existentes se hacen con whereIn por lotes, nunca una consulta por fila.
 * Una fila cuyo código o código de barras ya existe en la empresa se marca «existente» y
 * no se importa: esta etapa solo crea productos nuevos.
 *
 * Con «Lotes y vencimientos» activa (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 7): los productos nuevos controlan lote
 * por defecto (D3; la columna «Controla lote y vencimiento» puede decir que no) y el stock inicial puede traer
 * «N.º de lote» y «Vencimiento del lote». Sin esas columnas, el stock va al lote de regularización (§ 9).
 */
final class ValidadorImportacion
{
    private const LOTE = 500;

    public function __construct(private readonly LibroLotes $lotes) {}

    private const PATRON_SKU = '/^[A-Za-z0-9._\-\/]+$/';

    private const PATRON_CIENTIFICO = '/^\d+([.,]\d+)?e\+?\d+$/i';

    /**
     * @return array{total: int, validas: int, errores: int, existentes: int, advertencias: int, con_stock: int, categorias_nuevas: int, unidades_nuevas: int, firma: string}
     */
    public function validar(ImportacionProducto $importacion): array
    {
        $empresaId = $importacion->empresa_id;
        $mapeo = $importacion->mapeo ?? [];
        $valores = $importacion->valores ?? [];
        $usarStock = $importacion->usar_stock === true && isset($mapeo['stock']);
        $conLotes = $this->lotes->activo($empresaId);

        if (! $conLotes) {
            // Sin la capacidad, una asignación vieja de columnas de lote no se lee.
            $mapeo = array_diff_key($mapeo, array_flip(CamposImportacion::CAMPOS_LOTES));
        }

        $unidades = DB::table('unidades_medida')->where('empresa_id', $empresaId)
            ->get(['id', 'nombre', 'abreviatura', 'admite_decimales'])->keyBy('id');
        $categorias = DB::table('categorias')->where('empresa_id', $empresaId)->get(['id', 'nombre'])->keyBy('id');

        DB::table('importacion_producto_filas')
            ->where('importacion_id', $importacion->id)
            ->where('fila', '<=', $importacion->fila_encabezados)
            ->update(['estado' => null, 'normalizado' => null, 'errores' => null, 'advertencias' => null]);

        $filas = DB::table('importacion_producto_filas')
            ->where('importacion_id', $importacion->id)
            ->where('fila', '>', $importacion->fila_encabezados)
            ->orderBy('fila')
            ->get(['id', 'empresa_id', 'importacion_id', 'fila', 'celdas', 'numericas']);

        $resultados = [];

        foreach ($filas as $fila) {
            $resultados[] = $this->interpretar($fila, $mapeo, $valores, $usarStock, $importacion->unidad_defecto_id, $unidades, $categorias, $conLotes);
        }

        $existentesSku = $this->existentes($empresaId, 'sku', array_filter(array_column(array_column($resultados, 'datos'), 'sku')));
        $existentesBarras = $this->existentes($empresaId, 'codigo_barras', array_filter(array_column(array_column($resultados, 'datos'), 'codigo_barras')));
        $nombresCatalogo = $this->nombresEnCatalogo($empresaId, array_filter(array_column(array_column($resultados, 'datos'), 'nombre')));
        $vistos = ['sku' => [], 'codigo_barras' => [], 'nombre' => []];
        $resumen = ['total' => count($resultados), 'validas' => 0, 'errores' => 0, 'existentes' => 0, 'advertencias' => 0, 'con_stock' => 0];
        $categoriasNuevas = [];
        $unidadesNuevas = [];
        $validas = [];
        $escribir = [];

        foreach ($resultados as $resultado) {
            $datos = $resultado['datos'];
            $errores = $resultado['errores'];
            $advertencias = $resultado['advertencias'];
            $existente = ($datos['sku'] !== null ? $existentesSku[Str::upper($datos['sku'])] ?? null : null)
                ?? ($datos['codigo_barras'] !== null ? $existentesBarras[Str::upper($datos['codigo_barras'])] ?? null : null);

            if ($existente !== null) {
                $estado = ImportacionProductoFila::EXISTENTE;
                $datos['existente'] = $existente;
                $errores = [];
                $advertencias = [__('Ya existe en tu catálogo como «:nombre»; esta fila se omitirá.', ['nombre' => $existente['nombre']])];
            } else {
                foreach (['sku' => 'El código :valor ya aparece en la fila :fila.', 'codigo_barras' => 'El código de barras :valor ya aparece en la fila :fila.'] as $campo => $mensaje) {
                    $clave = $datos[$campo] === null ? null : Str::upper($datos[$campo]);

                    if ($clave === null) {
                        continue;
                    }

                    if (isset($vistos[$campo][$clave])) {
                        $errores[] = __($mensaje, ['valor' => $datos[$campo], 'fila' => $vistos[$campo][$clave]]);
                    } else {
                        $vistos[$campo][$clave] = $resultado['fila'];
                    }
                }

                if ($datos['nombre'] !== null) {
                    $claveNombre = CamposImportacion::clave($datos['nombre'].' '.($datos['contenido'] ?? '').' '.($datos['marca'] ?? ''));

                    if (isset($vistos['nombre'][$claveNombre])) {
                        $advertencias[] = __('El mismo nombre aparece en la fila :fila.', ['fila' => $vistos['nombre'][$claveNombre]]);
                    } else {
                        $vistos['nombre'][$claveNombre] = $resultado['fila'];
                    }

                    if (isset($nombresCatalogo[CamposImportacion::clave($datos['nombre'].' '.($datos['marca'] ?? ''))])) {
                        $advertencias[] = __('Ya tienes un producto con este nombre; revisa que no sea el mismo.');
                    }
                }

                $estado = $errores === [] ? ImportacionProductoFila::VALIDA : ImportacionProductoFila::ERROR;
            }

            if ($estado === ImportacionProductoFila::VALIDA) {
                $resumen['validas']++;
                $validas[] = $resultado['fila'];
                $resumen['advertencias'] += $advertencias === [] ? 0 : 1;
                $resumen['con_stock'] += $datos['stock'] === null ? 0 : 1;

                if (isset($datos['categoria']['crear'])) {
                    $categoriasNuevas[$datos['categoria']['crear']] = true;
                }

                if (isset($datos['unidad']['crear'])) {
                    $unidadesNuevas[$datos['unidad']['crear']] = true;
                }
            } else {
                $resumen[$estado === ImportacionProductoFila::ERROR ? 'errores' : 'existentes']++;
            }

            $escribir[] = [
                'id' => $resultado['id'],
                'empresa_id' => $resultado['empresa_id'],
                'importacion_id' => $resultado['importacion_id'],
                'fila' => $resultado['fila'],
                'celdas' => $resultado['celdas'],
                'numericas' => $resultado['numericas'],
                'estado' => $estado,
                'normalizado' => json_encode($datos, JSON_UNESCAPED_UNICODE),
                'errores' => $errores === [] ? null : json_encode($errores, JSON_UNESCAPED_UNICODE),
                'advertencias' => $advertencias === [] ? null : json_encode($advertencias, JSON_UNESCAPED_UNICODE),
            ];
        }

        // Upsert con todas las columnas: MySQL estricto exige valores para las NOT NULL.
        foreach (array_chunk($escribir, self::LOTE) as $lote) {
            DB::table('importacion_producto_filas')->upsert($lote, ['id'], ['estado', 'normalizado', 'errores', 'advertencias']);
        }

        return $resumen + [
            'categorias_nuevas' => count($categoriasNuevas),
            'unidades_nuevas' => count($unidadesNuevas),
            'firma' => hash('sha256', implode(',', $validas)),
        ];
    }

    /**
     * @param  array<string, int>  $mapeo
     * @param  array<string, mixed>  $valores
     * @param  Collection<int|string, stdClass>  $unidades
     * @param  Collection<int|string, stdClass>  $categorias
     * @return array{id: int, empresa_id: int, importacion_id: int, fila: int, celdas: string, numericas: string|null, datos: array<string, mixed>, errores: list<array<mixed>|string>, advertencias: list<array<mixed>|string>}
     */
    private function interpretar(stdClass $fila, array $mapeo, array $valores, bool $usarStock, ?int $unidadDefecto, $unidades, $categorias, bool $conLotes = false): array
    {
        $celdas = (array) json_decode((string) $fila->celdas, true);
        $numericas = $fila->numericas === null ? [] : (array) json_decode((string) $fila->numericas, true);
        $errores = [];
        $advertencias = [];
        $texto = fn (string $campo): string => isset($mapeo[$campo]) ? trim((string) ($celdas[$mapeo[$campo]] ?? '')) : '';
        $esNumero = fn (string $campo): bool => isset($mapeo[$campo]) && in_array($mapeo[$campo], $numericas, true);
        $etiqueta = fn (string $campo): string => __(CamposImportacion::CAMPOS[$campo]['etiqueta']);

        $numero = function (string $campo, int $escala, bool $obligatorio) use ($texto, $esNumero, $etiqueta, &$errores, &$advertencias): ?string {
            $original = $texto($campo);
            [$valor, $motivo] = NumeroImportado::leer($original, $esNumero($campo));

            if ($motivo !== null) {
                $mensaje = match ($motivo) {
                    NumeroImportado::VACIO => $obligatorio ? __(':campo es obligatorio.', ['campo' => $etiqueta($campo)]) : null,
                    NumeroImportado::AMBIGUO => __('«:valor» en :campo es ambiguo (¿miles o decimales?). Escríbelo sin separador de miles, por ejemplo 1500 o 1,5.', ['valor' => $original, 'campo' => $etiqueta($campo)]),
                    NumeroImportado::CIENTIFICO => __('«:valor» en :campo está en notación científica. En Excel, da formato de número a esa columna.', ['valor' => $original, 'campo' => $etiqueta($campo)]),
                    default => __('«:valor» no es un número válido en :campo.', ['valor' => $original, 'campo' => $etiqueta($campo)]),
                };

                if ($mensaje !== null) {
                    $errores[] = $mensaje;
                }

                return null;
            }

            if (Decimal::comparar((string) $valor, '0') < 0) {
                $errores[] = __(':campo no puede ser negativo.', ['campo' => $etiqueta($campo)]);

                return null;
            }

            $redondeado = Decimal::redondear((string) $valor, $escala);

            if (Decimal::comparar($redondeado, (string) $valor, 10) !== 0) {
                $advertencias[] = __(':campo redondeado de :antes a :despues.', [
                    'campo' => $etiqueta($campo), 'antes' => Decimal::formatear((string) $valor, 0, 10), 'despues' => Decimal::formatear($redondeado, $escala, $escala),
                ]);
            }

            $normal = Decimal::normalizar($redondeado, $escala);

            if ($normal === null) {
                $errores[] = __('«:valor» es demasiado grande para :campo.', ['valor' => $original, 'campo' => $etiqueta($campo)]);
            }

            return $normal;
        };

        $nombre = $texto('nombre');

        if ($nombre === '') {
            $errores[] = __('Falta el nombre del producto.');
        } elseif (mb_strlen($nombre) > 150) {
            $errores[] = __('El nombre supera los 150 caracteres.');
        }

        $marca = Producto::normalizarMarca($texto('marca'));

        if ($marca !== null && mb_strlen($marca) > Producto::MAX_MARCA) {
            $errores[] = __('La marca admite hasta :max caracteres.', ['max' => Producto::MAX_MARCA]);
        }

        $sku = $texto('sku');

        if ($sku !== '') {
            if (preg_match(self::PATRON_CIENTIFICO, $sku) === 1) {
                $errores[] = __('El código «:valor» llegó en notación científica. En Excel, da formato de Texto a la columna y vuelve a guardar.', ['valor' => $sku]);
            } elseif (mb_strlen($sku) > 40 || preg_match(self::PATRON_SKU, $sku) !== 1) {
                $errores[] = __('El código «:valor» admite hasta 40 letras, números y . _ - / (sin espacios).', ['valor' => $sku]);
            }
        }

        $barras = $texto('codigo_barras');

        if ($barras !== '') {
            if (preg_match(self::PATRON_CIENTIFICO, $barras) === 1) {
                $errores[] = __('El código de barras «:valor» llegó en notación científica. En Excel, da formato de Texto a la columna y vuelve a guardar.', ['valor' => $barras]);
            } elseif (mb_strlen($barras) > 64) {
                $errores[] = __('El código de barras supera los 64 caracteres.');
            }
        }

        $descripcion = $texto('descripcion');

        if (mb_strlen($descripcion) > 2000) {
            $errores[] = __('La descripción supera los 2000 caracteres.');
        }

        [$categoria, $categoriaTexto] = $this->categoria($texto('categoria'), $valores['categorias'] ?? [], $categorias, $errores);
        [$unidad, $unidadTexto, $admiteDecimales] = $this->unidad($texto('unidad'), $valores['unidades'] ?? [], $unidades, $unidadDefecto, $errores);

        $precioConsumidor = $numero('precio_consumidor', Decimal::ESCALA_DINERO, true);
        $precioMinorista = $numero('precio_minorista', Decimal::ESCALA_DINERO, false);
        $precioMayorista = $numero('precio_mayorista', Decimal::ESCALA_DINERO, false);
        $stockMinimo = $numero('stock_minimo', Decimal::ESCALA_CANTIDAD, false);
        $contenido = $numero('contenido', Decimal::ESCALA_CANTIDAD, false);

        if ($contenido !== null && Decimal::comparar($contenido, '0') === 0) {
            $errores[] = __('El contenido debe ser mayor a 0.');
            $contenido = null;
        }

        $controlaStock = CamposImportacion::siNo($texto('controla_stock'));

        if ($controlaStock === null) {
            $errores[] = __('«:valor» en :campo no es válido: escribe sí o no.', ['valor' => $texto('controla_stock'), 'campo' => $etiqueta('controla_stock')]);
            $controlaStock = true;
        }

        // Igual que en la ficha: con contenido se vende por unidad («Aceite 1 L»); sin contenido,
        // una unidad con decimales (kg, L) se vende por peso o medida.
        $tipoVenta = Producto::tipoVentaPara($contenido, $admiteDecimales);
        $stock = null;

        if ($usarStock) {
            $stock = $numero('stock', Decimal::ESCALA_CANTIDAD, false);

            // «medida» implica una unidad con decimales; por unidad, el stock es entero (como en LibroStock).
            if ($stock !== null && $tipoVenta === 'unidad' && ! Decimal::esEntero($stock)) {
                $errores[] = __('El stock :valor no es válido: este producto se mide en unidades enteras.', ['valor' => Decimal::formatear($stock)]);
                $stock = null;
            }

            if ($stock !== null && Decimal::comparar($stock, '0') === 0) {
                $stock = null;
            }

            // Sin control de stock no hay stock inicial que cargar: se ignora y se avisa en la revisión.
            if ($stock !== null && ! $controlaStock) {
                $advertencias[] = __('No controla stock: se ignora el stock :valor.', ['valor' => Decimal::formatear($stock)]);
                $stock = null;
            }
        }

        [$controlaVencimiento, $lote] = $this->lote($conLotes, $controlaStock, $stock, $nombre, $texto, $etiqueta, $errores, $advertencias);

        return [
            'id' => (int) $fila->id,
            'empresa_id' => (int) $fila->empresa_id,
            'importacion_id' => (int) $fila->importacion_id,
            'fila' => (int) $fila->fila,
            'celdas' => (string) $fila->celdas,
            'numericas' => $fila->numericas === null ? null : (string) $fila->numericas,
            'datos' => [
                'nombre' => $nombre === '' ? null : $nombre,
                'marca' => $marca,
                'sku' => $sku === '' ? null : Str::upper($sku),
                'codigo_barras' => $barras === '' ? null : $barras,
                'descripcion' => $descripcion === '' ? null : $descripcion,
                'categoria' => $categoria,
                'categoria_texto' => $categoriaTexto,
                'unidad' => $unidad,
                'unidad_texto' => $unidadTexto,
                'tipo_venta' => $tipoVenta,
                'contenido' => $contenido,
                'precio_consumidor' => $precioConsumidor,
                'precio_minorista' => $precioMinorista,
                'precio_mayorista' => $precioMayorista,
                'stock_minimo' => $stockMinimo ?? '0.000',
                'stock' => $stock,
                'controla_stock' => $controlaStock,
                'controla_vencimiento' => $controlaVencimiento,
                'lote' => $lote,
            ],
            'errores' => $errores,
            'advertencias' => $advertencias,
        ];
    }

    /**
     * Marca de lote y lote del stock inicial de una fila. Sin la capacidad, o sin control de stock, no hay lote. Con
     * stock y sin número ni fecha, el stock va al lote de regularización (se avisa). Un lote ya vencido se acepta
     * (queda bloqueado para la venta) y se avisa, como en compras (§ 5.1.4).
     *
     * @param  callable(string): string  $texto
     * @param  callable(string): string  $etiqueta
     * @param  list<array<mixed>|string>  $errores
     * @param  list<array<mixed>|string>  $advertencias
     *
     * @param-out list<array<mixed>|string>  $errores
     * @param-out list<array<mixed>|string>  $advertencias
     *
     * @return array{0: bool, 1: array{numero: string, vencimiento: string}|null}
     */
    private function lote(bool $conLotes, bool $controlaStock, ?string $stock, string $nombre, callable $texto, callable $etiqueta, array &$errores, array &$advertencias): array
    {
        if (! $conLotes) {
            return [false, null];
        }

        $marca = CamposImportacion::siNo($texto('controla_vencimiento'));

        if ($marca === null) {
            $errores[] = __('«:valor» en :campo no es válido: escribe sí o no.', ['valor' => $texto('controla_vencimiento'), 'campo' => $etiqueta('controla_vencimiento')]);
            $marca = true;
        }

        $marca = $marca && $controlaStock;
        $numero = $texto('lote');
        $vencimiento = $texto('vencimiento');

        if (! $marca || $stock === null) {
            if ($numero !== '' || $vencimiento !== '') {
                $advertencias[] = $marca
                    ? __('Sin stock inicial: se ignora el lote.')
                    : __('No controla lote y vencimiento: se ignora el lote.');
            }

            return [$marca, null];
        }

        if ($numero === '' && $vencimiento === '') {
            $advertencias[] = __('Sin lote: el stock queda como «Sin lote (regularización)» hasta regularizarlo.');

            return [true, null];
        }

        $lote = DatosLote::leer($nombre === '' ? __('Lote') : $nombre, $numero, $vencimiento, $errores);

        if ($lote !== null && DatosLote::vencido($lote['vencimiento'])) {
            $advertencias[] = __('El lote :lote ya venció (:fecha): quedará bloqueado para la venta.', ['lote' => $lote['numero'], 'fecha' => DatosLote::formatear($lote['vencimiento'])]);
        }

        return [true, $lote];
    }

    /**
     * @param  array<string, array<string, mixed>>  $asignacion  clave => destino elegido
     * @param  Collection<int|string, stdClass>  $categorias
     * @param  list<array<mixed>|string>  $errores  mensajes de __()
     *
     * @param-out list<array<mixed>|string>  $errores
     *
     * @return array{0: array{id: int}|array{crear: string}|null, 1: string|null}
     */
    private function categoria(string $texto, array $asignacion, $categorias, array &$errores): array
    {
        if ($texto === '') {
            return [null, null];
        }

        $destino = $asignacion[CamposImportacion::clave($texto)]['destino'] ?? null;

        if ($destino === 'ninguna') {
            return [null, null];
        }

        if ($destino === 'crear') {
            $nueva = $asignacion[CamposImportacion::clave($texto)];

            return [['crear' => CamposImportacion::clave($texto)], (string) ($nueva['nombre_nuevo'] ?? $nueva['nombre'] ?? $texto)];
        }

        $categoria = is_int($destino) ? $categorias->get($destino) : null;

        if ($categoria === null) {
            $errores[] = __('Indica qué hacer con la categoría «:valor».', ['valor' => $texto]);

            return [null, null];
        }

        return [['id' => (int) $categoria->id], (string) $categoria->nombre];
    }

    /**
     * @param  array<string, array<string, mixed>>  $asignacion
     * @param  Collection<int|string, stdClass>  $unidades
     * @param  list<array<mixed>|string>  $errores  mensajes de __()
     *
     * @param-out list<array<mixed>|string>  $errores
     *
     * @return array{0: array{id: int}|array{crear: string}|null, 1: string|null, 2: bool}
     */
    private function unidad(string $texto, array $asignacion, $unidades, ?int $unidadDefecto, array &$errores): array
    {
        $destino = $texto === '' ? $unidadDefecto : ($asignacion[CamposImportacion::clave($texto)]['destino'] ?? null);

        if ($destino === 'crear') {
            $nueva = $asignacion[CamposImportacion::clave($texto)];

            return [['crear' => CamposImportacion::clave($texto)], (string) ($nueva['abreviatura'] ?? $texto), (bool) ($nueva['decimales'] ?? false)];
        }

        $unidad = is_int($destino) ? $unidades->get($destino) : null;

        if ($unidad === null) {
            $errores[] = $texto === ''
                ? __('Elige la unidad de medida para las filas sin unidad.')
                : __('Indica qué hacer con la unidad «:valor».', ['valor' => $texto]);

            return [null, null, false];
        }

        return [['id' => (int) $unidad->id], (string) $unidad->abreviatura, (bool) $unidad->admite_decimales];
    }

    /**
     * @param  array<int, string>  $codigos
     * @return array<string, array{id: int, nombre: string}> código en mayúsculas => producto
     */
    private function existentes(int $empresaId, string $columna, array $codigos): array
    {
        $encontrados = [];

        foreach (array_chunk(array_values(array_unique($codigos)), 1000) as $lote) {
            $productos = DB::table('productos')->where('empresa_id', $empresaId)->whereIn($columna, $lote)->get(['id', $columna, 'nombre']);

            foreach ($productos as $producto) {
                $encontrados[Str::upper((string) $producto->{$columna})] = ['id' => (int) $producto->id, 'nombre' => (string) $producto->nombre];
            }
        }

        return $encontrados;
    }

    /**
     * @param  array<int, string>  $nombres
     * @return array<string, true> clave del nombre => true
     */
    private function nombresEnCatalogo(int $empresaId, array $nombres): array
    {
        $encontrados = [];

        foreach (array_chunk(array_values(array_unique($nombres)), 1000) as $lote) {
            foreach (DB::table('productos')->where('empresa_id', $empresaId)->whereIn('nombre', $lote)->get(['nombre', 'marca']) as $producto) {
                $encontrados[CamposImportacion::clave($producto->nombre.' '.($producto->marca ?? ''))] = true;
            }
        }

        return $encontrados;
    }
}
