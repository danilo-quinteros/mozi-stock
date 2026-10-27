<?php

namespace App\Http\Controllers;

use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Domain\Creditos\Consultas\ListadoCreditos;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Consultas\InventarioSucursales;
use App\Domain\Reportes\Consultas\Salidas;
use App\Domain\Reportes\Consultas\VentasPeriodo;
use App\Domain\Reportes\Periodo;
use App\Models\Pedido;
use App\Models\User;
use App\Models\Venta;
use App\Support\Csv;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportaciones CSV de créditos, caja y reportes. Cada una usa exactamente la consulta y los
 * filtros de su pantalla (T23) y la misma autorización. Se recorren con lazy() (paginación
 * que respeta el orden de la pantalla): lazyById() mezcla su orden por id con el de la
 * consulta y podía omitir o repetir filas pasadas las 500.
 */
class ReportesCsvController extends Controller
{
    public function creditos(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('viewAny', Pedido::class);
        $estado = (string) $request->query('estado', 'todos');
        $filas = ListadoCreditos::consulta((int) $actor->empresa_id, in_array($estado, ListadoCreditos::ESTADOS, true) ? $estado : 'todos',
            $actor->esAdministrador() ? $actor->sucursalActivaId() : null, (string) $request->query('q', ''))
            ->lazy(500);

        return Csv::descargar('creditos.csv', [__('Venta'), __('Pedido'), __('Fecha venta'), __('Cliente'), 'CI/NIT', __('Sucursal'), __('Vendedor'), __('Vendido'), __('Cobrado'), __('Saldo'), __('Estado')],
            function () use ($filas): iterable {
                foreach ($filas as $f) {
                    yield [$this->codigo('V', $f->numero), $this->codigo('PED', $f->pedido_numero), $this->fecha($f->fecha, 'd/m/Y'),
                        $f->cliente ?? '', $f->documento ?? '', $f->sucursal, $f->vendedor, (string) $f->total,
                        Decimal::redondear((string) $f->cobrado), (string) $f->saldo_pendiente,
                        Decimal::comparar((string) $f->saldo_pendiente, '0', 2) > 0 ? __('Pendiente') : __('Pagado')];
                }
            });
    }

    public function historial(Request $request, Venta $venta): StreamedResponse
    {
        Gate::forUser($this->actor($request))->authorize('cobrar', $venta);
        $pagos = $venta->pagos()->with(['sucursal:id,nombre', 'receptor:id,name'])->get();

        return Csv::descargar("historial-{$venta->codigo()}.csv", [__('Fecha'), __('Concepto'), __('Método'), __('Efectivo'), __('Electrónico'), __('Total abonado'), __('Sucursal'), __('Recibió'), __('Nota')],
            function () use ($pagos): iterable {
                foreach ($pagos as $p) {
                    yield [$p->fecha_cobro->timezone(Periodo::zona())->format('d/m/Y H:i'), $p->tipo === 'inicial' ? __('Cuota inicial') : __('Abono'),
                        $p->metodo->etiqueta(), $p->monto_efectivo, $p->monto_electronico, $p->monto, $p->sucursal->nombre,
                        $p->receptor->name, $p->nota ?? ''];
                }
            });
    }

    public function arqueo(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('ver-reportes');
        $dia = Periodo::dia((string) $request->query('fecha', ''));
        $metodo = (string) $request->query('metodo', '');
        $sucursalId = $this->sucursal($request);
        $empresaId = (int) $actor->empresa_id;

        // Las mismas dos tablas de la pantalla: cobros del día y salidas del día.
        return Csv::descargar('arqueo-'.$dia->desde->format('Y-m-d').'.csv',
            [__('Fecha'), __('Movimiento'), __('Concepto'), __('Referencia'), __('Detalle'), __('Sucursal'), __('Usuario'), __('Efectivo'), __('Electrónico'), __('Total')],
            function () use ($empresaId, $dia, $sucursalId, $metodo): iterable {
                foreach (Cobros::detalle($empresaId, $dia, $sucursalId, $metodo)->cursor() as $f) {
                    yield [$this->fecha($f->fecha_cobro, 'd/m/Y H:i'), __('Ingreso'), $f->tipo === 'inicial' ? __('Cuota inicial') : __('Abono'),
                        $this->codigo('V', $f->venta_numero), $f->cliente ?? __('Consumidor final'), $f->sucursal, $f->recibido_por,
                        Decimal::redondear((string) $f->efectivo), Decimal::redondear((string) $f->electronico), Decimal::redondear((string) $f->total)];
                }

                foreach (Salidas::detalle($empresaId, $dia, $sucursalId, $metodo)->cursor() as $f) {
                    $descripcion = Salidas::describir($f);
                    yield [$this->fecha($f->fecha_operacion, 'd/m/Y H:i'), __('Salida'), $descripcion['concepto'], $descripcion['referencia'],
                        $descripcion['detalle'], $f->sucursal, $f->usuario,
                        Decimal::redondear((string) $f->efectivo), Decimal::redondear((string) $f->electronico), Decimal::redondear((string) $f->total)];
                }
            });
    }

    public function reportes(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('ver-reportes');
        $empresaId = (int) $actor->empresa_id;
        $periodo = Periodo::rango((string) $request->query('desde', ''), (string) $request->query('hasta', ''));
        $sucursalId = $this->sucursal($request);
        $buscar = (string) $request->query('q', '');
        $sufijo = $periodo->desde->format('Y-m-d').'_'.$periodo->hasta->format('Y-m-d');

        return match ((string) $request->query('pestana', 'cobros')) {
            'ventas' => Csv::descargar("ventas-{$sufijo}.csv",
                [__('Fecha venta'), __('Venta'), __('Sucursal'), __('Cliente'), __('Vendedor'), __('Valor vendido'), __('Devuelto'), __('Cobrado a hoy'), __('Saldo pendiente')],
                function () use ($empresaId, $periodo, $sucursalId, $buscar): iterable {
                    foreach (VentasPeriodo::consulta($empresaId, $periodo, $sucursalId, $buscar)->lazy(500) as $f) {
                        yield [$this->fecha($f->fecha, 'd/m/Y H:i'), $this->codigo('V', $f->numero), $f->sucursal, $f->cliente ?? '', $f->vendedor,
                            (string) $f->total, (string) $f->devuelto, Decimal::redondear((string) $f->cobrado), (string) $f->saldo_pendiente];
                    }
                }),
            'inventario' => Csv::descargar('inventario.csv', [__('Producto'), 'SKU', __('Sucursal'), __('Existencias'), __('Unidad'), __('Mínimo'), __('Estado')],
                function () use ($empresaId, $sucursalId, $buscar, $request): iterable {
                    foreach (InventarioSucursales::consulta($empresaId, $sucursalId, $buscar, $request->boolean('alertas'))->cursor() as $f) {
                        $alerta = ListadoProductos::alerta((string) $f->stock, (string) $f->minimo);
                        yield [$f->nombre, $f->sku, $f->sucursal, (string) $f->stock, $f->abreviatura, (string) $f->minimo,
                            ['sin_stock' => __('Sin stock'), 'bajo' => __('Stock bajo'), 'normal' => __('Normal')][$alerta]];
                    }
                }),
            'creditos' => Csv::descargar('saldos-por-cobrar.csv', [__('Venta'), __('Fecha venta'), __('Cliente'), __('Sucursal'), __('Vendido'), __('Cobrado'), __('Saldo')],
                function () use ($empresaId, $sucursalId, $buscar): iterable {
                    foreach (ListadoCreditos::consulta($empresaId, 'pendientes', $sucursalId, $buscar)->lazy(500) as $f) {
                        yield [$this->codigo('V', $f->numero), $this->fecha($f->fecha, 'd/m/Y'), $f->cliente ?? '', $f->sucursal, (string) $f->total,
                            Decimal::redondear((string) $f->cobrado), (string) $f->saldo_pendiente];
                    }
                }),
            default => $this->cobros($actor, $periodo, $sucursalId, '', $buscar, "cobros-{$sufijo}.csv"),
        };
    }

    private function cobros(User $actor, Periodo $periodo, ?int $sucursalId, string $metodo, string $buscar, string $nombre): StreamedResponse
    {
        $filas = Cobros::detalle((int) $actor->empresa_id, $periodo, $sucursalId, $metodo, $buscar)->cursor();

        return Csv::descargar($nombre, [__('Fecha de cobro'), __('Concepto'), __('Venta'), __('Cliente'), __('Sucursal que recibió'), __('Recibió'), __('Vendedor'), __('Efectivo'), __('Electrónico'), __('Cobrado')],
            function () use ($filas): iterable {
                foreach ($filas as $f) {
                    yield [$this->fecha($f->fecha_cobro, 'd/m/Y H:i'), $f->tipo === 'inicial' ? __('Cuota inicial') : __('Abono'), $this->codigo('V', $f->venta_numero),
                        $f->cliente ?? '', $f->sucursal, $f->recibido_por, $f->vendedor,
                        Decimal::redondear((string) $f->efectivo), Decimal::redondear((string) $f->electronico), Decimal::redondear((string) $f->total)];
                }
            });
    }

    private function sucursal(Request $request): ?int
    {
        $valor = (string) $request->query('sucursal', '');

        return ctype_digit($valor) ? (int) $valor : null;
    }

    private function codigo(string $serie, int|string $numero): string
    {
        return $serie.'-'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT);
    }

    private function fecha(string $utc, string $formato): string
    {
        return Carbon::parse($utc, 'UTC')->timezone(Periodo::zona())->format($formato);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
