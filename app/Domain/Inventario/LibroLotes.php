<?php

namespace App\Domain\Inventario;

use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Enums\TipoOperacionInventario;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;

/**
 * Colaborador de LibroStock para los productos con vencimiento (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 7). Corre
 * dentro de la misma transacción que LibroStock::aplicar() y es el único que escribe stock_lotes y
 * movimientos_stock_lotes. Invariante (§ 6.4): en cada sucursal, stock_niveles.cantidad = Σ stock_lotes.cantidad.
 *
 * Cada cambio puede traer:
 * - `lote` => {numero, vencimiento (Y-m-d)}: ingreso a ese lote, que se crea si no existe (compras, entradas,
 *   ajustes que suman, importación y regularización);
 * - `lote_id`: un lote concreto, para sumar o restar (salidas y ajustes con lote elegido, regularización);
 * - `replica_de` => sucursal de origen (destino de un traspaso, § 5.3): recibe los mismos lotes y cantidades que
 *   el reparto de la salida de ese producto en el origen, dentro de la misma operación;
 * - `devolucion` => {salida: operación de la venta, previas: operaciones de devoluciones anteriores de esa venta}
 *   (§ 5.5): reingresa a los lotes de los que salió esa venta, primero al que vence más tarde, y nunca más de lo
 *   que la venta sacó de cada lote menos lo ya devuelto. Lo que la venta no sacó de ningún lote (se vendió antes
 *   de marcar el producto) va al lote de regularización;
 * - nada: una salida se reparte sola según la política del tipo de operación (§ 5.2 a § 5.4) y un ingreso va al
 *   lote de regularización (§ 9): stock sin lote conocido, que se vende primero y se regulariza desde Vencimientos.
 *
 * Coste para quien no usa el módulo (§ 7.3): LibroStock solo llama aquí si algún producto de la operación está
 * marcado y la empresa tiene la capacidad; sin eso no se consulta ninguna tabla de lotes.
 *
 * Orden de bloqueo (§ 7.2): stock_niveles (LibroStock) → lotes (compartido) → stock_lotes por (sucursal, producto).
 * Lotes y saldos nuevos se crean fuera de la transacción (asegurar), como LibroStock::asegurarNiveles(). Solo el
 * destino de un traspaso o de una devolución puede necesitar, en casos raros (un lote nuevo entre asegurar() y el
 * bloqueo), crear su fila dentro: la consulta FOR UPDATE ya bloqueó el rango (empresa, sucursal, producto) del
 * índice, así que nadie más puede insertar ahí y no hay ciclo de bloqueos.
 */
final class LibroLotes
{
    private const VENTA = 'venta';

    private const TRASPASO = 'traspaso';

    private const BAJA = 'baja';

    public function __construct(private readonly CapacidadesEmpresa $capacidades) {}

    public function activo(int $empresaId): bool
    {
        return $this->capacidades->tiene($empresaId, Capacidad::LotesVencimientos);
    }

    /**
     * Productos que se llevan por lote entre los indicados: marcados y con la capacidad activa. Sin bloqueo; sin
     * consulta si la empresa no tiene la capacidad.
     *
     * @param  list<int>  $productoIds
     * @return array<int, true>
     */
    public function productosConLote(int $empresaId, array $productoIds): array
    {
        if ($productoIds === [] || ! $this->activo($empresaId)) {
            return [];
        }

        return array_fill_keys(DB::table('productos')
            ->where('empresa_id', $empresaId)
            ->whereIn('id', array_values(array_unique($productoIds)))
            ->where('controla_vencimiento', true)
            ->pluck('id')->map(fn ($id): int => (int) $id)->all(), true);
    }

    /**
     * Fuera de la transacción de la operación (§ 7.6): crea los lotes nuevos y, en cero, el saldo por sucursal de cada
     * lote que va a recibir unidades. Solo los ingresos (cantidad positiva) necesitan filas nuevas: una salida solo
     * descuenta de saldos que ya existen. Adelanta sin bloqueo la regla de § 5.1.3 para no crear nada si el número
     * ya está registrado con otra fecha; la comprobación definitiva se repite en preparar().
     *
     * @param  list<array<string, mixed>>  $cambios
     */
    public function asegurar(int $empresaId, array $cambios): void
    {
        $ingresos = array_values(array_filter($cambios, fn (array $c): bool => isset($c['cantidad'])
            && Decimal::comparar((string) $c['cantidad'], '0') > 0));

        if ($ingresos === []) {
            return;
        }

        $conLote = $this->productosConLote($empresaId, array_map(fn (array $c): int => (int) $c['producto_id'], $ingresos));
        $ingresos = array_values(array_filter($ingresos, fn (array $c): bool => isset($conLote[(int) $c['producto_id']])));

        if ($ingresos === []) {
            return;
        }

        $ahora = Carbon::now();
        $conNumero = array_values(array_filter($ingresos, fn (array $c): bool => isset($c['lote'])));
        $porNumero = [];

        if ($conNumero !== []) {
            $this->exigirDatos($conNumero);
            $porNumero = $this->buscarPorNumero($empresaId, $conNumero, false);
            $this->adelantarFechas($empresaId, $conNumero, $porNumero);
            $nuevos = [];

            foreach ($conNumero as $c) {
                $clave = DatosLote::clave((int) $c['producto_id'], $c['lote']['numero']);

                if (! isset($porNumero[$clave])) {
                    $nuevos[$clave] = [
                        'empresa_id' => $empresaId, 'producto_id' => (int) $c['producto_id'], 'numero' => $c['lote']['numero'],
                        'vencimiento' => $c['lote']['vencimiento'], 'es_regularizacion' => false, 'created_at' => $ahora,
                    ];
                }
            }

            if ($nuevos !== []) {
                foreach (array_chunk(array_values($nuevos), 500) as $parte) {
                    DB::table('lotes')->insertOrIgnore($parte);
                }

                $porNumero = $this->buscarPorNumero($empresaId, $conNumero, false);
            }
        }

        $sinLote = $this->productosSinLote($ingresos);
        $regularizacion = [];
        $filas = $this->filasReplica($empresaId, $ingresos, $ahora);

        if ($sinLote !== []) {
            DB::table('lotes')->insertOrIgnore(array_map(fn (int $productoId): array => [
                'empresa_id' => $empresaId, 'producto_id' => $productoId, 'numero' => null, 'vencimiento' => null,
                'es_regularizacion' => true, 'created_at' => $ahora,
            ], $sinLote));
            $regularizacion = $this->regularizacion($empresaId, $sinLote);
        }

        $explicitos = array_values(array_unique(array_map(fn (array $c): int => (int) $c['lote_id'],
            array_filter($ingresos, fn (array $c): bool => ! isset($c['lote']) && isset($c['lote_id'])))));
        // Un lote_id de otra empresa o de otro producto no recibe fila: preparar() rechaza la línea.
        $propios = $explicitos === [] ? [] : DB::table('lotes')->where('empresa_id', $empresaId)->whereIn('id', $explicitos)
            ->pluck('producto_id', 'id')->map(fn ($id): int => (int) $id)->all();

        foreach ($ingresos as $c) {
            if ($this->diferido($c)) {
                continue;
            }

            $productoId = (int) $c['producto_id'];
            $loteId = match (true) {
                isset($c['lote']) => isset($porNumero[$clave = DatosLote::clave($productoId, $c['lote']['numero'])]) ? (int) $porNumero[$clave]->id : null,
                isset($c['lote_id']) => ($propios[(int) $c['lote_id']] ?? null) === $productoId ? (int) $c['lote_id'] : null,
                default => $regularizacion[$productoId] ?? null,
            };

            if ($loteId !== null) {
                $filas[$c['sucursal_id'].':'.$loteId] = [
                    'empresa_id' => $empresaId, 'sucursal_id' => (int) $c['sucursal_id'], 'producto_id' => $productoId,
                    'lote_id' => $loteId, 'cantidad' => '0.000', 'created_at' => $ahora, 'updated_at' => $ahora,
                ];
            }
        }

        foreach (array_chunk(array_values($filas), 500) as $parte) {
            DB::table('stock_lotes')->insertOrIgnore($parte);
        }
    }

    /**
     * Destino de un traspaso: prepara en cero el saldo del destino de cada lote con stock en el origen (los que el
     * reparto FEFO puede elegir). Una consulta para todos los productos del traspaso.
     *
     * @param  list<array<string, mixed>>  $ingresos
     * @return array<string, array<string, mixed>>
     */
    private function filasReplica(int $empresaId, array $ingresos, Carbon $ahora): array
    {
        $replicas = array_values(array_filter($ingresos, fn (array $c): bool => isset($c['replica_de'])));

        if ($replicas === []) {
            return [];
        }

        $origenes = DB::table('stock_lotes')
            ->where('empresa_id', $empresaId)
            ->whereIn('sucursal_id', array_values(array_unique(array_map(fn (array $c): int => (int) $c['replica_de'], $replicas))))
            ->whereIn('producto_id', array_values(array_unique(array_map(fn (array $c): int => (int) $c['producto_id'], $replicas))))
            ->where('cantidad', '>', 0)
            ->get(['sucursal_id', 'producto_id', 'lote_id']);
        $filas = [];

        foreach ($replicas as $c) {
            foreach ($origenes as $origen) {
                if ((int) $origen->sucursal_id === (int) $c['replica_de'] && (int) $origen->producto_id === (int) $c['producto_id']) {
                    $filas[$c['sucursal_id'].':'.$origen->lote_id] = [
                        'empresa_id' => $empresaId, 'sucursal_id' => (int) $c['sucursal_id'], 'producto_id' => (int) $c['producto_id'],
                        'lote_id' => (int) $origen->lote_id, 'cantidad' => '0.000', 'created_at' => $ahora, 'updated_at' => $ahora,
                    ];
                }
            }
        }

        return $filas;
    }

    /**
     * Ingresos que se resuelven después de las salidas de la misma operación (destino de traspaso) o según una venta
     * anterior (devolución).
     *
     * @param  array<string, mixed>  $cambio
     */
    private function diferido(array $cambio): bool
    {
        return isset($cambio['replica_de']) || isset($cambio['devolucion']);
    }

    /**
     * Dentro de la transacción, con stock_niveles ya bloqueado: bloquea los saldos por lote de esos mismos pares,
     * comprueba el invariante, resuelve el lote de cada cambio y reparte las salidas automáticas. No escribe saldos:
     * devuelve los movimientos netos por lote y los errores de stock por par «sucursal:producto», para que
     * LibroStock los informe junto con los demás. Los errores de datos (lote con otra fecha) se lanzan aquí.
     *
     * @param  list<array<string, mixed>>  $cambios  solo de productos con lote
     * @param  array<string, string>  $totales  «sucursal:producto» => stock_niveles.cantidad antes de la operación
     * @return array{movimientos: list<array{stock_lote_id: int, sucursal_id: int, producto_id: int, lote_id: int, cantidad: string, saldo_anterior: string, saldo_posterior: string}>, errores: array<string, list<string>>}
     */
    public function preparar(int $empresaId, TipoOperacionInventario $tipo, array $cambios, array $totales): array
    {
        $pares = [];
        $nombres = [];

        foreach ($cambios as $c) {
            $par = $c['sucursal_id'].':'.$c['producto_id'];
            $pares[$par] = true;
            $nombres[$par] ??= (string) $c['nombre'];
        }

        $filas = DB::table('stock_lotes')
            ->where('empresa_id', $empresaId)
            ->whereIn('sucursal_id', array_values(array_unique(array_map(fn (array $c): int => (int) $c['sucursal_id'], $cambios))))
            ->whereIn('producto_id', array_values(array_unique(array_map(fn (array $c): int => (int) $c['producto_id'], $cambios))))
            ->orderBy('sucursal_id')
            ->orderBy('producto_id')
            ->orderBy('lote_id')
            ->lockForUpdate()
            ->get(['id', 'sucursal_id', 'producto_id', 'lote_id', 'cantidad'])
            ->filter(fn (stdClass $f): bool => isset($pares[$f->sucursal_id.':'.$f->producto_id]))
            ->values();

        $this->exigirInvariante($empresaId, $filas->all(), $totales, $nombres);

        $estado = ['filas' => [], 'porLote' => [], 'porPar' => [], 'saldos' => [], 'originales' => []];

        foreach ($filas as $fila) {
            $this->registrarFila($estado, $fila);
        }

        $porLote = &$estado['porLote'];
        $porPar = &$estado['porPar'];
        $saldos = &$estado['saldos'];
        $positivo = fn (array $c): bool => Decimal::comparar((string) $c['cantidad'], '0') > 0;
        $conNumero = array_values(array_filter($cambios, fn (array $c): bool => $positivo($c) && isset($c['lote'])));
        $porNumero = [];

        if ($conNumero !== []) {
            $this->exigirDatos($conNumero);
            $porNumero = $this->buscarPorNumero($empresaId, $conNumero, true);
            $this->corregirFechas($empresaId, $conNumero, $porNumero);
        }

        $sinLote = $this->productosSinLote(array_values(array_filter($cambios, $positivo)));
        $lotes = $this->metadatos($empresaId, $filas->all(), $cambios, $sinLote);
        $regularizacion = [];

        foreach ($lotes as $lote) {
            if ((bool) $lote->es_regularizacion) {
                $regularizacion[(int) $lote->producto_id] = (int) $lote->id;
            }
        }

        $errores = [];

        // 1. Ingresos que ya saben a qué lote van (réplicas y devoluciones se resuelven al final).
        foreach ($cambios as $c) {
            if (! $positivo($c) || $this->diferido($c)) {
                continue;
            }

            $productoId = (int) $c['producto_id'];
            $loteId = match (true) {
                isset($c['lote']) => isset($porNumero[$clave = DatosLote::clave($productoId, $c['lote']['numero'])]) ? (int) $porNumero[$clave]->id : null,
                isset($c['lote_id']) => (int) $c['lote_id'],
                default => $regularizacion[$productoId] ?? null,
            };
            $fila = $loteId === null ? null : ($porLote[$c['sucursal_id'].':'.$productoId.':'.$loteId] ?? null);

            if ($fila === null) {
                throw ValidationException::withMessages(['lineas' => isset($c['lote_id']) && ! isset($c['lote'])
                    ? __(':producto: el lote indicado no existe.', ['producto' => $c['nombre']])
                    : __('No se pudo preparar el stock por lote de :producto. Inténtalo de nuevo.', ['producto' => $c['nombre']])]);
            }

            $saldos[(int) $fila->id] = Decimal::sumar($saldos[(int) $fila->id], (string) $c['cantidad']);
        }

        // 2. Salidas de un lote elegido (también vencido: así se dan de baja, § 5.4).
        foreach ($cambios as $c) {
            if ($positivo($c) || ! isset($c['lote_id'])) {
                continue;
            }

            $par = $c['sucursal_id'].':'.$c['producto_id'];
            $fila = $porLote[$par.':'.(int) $c['lote_id']] ?? null;
            $pedido = Decimal::restar('0', (string) $c['cantidad']);

            if ($fila === null) {
                $errores[$par][] = __(':producto: el lote indicado no tiene stock en esta sucursal.', ['producto' => $c['nombre']]);

                continue;
            }

            $disponible = $saldos[(int) $fila->id];

            if (Decimal::comparar($disponible, $pedido) < 0) {
                $errores[$par][] = __(':producto, :lote: disponible :cantidad.', [
                    'producto' => $c['nombre'], 'lote' => $this->nombreLote($lotes[(int) $fila->lote_id] ?? null), 'cantidad' => Decimal::formatear($disponible),
                ]);

                continue;
            }

            $saldos[(int) $fila->id] = Decimal::restar($disponible, $pedido);
        }

        // 3. Salidas automáticas, según la política del tipo de operación.
        $politica = $this->politica($tipo);
        $hoy = DatosLote::hoy();

        foreach ($cambios as $c) {
            if ($positivo($c) || isset($c['lote_id'])) {
                continue;
            }

            $par = $c['sucursal_id'].':'.$c['producto_id'];
            $error = $this->repartir($politica, $hoy, $porPar[$par] ?? [], $lotes, $saldos, Decimal::restar('0', (string) $c['cantidad']), (string) $c['nombre']);

            if ($error !== null) {
                $errores[$par][] = $error;
            }
        }

        // 4. Destino de un traspaso: los mismos lotes y cantidades que salieron del origen (§ 5.3).
        foreach ($cambios as $c) {
            if (! $positivo($c) || ! isset($c['replica_de'])) {
                continue;
            }

            $origen = (int) $c['replica_de'].':'.(int) $c['producto_id'];

            if (isset($errores[$origen])) {
                continue;
            }

            $pendiente = (string) $c['cantidad'];

            foreach ($porPar[$origen] ?? [] as $filaOrigen) {
                $tomado = Decimal::restar($estado['originales'][(int) $filaOrigen->id], $saldos[(int) $filaOrigen->id]);
                $toma = Decimal::comparar($tomado, $pendiente) < 0 ? $tomado : $pendiente;

                if (Decimal::comparar($toma, '0') <= 0) {
                    continue;
                }

                $fila = $this->filaDestino($empresaId, $estado, (int) $c['sucursal_id'], (int) $c['producto_id'], (int) $filaOrigen->lote_id);
                $saldos[(int) $fila->id] = Decimal::sumar($saldos[(int) $fila->id], $toma);
                $pendiente = Decimal::restar($pendiente, $toma);
            }

            if (Decimal::comparar($pendiente, '0') !== 0) {
                throw new LogicException('El destino del traspaso no coincide con la salida del origen.');
            }
        }

        // 5. Devoluciones: a los lotes de los que salió la venta (§ 5.5).
        $this->repartirDevoluciones($empresaId, $estado, array_values(array_filter($cambios, fn (array $c): bool => $positivo($c) && isset($c['devolucion']))));

        $movimientos = [];

        foreach ($estado['filas'] as $fila) {
            $anterior = $estado['originales'][(int) $fila->id];
            $posterior = $saldos[(int) $fila->id];
            $cantidad = Decimal::restar($posterior, $anterior);

            if (Decimal::comparar($cantidad, '0') !== 0) {
                $movimientos[] = [
                    'stock_lote_id' => (int) $fila->id, 'sucursal_id' => (int) $fila->sucursal_id, 'producto_id' => (int) $fila->producto_id,
                    'lote_id' => (int) $fila->lote_id, 'cantidad' => $cantidad, 'saldo_anterior' => $anterior, 'saldo_posterior' => $posterior,
                ];
            }
        }

        return ['movimientos' => $movimientos, 'errores' => $errores];
    }

    /**
     * @param  array{filas: list<stdClass>, porLote: array<string, stdClass>, porPar: array<string, list<stdClass>>, saldos: array<int, string>, originales: array<int, string>}  $estado
     */
    private function registrarFila(array &$estado, stdClass $fila): void
    {
        $estado['filas'][] = $fila;
        $estado['porLote'][$fila->sucursal_id.':'.$fila->producto_id.':'.$fila->lote_id] = $fila;
        $estado['porPar'][$fila->sucursal_id.':'.$fila->producto_id][] = $fila;
        $estado['saldos'][(int) $fila->id] = (string) $fila->cantidad;
        $estado['originales'][(int) $fila->id] = (string) $fila->cantidad;
    }

    /**
     * Saldo bloqueado de un lote (o del de regularización, con $loteId null) en la sucursal de destino. Lo normal es
     * que ya esté entre los bloqueados; si no existía, se crea aquí (ver la nota de bloqueo de la clase).
     *
     * @param  array{filas: list<stdClass>, porLote: array<string, stdClass>, porPar: array<string, list<stdClass>>, saldos: array<int, string>, originales: array<int, string>}  $estado
     */
    private function filaDestino(int $empresaId, array &$estado, int $sucursalId, int $productoId, ?int $loteId): stdClass
    {
        $loteId ??= $this->loteRegularizacion($empresaId, $productoId);
        $clave = $sucursalId.':'.$productoId.':'.$loteId;

        if (isset($estado['porLote'][$clave])) {
            return $estado['porLote'][$clave];
        }

        $ahora = Carbon::now();
        DB::table('stock_lotes')->insertOrIgnore([
            'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId, 'producto_id' => $productoId, 'lote_id' => $loteId,
            'cantidad' => '0.000', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $fila = DB::table('stock_lotes')->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)->where('lote_id', $loteId)
            ->lockForUpdate()->first(['id', 'sucursal_id', 'producto_id', 'lote_id', 'cantidad'])
            ?? throw new LogicException("No se pudo preparar el saldo del lote {$loteId} en la sucursal {$sucursalId}.");
        $this->registrarFila($estado, $fila);

        return $fila;
    }

    /**
     * Id del lote de regularización del producto, que se crea si todavía no existe (uno por producto).
     */
    public function loteRegularizacion(int $empresaId, int $productoId): int
    {
        $id = DB::table('lotes')->where('empresa_id', $empresaId)->where('clave_regularizacion', $productoId)->value('id');

        if ($id === null) {
            DB::table('lotes')->insertOrIgnore([
                'empresa_id' => $empresaId, 'producto_id' => $productoId, 'numero' => null, 'vencimiento' => null,
                'es_regularizacion' => true, 'created_at' => Carbon::now(),
            ]);
            $id = DB::table('lotes')->where('empresa_id', $empresaId)->where('clave_regularizacion', $productoId)->value('id');
        }

        return (int) ($id ?? throw new LogicException("No se pudo crear el lote de regularización del producto {$productoId}."));
    }

    /**
     * § 5.5: cada producto devuelto reingresa a los lotes de los que lo sacó la venta, primero al que vence más tarde
     * (deja en el estante el de más vida útil), sin pasar de lo que la venta sacó de cada lote menos lo ya devuelto.
     * Un lote vencido también recibe (es la verdad física) y queda bloqueado para la venta. Lo que la venta no sacó
     * de ningún lote va al lote de regularización. Dos consultas para toda la operación. La venta está bloqueada por
     * RegistrarDevolucion, así que dos devoluciones de la misma venta no leen a la vez lo ya devuelto.
     *
     * @param  array{filas: list<stdClass>, porLote: array<string, stdClass>, porPar: array<string, list<stdClass>>, saldos: array<int, string>, originales: array<int, string>}  $estado
     * @param  list<array<string, mixed>>  $devoluciones
     */
    private function repartirDevoluciones(int $empresaId, array &$estado, array $devoluciones): void
    {
        if ($devoluciones === []) {
            return;
        }

        $operaciones = [];

        foreach ($devoluciones as $c) {
            $operaciones[] = (int) $c['devolucion']['salida'];
            array_push($operaciones, ...array_map('intval', $c['devolucion']['previas'] ?? []));
        }

        $movimientos = DB::table('movimientos_stock_lotes')
            ->where('empresa_id', $empresaId)
            ->whereIn('operacion_id', array_values(array_unique($operaciones)))
            ->whereIn('producto_id', array_values(array_unique(array_map(fn (array $c): int => (int) $c['producto_id'], $devoluciones))))
            ->get(['operacion_id', 'sucursal_id', 'producto_id', 'lote_id', 'cantidad']);
        $lotes = $movimientos->isEmpty() ? [] : DB::table('lotes')->where('empresa_id', $empresaId)
            ->whereIn('id', $movimientos->pluck('lote_id')->unique()->values()->all())
            ->get(['id', 'vencimiento', 'es_regularizacion'])->keyBy(fn (stdClass $l): int => (int) $l->id)->all();

        foreach ($devoluciones as $c) {
            $salida = (int) $c['devolucion']['salida'];
            $previas = array_map('intval', $c['devolucion']['previas'] ?? []);
            $disponibles = [];

            foreach ($movimientos as $m) {
                if ((int) $m->sucursal_id !== (int) $c['sucursal_id'] || (int) $m->producto_id !== (int) $c['producto_id']) {
                    continue;
                }

                $loteId = (int) $m->lote_id;
                $cantidad = (string) $m->cantidad;

                if ((int) $m->operacion_id === $salida && Decimal::comparar($cantidad, '0') < 0) {
                    $disponibles[$loteId] = Decimal::restar($disponibles[$loteId] ?? '0', $cantidad);
                } elseif (in_array((int) $m->operacion_id, $previas, true) && Decimal::comparar($cantidad, '0') > 0) {
                    $disponibles[$loteId] = Decimal::restar($disponibles[$loteId] ?? '0', $cantidad);
                }
            }

            // Vence más tarde primero; el de regularización (stock anterior, sin fecha) al final.
            uksort($disponibles, function (int $a, int $b) use ($lotes): int {
                $rango = fn (int $id): int => (bool) ($lotes[$id]->es_regularizacion ?? true) ? 1 : 0;
                $fecha = fn (int $id): string => (string) ($lotes[$id]->vencimiento ?? '');

                return [$rango($a), $fecha($b), $b] <=> [$rango($b), $fecha($a), $a];
            });
            $pendiente = (string) $c['cantidad'];

            foreach ($disponibles as $loteId => $disponible) {
                $toma = Decimal::comparar($disponible, $pendiente) < 0 ? $disponible : $pendiente;

                if (Decimal::comparar($toma, '0') <= 0) {
                    continue;
                }

                $fila = $this->filaDestino($empresaId, $estado, (int) $c['sucursal_id'], (int) $c['producto_id'], $loteId);
                $estado['saldos'][(int) $fila->id] = Decimal::sumar($estado['saldos'][(int) $fila->id], $toma);
                $pendiente = Decimal::restar($pendiente, $toma);
            }

            if (Decimal::comparar($pendiente, '0') > 0) {
                $fila = $this->filaDestino($empresaId, $estado, (int) $c['sucursal_id'], (int) $c['producto_id'], null);
                $estado['saldos'][(int) $fila->id] = Decimal::sumar($estado['saldos'][(int) $fila->id], $pendiente);
            }
        }
    }

    /**
     * Saldos por lote (un UPDATE … CASE por bloque) y libro por lote (INSERT en bloque), sin una consulta por línea.
     *
     * @param  list<array{stock_lote_id: int, sucursal_id: int, producto_id: int, lote_id: int, cantidad: string, saldo_anterior: string, saldo_posterior: string}>  $movimientos
     */
    public function escribir(int $empresaId, int $operacionId, array $movimientos): void
    {
        $ahora = Carbon::now();

        foreach (array_chunk($movimientos, 500) as $parte) {
            $casos = implode(' ', array_fill(0, count($parte), 'WHEN ? THEN ?'));
            $valores = [];

            foreach ($parte as $m) {
                array_push($valores, $m['stock_lote_id'], $m['saldo_posterior']);
            }

            $marcadores = implode(',', array_fill(0, count($parte), '?'));
            DB::update(
                "UPDATE stock_lotes SET cantidad = CASE id {$casos} END, updated_at = ? WHERE empresa_id = ? AND id IN ({$marcadores})",
                [...$valores, $ahora, $empresaId, ...array_column($parte, 'stock_lote_id')],
            );
            DB::table('movimientos_stock_lotes')->insert(array_map(fn (array $m): array => [
                'empresa_id' => $empresaId, 'operacion_id' => $operacionId, 'sucursal_id' => $m['sucursal_id'],
                'producto_id' => $m['producto_id'], 'lote_id' => $m['lote_id'], 'cantidad' => $m['cantidad'],
                'saldo_anterior' => $m['saldo_anterior'], 'saldo_posterior' => $m['saldo_posterior'], 'created_at' => $ahora,
            ], $parte));
        }
    }

    /**
     * Venta y traspaso: solo lotes no vencidos (§ 5.2 y § 5.3). Salida y ajuste: primero los vencidos (§ 5.4).
     */
    private function politica(TipoOperacionInventario $tipo): string
    {
        return match ($tipo) {
            TipoOperacionInventario::Venta => self::VENTA,
            TipoOperacionInventario::Traspaso => self::TRASPASO,
            default => self::BAJA,
        };
    }

    /**
     * Reparte una salida entre los lotes del par y actualiza $saldos. Orden:
     * - venta y traspaso: regularización (stock anterior, se supone más antiguo, § 5.2.3), luego FEFO entre vigentes;
     * - baja: vencidos (FEFO), luego regularización, luego vigentes (FEFO).
     * A igual fecha, primero el lote de menor id (el que se registró antes). Si no alcanza, no descuenta nada y
     * devuelve el mensaje, que distingue lo vendible de lo vencido.
     *
     * @param  array<int, stdClass>  $filas
     * @param  array<int, stdClass>  $lotes
     * @param  array<int, string>  $saldos
     */
    private function repartir(string $politica, string $hoy, array $filas, array $lotes, array &$saldos, string $pedido, string $nombre): ?string
    {
        $candidatas = [];
        $vencidas = '0';
        $vigentes = '0';

        foreach ($filas as $fila) {
            $saldo = $saldos[(int) $fila->id];
            $lote = $lotes[(int) $fila->lote_id] ?? null;

            if ($lote === null || Decimal::comparar($saldo, '0') <= 0) {
                continue;
            }

            $vencido = DatosLote::vencido($lote->vencimiento === null ? null : (string) $lote->vencimiento, $hoy);

            if ($vencido) {
                $vencidas = Decimal::sumar($vencidas, $saldo);
            } else {
                $vigentes = Decimal::sumar($vigentes, $saldo);
            }

            if ($vencido && $politica !== self::BAJA) {
                continue;
            }

            $rango = match (true) {
                $vencido => 0,
                (bool) $lote->es_regularizacion => 1,
                default => 2,
            };
            $candidatas[] = ['fila' => (int) $fila->id, 'orden' => [$rango, (string) ($lote->vencimiento ?? ''), (int) $lote->id]];
        }

        $disponible = $politica === self::BAJA ? Decimal::sumar($vigentes, $vencidas) : $vigentes;

        if (Decimal::comparar($disponible, $pedido) < 0) {
            if ($politica === self::BAJA || Decimal::comparar($vencidas, '0') === 0) {
                return __(':producto: stock disponible :cantidad.', ['producto' => $nombre, 'cantidad' => Decimal::formatear($disponible)]);
            }

            return $politica === self::VENTA
                ? __(':producto: disponible :vendible; :vencidas vencidas (dar de baja en Ajustes → Salida → Vencido).', [
                    'producto' => $nombre, 'vendible' => Decimal::formatear($vigentes), 'vencidas' => Decimal::formatear($vencidas)])
                : __(':producto: disponible para traspasar :vigente; :vencidas vencidas no se traspasan (dar de baja en Ajustes → Salida → Vencido).', [
                    'producto' => $nombre, 'vigente' => Decimal::formatear($vigentes), 'vencidas' => Decimal::formatear($vencidas)]);
        }

        usort($candidatas, fn (array $a, array $b): int => $a['orden'] <=> $b['orden']);
        $pendiente = $pedido;

        foreach ($candidatas as $candidata) {
            if (Decimal::comparar($pendiente, '0') <= 0) {
                break;
            }

            $saldo = $saldos[$candidata['fila']];
            $toma = Decimal::comparar($saldo, $pendiente) < 0 ? $saldo : $pendiente;
            $saldos[$candidata['fila']] = Decimal::restar($saldo, $toma);
            $pendiente = Decimal::restar($pendiente, $toma);
        }

        return null;
    }

    /**
     * § 6.4: con stock_niveles y stock_lotes bloqueados, el total y la suma por lote deben coincidir antes de mover
     * nada. Si no, la operación se rechaza entera y queda en el log (es un error de datos, no del usuario).
     *
     * @param  array<int, stdClass>  $filas
     * @param  array<string, string>  $totales
     * @param  array<string, string>  $nombres
     */
    private function exigirInvariante(int $empresaId, array $filas, array $totales, array $nombres): void
    {
        $sumas = [];

        foreach ($filas as $fila) {
            $par = $fila->sucursal_id.':'.$fila->producto_id;
            $sumas[$par] = Decimal::sumar($sumas[$par] ?? '0', (string) $fila->cantidad);
        }

        foreach ($nombres as $par => $nombre) {
            $total = $totales[$par] ?? '0';
            $suma = $sumas[$par] ?? '0';

            if (Decimal::comparar($total, $suma) !== 0) {
                report(new LogicException("Stock por lote descuadrado: empresa {$empresaId}, sucursal:producto {$par}, total {$total}, lotes {$suma}."));

                throw ValidationException::withMessages(['lineas' => __('El stock por lote de :producto no coincide con su stock total en la sucursal (:total frente a :lotes). No se guardó ningún cambio; avisa al administrador.', [
                    'producto' => $nombre, 'total' => Decimal::formatear($total), 'lotes' => Decimal::formatear($suma),
                ])]);
            }
        }
    }

    /**
     * Los llamadores normalizan el lote con DatosLote. Llegar aquí con un número vacío o una fecha mal formada es un
     * error de programación. Dos líneas con el mismo número y fechas distintas sí son un error del usuario.
     *
     * @param  list<array<string, mixed>>  $cambios
     */
    private function exigirDatos(array $cambios): void
    {
        $fechas = [];

        foreach ($cambios as $c) {
            $numero = $c['lote']['numero'] ?? null;
            $vencimiento = $c['lote']['vencimiento'] ?? null;

            if (! is_string($numero) || $numero === '' || $numero !== trim($numero) || mb_strlen($numero) > DatosLote::MAX_NUMERO
                || ! is_string($vencimiento) || DatosLote::vencimiento($vencimiento) !== $vencimiento) {
                throw new LogicException('LibroLotes recibió un lote sin normalizar.');
            }

            $clave = DatosLote::clave((int) $c['producto_id'], $numero);

            if (isset($fechas[$clave]) && $fechas[$clave] !== $vencimiento) {
                throw ValidationException::withMessages(['lineas' => __(':producto: el lote :lote tiene dos vencimientos distintos.', [
                    'producto' => $c['nombre'] ?? '', 'lote' => $numero,
                ])]);
            }

            $fechas[$clave] = $vencimiento;
        }
    }

    /**
     * Lotes con número de esos productos, por clave producto|NÚMERO. Dentro de la transacción, con bloqueo
     * compartido: lee la última versión confirmada aunque el lote se haya creado después de empezar.
     *
     * @param  list<array<string, mixed>>  $cambios
     * @return array<string, stdClass>
     */
    private function buscarPorNumero(int $empresaId, array $cambios, bool $bloquear): array
    {
        $consulta = DB::table('lotes')
            ->where('empresa_id', $empresaId)
            ->where('es_regularizacion', false)
            ->whereIn('producto_id', array_values(array_unique(array_map(fn (array $c): int => (int) $c['producto_id'], $cambios))))
            ->whereIn('numero', array_values(array_unique(array_map(fn (array $c): string => $c['lote']['numero'], $cambios))))
            ->orderBy('id');

        if ($bloquear) {
            $consulta->sharedLock();
        }

        return $consulta->get(['id', 'producto_id', 'numero', 'vencimiento'])
            ->keyBy(fn (stdClass $l): string => DatosLote::clave((int) $l->producto_id, (string) $l->numero))
            ->all();
    }

    /**
     * Antes de crear nada: el mismo número con otra fecha se rechaza (§ 5.1.3), salvo que ese lote nunca haya tenido
     * movimientos (quedó de una operación que falló); en ese caso preparar() corrige su fecha.
     *
     * @param  list<array<string, mixed>>  $cambios
     * @param  array<string, stdClass>  $porNumero
     */
    private function adelantarFechas(int $empresaId, array $cambios, array $porNumero): void
    {
        $distintos = $this->fechasDistintas($cambios, $porNumero);

        if ($distintos === []) {
            return;
        }

        $usados = DB::table('movimientos_stock_lotes')->where('empresa_id', $empresaId)
            ->whereIn('lote_id', array_keys($distintos))->distinct()->pluck('lote_id')->map(fn ($id): int => (int) $id)->all();
        $this->rechazarFechas($distintos, $usados);
    }

    /**
     * Dentro de la transacción: si la fecha no coincide, bloquea el lote en exclusiva y mira si tuvo movimientos (quien
     * los insertó tenía un bloqueo compartido sobre el lote hasta confirmar). Sin movimientos, el lote quedó de una
     * operación fallida y se corrige su fecha; con movimientos se rechaza la línea.
     *
     * @param  list<array<string, mixed>>  $cambios
     * @param  array<string, stdClass>  $porNumero
     */
    private function corregirFechas(int $empresaId, array $cambios, array &$porNumero): void
    {
        $distintos = $this->fechasDistintas($cambios, $porNumero);

        if ($distintos === []) {
            return;
        }

        $ids = array_keys($distintos);
        DB::table('lotes')->where('empresa_id', $empresaId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
        $usados = DB::table('movimientos_stock_lotes')->where('empresa_id', $empresaId)->whereIn('lote_id', $ids)
            ->sharedLock()->distinct()->pluck('lote_id')->map(fn ($id): int => (int) $id)->all();
        $this->rechazarFechas($distintos, $usados);

        foreach ($distintos as $loteId => $distinto) {
            DB::table('lotes')->where('empresa_id', $empresaId)->where('id', $loteId)->update(['vencimiento' => $distinto['vencimiento']]);
            $porNumero[$distinto['clave']]->vencimiento = $distinto['vencimiento'];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $cambios
     * @param  array<string, stdClass>  $porNumero
     * @return array<int, array{clave: string, vencimiento: string, registrado: string, numero: string, producto: string}>
     */
    private function fechasDistintas(array $cambios, array $porNumero): array
    {
        $distintos = [];

        foreach ($cambios as $c) {
            $clave = DatosLote::clave((int) $c['producto_id'], $c['lote']['numero']);
            $lote = $porNumero[$clave] ?? null;

            if ($lote !== null && substr((string) $lote->vencimiento, 0, 10) !== $c['lote']['vencimiento']) {
                $distintos[(int) $lote->id] = [
                    'clave' => $clave, 'vencimiento' => $c['lote']['vencimiento'], 'registrado' => substr((string) $lote->vencimiento, 0, 10),
                    'numero' => (string) $lote->numero, 'producto' => (string) ($c['nombre'] ?? ''),
                ];
            }
        }

        return $distintos;
    }

    /**
     * @param  array<int, array{clave: string, vencimiento: string, registrado: string, numero: string, producto: string}>  $distintos
     * @param  array<int, int>  $usados
     */
    private function rechazarFechas(array $distintos, array $usados): void
    {
        $errores = [];

        foreach ($distintos as $loteId => $distinto) {
            if (in_array($loteId, $usados, true)) {
                $errores[] = __(':producto: el lote :lote ya está registrado con vencimiento :fecha. Un mismo lote no puede tener dos vencimientos.', [
                    'producto' => $distinto['producto'], 'lote' => $distinto['numero'], 'fecha' => DatosLote::formatear($distinto['registrado']),
                ]);
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages(['lineas' => $errores]);
        }
    }

    /**
     * Productos de los ingresos que no indican lote ni se resuelven como réplica o devolución: van al lote de
     * regularización.
     *
     * @param  list<array<string, mixed>>  $ingresos
     * @return list<int>
     */
    private function productosSinLote(array $ingresos): array
    {
        return array_values(array_unique(array_map(fn (array $c): int => (int) $c['producto_id'],
            array_filter($ingresos, fn (array $c): bool => ! isset($c['lote']) && ! isset($c['lote_id']) && ! $this->diferido($c)))));
    }

    /**
     * @param  list<int>  $productoIds
     * @return array<int, int> producto_id => id del lote de regularización
     */
    private function regularizacion(int $empresaId, array $productoIds): array
    {
        return DB::table('lotes')->where('empresa_id', $empresaId)->whereIn('clave_regularizacion', $productoIds)
            ->pluck('id', 'producto_id')->mapWithKeys(fn ($id, $productoId): array => [(int) $productoId => (int) $id])->all();
    }

    /**
     * Número, fecha y tipo de los lotes que intervienen: los que tienen saldo en los pares, los elegidos y los de
     * regularización de los ingresos sin lote. Una consulta, con bloqueo compartido.
     *
     * @param  array<int, stdClass>  $filas
     * @param  list<array<string, mixed>>  $cambios
     * @param  list<int>  $sinLote
     * @return array<int, stdClass>
     */
    private function metadatos(int $empresaId, array $filas, array $cambios, array $sinLote): array
    {
        $ids = [];

        foreach ($filas as $fila) {
            if (Decimal::comparar((string) $fila->cantidad, '0') > 0) {
                $ids[] = (int) $fila->lote_id;
            }
        }

        foreach ($cambios as $c) {
            if (isset($c['lote_id']) && ! isset($c['lote'])) {
                $ids[] = (int) $c['lote_id'];
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === [] && $sinLote === []) {
            return [];
        }

        return DB::table('lotes')
            ->where('empresa_id', $empresaId)
            ->where(function ($q) use ($ids, $sinLote): void {
                if ($ids !== []) {
                    $q->whereIn('id', $ids);
                }

                if ($sinLote !== []) {
                    $q->orWhereIn('clave_regularizacion', $sinLote);
                }
            })
            ->orderBy('id')
            ->sharedLock()
            ->get(['id', 'producto_id', 'numero', 'vencimiento', 'es_regularizacion'])
            ->keyBy(fn (stdClass $l): int => (int) $l->id)
            ->all();
    }

    private function nombreLote(?stdClass $lote): string
    {
        if ($lote === null) {
            return __('lote');
        }

        return (bool) $lote->es_regularizacion ? __('sin lote (regularización)') : __('lote :numero', ['numero' => $lote->numero]);
    }
}
