<?php

namespace App\Console\Commands;

use App\Domain\Migracion\Cyclone\FotosCyclone;
use App\Domain\Migracion\Cyclone\MigrarCyclone;
use App\Domain\Migracion\Cyclone\OrigenCyclone;
use App\Domain\Migracion\Cyclone\PlanMigracionCyclone;
use App\Enums\Rol;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Migra a una empresa nueva de MoziStock los productos, sucursales, stock (como «Inventario inicial»), clientes y
 * proveedores del sistema anterior de Cyclone. Sin --ejecutar solo muestra la vista previa y escribe el informe.
 */
class MigrarCycloneCommand extends Command
{
    protected $signature = 'mozistock:migrar-cyclone
        {empresa : ID de la empresa de MoziStock que recibe los datos}
        {--origen=cyclone_origen : Base MySQL (mismo servidor y usuario) con el respaldo de Cyclone}
        {--conexion= : Conexión ya configurada que se usa en lugar de --origen}
        {--usuario= : Correo del administrador que registra la migración (por defecto, el primero)}
        {--ejecutar : Guarda los datos; sin esta opción solo muestra la vista previa}
        {--sin-fotos : No copia las fotos de los productos}
        {--solo-fotos : Solo completa las fotos de una empresa ya migrada}';

    protected $description = 'Migra productos, sucursales, stock inicial, clientes y proveedores desde el sistema anterior de Cyclone';

    public function handle(PlanMigracionCyclone $planificador, MigrarCyclone $migrador, FotosCyclone $fotos): int
    {
        $empresa = Empresa::query()->find((int) $this->argument('empresa'));

        if ($empresa === null) {
            $this->error('No existe esa empresa.');

            return self::FAILURE;
        }

        $actor = $this->administrador($empresa);

        if ($actor === null) {
            $this->error('La empresa no tiene un administrador activo con ese correo.');

            return self::FAILURE;
        }

        $conexion = $this->option('conexion');
        $origen = filled($conexion) ? new OrigenCyclone(DB::connection((string) $conexion)) : OrigenCyclone::desdeBase((string) $this->option('origen'));
        $faltantes = $origen->tablasFaltantes();

        if ($faltantes !== []) {
            $this->error('No se pueden leer estas tablas del respaldo: '.implode(', ', $faltantes).'.');

            return self::FAILURE;
        }

        $plan = $planificador->construir($origen);

        if ($this->option('solo-fotos')) {
            if (! MigrarCyclone::migrada((int) $empresa->id)) {
                $this->error('La empresa todavía no fue migrada.');

                return self::FAILURE;
            }

            return $this->copiarFotos($fotos, $empresa, $plan);
        }

        $revision = $migrador->revisarDestino($empresa, $plan);
        $this->mostrarVistaPrevia($empresa, $plan, $revision);
        $this->escribirInforme($empresa, $plan);
        $problemas = array_merge($plan['errores'], $revision['problemas']);

        if ($problemas !== []) {
            $this->newLine();
            $this->error('No se puede migrar:');

            foreach ($problemas as $problema) {
                $this->line("  - {$problema}");
            }

            return self::FAILURE;
        }

        if (! $this->option('ejecutar')) {
            $this->newLine();
            $this->info('Vista previa: no se guardó nada. Para migrar, repite el comando con --ejecutar.');

            return self::SUCCESS;
        }

        try {
            $resultado = $migrador->ejecutar($actor, $empresa, $plan, 'Migración desde el sistema anterior de Cyclone');
        } catch (Throwable $e) {
            $this->error('La migración no se guardó (no quedó nada a medias): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Migración guardada.');
        $this->table(['Dato', 'Resultado'], [
            ['Sucursales', $resultado['sucursales'].' ('.$resultado['sucursales_nuevas'].' nuevas)'],
            ['Unidades / categorías creadas', $resultado['unidades_creadas'].' / '.$resultado['categorias_creadas']],
            ['Productos', $resultado['productos']],
            ['Entradas «Inventario inicial»', implode(', ', $resultado['operaciones_stock'])],
            ['Unidades en stock', $resultado['unidades_stock']],
            ['Clientes', $resultado['clientes']],
            ['Proveedores', $resultado['proveedores']],
        ]);

        return $this->option('sin-fotos') ? self::SUCCESS : $this->copiarFotos($fotos, $empresa, $plan);
    }

    private function administrador(Empresa $empresa): ?User
    {
        return User::query()
            ->where('empresa_id', $empresa->id)
            ->where('rol', Rol::Administrador)
            ->where('activo', true)
            ->when(filled($this->option('usuario')), fn ($q) => $q->where('email', mb_strtolower((string) $this->option('usuario'))))
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $revision
     */
    private function mostrarVistaPrevia(Empresa $empresa, array $plan, array $revision): void
    {
        $this->info("Migración de Cyclone a la empresa #{$empresa->id} {$empresa->nombre}");

        $productos = collect($plan['productos']);
        $nombres = collect($plan['sucursales'])->pluck('nombre', 'origen_id');
        $filas = [];

        foreach ($plan['sucursales'] as $sucursal) {
            $lineas = collect($plan['stock'][$sucursal['origen_id']] ?? []);
            $destino = $sucursal['matriz'] && $revision['matriz'] !== null ? "reutiliza «{$revision['matriz']}»" : 'nueva';
            $filas[] = [
                $nombres[$sucursal['origen_id']], $sucursal['codigo'], $destino,
                $lineas->count(), $lineas->sum(fn (array $l): int => (int) $l['cantidad']),
            ];
        }

        $this->table(['Sucursal', 'Código', 'En MoziStock', 'Productos con stock', 'Unidades'], $filas);
        $this->table(['Dato', 'Cantidad'], [
            ['Productos (activos / inactivos)', $productos->count().' ('.$productos->where('activo', true)->count().' / '.$productos->where('activo', false)->count().')'],
            ['Productos con foto', $productos->whereNotNull('foto')->count()],
            ['Unidades nuevas', $revision['unidades_nuevas'] === [] ? '0' : implode(', ', $revision['unidades_nuevas'])],
            ['Categorías nuevas', $revision['categorias_nuevas'] === [] ? '0' : implode(', ', $revision['categorias_nuevas'])],
            ['Clientes', count($plan['clientes'])],
            ['Proveedores', count($plan['proveedores'])],
        ]);

        if ($plan['observaciones'] !== []) {
            $this->line('Observaciones (detalle completo en el informe):');

            foreach ($plan['observaciones'] as $clave => $detalles) {
                $this->line(sprintf('  %5d  %s', count($detalles), PlanMigracionCyclone::TITULOS[$clave] ?? $clave));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function escribirInforme(Empresa $empresa, array $plan): void
    {
        $texto = PlanMigracionCyclone::informe("la empresa #{$empresa->id} {$empresa->nombre}", $plan);
        $ruta = "migraciones/cyclone-empresa-{$empresa->id}-".now()->format('Ymd-His').'.txt';
        Storage::disk('local')->put($ruta, $texto);
        $this->line('Informe: '.Storage::disk('local')->path($ruta));
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function copiarFotos(FotosCyclone $fotos, Empresa $empresa, array $plan): int
    {
        $conFoto = collect($plan['productos'])->whereNotNull('foto')->pluck('foto', 'origen_id')->all();
        $resultado = $fotos->copiar($empresa, $conFoto);

        $this->info("Fotos copiadas: {$resultado['copiadas']}.");

        foreach (['sin_catalogo' => 'Sin foto en el catálogo central', 'fallidas' => 'No se pudo copiar'] as $clave => $titulo) {
            if ($resultado[$clave] !== []) {
                $this->warn("{$titulo} (".count($resultado[$clave]).'): '.implode(', ', array_slice($resultado[$clave], 0, 10)));
            }
        }

        return self::SUCCESS;
    }
}
