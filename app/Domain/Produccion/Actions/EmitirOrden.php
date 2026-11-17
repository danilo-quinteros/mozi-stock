<?php

namespace App\Domain\Produccion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Produccion\Escalado;
use App\Domain\Produccion\LineasOrden;
use App\Domain\Tenancy\Numerador;
use App\Domain\Tenancy\ResolverSucursal;
use App\Enums\EstadoOrdenProduccion;
use App\Models\OrdenProduccion;
use App\Models\OrdenProduccionItem;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Emitir y editar órdenes de producción (ESPECIFICACION_PRODUCCION.md § 6.1, § 6.2 y M2). Emitir no mueve stock: copia
 * la receta vigente (cantidad base y líneas) y escala las cantidades (M5: enteras hacia arriba). Los faltantes de
 * materia prima solo se avisan (DisponibilidadMaterias); aprobar los valida.
 *
 * - Idempotencia: la clave UUID del formulario es única por empresa; repetir la solicitud devuelve la misma orden.
 * - Editar (solo Emitida): cantidad, fecha programada, sucursal y nota; recalcula con la copia que guardó la orden, así
 *   que una versión nueva de la receta no la cambia. La orden se bloquea (FOR UPDATE) para no cruzarse con aprobar.
 */
final class EmitirOrden
{
    public const MAX_NOTA = 250;

    /** Máximo de una cantidad: DECIMAL(18,3). */
    private const MAXIMO = '999999999999999';

    public function __construct(
        private readonly Numerador $numerador,
        private readonly ResolverSucursal $sucursales,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{producto_id: mixed, sucursal_id: mixed, cantidad: mixed, fecha_programada: mixed, nota?: mixed, clave: mixed}  $datos
     */
    public function emitir(User $actor, array $datos): OrdenProduccion
    {
        Gate::forUser($actor)->authorize('usar-produccion');

        $empresaId = (int) $actor->empresa_id;
        $clave = is_string($datos['clave'] ?? null) ? $datos['clave'] : '';

        if (! Str::isUuid($clave)) {
            throw ValidationException::withMessages(['cantidad' => __('La orden no tiene una clave válida. Recarga la página.')]);
        }

        if (($previa = $this->existente($clave)) !== null) {
            return $previa;
        }

        $productoId = is_numeric($datos['producto_id'] ?? null) ? (int) $datos['producto_id'] : 0;
        $producto = Producto::query()->with('unidad:id,abreviatura,admite_decimales')->find($productoId)
            ?? throw ValidationException::withMessages(['producto_id' => __('Elige un producto de tu empresa.')]);
        GuardarReceta::exigirFabricable($producto->clase, (bool) $producto->activo, (bool) $producto->controla_stock, $producto->modelo_id, $producto->nombre);
        $sucursal = $this->sucursales->paraOperacion($actor, is_numeric($datos['sucursal_id'] ?? null) ? (int) $datos['sucursal_id'] : null);
        $cantidad = $this->cantidad($datos['cantidad'] ?? null, $producto);
        $fecha = $this->fecha($datos['fecha_programada'] ?? null);
        $nota = $this->nota($datos['nota'] ?? null);

        try {
            return DB::transaction(function () use ($actor, $empresaId, $producto, $sucursal, $cantidad, $fecha, $nota, $clave): OrdenProduccion {
                // Bloqueo compartido de la ficha: GuardarReceta la bloquea en exclusiva, así la receta vigente no
                // cambia mientras se copia.
                $ficha = DB::table('productos')->where('empresa_id', $empresaId)->where('id', $producto->id)->sharedLock()
                    ->first(['clase', 'activo', 'controla_stock', 'modelo_id']);
                GuardarReceta::exigirFabricable((string) $ficha?->clase, (bool) $ficha?->activo, (bool) $ficha?->controla_stock, $ficha?->modelo_id, $producto->nombre);

                $receta = Receta::query()->where('producto_id', $producto->id)->where('vigente', true)->first();

                if ($receta === null || ! $receta->activa) {
                    throw ValidationException::withMessages(['producto_id' => $receta === null
                        ? __(':producto no tiene receta. Créala en Producción → Recetas.', ['producto' => $producto->nombre])
                        : __('La receta de :producto está desactivada. Actívala para emitir órdenes.', ['producto' => $producto->nombre])]);
                }

                $lineas = LineasOrden::deReceta($empresaId, $receta->id);
                $this->exigirActivas($lineas);
                $teoricas = $this->teoricas($lineas, $cantidad, (string) $receta->cantidad_base);

                $orden = new OrdenProduccion;
                $orden->numero = $this->numerador->siguiente($empresaId, OrdenProduccion::SERIE);
                $orden->sucursal_id = $sucursal->id;
                $orden->producto_id = $producto->id;
                $orden->receta_id = $receta->id;
                $orden->estado = EstadoOrdenProduccion::Emitida;
                $orden->cantidad_planificada = $cantidad;
                $orden->cantidad_base = (string) $receta->cantidad_base;
                $orden->fecha_programada = Carbon::parse($fecha);
                $orden->nota = $nota;
                $orden->emitida_por = $actor->id;
                $orden->clave_idempotencia = $clave;
                $orden->save();

                OrdenProduccionItem::query()->insert(array_map(fn (array $linea, int $indice): array => [
                    'empresa_id' => $empresaId, 'orden_id' => $orden->id, 'producto_id' => $linea['producto_id'], 'orden' => $indice + 1,
                    'cantidad_receta' => $linea['cantidad_receta'], 'cantidad_teorica' => $teoricas[$linea['producto_id']],
                ], $lineas, array_keys($lineas)));

                $this->auditor->registrar('orden_produccion.emitida', $orden, $actor, $empresaId, null, [
                    'codigo' => $orden->codigo(), 'producto_id' => $producto->id, 'receta_id' => $receta->id, 'version' => $receta->version,
                    'sucursal_id' => $sucursal->id, 'cantidad' => $cantidad, 'fecha_programada' => $fecha, 'nota' => $nota,
                    'items' => array_map(fn (array $l): array => ['producto_id' => $l['producto_id'], 'cantidad' => $teoricas[$l['producto_id']]], $lineas),
                ]);

                return $orden;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            // Dos envíos simultáneos con la misma clave: el segundo devuelve el primero.
            return $this->existente($clave) ?? throw $e;
        }
    }

    /**
     * M2: solo una orden Emitida se edita. Sin cambios no escribe nada (doble clic).
     *
     * @param  array{sucursal_id: mixed, cantidad: mixed, fecha_programada: mixed, nota?: mixed}  $datos
     */
    public function editar(User $actor, OrdenProduccion $orden, array $datos): OrdenProduccion
    {
        Gate::forUser($actor)->authorize('usar-produccion');

        $empresaId = (int) $actor->empresa_id;
        $producto = Producto::query()->with('unidad:id,abreviatura,admite_decimales')->findOrFail($orden->producto_id);
        $sucursal = $this->sucursales->paraOperacion($actor, is_numeric($datos['sucursal_id'] ?? null) ? (int) $datos['sucursal_id'] : null);
        $cantidad = $this->cantidad($datos['cantidad'] ?? null, $producto);
        $fecha = $this->fecha($datos['fecha_programada'] ?? null);
        $nota = $this->nota($datos['nota'] ?? null);

        return DB::transaction(function () use ($actor, $empresaId, $orden, $sucursal, $cantidad, $fecha, $nota): OrdenProduccion {
            $bloqueada = OrdenProduccion::query()->whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoOrdenProduccion::Emitida) {
                throw ValidationException::withMessages(['orden' => __('La orden :codigo ya está :estado: no se puede editar.', [
                    'codigo' => $bloqueada->codigo(), 'estado' => mb_strtolower($bloqueada->estado->etiqueta()),
                ])]);
            }

            $antes = [
                'sucursal_id' => $bloqueada->sucursal_id, 'cantidad' => (string) $bloqueada->cantidad_planificada,
                'fecha_programada' => $bloqueada->fecha_programada->format('Y-m-d'), 'nota' => $bloqueada->nota,
            ];
            $despues = ['sucursal_id' => $sucursal->id, 'cantidad' => $cantidad, 'fecha_programada' => $fecha, 'nota' => $nota];

            if ($antes === $despues) {
                return $bloqueada;
            }

            $lineas = LineasOrden::deOrden($empresaId, $bloqueada->id);
            $teoricas = $this->teoricas($lineas, $cantidad, (string) $bloqueada->cantidad_base);

            $bloqueada->sucursal_id = $sucursal->id;
            $bloqueada->cantidad_planificada = $cantidad;
            $bloqueada->fecha_programada = Carbon::parse($fecha);
            $bloqueada->nota = $nota;
            $bloqueada->save();

            $porLinea = [];

            foreach ($lineas as $linea) {
                $porLinea[$linea['id']] = $teoricas[$linea['producto_id']];
            }

            LineasOrden::actualizar($empresaId, 'cantidad_teorica', $porLinea);
            $this->auditor->registrar('orden_produccion.editada', $bloqueada, $actor, $empresaId, $antes, $despues);

            return $bloqueada;
        }, attempts: 3);
    }

    /**
     * Cantidades teóricas; una cantidad tan pequeña que alguna línea quede en 0 se rechaza.
     *
     * @param  list<array{producto_id: int, nombre: string, entera: bool, cantidad_receta: string}>  $lineas
     * @return array<int, string>
     */
    private function teoricas(array $lineas, string $cantidad, string $base): array
    {
        $teoricas = LineasOrden::escalar($lineas, $cantidad, $base);

        foreach ($lineas as $linea) {
            if (Decimal::comparar($teoricas[$linea['producto_id']], '0') <= 0) {
                throw ValidationException::withMessages(['cantidad' => __('La cantidad es demasiado pequeña para la receta: :materia quedaría en 0.', ['materia' => $linea['nombre']])]);
            }

            if (Decimal::comparar($teoricas[$linea['producto_id']], self::MAXIMO) > 0) {
                throw ValidationException::withMessages(['cantidad' => __('La cantidad es demasiado grande para la receta.')]);
            }
        }

        return $teoricas;
    }

    /**
     * @param  list<array{nombre: string, activo: bool}>  $lineas
     */
    private function exigirActivas(array $lineas): void
    {
        $inactivas = array_column(array_filter($lineas, fn (array $l): bool => ! $l['activo']), 'nombre');

        if ($inactivas !== []) {
            throw ValidationException::withMessages(['producto_id' => __('La receta usa materias primas inactivas (:materias). Actívalas en Materias primas o edita la receta.', [
                'materias' => implode(', ', $inactivas),
            ])]);
        }
    }

    private function cantidad(mixed $valor, Producto $producto): string
    {
        $cantidad = Decimal::normalizar(is_scalar($valor) ? (string) $valor : '', Decimal::ESCALA_CANTIDAD);

        if ($cantidad === null || Decimal::comparar($cantidad, '0') <= 0 || Decimal::comparar($cantidad, self::MAXIMO) > 0) {
            throw ValidationException::withMessages(['cantidad' => __('Ingresa la cantidad a producir, mayor que cero (hasta 3 decimales).')]);
        }

        if (Escalado::esEntera($producto->tipo_venta, (bool) $producto->unidad->admite_decimales) && ! Decimal::esEntero($cantidad)) {
            throw ValidationException::withMessages(['cantidad' => __(':producto se mide en unidades enteras: escribe la cantidad sin decimales.', ['producto' => $producto->nombre])]);
        }

        return $cantidad;
    }

    private function fecha(mixed $valor): string
    {
        $texto = is_string($valor) ? trim($valor) : '';
        $fecha = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[1] >= 2000 && (int) $m[1] <= 2099 ? $texto : null;

        return $fecha ?? throw ValidationException::withMessages(['fecha_programada' => __('Elige la fecha programada.')]);
    }

    private function nota(mixed $valor): ?string
    {
        $nota = is_string($valor) ? trim($valor) : '';

        if (mb_strlen($nota) > self::MAX_NOTA) {
            throw ValidationException::withMessages(['nota' => __('La nota admite hasta :max caracteres.', ['max' => self::MAX_NOTA])]);
        }

        return $nota === '' ? null : $nota;
    }

    private function existente(string $clave): ?OrdenProduccion
    {
        return OrdenProduccion::query()->where('clave_idempotencia', $clave)->first();
    }
}
