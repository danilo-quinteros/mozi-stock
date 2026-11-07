<?php

namespace App\Http\Controllers;

use App\Domain\Compras\Consultas\ListadoCompras;
use App\Domain\Contactos\Consultas\ListadoClientes;
use App\Domain\Contactos\Consultas\ListadoProveedores;
use App\Domain\Egresos\Consultas\ListadoEgresos;
use App\Domain\Reportes\Periodo;
use App\Enums\MetodoPago;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Egreso;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\Csv;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV de Compras, Egresos, Clientes y Proveedores. Cada uno usa exactamente la consulta,
 * los filtros y la autorización de su pantalla (T03 y T23).
 */
class OperacionesCsvController extends Controller
{
    public function compras(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('viewAny', Compra::class);
        $filas = ListadoCompras::consulta((int) $actor->empresa_id, (string) $request->query('q', ''), $this->sucursal($request))
            ->lazy(500);

        return Csv::descargar('compras.csv', [__('Compra'), __('Fecha'), __('Proveedor'), __('Sucursal'), __('Artículos'), __('Medio'), __('Efectivo'), __('Electrónico'), __('Total pagado'), __('Referencia'), __('Registró')],
            function () use ($filas): iterable {
                foreach ($filas as $f) {
                    yield ['COM-'.str_pad((string) $f->numero, 5, '0', STR_PAD_LEFT), $this->fecha($f->fecha), $f->proveedor ?? '', $f->sucursal,
                        (int) $f->articulos, MetodoPago::tryFrom((string) $f->metodo)?->etiqueta() ?? __('Sin pago al registrar'), (string) $f->monto_efectivo, (string) $f->monto_electronico,
                        (string) $f->total, $f->referencia ?? '', $f->usuario];
                }
            });
    }

    public function egresos(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('viewAny', Egreso::class);
        $filas = ListadoEgresos::consulta((int) $actor->empresa_id, (string) $request->query('q', ''), $this->sucursal($request))
            ->lazy(500);

        return Csv::descargar('egresos.csv', [__('Egreso'), __('Fecha'), __('Categoría'), __('Descripción'), __('Sucursal'), __('Medio'), __('Monto'), __('Registró')],
            function () use ($filas): iterable {
                foreach ($filas as $f) {
                    yield ['EGR-'.str_pad((string) $f->numero, 5, '0', STR_PAD_LEFT), $this->fecha($f->fecha), $f->categoria, $f->descripcion,
                        $f->sucursal, MetodoPago::tryFrom((string) $f->metodo)?->etiqueta() ?? __('Sin pago al registrar'), Decimal::redondear((string) $f->monto), $f->usuario];
                }
            });
    }

    public function clientes(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('viewAny', Cliente::class);
        $filas = ListadoClientes::consulta((int) $actor->empresa_id, (string) $request->query('q', ''),
            $actor->sucursalActivaId())->lazy(500);

        return Csv::descargar('clientes.csv', [__('Cliente'), 'CI/NIT', __('Teléfono'), __('Correo'), __('Ventas'), __('Estado')],
            function () use ($filas): iterable {
                foreach ($filas as $f) {
                    yield [$f->nombre, $f->documento ?? '', $f->telefono ?? '', $f->correo ?? '', (int) $f->ventas, $f->activo ? __('Activo') : __('Inactivo')];
                }
            });
    }

    public function proveedores(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('viewAny', Proveedor::class);
        $filas = ListadoProveedores::consulta((int) $actor->empresa_id, (string) $request->query('q', ''))->lazy(500);

        return Csv::descargar('proveedores.csv', [__('Proveedor'), 'NIT/CI', __('Ciudad'), __('Teléfono'), __('Correo'), __('Compras'), __('Estado')],
            function () use ($filas): iterable {
                foreach ($filas as $f) {
                    yield [$f->nombre, $f->documento ?? '', $f->ciudad ?? '', $f->telefono ?? '', $f->correo ?? '', (int) $f->compras, $f->activo ? __('Activo') : __('Inactivo')];
                }
            });
    }

    private function sucursal(Request $request): ?int
    {
        $valor = (string) $request->query('sucursal', '');

        return ctype_digit($valor) ? (int) $valor : null;
    }

    private function fecha(string $utc): string
    {
        return Carbon::parse($utc, 'UTC')->timezone(Periodo::zona())->format('d/m/Y H:i');
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
