<?php

namespace App\Domain\Despachos\Consultas;

use App\Domain\Caja\CajaOperacion;
use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Botón flotante y panel «En cocina» (ESPECIFICACION_COCINA.md § 4.2, § 4.3, § 5.4 y § 5.5).
 *
 * - Quién lo ve: con la capacidad `cocina`, el administrador siempre; el vendedor solo con un turno de caja
 *   abierto que pueda operar (propio o compartido) en su sucursal. Nadie más.
 * - De qué sucursal: el vendedor, siempre la suya, aunque pida otra. El administrador, la que elija en el
 *   panel; al abrirlo, la del menú lateral (null = todas). Siempre de la empresa del usuario.
 * - Contador: una consulta con el total y los demorados, sobre el índice (empresa, sucursal, estado, fecha)
 *   o, para todas las sucursales, (empresa, estado, fecha).
 * - Lista: del más antiguo al más nuevo, hasta 100, con los productos y lo devuelto en dos consultas más
 *   (sin N+1). Las cantidades ya descuentan las devoluciones parciales.
 */
final class PedidosEnCocina
{
    public const MAXIMO = 100;

    /** Productos que se resumen en cada tarjeta; el resto se ve con «Ver». */
    public const RESUMEN = 3;

    public function __construct(
        private readonly CapacidadesEmpresa $capacidades,
        private readonly CajaOperacion $cajas,
    ) {}

    /**
     * Si el usuario ve el botón ahora (§ 4.2). Se vuelve a comprobar en cada actualización: si el vendedor
     * cierra su turno, o la empresa desactiva cocina, el botón desaparece.
     */
    public function visible(User $usuario): bool
    {
        if (! $usuario->perteneceAEmpresa() || ! $this->capacidades->tiene((int) $usuario->empresa_id, Capacidad::Cocina)) {
            return false;
        }

        if ($usuario->esAdministrador()) {
            return true;
        }

        return $usuario->esVendedor() && $usuario->sucursal_id !== null
            && $this->cajas->turnoDe($usuario, (int) $usuario->sucursal_id) !== null;
    }

    /**
     * Sucursal cuyos pedidos ve el usuario. El vendedor no elige; el administrador solo puede pedir una
     * sucursal de su empresa (otra se trata como «todas», que igual se limita a su empresa).
     */
    public static function sucursalDe(User $usuario, ?int $pedida): ?int
    {
        if (! $usuario->esAdministrador()) {
            return $usuario->sucursal_id;
        }

        if ($pedida === null) {
            return null;
        }

        return Sucursal::withoutGlobalScopes()->where('empresa_id', $usuario->empresa_id)->whereKey($pedida)->exists() ? $pedida : null;
    }

    /**
     * Total de pedidos En cocina y cuántos están demorados (§ 5.4), en una sola consulta.
     *
     * @return array{total: int, demorados: int}
     */
    public static function conteo(User $usuario, ?int $sucursalId, int $minutosAlerta, ?CarbonInterface $ahora = null): array
    {
        $fila = self::base($usuario, $sucursalId)->toBase()
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN ventas.fecha <= ? THEN 1 ELSE 0 END), 0) AS demorados',
                [self::limiteDemora($minutosAlerta, $ahora)])
            ->first();

        return ['total' => (int) ($fila->total ?? 0), 'demorados' => (int) ($fila->demorados ?? 0)];
    }

    /**
     * Pedidos En cocina con sus líneas pendientes, del más antiguo al más nuevo (§ 4.3).
     *
     * @return Collection<int, Venta>
     */
    public static function lista(User $usuario, ?int $sucursalId): Collection
    {
        $ventas = self::base($usuario, $sucursalId)
            ->with(['items:id,venta_id,orden,descripcion,unidad,cantidad', 'sucursal:id,nombre'])
            ->orderBy('ventas.fecha')->orderBy('ventas.id')
            ->limit(self::MAXIMO)
            ->get(['ventas.id', 'ventas.empresa_id', 'ventas.numero', 'ventas.sucursal_id', 'ventas.fecha', 'ventas.nombre_mesa', 'ventas.estado_entrega']);

        self::cargarLineas($ventas, (int) $usuario->empresa_id);

        return $ventas;
    }

    /**
     * Guarda en cada venta (atributo `lineas`) lo que falta servir: cantidad vendida menos lo devuelto,
     * sin las líneas devueltas por completo. Una consulta para todas las ventas.
     *
     * @param  Collection<int, Venta>  $ventas
     */
    public static function cargarLineas(Collection $ventas, int $empresaId): void
    {
        $itemIds = [];

        foreach ($ventas as $venta) {
            foreach ($venta->items as $item) {
                $itemIds[] = $item->id;
            }
        }

        $devuelto = $itemIds === [] ? [] : RegistrarDevolucion::devueltoPorLinea($empresaId, $itemIds);

        foreach ($ventas as $venta) {
            $lineas = [];

            foreach ($venta->items as $item) {
                $pendiente = Decimal::restar((string) $item->cantidad, $devuelto[$item->id] ?? '0', 3);

                if (Decimal::comparar($pendiente, '0', 3) > 0) {
                    $lineas[] = ['nombre' => $item->descripcion, 'unidad' => $item->unidad, 'cantidad' => $pendiente];
                }
            }

            $venta->setAttribute('lineas', $lineas);
        }
    }

    /**
     * Resumen de la tarjeta: hasta tres productos y «y N más» (§ 4.3), p. ej. «2× Hamburguesa, 1× Papas y 1 más».
     *
     * @param  list<array{nombre: string, unidad: string, cantidad: string}>  $lineas
     */
    public static function resumen(array $lineas): string
    {
        $texto = implode(', ', array_map(
            fn (array $linea): string => Decimal::formatear($linea['cantidad']).'× '.$linea['nombre'],
            array_slice($lineas, 0, self::RESUMEN),
        ));
        $resto = count($lineas) - self::RESUMEN;

        return $resto > 0 ? $texto.__(' y :n más', ['n' => $resto]) : $texto;
    }

    /**
     * Minutos enteros desde la confirmación de la venta, con el reloj del servidor.
     */
    public static function minutos(Venta $venta, ?CarbonInterface $ahora = null): int
    {
        return max(0, intdiv(($ahora ?? now())->getTimestamp() - $venta->fecha->getTimestamp(), 60));
    }

    /**
     * Demorado cuando los minutos que se muestran superan el límite: con 15, «hace 16 min» se resalta y
     * «hace 15 min» todavía no. Es la misma regla que usa el contador.
     */
    public static function demorado(Venta $venta, int $minutosAlerta, ?CarbonInterface $ahora = null): bool
    {
        return self::minutos($venta, $ahora) > $minutosAlerta;
    }

    /**
     * «hace N min» o, desde una hora, «hace 1 h 05 min» (§ 5.4).
     */
    public static function textoTiempo(int $minutos): string
    {
        if ($minutos < 60) {
            return __('hace :min min', ['min' => $minutos]);
        }

        return __('hace :h h :min min', ['h' => intdiv($minutos, 60), 'min' => str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT)]);
    }

    /**
     * @return Builder<Venta>
     */
    private static function base(User $usuario, ?int $sucursalId): Builder
    {
        // Fuera del administrador, solo la sucursal asignada; sin una, ningún pedido (nunca toda la empresa).
        $sucursalId = $usuario->esAdministrador() ? $sucursalId : ($usuario->sucursal_id ?? 0);

        return Venta::query()
            ->where('ventas.empresa_id', $usuario->empresa_id)
            ->where('ventas.estado_entrega', EstadoEntrega::EnCocina->value)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('ventas.sucursal_id', $sucursalId));
    }

    /**
     * Fecha a partir de la cual (hacia atrás) un pedido muestra más minutos que el límite.
     */
    private static function limiteDemora(int $minutosAlerta, ?CarbonInterface $ahora): CarbonInterface
    {
        return ($ahora ?? now())->toImmutable()->subMinutes($minutosAlerta + 1);
    }
}
