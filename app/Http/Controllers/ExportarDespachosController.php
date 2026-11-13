<?php

namespace App\Http\Controllers;

use App\Domain\Despachos\Consultas\ListadoDespachos;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Models\Pedido;
use App\Models\User;
use App\Support\Csv;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV de la pestaña Despachos con la misma consulta y filtros de la pantalla (ESPECIFICACION_DESPACHOS.md
 * § 4): solo ventas de la empresa y, para el vendedor, solo las de su sucursal. La ruta exige la
 * capacidad «despachos» (404 si está apagada). «Total» es valor vendido; «Saldo» es deuda pendiente.
 */
class ExportarDespachosController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('viewAny', Pedido::class);

        $fecha = fn (string $clave) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($clave, '')) === 1
            ? (string) $request->query($clave) : null;
        $sucursal = (string) $request->query('suc', '');
        $estado = EstadoEntrega::tryFrom((string) $request->query('entrega', ''));
        $estado = in_array($estado, EstadoEntrega::delFlujo(Capacidad::Despachos), true) ? $estado : EstadoEntrega::PorDespachar;

        $consulta = ListadoDespachos::consulta($actor, array_filter([
            'estado' => $estado->value,
            'sucursal_id' => ctype_digit($sucursal) ? (int) $sucursal : null,
            'cliente' => (string) $request->query('cliente', ''),
            'desde' => $fecha('desde'),
            'hasta' => $fecha('hasta'),
        ], fn ($valor, string $clave) => $clave === 'sucursal_id' || $valor !== null, ARRAY_FILTER_USE_BOTH))
            ->with(['despachador:id,name', 'entregador:id,name'])
            ->reorder();
        // Por id para recorrer en bloques: lo pendiente del más antiguo al más nuevo, lo resuelto al revés (como la pantalla).
        $ventas = in_array($estado, EstadoEntrega::pendientes(Capacidad::Despachos), true)
            ? $consulta->lazyById(500, 'ventas.id', 'id')
            : $consulta->lazyByIdDesc(500, 'ventas.id', 'id');

        $zona = (string) config('mozistock.business_timezone');
        $hora = fn (?CarbonInterface $momento) => $momento?->copy()->setTimezone($zona)->format('d/m/Y H:i') ?? '';

        return Csv::descargar('despachos.csv',
            [__('Venta'), __('Fecha'), __('Sucursal'), __('Cliente'), __('Vendedor'), __('Total vendido'), __('Saldo pendiente'),
                __('Estado de entrega'), __('Despachó'), __('Despachado el'), __('Retira'), __('Entregó'), __('Entregado el'), __('Recibió')],
            function () use ($ventas, $hora): iterable {
                foreach ($ventas as $venta) {
                    yield [
                        $venta->codigo(),
                        $hora($venta->fecha),
                        $venta->sucursal->nombre,
                        $venta->cliente->nombre ?? __('Consumidor final'),
                        $venta->vendedor->name,
                        $venta->total,
                        $venta->saldo_pendiente,
                        $venta->estado_entrega->etiqueta(),
                        $venta->despachador->name ?? '',
                        $hora($venta->despachado_en),
                        $venta->despacho_retira ?? '',
                        $venta->entregador->name ?? '',
                        $hora($venta->entregado_en),
                        $venta->entrega_recibio ?? '',
                    ];
                }
            });
    }
}
