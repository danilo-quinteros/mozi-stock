<?php

namespace App\Domain\Inventario;

use App\Domain\Tenancy\Numerador;
use App\Enums\TipoOperacionInventario;
use App\Models\OperacionInventario;
use App\Models\Producto;
use App\Models\Scopes\EmpresaScope;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Único punto que modifica stock_niveles. Cada operación, en una transacción:
 * bloquea los saldos en orden (sucursal, producto), valida que ninguno quede negativo,
 * toma el correlativo, guarda la cabecera y actualiza saldos e historial en lote.
 * Con la misma clave de idempotencia devuelve la operación ya registrada sin repetirla.
 *
 * Productos sin control de stock (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 4.4): prepararLineas() los rechaza,
 * así compras, ajustes y traspasos los rechazan en el servidor sin código propio, y aplicar() lanza una excepción
 * si le llega uno. Invariante: un producto sin control nunca tiene movimientos posteriores a su desmarcado.
 * Una venta solo de esos productos guarda su operación como cabecera, sin cambios (total_lineas = 0).
 *
 * Lotes y vencimientos (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 7): los productos con vencimiento de una empresa con la
 * capacidad pasan además por LibroLotes en la misma transacción, que mueve el saldo por lote y mantiene
 * stock_niveles = Σ stock_lotes. El saldo total se agrupa por sucursal y producto: varias líneas del mismo producto
 * (una por lote) generan un solo movimiento de stock_niveles. Sin productos marcados no hay ninguna consulta extra.
 */
final class LibroStock
{
    public const MAX_LINEAS = 1000;

    public function __construct(private readonly Numerador $numerador, private readonly LibroLotes $lotes) {}

    /**
     * Valida cantidades (texto) por producto: positivas, con decimales solo si la unidad
     * los admite, de productos activos de la empresa que controlan stock. Una sola consulta
     * para todas las líneas.
     *
     * @param  array<int|string, mixed>  $lineas  producto_id => cantidad
     * @return array<int, array{cantidad: string, nombre: string}>
     */
    public function prepararLineas(int $empresaId, array $lineas): array
    {
        $lista = [];

        foreach ($lineas as $id => $valor) {
            $lista[] = ['producto_id' => $id, 'cantidad' => $valor];
        }

        $preparadas = [];

        foreach ($this->prepararLista($empresaId, $lista) as $linea) {
            $preparadas[$linea['producto_id']] = ['cantidad' => $linea['cantidad'], 'nombre' => $linea['nombre']];
        }

        return $preparadas;
    }

    /**
     * Como prepararLineas(), con las líneas como lista: un producto puede repetirse, por ejemplo una línea por lote
     * (§ 6.5). Cada línea se valida por separado y conserva su índice. `controla_vencimiento` indica si la línea
     * necesita lote: producto marcado y capacidad activa, que solo se consulta si algún producto está marcado.
     *
     * @param  list<array{producto_id: mixed, cantidad: mixed}>  $lineas
     * @return array<int, array{producto_id: int, cantidad: string, nombre: string, controla_vencimiento: bool}>
     */
    public function prepararLista(int $empresaId, array $lineas): array
    {
        if ($lineas === []) {
            throw ValidationException::withMessages(['lineas' => __('Agrega al menos un producto.')]);
        }

        if (count($lineas) > self::MAX_LINEAS) {
            throw ValidationException::withMessages(['lineas' => __('Una operación admite como máximo :maximo productos.', ['maximo' => self::MAX_LINEAS])]);
        }

        $ids = array_map(fn (array $linea): int => is_numeric($linea['producto_id'] ?? null) ? (int) $linea['producto_id'] : 0, $lineas);
        $productos = DB::table('productos')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'productos.unidad_medida_id')
            ->where('productos.empresa_id', $empresaId)
            ->where('productos.activo', true)
            ->whereIn('productos.id', array_values(array_unique($ids)))
            ->get(['productos.id', 'productos.nombre', 'productos.contenido', 'productos.tipo_venta', 'productos.controla_stock', 'productos.controla_vencimiento', 'unidades_medida.admite_decimales', 'unidades_medida.abreviatura'])
            ->keyBy('id');
        $conLote = $productos->contains(fn (object $p): bool => (bool) $p->controla_vencimiento) && $this->lotes->activo($empresaId);

        $preparadas = [];
        $errores = [];

        foreach ($lineas as $indice => $linea) {
            $id = $ids[$indice];
            $valor = $linea['cantidad'] ?? '';
            $producto = $productos->get($id);

            if ($producto === null) {
                $errores[] = __('Uno de los productos no existe o está inactivo.');

                continue;
            }

            $cantidad = Decimal::normalizar(is_scalar($valor) ? (string) $valor : '', Decimal::ESCALA_CANTIDAD);
            $nombre = Producto::formatoNombreCompleto($producto->nombre, $producto->contenido, $producto->abreviatura);
            $esEntera = ($producto->tipo_venta ?? 'unidad') === 'unidad' || ! $producto->admite_decimales;

            if (! (bool) $producto->controla_stock) {
                // Sin saldo que mover: no se compra, no se ajusta ni se traspasa (§ 2, S3).
                $errores[] = __(':producto no controla stock.', ['producto' => $nombre]);
            } elseif ($cantidad === null || Decimal::comparar($cantidad, '0') <= 0) {
                $errores[] = __(':nombre: la cantidad debe ser mayor que cero (hasta 3 decimales).', ['nombre' => $nombre]);
            } elseif ($esEntera && ! Decimal::esEntero($cantidad)) {
                $errores[] = __(':nombre: se mide en unidades enteras.', ['nombre' => $nombre]);
            } else {
                $preparadas[$indice] = [
                    'producto_id' => $id, 'cantidad' => $cantidad, 'nombre' => $nombre,
                    'controla_vencimiento' => $conLote && (bool) $producto->controla_vencimiento,
                ];
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages(['lineas' => array_values(array_unique($errores))]);
        }

        return $preparadas;
    }

    public function existente(int $empresaId, string $clave): ?OperacionInventario
    {
        return OperacionInventario::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)
            ->where('clave_idempotencia', $clave)
            ->first();
    }

    /**
     * @param  array{tipo: TipoOperacionInventario, motivo: string|null, sucursal_id: int, sucursal_destino_id: int|null, nota: string|null, clave: string, serie?: string}  $cabecera
     * @param  list<array{sucursal_id: int, producto_id: int, cantidad: string, nombre: string, lote?: array{numero: string, vencimiento: string}, lote_id?: int, replica_de?: int, devolucion?: array{salida: int, previas: list<int>}}>  $cambios  cantidad con signo
     */
    public function registrar(User $actor, array $cabecera, array $cambios): OperacionInventario
    {
        $empresaId = (int) $actor->empresa_id;

        if (! Str::isUuid($cabecera['clave'])) {
            throw ValidationException::withMessages(['lineas' => __('La operación no tiene una clave válida. Recarga la página.')]);
        }

        if (($previa = $this->existente($empresaId, $cabecera['clave'])) !== null) {
            return $previa;
        }

        // Las filas de saldo faltantes se crean antes y fuera de la transacción para no mezclar
        // bloqueos de inserción con los FOR UPDATE ordenados.
        $this->asegurarNiveles($empresaId, $cambios);

        try {
            return DB::transaction(fn (): OperacionInventario => $this->aplicar($actor, $cabecera, $cambios), attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            // Dos envíos simultáneos con la misma clave: el segundo devuelve el primero.
            return $this->existente($empresaId, $cabecera['clave']) ?? throw $e;
        }
    }

    /**
     * Núcleo de una operación de stock. Debe ejecutarse dentro de la transacción del llamador
     * (ajuste, traspaso o venta), después de asegurarNiveles(). Bloquea saldos en orden,
     * valida que ninguno quede negativo, toma el correlativo y escribe en lote.
     *
     * Con $cambios vacío solo guarda la cabecera (venta solo de productos sin control de stock):
     * conserva el correlativo y la clave de idempotencia sin tocar saldos ni historial.
     *
     * Cada cambio de un producto con vencimiento puede indicar `lote` ({numero, vencimiento Y-m-d}, para ingresos) o
     * `lote_id`; sin ellos, LibroLotes reparte la salida según el tipo de operación (§ 7.1).
     *
     * @param  array{tipo: TipoOperacionInventario, motivo: string|null, sucursal_id: int, sucursal_destino_id: int|null, nota: string|null, clave: string, serie?: string}  $cabecera
     * @param  list<array{sucursal_id: int, producto_id: int, cantidad: string, nombre: string, lote?: array{numero: string, vencimiento: string}, lote_id?: int, replica_de?: int, devolucion?: array{salida: int, previas: list<int>}}>  $cambios
     */
    public function aplicar(User $actor, array $cabecera, array $cambios): OperacionInventario
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('LibroStock::aplicar() requiere una transacción abierta.');
        }

        $empresaId = (int) $actor->empresa_id;
        $conLote = $this->leerMarcas($empresaId, $cambios);
        $niveles = $cambios === [] ? collect() : DB::table('stock_niveles')
            ->where('empresa_id', $empresaId)
            ->whereIn('sucursal_id', array_values(array_unique(array_column($cambios, 'sucursal_id'))))
            ->whereIn('producto_id', array_values(array_unique(array_column($cambios, 'producto_id'))))
            ->orderBy('sucursal_id')
            ->orderBy('producto_id')
            ->lockForUpdate()
            ->get(['id', 'sucursal_id', 'producto_id', 'cantidad'])
            ->keyBy(fn (object $fila) => $fila->sucursal_id.':'.$fila->producto_id);

        // Total por sucursal y producto: varias líneas del mismo producto (una por lote) mueven el saldo total una vez.
        $totales = [];

        foreach ($cambios as $cambio) {
            $clave = $cambio['sucursal_id'].':'.$cambio['producto_id'];
            $totales[$clave] ??= ['sucursal_id' => $cambio['sucursal_id'], 'producto_id' => $cambio['producto_id'], 'cantidad' => '0', 'nombre' => $cambio['nombre']];
            $totales[$clave]['cantidad'] = Decimal::sumar($totales[$clave]['cantidad'], $cambio['cantidad']);
        }

        $previos = [];
        $movimientos = [];
        $faltantes = [];

        foreach ($totales as $clave => $total) {
            $nivel = $niveles->get($clave)
                ?? throw ValidationException::withMessages(['lineas' => __('No se pudo preparar el stock de la sucursal. Inténtalo de nuevo.')]);
            $anterior = (string) $nivel->cantidad;
            $previos[$clave] = $anterior;

            // Un cambio neto 0 (p. ej. pasar unidades de un lote a otro) no mueve el saldo total.
            if (Decimal::comparar($total['cantidad'], '0') === 0) {
                continue;
            }

            $posterior = Decimal::sumar($anterior, $total['cantidad']);

            if (Decimal::comparar($posterior, '0') < 0) {
                $faltantes[$clave] = [__(':producto: stock disponible :cantidad.', ['producto' => $total['nombre'], 'cantidad' => Decimal::formatear($anterior)])];
            }

            $movimientos[] = [
                'nivel_id' => (int) $nivel->id,
                'sucursal_id' => $total['sucursal_id'],
                'producto_id' => $total['producto_id'],
                'cantidad' => $total['cantidad'],
                'saldo_anterior' => $anterior,
                'saldo_posterior' => $posterior,
            ];
        }

        $cambiosLote = array_values(array_filter($cambios, fn (array $c): bool => isset($conLote[(int) $c['producto_id']])));
        $planLotes = $cambiosLote === [] ? null : $this->lotes->preparar($empresaId, $cabecera['tipo'], $cambiosLote, $previos);

        // En productos con lote, el mensaje de LibroLotes (vendible y vencido) reemplaza al del saldo total.
        foreach ($planLotes['errores'] ?? [] as $clave => $mensajes) {
            $faltantes[$clave] = $mensajes;
        }

        if ($faltantes !== []) {
            throw ValidationException::withMessages(['lineas' => array_merge(
                [__('No hay stock suficiente; no se guardó ningún cambio.')], ...array_values($faltantes),
            )]);
        }

        $tipo = $cabecera['tipo'];
        // Las operaciones que solo pasan unidades de un lote a otro llevan su propia serie (LOT), sin huecos en AJU.
        $serie = $cabecera['serie'] ?? $tipo->serie();
        $operacion = new OperacionInventario;
        $operacion->empresa_id = $empresaId;
        $operacion->serie = $serie;
        $operacion->numero = $this->numerador->siguiente($empresaId, $serie);
        $operacion->tipo = $tipo;
        $operacion->motivo = $cabecera['motivo'];
        $operacion->sucursal_id = $cabecera['sucursal_id'];
        $operacion->sucursal_destino_id = $cabecera['sucursal_destino_id'];
        $operacion->nota = $cabecera['nota'];
        $operacion->usuario_id = $actor->id;
        $operacion->total_lineas = count(array_unique(array_column($cambios, 'producto_id')));
        $operacion->clave_idempotencia = $cabecera['clave'];
        $operacion->save();

        $this->actualizarSaldos($empresaId, $movimientos);
        $this->insertarMovimientos($empresaId, $operacion->id, $movimientos);

        if ($planLotes !== null) {
            $this->lotes->escribir($empresaId, $operacion->id, $planLotes['movimientos']);
        }

        return $operacion;
    }

    /**
     * Guardia del invariante (§ 4.4): una sola consulta, con bloqueo compartido de las fichas para que
     * GuardarProducto no desmarque la casilla mientras la operación está en curso. Llegar aquí con un
     * producto sin control es un error de programación, no una entrada del usuario.
     *
     * La misma consulta lee controla_vencimiento: devuelve los productos que se llevan por lote, y la capacidad
     * solo se consulta si alguno está marcado (lotes § 7.3).
     *
     * @param  list<array{producto_id: int}>  $cambios
     * @return array<int, true>
     */
    private function leerMarcas(int $empresaId, array $cambios): array
    {
        $ids = array_values(array_unique(array_column($cambios, 'producto_id')));

        if ($ids === []) {
            return [];
        }

        $marcas = DB::table('productos')
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->sharedLock()
            ->get(['id', 'controla_stock', 'controla_vencimiento']);
        $sinControl = $marcas->reject(fn (object $p) => (bool) $p->controla_stock)->pluck('id');

        if ($sinControl->isNotEmpty()) {
            throw new LogicException('LibroStock::aplicar() recibió productos sin control de stock: '.$sinControl->implode(', ').'.');
        }

        $conVencimiento = $marcas->filter(fn (object $p) => (bool) $p->controla_vencimiento)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $conVencimiento !== [] && $this->lotes->activo($empresaId) ? array_fill_keys($conVencimiento, true) : [];
    }

    /**
     * Crea con cantidad 0 las filas de saldo que aún no existen. Llamar fuera de la
     * transacción de la operación. Con la cantidad (con signo) y, si corresponde, el lote, también crea los lotes y
     * saldos por lote que van a recibir unidades.
     *
     * @param  list<array{sucursal_id: int, producto_id: int, cantidad?: string, nombre?: string, lote?: array{numero: string, vencimiento: string}, lote_id?: int, replica_de?: int, devolucion?: array{salida: int, previas: list<int>}}>  $cambios
     */
    public function asegurarNiveles(int $empresaId, array $cambios): void
    {
        $sucursales = array_values(array_unique(array_column($cambios, 'sucursal_id')));
        $productos = array_values(array_unique(array_column($cambios, 'producto_id')));
        $ahora = Carbon::now();
        $filas = [];

        foreach ($sucursales as $sucursalId) {
            foreach ($productos as $productoId) {
                $filas[] = [
                    'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId, 'producto_id' => $productoId,
                    'cantidad' => '0.000', 'created_at' => $ahora, 'updated_at' => $ahora,
                ];
            }
        }

        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('stock_niveles')->insertOrIgnore($lote);
        }

        // Productos con vencimiento: lotes nuevos y sus saldos por sucursal, también en cero (lotes § 7.6). Solo para
        // los cambios que traen su cantidad y son ingresos; sin productos marcados no consulta tablas de lotes.
        $this->lotes->asegurar($empresaId, $cambios);
    }

    /**
     * Una sentencia UPDATE ... CASE por lote, no una por producto.
     *
     * @param  list<array{nivel_id: int, saldo_posterior: string}>  $movimientos
     */
    private function actualizarSaldos(int $empresaId, array $movimientos): void
    {
        $finales = [];

        foreach ($movimientos as $movimiento) {
            $finales[$movimiento['nivel_id']] = $movimiento['saldo_posterior'];
        }

        foreach (array_chunk($finales, 500, true) as $lote) {
            $casos = implode(' ', array_fill(0, count($lote), 'WHEN ? THEN ?'));
            $valores = [];

            foreach ($lote as $id => $saldo) {
                array_push($valores, $id, $saldo);
            }

            $marcadores = implode(',', array_fill(0, count($lote), '?'));
            DB::update(
                "UPDATE stock_niveles SET cantidad = CASE id {$casos} END, updated_at = ? WHERE empresa_id = ? AND id IN ({$marcadores})",
                [...$valores, Carbon::now(), $empresaId, ...array_keys($lote)],
            );
        }
    }

    /**
     * @param  list<array{sucursal_id: int, producto_id: int, cantidad: string, saldo_anterior: string, saldo_posterior: string}>  $movimientos
     */
    private function insertarMovimientos(int $empresaId, int $operacionId, array $movimientos): void
    {
        $ahora = Carbon::now();
        $filas = array_map(fn (array $m) => [
            'empresa_id' => $empresaId,
            'operacion_id' => $operacionId,
            'sucursal_id' => $m['sucursal_id'],
            'producto_id' => $m['producto_id'],
            'cantidad' => $m['cantidad'],
            'saldo_anterior' => $m['saldo_anterior'],
            'saldo_posterior' => $m['saldo_posterior'],
            'created_at' => $ahora,
        ], $movimientos);

        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('movimientos_stock')->insert($lote);
        }
    }
}
