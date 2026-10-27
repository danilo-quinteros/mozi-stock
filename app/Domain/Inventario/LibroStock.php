<?php

namespace App\Domain\Inventario;

use App\Domain\Tenancy\Numerador;
use App\Enums\TipoOperacionInventario;
use App\Models\OperacionInventario;
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
 */
final class LibroStock
{
    public const MAX_LINEAS = 1000;

    public function __construct(private readonly Numerador $numerador) {}

    /**
     * Valida cantidades (texto) por producto: positivas, con decimales solo si la unidad
     * los admite, de productos activos de la empresa. Una sola consulta para todas las líneas.
     *
     * @param  array<int|string, mixed>  $lineas  producto_id => cantidad
     * @return array<int, array{cantidad: string, nombre: string}>
     */
    public function prepararLineas(int $empresaId, array $lineas): array
    {
        if ($lineas === []) {
            throw ValidationException::withMessages(['lineas' => __('Agrega al menos un producto.')]);
        }

        if (count($lineas) > self::MAX_LINEAS) {
            throw ValidationException::withMessages(['lineas' => __('Una operación admite como máximo :maximo productos.', ['maximo' => self::MAX_LINEAS])]);
        }

        $ids = array_map('intval', array_keys($lineas));
        $productos = DB::table('productos')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'productos.unidad_medida_id')
            ->where('productos.empresa_id', $empresaId)
            ->where('productos.activo', true)
            ->whereIn('productos.id', $ids)
            ->get(['productos.id', 'productos.nombre', 'unidades_medida.admite_decimales'])
            ->keyBy('id');

        $preparadas = [];
        $errores = [];

        foreach ($lineas as $id => $valor) {
            $producto = $productos->get((int) $id);

            if ($producto === null) {
                $errores[] = __('Uno de los productos no existe o está inactivo.');

                continue;
            }

            $cantidad = Decimal::normalizar(is_scalar($valor) ? (string) $valor : '', Decimal::ESCALA_CANTIDAD);

            if ($cantidad === null || Decimal::comparar($cantidad, '0') <= 0) {
                $errores[] = __(':nombre: la cantidad debe ser mayor que cero (hasta 3 decimales).', ['nombre' => $producto->nombre]);
            } elseif (! $producto->admite_decimales && ! Decimal::esEntero($cantidad)) {
                $errores[] = __(':nombre: se mide en unidades enteras.', ['nombre' => $producto->nombre]);
            } else {
                $preparadas[(int) $id] = ['cantidad' => $cantidad, 'nombre' => (string) $producto->nombre];
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
     * @param  array{tipo: TipoOperacionInventario, motivo: string|null, sucursal_id: int, sucursal_destino_id: int|null, nota: string|null, clave: string}  $cabecera
     * @param  list<array{sucursal_id: int, producto_id: int, cantidad: string, nombre: string}>  $cambios  cantidad con signo
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
     * @param  array{tipo: TipoOperacionInventario, motivo: string|null, sucursal_id: int, sucursal_destino_id: int|null, nota: string|null, clave: string}  $cabecera
     * @param  list<array{sucursal_id: int, producto_id: int, cantidad: string, nombre: string}>  $cambios
     */
    public function aplicar(User $actor, array $cabecera, array $cambios): OperacionInventario
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('LibroStock::aplicar() requiere una transacción abierta.');
        }

        $empresaId = (int) $actor->empresa_id;
        $niveles = DB::table('stock_niveles')
            ->where('empresa_id', $empresaId)
            ->whereIn('sucursal_id', array_values(array_unique(array_column($cambios, 'sucursal_id'))))
            ->whereIn('producto_id', array_values(array_unique(array_column($cambios, 'producto_id'))))
            ->orderBy('sucursal_id')
            ->orderBy('producto_id')
            ->lockForUpdate()
            ->get(['id', 'sucursal_id', 'producto_id', 'cantidad'])
            ->keyBy(fn (object $fila) => $fila->sucursal_id.':'.$fila->producto_id);

        $saldos = [];
        $movimientos = [];
        $faltantes = [];

        foreach ($cambios as $cambio) {
            $clave = $cambio['sucursal_id'].':'.$cambio['producto_id'];
            $nivel = $niveles->get($clave)
                ?? throw ValidationException::withMessages(['lineas' => __('No se pudo preparar el stock de la sucursal. Inténtalo de nuevo.')]);
            $anterior = $saldos[$clave] ?? (string) $nivel->cantidad;
            $posterior = Decimal::sumar($anterior, $cambio['cantidad']);

            if (Decimal::comparar($posterior, '0') < 0) {
                $faltantes[] = __(':producto: stock disponible :cantidad.', ['producto' => $cambio['nombre'], 'cantidad' => Decimal::formatear($anterior)]);
            }

            $saldos[$clave] = $posterior;
            $movimientos[] = [
                'nivel_id' => (int) $nivel->id,
                'sucursal_id' => $cambio['sucursal_id'],
                'producto_id' => $cambio['producto_id'],
                'cantidad' => $cambio['cantidad'],
                'saldo_anterior' => $anterior,
                'saldo_posterior' => $posterior,
            ];
        }

        if ($faltantes !== []) {
            throw ValidationException::withMessages(['lineas' => array_merge(
                [__('No hay stock suficiente; no se guardó ningún cambio.')], $faltantes,
            )]);
        }

        $tipo = $cabecera['tipo'];
        $operacion = new OperacionInventario;
        $operacion->empresa_id = $empresaId;
        $operacion->serie = $tipo->serie();
        $operacion->numero = $this->numerador->siguiente($empresaId, $tipo->serie());
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

        return $operacion;
    }

    /**
     * Crea con cantidad 0 las filas de saldo que aún no existen. Llamar fuera de la
     * transacción de la operación.
     *
     * @param  list<array{sucursal_id: int, producto_id: int}>  $cambios
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
