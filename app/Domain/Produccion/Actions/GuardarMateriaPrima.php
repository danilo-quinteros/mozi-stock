<?php

namespace App\Domain\Produccion\Actions;

use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Alta y edición de materias primas (ESPECIFICACION_PRODUCCION.md § 4.1, Excel § 1.1). Una materia prima es un
 * producto con clase «materia_prima»: reutiliza GuardarProducto (auditoría, lotes, código automático), con los datos
 * propios del formulario de producción y sin precios de venta.
 *
 * - Solo el administrador con la capacidad Producción (gate usar-produccion).
 * - Siempre controla stock; no tiene precios, categoría, marca, contenido ni variantes.
 * - Se mide en la unidad elegida, la misma para comprar y para usar (PR14): con una unidad que admite decimales
 *   (kg, L) se mueve por medida; si no, por unidades enteras.
 * - La edición solo acepta materias primas: un producto de venta no se edita por aquí.
 */
final class GuardarMateriaPrima
{
    public function __construct(private readonly GuardarProducto $productos) {}

    /**
     * @param  array{nombre?: mixed, sku?: mixed, unidad_medida_id?: mixed, usos?: mixed, observacion?: mixed, stock_minimo?: mixed, stock_maximo?: mixed, controla_vencimiento?: mixed, codigo_barras?: mixed, costo_inicial?: mixed}  $datos
     */
    public function crear(User $actor, array $datos): Producto
    {
        Gate::forUser($actor)->authorize('usar-produccion');

        return $this->productos->crear($actor, $this->datos((int) $actor->empresa_id, $datos) + ['clase' => Producto::CLASE_MATERIA_PRIMA]);
    }

    /**
     * @param  array{nombre?: mixed, sku?: mixed, unidad_medida_id?: mixed, usos?: mixed, observacion?: mixed, stock_minimo?: mixed, stock_maximo?: mixed, controla_vencimiento?: mixed, codigo_barras?: mixed, costo_inicial?: mixed, activo?: mixed}  $datos
     */
    public function actualizar(User $actor, Producto $materia, array $datos): Producto
    {
        Gate::forUser($actor)->authorize('usar-produccion');
        abort_unless($materia->clase === Producto::CLASE_MATERIA_PRIMA && $materia->empresa_id === $actor->empresa_id, 404);

        return $this->productos->actualizar($actor, $materia, $this->datos((int) $actor->empresa_id, $datos)
            + (array_key_exists('activo', $datos) ? ['activo' => (bool) $datos['activo']] : []));
    }

    /**
     * Datos de GuardarProducto. La forma de venta sale de la unidad (de la empresa: otra empresa no tiene esa fila y
     * GuardarProducto rechaza la unidad).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function datos(int $empresaId, array $datos): array
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));

        if ($nombre === '' || mb_strlen($nombre) > 150) {
            throw ValidationException::withMessages(['nombre' => __('Escribe el nombre de la materia prima (hasta 150 caracteres).')]);
        }

        $unidadId = (int) ($datos['unidad_medida_id'] ?? 0);
        $admiteDecimales = (bool) DB::table('unidades_medida')->where('empresa_id', $empresaId)->where('id', $unidadId)->value('admite_decimales');
        $sku = trim((string) ($datos['sku'] ?? ''));

        return [
            'nombre' => $nombre,
            'sku' => $sku,
            'sku_auto' => $sku === '',
            'codigo_barras' => trim((string) ($datos['codigo_barras'] ?? '')),
            'descripcion' => (string) ($datos['usos'] ?? ''),
            'observacion' => (string) ($datos['observacion'] ?? ''),
            'unidad_medida_id' => $unidadId,
            'categoria_id' => null,
            'contenido' => null,
            'tipo_venta' => Producto::tipoVentaPara(null, $admiteDecimales),
            'precio_consumidor' => '0',
            'precio_minorista' => null,
            'precio_mayorista' => null,
            'stock_minimo' => trim((string) ($datos['stock_minimo'] ?? '')) === '' ? '0' : (string) $datos['stock_minimo'],
            'stock_maximo' => (string) ($datos['stock_maximo'] ?? ''),
            'controla_stock' => true,
            'controla_vencimiento' => (bool) ($datos['controla_vencimiento'] ?? false),
            'costo_inicial' => (string) ($datos['costo_inicial'] ?? ''),
        ];
    }
}
