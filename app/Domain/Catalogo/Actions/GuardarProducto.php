<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\Producto;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Alta y edición de la ficha de producto. Nunca asigna stock: las existencias solo
 * cambian con operaciones de inventario por sucursal. La imagen se guarda en el disco
 * privado, en una carpeta de la empresa y con nombre aleatorio.
 */
final class GuardarProducto
{
    public const DISCO = 'local';

    private const CAMPOS = ['nombre', 'sku', 'codigo_barras', 'descripcion', 'categoria_id', 'unidad_medida_id',
        'precio_consumidor', 'precio_minorista', 'precio_mayorista', 'stock_minimo', 'activo'];

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(User $actor, array $datos, ?UploadedFile $imagen = null): Producto
    {
        Gate::forUser($actor)->authorize('create', Producto::class);

        $producto = new Producto;
        $producto->empresa_id = (int) $actor->empresa_id;
        $producto->activo = true;

        return $this->guardar($actor, $producto, $datos, $imagen, false, 'producto.creado');
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(User $actor, Producto $producto, array $datos, ?UploadedFile $imagen = null, bool $quitarImagen = false): Producto
    {
        Gate::forUser($actor)->authorize('update', $producto);

        return $this->guardar($actor, $producto, $datos, $imagen, $quitarImagen, 'producto.actualizado');
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function guardar(User $actor, Producto $producto, array $datos, ?UploadedFile $imagen, bool $quitarImagen, string $accion): Producto
    {
        $empresaId = (int) $actor->empresa_id;
        $antes = $producto->exists ? $this->resumen($producto) : null;
        $imagenAnterior = $producto->imagen_path;
        $this->aplicar($empresaId, $producto, $datos);

        if ($producto->exists && array_key_exists('activo', $datos)) {
            $producto->activo = (bool) $datos['activo'];
        }

        $imagenNueva = $imagen?->storeAs("empresas/{$empresaId}/productos", Str::random(40).'.'.$imagen->extension(), self::DISCO);

        if ($imagen !== null && $imagenNueva === false) {
            throw ValidationException::withMessages(['imagen' => __('No se pudo guardar la imagen.')]);
        }

        if (is_string($imagenNueva)) {
            $producto->imagen_path = $imagenNueva;
        } elseif ($quitarImagen) {
            $producto->imagen_path = null;
        }

        try {
            DB::transaction(function () use ($actor, $producto, $accion, $antes): void {
                $producto->save();
                $this->auditor->registrar($accion, $producto, $actor, $producto->empresa_id, $antes, $this->resumen($producto));
            });
        } catch (Throwable $e) {
            if (is_string($imagenNueva)) {
                Storage::disk(self::DISCO)->delete($imagenNueva);
            }

            throw $e;
        }

        if (is_string($imagenAnterior) && $imagenAnterior !== $producto->imagen_path) {
            Storage::disk(self::DISCO)->delete($imagenAnterior);
        }

        return $producto;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function aplicar(int $empresaId, Producto $producto, array $datos): void
    {
        $categoriaId = filled($datos['categoria_id'] ?? null) ? (int) $datos['categoria_id'] : null;
        $unidadId = (int) ($datos['unidad_medida_id'] ?? 0);
        $errores = [];

        if ($categoriaId !== null && ! DB::table('categorias')->where('empresa_id', $empresaId)->where('id', $categoriaId)->exists()) {
            $errores['categoria_id'] = __('Selecciona una categoría de tu empresa.');
        }

        if (! DB::table('unidades_medida')->where('empresa_id', $empresaId)->where('id', $unidadId)->exists()) {
            $errores['unidad_medida_id'] = __('Selecciona una unidad de medida de tu empresa.');
        }

        $consumidor = $this->precio($datos['precio_consumidor'] ?? null);
        $minorista = filled($datos['precio_minorista'] ?? null) ? $this->precio($datos['precio_minorista']) : '';
        $mayorista = filled($datos['precio_mayorista'] ?? null) ? $this->precio($datos['precio_mayorista']) : '';
        $minimo = Decimal::normalizar((string) ($datos['stock_minimo'] ?? '0'), Decimal::ESCALA_CANTIDAD);

        foreach (['precio_consumidor' => $consumidor, 'precio_minorista' => $minorista, 'precio_mayorista' => $mayorista] as $campo => $valor) {
            if ($valor === null) {
                $errores[$campo] = __('Ingresa un precio válido en Bs, mayor o igual a 0 y con hasta 2 decimales.');
            }
        }

        if ($minimo === null || Decimal::comparar($minimo, '0') < 0) {
            $errores['stock_minimo'] = __('Ingresa un mínimo mayor o igual a 0.');
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $producto->nombre = trim((string) $datos['nombre']);
        $producto->sku = Str::upper(trim((string) $datos['sku']));
        $codigo = trim((string) ($datos['codigo_barras'] ?? ''));
        $producto->codigo_barras = $codigo === '' ? null : $codigo;
        $descripcion = trim((string) ($datos['descripcion'] ?? ''));
        $producto->descripcion = $descripcion === '' ? null : $descripcion;
        $producto->categoria_id = $categoriaId;
        $producto->unidad_medida_id = $unidadId;
        $producto->precio_consumidor = (string) $consumidor;
        $producto->precio_minorista = $minorista === '' ? null : $minorista;
        $producto->precio_mayorista = $mayorista === '' ? null : $mayorista;
        $producto->stock_minimo = (string) $minimo;
    }

    private function precio(mixed $valor): ?string
    {
        $precio = Decimal::normalizar(is_scalar($valor) ? (string) $valor : '', Decimal::ESCALA_DINERO);

        return $precio !== null && Decimal::comparar($precio, '0', Decimal::ESCALA_DINERO) >= 0 ? $precio : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(Producto $producto): array
    {
        return $producto->only(self::CAMPOS) + ['imagen' => $producto->imagen_path !== null];
    }
}
