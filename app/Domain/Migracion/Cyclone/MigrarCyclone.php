<?php

namespace App\Domain\Migracion\Cyclone;

use App\Domain\Auditoria\Auditor;
use App\Domain\Inventario\LibroStock;
use App\Domain\Suscripciones\CupoSuscripcion;
use App\Domain\Tenancy\Actions\GuardarSucursal;
use App\Enums\TipoOperacionInventario;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Escribe en una empresa NUEVA de MoziStock lo que preparó PlanMigracionCyclone, todo en una sola transacción: si algo
 * falla no queda nada a medias. Solo una vez por empresa (migracion_origenes) y con la fila de la empresa bloqueada,
 * así que repetir el comando o lanzarlo dos veces a la vez no duplica productos, clientes ni stock.
 *
 * - Sucursales con GuardarSucursal (cupo del plan, caja principal y auditoría); la casa matriz reutiliza «Casa Matriz».
 * - Productos por lotes, como la importación de productos; sus ids se recuperan por SKU (único por empresa).
 * - Stock: una entrada «Inventario inicial» de LibroStock por sucursal (hasta LibroStock::MAX_LINEAS líneas cada una),
 *   con clave de idempotencia derivada de la empresa y la sucursal. No es una compra: no toca caja ni cuentas por pagar.
 * - Clientes y proveedores por lotes. Ventas, caja, créditos y compras empiezan desde cero.
 */
final class MigrarCyclone
{
    public const SISTEMA = 'cyclone';

    private const LOTE = 500;

    /** Unidades que la interpretación de presentaciones puede pedir y que no están en UnidadMedida::BASE. */
    private const OTRAS_UNIDADES = [
        'paq.' => ['nombre' => 'Paquete', 'admite_decimales' => false, 'unidad_medida_sin' => 42],
    ];

    public function __construct(
        private readonly GuardarSucursal $sucursales,
        private readonly LibroStock $libro,
        private readonly Auditor $auditor,
    ) {}

    public static function migrada(int $empresaId): bool
    {
        return DB::table('migracion_origenes')->where('empresa_id', $empresaId)->where('sistema', self::SISTEMA)->exists();
    }

    /**
     * Lo que impide migrar a esta empresa y lo que se crearía (vista previa). Se repite dentro de la transacción.
     *
     * @param  array<string, mixed>  $plan
     * @return array{problemas: list<string>, unidades_nuevas: list<string>, categorias_nuevas: list<string>, sucursales_nuevas: int, matriz: ?string}
     */
    public function revisarDestino(Empresa $empresa, array $plan): array
    {
        $id = (int) $empresa->id;
        $problemas = [];

        if (self::migrada($id)) {
            return [
                'problemas' => ['Esta empresa ya fue migrada desde Cyclone; no se vuelve a migrar.'],
                'unidades_nuevas' => [], 'categorias_nuevas' => [], 'sucursales_nuevas' => 0, 'matriz' => null,
            ];
        }

        foreach (['productos', 'clientes', 'proveedores'] as $tabla) {
            $cantidad = DB::table($tabla)->where('empresa_id', $id)->count();

            if ($cantidad > 0) {
                $problemas[] = "La empresa ya tiene {$cantidad} {$tabla}: la migración es solo para una empresa nueva.";
            }
        }

        $existentes = DB::table('sucursales')->where('empresa_id', $id)->get(['id', 'codigo', 'nombre']);
        $matriz = $existentes->first(fn (object $s): bool => strcasecmp($s->codigo, 'CM') === 0);
        $nuevas = 0;

        foreach ($plan['sucursales'] as $sucursal) {
            if ($sucursal['matriz'] && $matriz !== null) {
                continue;
            }

            $nuevas++;

            if ($existentes->contains(fn (object $s): bool => strcasecmp($s->codigo, $sucursal['codigo']) === 0)) {
                $problemas[] = "Ya existe una sucursal con el código {$sucursal['codigo']}.";
            }
        }

        $cupo = CupoSuscripcion::resumen($id)['sucursales'];

        if ($cupo['limite'] !== null && $cupo['limite'] < $cupo['uso'] + $nuevas) {
            $problemas[] = 'Con la migración la empresa tendría '.($cupo['uso'] + $nuevas)." sucursales activas y su plan permite {$cupo['limite']}."
                .' Agrega sucursales extra a su suscripción en Superadmin.';
        }

        $unidades = DB::table('unidades_medida')->where('empresa_id', $id)->pluck('abreviatura')
            ->map(fn (string $a): string => mb_strtolower($a))->flip();
        $categorias = DB::table('categorias')->where('empresa_id', $id)->pluck('nombre')
            ->map(fn (string $c): string => mb_strtolower($c))->flip();

        return [
            'problemas' => $problemas,
            'unidades_nuevas' => collect($plan['productos'])->pluck('unidad')->unique(fn (string $u): string => mb_strtolower($u))
                ->reject(fn (string $u): bool => $unidades->has(mb_strtolower($u)))->values()->all(),
            'categorias_nuevas' => collect($plan['productos'])->pluck('categoria')->filter()->unique(fn (string $c): string => mb_strtolower($c))
                ->reject(fn (string $c): bool => $categorias->has(mb_strtolower($c)))->values()->all(),
            'sucursales_nuevas' => $nuevas,
            'matriz' => $matriz?->nombre,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $contexto  datos extra para la auditoría (por ejemplo, el superadmin que la lanzó)
     * @return array<string, mixed> resumen de lo creado
     */
    public function ejecutar(User $actor, Empresa $empresa, array $plan, string $nota, array $contexto = []): array
    {
        if ($plan['errores'] !== []) {
            throw new RuntimeException('El respaldo tiene errores que impiden migrar: '.implode(' ', $plan['errores']));
        }

        if ((int) $actor->empresa_id !== (int) $empresa->id || ! $actor->esAdministrador() || ! $actor->activo) {
            throw new RuntimeException('La migración la registra un administrador activo de la misma empresa.');
        }

        return DB::transaction(function () use ($actor, $empresa, $plan, $nota, $contexto): array {
            DB::table('empresas')->where('id', $empresa->id)->lockForUpdate()->first();
            $revision = $this->revisarDestino($empresa, $plan);

            if ($revision['problemas'] !== []) {
                throw new RuntimeException(implode(' ', $revision['problemas']));
            }

            $empresaId = (int) $empresa->id;
            $ahora = Carbon::now();
            $unidades = $this->unidades($empresaId, $revision['unidades_nuevas'], $ahora);
            $categorias = $this->categorias($empresaId, $revision['categorias_nuevas'], $ahora);
            $sucursales = $this->sucursales($actor, $plan['sucursales']);
            $productos = $this->productos($empresaId, $plan['productos'], $unidades, $categorias, $ahora);
            [$operaciones, $unidadesStock] = $this->stock($actor, $plan, $sucursales, $productos, $nota);

            $this->insertarPorLotes('clientes', array_map(fn (array $c): array => $c + [
                'empresa_id' => $empresaId, 'created_at' => $ahora, 'updated_at' => $ahora,
            ], $plan['clientes']));
            $this->insertarPorLotes('proveedores', array_map(fn (array $p): array => $p + [
                'empresa_id' => $empresaId, 'documento' => null, 'ciudad' => null, 'created_at' => $ahora, 'updated_at' => $ahora,
            ], $plan['proveedores']));

            $this->equivalencias($empresaId, 'sucursal', $sucursales, $ahora);
            $this->equivalencias($empresaId, 'producto', $productos, $ahora,
                collect($plan['productos'])->mapWithKeys(fn (array $p): array => [$p['origen_id'] => (string) ($p['foto'] ?? '')])->all());

            $resultado = [
                'sucursales' => count($sucursales),
                'sucursales_nuevas' => $revision['sucursales_nuevas'],
                'unidades_creadas' => count($revision['unidades_nuevas']),
                'categorias_creadas' => count($revision['categorias_nuevas']),
                'productos' => count($productos),
                'operaciones_stock' => $operaciones,
                'unidades_stock' => $unidadesStock,
                'clientes' => count($plan['clientes']),
                'proveedores' => count($plan['proveedores']),
            ];

            $this->auditor->registrar('empresa.migrada_desde_cyclone', $empresa, $actor, $empresaId, null, $resultado + $contexto);

            return $resultado;
        });
    }

    /**
     * @param  list<string>  $nuevas  abreviaturas que la empresa todavía no tiene
     * @return array<string, int> abreviatura en minúsculas => id
     */
    private function unidades(int $empresaId, array $nuevas, Carbon $ahora): array
    {
        foreach ($nuevas as $abreviatura) {
            $clave = mb_strtolower($abreviatura);
            $base = collect(UnidadMedida::BASE)->first(fn (array $u): bool => mb_strtolower($u['abreviatura']) === $clave)
                ?? self::OTRAS_UNIDADES[$clave]
                ?? ['nombre' => $abreviatura, 'admite_decimales' => false, 'unidad_medida_sin' => UnidadMedida::resolverCodigoSin(null, $abreviatura) ?? 57];

            DB::table('unidades_medida')->insert([
                'empresa_id' => $empresaId, 'nombre' => $base['nombre'], 'abreviatura' => $abreviatura,
                'admite_decimales' => $base['admite_decimales'], 'unidad_medida_sin' => $base['unidad_medida_sin'],
                'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        return DB::table('unidades_medida')->where('empresa_id', $empresaId)->pluck('id', 'abreviatura')
            ->mapWithKeys(fn (int $id, string $abreviatura): array => [mb_strtolower($abreviatura) => $id])->all();
    }

    /**
     * @param  list<string>  $nuevas
     * @return array<string, int> nombre en minúsculas => id
     */
    private function categorias(int $empresaId, array $nuevas, Carbon $ahora): array
    {
        if ($nuevas !== []) {
            DB::table('categorias')->insert(array_map(fn (string $nombre): array => [
                'empresa_id' => $empresaId, 'nombre' => $nombre, 'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
            ], $nuevas));
        }

        return DB::table('categorias')->where('empresa_id', $empresaId)->pluck('id', 'nombre')
            ->mapWithKeys(fn (int $id, string $nombre): array => [mb_strtolower($nombre) => $id])->all();
    }

    /**
     * @param  list<array{origen_id: int, nombre: string, codigo: string, direccion: ?string, telefono: ?string, matriz: bool}>  $plan
     * @return array<int, int> id de origen => id de la sucursal
     */
    private function sucursales(User $actor, array $plan): array
    {
        $ids = [];

        foreach ($plan as $datos) {
            $campos = ['nombre' => $datos['nombre'], 'codigo' => $datos['codigo'], 'direccion' => $datos['direccion'], 'telefono' => $datos['telefono']];
            $matriz = $datos['matriz'] ? Sucursal::withoutGlobalScopes()->where('empresa_id', $actor->empresa_id)
                ->where('codigo', 'CM')->first() : null;

            $sucursal = $matriz !== null
                ? $this->sucursales->actualizar($actor, $matriz, $campos)
                : $this->sucursales->crear($actor, $campos);

            $ids[$datos['origen_id']] = (int) $sucursal->id;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @param  array<string, int>  $unidades
     * @param  array<string, int>  $categorias
     * @return array<int, int> id de origen => id del producto
     */
    private function productos(int $empresaId, array $plan, array $unidades, array $categorias, Carbon $ahora): array
    {
        $this->insertarPorLotes('productos', array_map(fn (array $p): array => [
            'empresa_id' => $empresaId,
            'clase' => 'venta',
            'categoria_id' => $p['categoria'] === null ? null : $categorias[mb_strtolower($p['categoria'])],
            'unidad_medida_id' => $unidades[mb_strtolower($p['unidad'])],
            'tipo_venta' => $p['tipo_venta'],
            'sku' => $p['sku'],
            'codigo_barras' => null,
            'nombre' => $p['nombre'],
            'contenido' => $p['contenido'],
            'descripcion' => $p['descripcion'],
            'precio_consumidor' => $p['precio_consumidor'],
            'precio_minorista' => $p['precio_minorista'],
            'precio_mayorista' => $p['precio_mayorista'],
            'costo_promedio' => $p['costo'],
            'stock_minimo' => $p['stock_minimo'],
            'stock_maximo' => $p['stock_maximo'],
            'controla_stock' => true,
            'controla_vencimiento' => false,
            'activo' => $p['activo'],
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], $plan));

        $porSku = DB::table('productos')->where('empresa_id', $empresaId)->pluck('id', 'sku')
            ->mapWithKeys(fn (int $id, string $sku): array => [mb_strtoupper($sku) => $id]);
        $ids = [];

        foreach ($plan as $producto) {
            $ids[$producto['origen_id']] = $porSku[$producto['sku']] ?? throw new RuntimeException("No se encontró el producto {$producto['sku']} recién creado.");
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<int, int>  $sucursales
     * @param  array<int, int>  $productos
     * @return array{0: list<string>, 1: string} códigos de las operaciones y unidades cargadas
     */
    private function stock(User $actor, array $plan, array $sucursales, array $productos, string $nota): array
    {
        $nombres = collect($plan['productos'])->pluck('nombre', 'origen_id');
        $operaciones = [];
        $total = '0';

        foreach ($plan['stock'] as $sucursalOrigen => $lineas) {
            $cambios = array_map(fn (array $linea): array => [
                'sucursal_id' => $sucursales[$sucursalOrigen],
                'producto_id' => $productos[$linea['producto_origen']],
                'cantidad' => $linea['cantidad'],
                'nombre' => (string) $nombres[$linea['producto_origen']],
            ], $lineas);

            foreach (array_chunk($cambios, LibroStock::MAX_LINEAS) as $parte => $lote) {
                // Productos creados en esta transacción: nadie más puede tener sus saldos (igual que la importación).
                $this->libro->asegurarNiveles((int) $actor->empresa_id, $lote);
                $operaciones[] = $this->libro->aplicar($actor, [
                    'tipo' => TipoOperacionInventario::Entrada,
                    'motivo' => 'inventario_inicial',
                    'sucursal_id' => $sucursales[$sucursalOrigen],
                    'sucursal_destino_id' => null,
                    'nota' => mb_substr($nota, 0, 250),
                    'clave' => Uuid::uuid5(Uuid::NAMESPACE_URL, "mozistock:migracion-cyclone:{$actor->empresa_id}:{$sucursalOrigen}:{$parte}")->toString(),
                ], $lote)->codigo();

                foreach ($lote as $cambio) {
                    $total = Decimal::sumar($total, $cambio['cantidad']);
                }
            }
        }

        return [$operaciones, $total];
    }

    /**
     * @param  array<int, int>  $ids  id de origen => id en MoziStock
     * @param  array<int, string>|null  $fotos  solo productos: id de origen => nombre de su foto ('' = sin foto)
     */
    private function equivalencias(int $empresaId, string $entidad, array $ids, Carbon $ahora, ?array $fotos = null): void
    {
        $filas = [];

        foreach ($ids as $origen => $destino) {
            $filas[] = [
                'empresa_id' => $empresaId, 'sistema' => self::SISTEMA, 'entidad' => $entidad,
                'origen_id' => (string) $origen, 'destino_id' => $destino,
                'foto' => $fotos === null ? null : mb_substr($fotos[$origen] ?? '', 0, 255), 'created_at' => $ahora,
            ];
        }

        $this->insertarPorLotes('migracion_origenes', $filas);
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     */
    private function insertarPorLotes(string $tabla, array $filas): void
    {
        foreach (array_chunk($filas, self::LOTE) as $lote) {
            DB::table($tabla)->insert($lote);
        }
    }
}
