<?php

namespace App\Http\Controllers;

use App\Domain\Caja\Consultas\ReporteCaja;
use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Domain\Creditos\Consultas\CuentasPorCobrar;
use App\Domain\Creditos\Consultas\HistorialPagos;
use App\Domain\CuentasPagar\Consultas\CuentasPorPagar;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Consultas\InventarioSucursales;
use App\Domain\Reportes\Consultas\Salidas;
use App\Domain\Reportes\Consultas\VentasPeriodo;
use App\Domain\Reportes\Periodo;
use App\Enums\MetodoPago;
use App\Models\CuentaPagar;
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
        // Los mismos filtros de la pantalla Cuentas por cobrar.
        $estado = (string) $request->query('estado', 'todas');
        $fecha = fn (string $campo) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($campo, '')) ? (string) $request->query($campo) : null;
        $filas = CuentasPorCobrar::consulta((int) $actor->empresa_id, in_array($estado, CuentasPorCobrar::ESTADOS, true) ? $estado : 'todas',
            $this->sucursal($request), (string) $request->query('q', ''), $fecha('desde'), $fecha('hasta'))->lazy(500);
        $estados = ['anulada' => __('Anulada'), 'pagada' => __('Pagada'), 'vencida' => __('Vencida'), 'parcial' => __('Parcial'), 'pendiente' => __('Pendiente')];

        return Csv::descargar('cuentas-por-cobrar.csv', [__('Documento'), __('Concepto'), __('Fecha'), __('Vencimiento'), __('Cliente'), 'CI/NIT', __('Sucursal'), __('Importe'), __('Cobrado'), __('Saldo'), __('Vencido'), __('Estado')],
            function () use ($filas, $estados): iterable {
                foreach ($filas as $f) {
                    yield [$this->codigo($f->tipo === 'venta' ? 'V' : 'CXC', $f->numero), $f->tipo === 'venta' ? __('Venta a crédito') : $f->concepto,
                        Carbon::parse($f->fecha)->format('d/m/Y'), $f->vencimiento ? Carbon::parse($f->vencimiento)->format('d/m/Y') : '',
                        $f->cliente ?? '', $f->documento_cliente ?? '', $f->sucursal, Decimal::redondear((string) $f->importe),
                        Decimal::redondear((string) $f->cobrado), Decimal::redondear((string) $f->saldo), Decimal::redondear((string) $f->vencido),
                        $estados[CuentasPorCobrar::estado($f)]];
                }
            });
    }

    /**
     * Cuentas por pagar con los mismos filtros de su pantalla (solo administradores).
     */
    public function cuentasPagar(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('viewAny', CuentaPagar::class);
        $estado = (string) $request->query('estado', 'todas');
        $fecha = fn (string $campo) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($campo, '')) ? (string) $request->query($campo) : null;
        $proveedor = (string) $request->query('proveedor', '');
        $filas = CuentasPorPagar::consulta((int) $actor->empresa_id, in_array($estado, CuentasPorPagar::ESTADOS, true) ? $estado : 'todas',
            $this->sucursal($request), ctype_digit($proveedor) ? (int) $proveedor : null, (string) $request->query('origen', ''),
            (string) $request->query('q', ''), $fecha('desde'), $fecha('hasta'))->lazy(500);
        $estados = ['anulada' => __('Anulada'), 'pagada' => __('Pagada'), 'vencida' => __('Vencida'), 'parcial' => __('Parcial'), 'pendiente' => __('Pendiente')];

        return Csv::descargar('cuentas-por-pagar.csv', [__('Cuenta'), __('Concepto'), __('Acreedor'), __('Sucursal'), __('Origen'), __('Documento'), __('Fecha'),
            __('Vencimiento'), __('Importe'), __('Pagado'), __('Saldo'), __('Estado'), __('Anulación')],
            function () use ($filas, $estados): iterable {
                foreach ($filas as $f) {
                    $estado = $f->anulado_at !== null ? 'anulada' : CuentaPagar::estadoDe((string) $f->saldo_pendiente, (string) $f->pagado, $f->vencimiento);
                    yield [$this->codigo('CXP', $f->numero), $f->concepto, $f->proveedor, $f->sucursal, __(CuentaPagar::ORIGENES[$f->origen]),
                        CuentasPorPagar::documento($f), Carbon::parse($f->fecha)->format('d/m/Y'), Carbon::parse($f->vencimiento)->format('d/m/Y'),
                        Decimal::redondear((string) $f->total_documento), Decimal::redondear((string) $f->pagado), Decimal::redondear((string) $f->saldo_pendiente),
                        $estados[$estado], (string) $f->motivo_anulacion];
                }
            });
    }

    public function historial(Request $request, Venta $venta): StreamedResponse
    {
        Gate::forUser($this->actor($request))->authorize('cobrar', $venta);
        $pagos = HistorialPagos::de($venta);

        return Csv::descargar("historial-{$venta->codigo()}.csv", [__('Fecha'), __('Concepto'), __('Método'), __('Efectivo'), __('Electrónico'), __('Total'), __('Sucursal'), __('Recibió'), __('Nota'), __('Anulación')],
            function () use ($pagos): iterable {
                foreach ($pagos as $p) {
                    yield [$p['fecha']->timezone(Periodo::zona())->format('d/m/Y H:i'), $p['concepto'], $p['metodo'],
                        $p['efectivo'], $p['electronico'], $p['monto'], $p['sucursal'], $p['receptor'], $p['nota'], $p['anulado'] ?? ''];
                }
            });
    }

    public function arqueo(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('ver-reportes');
        $fecha = (string) $request->query('fecha', '');
        $dia = Periodo::rango((string) $request->query('desde', $fecha), $fecha);
        $metodo = (string) $request->query('metodo', '');
        $sucursalId = $this->sucursal($request);
        $cajaId = ReporteCaja::cajaElegida((string) $request->query('caja', ''));
        $empresaId = (int) $actor->empresa_id;

        // Las mismas dos tablas de la pantalla: cobros del día y salidas del día. Las consultas
        // filtran por empresa, así que una caja ajena solo produce un archivo vacío.
        return Csv::descargar('arqueo-'.$dia->desde->format('Y-m-d').($dia->esUnDia() ? '' : '_'.$dia->hasta->format('Y-m-d')).'.csv',
            [__('Fecha'), __('Movimiento'), __('Concepto'), __('Referencia'), __('Detalle'), __('Sucursal'), __('Caja'), __('Usuario'), __('Efectivo'), __('Electrónico'), __('Total')],
            function () use ($empresaId, $dia, $sucursalId, $metodo, $cajaId): iterable {
                foreach (Cobros::detalle($empresaId, $dia, $sucursalId, $metodo, cajaId: $cajaId)->cursor() as $f) {
                    yield [$this->fecha($f->fecha_cobro, 'd/m/Y H:i'), __('Ingreso'), Cobros::concepto($f),
                        Cobros::referencia($f), $f->cliente ?? __('Consumidor final'), $f->sucursal, $f->caja ?? __('Fuera de caja'), $f->recibido_por,
                        Decimal::redondear((string) $f->efectivo), Decimal::redondear((string) $f->electronico), Decimal::redondear((string) $f->total)];
                }

                foreach (Salidas::detalle($empresaId, $dia, $sucursalId, $metodo, $cajaId)->cursor() as $f) {
                    $descripcion = Salidas::describir($f);
                    yield [$this->fecha($f->fecha_operacion, 'd/m/Y H:i'), __('Salida'), $descripcion['concepto'], $descripcion['referencia'],
                        $descripcion['detalle'], $f->sucursal, $f->caja ?? __('Fuera de caja'), $f->usuario,
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

        $pestana = (string) $request->query('pestana', 'cobros');

        return match (true) {
            $pestana === 'ventas' && $request->query('vista') === 'devoluciones' => Csv::descargar("devoluciones-{$sufijo}.csv",
                [__('Fecha devolución'), __('Devolución'), __('Venta'), __('Fecha venta'), __('Sucursal'), __('Cliente'), __('Registró'), __('Motivo'),
                    __('Valor devuelto'), __('Deuda reducida'), __('Reembolso'), __('Medio del reembolso')],
                function () use ($empresaId, $periodo, $sucursalId, $buscar): iterable {
                    foreach (VentasPeriodo::devoluciones($empresaId, $periodo, $sucursalId, $buscar)->lazy(500) as $f) {
                        yield [$this->fecha($f->fecha, 'd/m/Y H:i'), $this->codigo('DEV', $f->numero), $this->codigo('V', $f->venta_numero),
                            $this->fecha($f->venta_fecha, 'd/m/Y'), $f->sucursal, $f->cliente ?? '', $f->usuario, $f->motivo,
                            (string) $f->importe, (string) $f->deuda_reducida, (string) $f->reembolso,
                            $f->reembolso_metodo ? (MetodoPago::tryFrom($f->reembolso_metodo)?->etiqueta() ?? '') : ''];
                    }
                }),
            $pestana === 'ventas' => Csv::descargar("ventas-{$sufijo}.csv",
                [__('Fecha venta'), __('Venta'), __('Sucursal'), __('Cliente'), __('Vendedor'), __('Valor vendido'), __('Devuelto a hoy'), __('Cobrado a hoy'), __('Saldo pendiente')],
                function () use ($empresaId, $periodo, $sucursalId, $buscar): iterable {
                    foreach (VentasPeriodo::consulta($empresaId, $periodo, $sucursalId, $buscar)->lazy(500) as $f) {
                        yield [$this->fecha($f->fecha, 'd/m/Y H:i'), $this->codigo('V', $f->numero), $f->sucursal, $f->cliente ?? '', $f->vendedor,
                            (string) $f->total, (string) $f->devuelto, Decimal::redondear((string) $f->cobrado), (string) $f->saldo_pendiente];
                    }
                }),
            $pestana === 'inventario' => Csv::descargar('inventario.csv', [__('Producto'), 'SKU', __('Sucursal'), __('Existencias'), __('Unidad'), __('Mínimo'), __('Estado')],
                function () use ($empresaId, $sucursalId, $buscar, $request): iterable {
                    foreach (InventarioSucursales::consulta($empresaId, $sucursalId, $buscar, $request->boolean('alertas'))->cursor() as $f) {
                        $alerta = ListadoProductos::alerta((string) $f->stock, (string) $f->minimo);
                        yield [$f->nombre, $f->sku, $f->sucursal, (string) $f->stock, $f->abreviatura, (string) $f->minimo,
                            ['sin_stock' => __('Sin stock'), 'bajo' => __('Stock bajo'), 'normal' => __('Normal')][$alerta]];
                    }
                }),
            $pestana === 'creditos' => $request->query('agrupar') === 'cliente'
                ? Csv::descargar('saldos-por-cliente.csv', [__('Cliente'), __('Documento'), __('Cuentas'), __('Importe'), __('Cobrado'), __('Vencido'), __('Saldo')],
                    function () use ($empresaId, $sucursalId, $buscar): iterable {
                        foreach (CuentasPorCobrar::porCliente($empresaId, $sucursalId, $buscar)->cursor() as $f) {
                            yield [$f->cliente ?? __('Sin cliente'), $f->documento_cliente ?? '', (string) $f->cuentas, Decimal::redondear((string) $f->importe),
                                Decimal::redondear((string) $f->cobrado), Decimal::redondear((string) $f->vencido), Decimal::redondear((string) $f->saldo)];
                        }
                    })
                : Csv::descargar('saldos-por-cobrar.csv', [__('Documento'), __('Fecha'), __('Vencimiento'), __('Cliente'), __('Sucursal'), __('Importe'), __('Cobrado'), __('Saldo')],
                    function () use ($empresaId, $sucursalId, $buscar): iterable {
                        foreach (CuentasPorCobrar::consulta($empresaId, 'abiertas', $sucursalId, $buscar)->lazy(500) as $f) {
                            yield [$this->codigo($f->tipo === 'venta' ? 'V' : 'CXC', $f->numero), Carbon::parse($f->fecha)->format('d/m/Y'),
                                $f->vencimiento ? Carbon::parse($f->vencimiento)->format('d/m/Y') : '', $f->cliente ?? '', $f->sucursal,
                                Decimal::redondear((string) $f->importe), Decimal::redondear((string) $f->cobrado), Decimal::redondear((string) $f->saldo)];
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
                    yield [$this->fecha($f->fecha_cobro, 'd/m/Y H:i'), Cobros::concepto($f), Cobros::referencia($f),
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
