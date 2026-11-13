<?php

namespace App\Livewire\Inventario;

use App\Domain\Despachos\Consultas\ReservadoPorDespachar;
use App\Domain\Inventario\Actions\RegistrarAjuste;
use App\Domain\Inventario\Consultas\StockLotes;
use App\Domain\Inventario\DatosLote;
use App\Domain\Inventario\LibroLotes;
use App\Enums\Capacidad;
use App\Enums\TipoOperacionInventario;
use App\Livewire\Concerns\CapturaProductos;
use App\Models\OperacionInventario;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ajuste rápido (V3 § 3). En productos con vencimiento (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 4): una entrada o
 * un ajuste que suma pide N.º de lote y vencimiento por línea, como en compras; una salida o un ajuste que resta
 * ofrece «Automático (vence primero)» o un lote con stock en la sucursal, también vencido. Con «Otro lote» el mismo
 * producto va en varias líneas. RegistrarAjuste y LibroLotes lo vuelven a validar en el servidor.
 */
#[Title('Nuevo ajuste')]
class NuevoAjuste extends Component
{
    use CapturaProductos;

    public string $tipo = 'ajuste';

    public string $motivo = '';

    public string $direccion = 'sumar';

    public ?string $sucursal_id = null;

    public string $nota = '';

    public bool $confirmando = false;

    /**
     * Clave de idempotencia de esta pantalla: un doble envío no registra dos veces.
     */
    #[Locked]
    public string $clave = '';

    public function mount(): void
    {
        $this->authorize('create', OperacionInventario::class);
        $this->clave = (string) Str::uuid();
        $primera = $this->actor()->sucursalActivaId() ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
        $this->sucursal_id = $primera === null ? null : (string) $primera;
    }

    public function updatedTipo(): void
    {
        $this->motivo = '';
        $this->resetValidation(['motivo', 'direccion']);
    }

    /**
     * Cada fila lleva un uid: un producto con vencimiento puede ir en varias líneas, una por lote.
     *
     * @return array{id: int, cantidad: string, uid: string, lote: string, vencimiento: string, lote_id: string}
     */
    protected function nuevaLinea(int $productoId): array
    {
        return ['id' => $productoId, 'cantidad' => '1', 'uid' => 'l'.Str::lower(Str::random(10)), 'lote' => '', 'vencimiento' => '', 'lote_id' => ''];
    }

    /**
     * «Otro lote»: otra línea del mismo producto, debajo, con el lote en blanco (o en automático).
     */
    public function otroLote(string $uid): void
    {
        $indice = array_search($uid, array_column($this->lineas, 'uid'), true);

        if (! is_int($indice) || ! isset($this->productosConLote()[$this->lineas[$indice]['id']])) {
            return;
        }

        array_splice($this->lineas, $indice + 1, 0, [$this->nuevaLinea($this->lineas[$indice]['id'])]);
    }

    /**
     * Al corregir el lote o el vencimiento de una línea se quita su error (y el aviso general, si ya no quedan).
     */
    public function updated(string $propiedad): void
    {
        if (preg_match('/^lineas\.\d+\.(lote|vencimiento)$/', $propiedad) !== 1) {
            return;
        }

        $this->resetErrorBag($propiedad);

        if (! $this->getErrorBag()->hasAny(['lineas.*.lote', 'lineas.*.vencimiento'])) {
            $this->resetErrorBag('lineas');
        }
    }

    public function revisar(): void
    {
        $this->resetValidation();
        $this->validate([
            'tipo' => ['required', 'in:entrada,salida,ajuste'],
            'motivo' => [$this->tipo === 'ajuste' ? 'nullable' : 'required'],
            'direccion' => [$this->tipo === 'ajuste' ? 'required' : 'nullable', 'in:sumar,restar'],
            'sucursal_id' => ['required'],
            'nota' => ['required', 'string', 'max:250'],
            'lineas' => ['required', 'array', 'min:1'],
        ], [
            'lineas.required' => __('Agrega al menos un producto.'),
            'lineas.min' => __('Agrega al menos un producto.'),
        ], ['nota' => __('nota'), 'sucursal_id' => __('sucursal')]);

        // Lote y vencimiento por línea en los ingresos (§ 5.1). RegistrarAjuste lo vuelve a validar.
        if ($this->esIngreso()) {
            $conLote = $this->productosConLote();
            $errores = [];

            foreach ($this->lineas as $indice => $linea) {
                if (! isset($conLote[$linea['id']])) {
                    continue;
                }

                $numero = DatosLote::numero($linea['lote'] ?? '');

                if ($numero === null) {
                    $errores["lineas.{$indice}.lote"] = __('Escribe el número de lote.');
                } elseif (mb_strlen($numero) > DatosLote::MAX_NUMERO) {
                    $errores["lineas.{$indice}.lote"] = __('Hasta :maximo caracteres.', ['maximo' => DatosLote::MAX_NUMERO]);
                }

                if (DatosLote::vencimiento($linea['vencimiento'] ?? '') === null) {
                    $errores["lineas.{$indice}.vencimiento"] = __('Escribe dd/mm/aaaa o mm/aaaa.');
                }
            }

            if ($errores !== []) {
                throw ValidationException::withMessages($errores + ['lineas' => __('Completa el lote y el vencimiento de los productos marcados.')]);
            }
        }

        $this->confirmando = true;
    }

    public function confirmar(RegistrarAjuste $accion): void
    {
        $this->confirmando = false;
        $operacion = $accion->ejecutar($this->actor(), [
            'tipo' => $this->tipo,
            'motivo' => $this->motivo === '' ? null : $this->motivo,
            'direccion' => $this->direccion,
            'sucursal_id' => $this->sucursal_id === null ? null : (int) $this->sucursal_id,
            'nota' => $this->nota,
            'lineas' => array_map(fn (array $linea): array => [
                'producto_id' => $linea['id'], 'cantidad' => $linea['cantidad'],
                'lote' => $linea['lote'] ?? null, 'vencimiento' => $linea['vencimiento'] ?? null, 'lote_id' => $linea['lote_id'] ?? null,
            ], $this->lineas),
            'clave' => $this->clave,
        ]);

        session()->flash('aviso', trans_choice(':codigo registrado: :count producto actualizado.|:codigo registrado: :count productos actualizados.', $operacion->total_lineas, ['codigo' => $operacion->codigo()]));
        $this->redirectRoute('inventario.movimientos', navigate: false);
    }

    public function render(): View
    {
        $tipo = TipoOperacionInventario::tryFrom($this->tipo) ?? TipoOperacionInventario::Ajuste;
        $sucursalId = $this->sucursal_id === null ? null : (int) $this->sucursal_id;
        $datos = $this->datosDeLineas($sucursalId);
        $ingreso = $this->esIngreso();
        $signo = $ingreso ? '1' : '-1';
        $conLote = $this->lineas === [] ? [] : $this->productosConLote();
        $lotes = $ingreso || $conLote === [] || $sucursalId === null ? []
            : app(StockLotes::class)->conStock((int) $this->actor()->empresa_id, $sucursalId, array_keys($conLote));
        $porProducto = [];

        foreach ($this->lineas as $linea) {
            $cantidad = Decimal::normalizar($linea['cantidad'], Decimal::ESCALA_CANTIDAD);
            $previo = array_key_exists($linea['id'], $porProducto) ? $porProducto[$linea['id']] : '0';
            $porProducto[$linea['id']] = $cantidad === null || $previo === null ? null : Decimal::sumar($previo, $cantidad);
        }

        $filas = [];
        $vencidos = [];
        $unidades = '0';

        foreach ($this->lineas as $indice => $linea) {
            $producto = $datos['productos']->get($linea['id']);

            if ($producto === null) {
                continue;
            }

            $clave = (string) $this->claveLinea($linea);
            $lote = isset($conLote[$producto->id]);
            $vence = $lote && $ingreso ? DatosLote::vencimiento($linea['vencimiento'] ?? '') : null;
            $vencido = DatosLote::vencido($vence);

            if ($vencido) {
                // § 5.1.4: se puede ingresar, pero queda bloqueado para la venta; se avisa antes de confirmar.
                $vencidos[] = $producto->nombre.' · '.__('lote :lote', ['lote' => trim($linea['lote'] ?? '')]).' · '.DatosLote::formatear($vence);
            }

            $actual = $datos['stock'][$linea['id']] ?? '0';
            $cantidad = Decimal::normalizar($linea['cantidad'], Decimal::ESCALA_CANTIDAD);
            $total = $porProducto[$linea['id']] ?? null;
            $despues = $total === null ? null : Decimal::sumar($actual, Decimal::multiplicar($total, $signo));
            $unidades = $cantidad === null ? $unidades : Decimal::sumar($unidades, $cantidad);
            $opciones = $lotes[$producto->id] ?? [];
            $elegido = collect($opciones)->firstWhere('id', (int) ($linea['lote_id'] ?? 0));
            $filas[] = compact('indice', 'clave', 'producto', 'actual', 'cantidad', 'despues', 'lote', 'vence', 'vencido') + [
                'loteModo' => $ingreso ? 'ingreso' : 'salida',
                'lotes' => $opciones,
                'numeroLote' => trim($linea['lote'] ?? ''),
                'loteElegido' => $elegido === null ? null : StockLotes::etiqueta($elegido),
            ];
        }

        // Despachos § 5.4: lo vendido Por despachar sigue en el almacén aunque el stock ya lo descontó.
        $reservados = $sucursalId !== null && Gate::allows('capacidad', Capacidad::Despachos)
            ? (ReservadoPorDespachar::cantidades((int) $this->actor()->empresa_id, $sucursalId)[$sucursalId] ?? [])
            : [];
        $motivos = $tipo->motivos();

        // El motivo «Vencido» es parte del módulo de lotes: sin la capacidad no se ofrece.
        if (! Gate::allows('capacidad', Capacidad::LotesVencimientos)) {
            unset($motivos['vencido']);
        }

        return view('livewire.inventario.nuevo-ajuste', [
            'sucursales' => Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'motivos' => $motivos,
            'filas' => $filas,
            'vencidos' => $vencidos,
            'vencidas' => $datos['vencidas'],
            'unidades' => $unidades,
            'variacion' => Decimal::multiplicar($unidades, $signo),
            'tipoActual' => $tipo,
            'reservados' => $reservados,
            'reservadoTotal' => array_reduce($reservados, fn (string $suma, string $cantidad) => Decimal::sumar($suma, $cantidad), '0'),
        ]);
    }

    private function esIngreso(): bool
    {
        return $this->tipo === 'entrada' || ($this->tipo === 'ajuste' && $this->direccion !== 'restar');
    }

    /**
     * Productos de la tabla que se ajustan por lote: marcados y con la capacidad activa (como en el servidor).
     *
     * @return array<int, true>
     */
    private function productosConLote(): array
    {
        return app(LibroLotes::class)->productosConLote((int) $this->actor()->empresa_id, array_column($this->lineas, 'id'));
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
