<?php

namespace App\Livewire\Admin;

use App\Domain\Migracion\Cyclone\FotosCyclone;
use App\Domain\Migracion\Cyclone\MigrarCyclone;
use App\Domain\Migracion\Cyclone\PlanMigracionCyclone;
use App\Domain\Migracion\Cyclone\RespaldoSqlCyclone;
use App\Enums\TipoCuenta;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Superadmin › Empresas › «Migrar desde Cyclone»: el superadmin elige la empresa (nueva), sube el respaldo .sql de
 * Cyclone, revisa la vista previa y migra con un botón. Usa la misma migración que el comando
 * `mozistock:migrar-cyclone` (una transacción, una sola vez por empresa). El SQL subido nunca se ejecuta: lo lee
 * RespaldoSqlCyclone. El archivo queda en el almacenamiento privado solo hasta migrar o quitarlo (y se borra solo al
 * día siguiente si se abandona), porque trae datos personales de clientes.
 */
#[Title('Migrar desde Cyclone')]
class MigracionCyclone extends Component
{
    use MuestraAvisos, WithFileUploads;

    private const DISCO = 'local';

    private const CARPETA = 'migraciones/respaldos';

    private const FOTOS_POR_TANDA = 8;

    private const NOTA = 'Migración desde el sistema anterior de Cyclone';

    #[Url(as: 'empresa', except: null)]
    public ?int $empresaId = null;

    /** @var TemporaryUploadedFile|null */
    public $archivo = null;

    /** Ruta del respaldo subido en el disco privado: la fija el servidor, el navegador no puede cambiarla. */
    #[Locked]
    public ?string $respaldo = null;

    #[Locked]
    public ?string $respaldoNombre = null;

    /** Vista previa del respaldo: sucursales y cantidades, sin datos personales (el detalle va en el informe). */
    #[Locked]
    public array $vista = [];

    /** Lo que impide migrar a la empresa elegida y lo que se crearía (MigrarCyclone::revisarDestino). */
    #[Locked]
    public array $revision = [];

    #[Locked]
    public ?array $resultado = null;

    public bool $confirmando = false;

    #[Locked]
    public bool $copiandoFotos = false;

    /** @var array{total: int, copiadas: int, fallidas: list<string>} */
    #[Locked]
    public array $fotos = ['total' => 0, 'copiadas' => 0, 'fallidas' => []];

    /** @var list<string> ids de origen ya intentados en esta copia (los que fallan no se repiten en bucle) */
    #[Locked]
    public array $fotosIntentadas = [];

    public function mount(): void
    {
        $this->authorize('migrarDatos', Empresa::class);
        $this->borrarRespaldosViejos();

        if ($this->empresaId !== null && ! Empresa::query()->whereKey($this->empresaId)->exists()) {
            $this->empresaId = null;
        }
    }

    public function updatedEmpresaId(): void
    {
        $this->authorize('migrarDatos', Empresa::class);
        $this->resetErrorBag();
        $this->reset('resultado', 'confirmando', 'copiandoFotos', 'fotos', 'fotosIntentadas', 'revision');

        if ($this->empresaId !== null && ! Empresa::query()->whereKey($this->empresaId)->exists()) {
            $this->empresaId = null;
        }

        if ($this->respaldo !== null) {
            $this->prevista();
        }
    }

    public function updatedArchivo(): void
    {
        $this->authorize('migrarDatos', Empresa::class);
        $this->validate(
            ['archivo' => ['required', 'file', 'max:'.RespaldoSqlCyclone::MAX_KB, 'extensions:sql,txt']],
            ['archivo.max' => __('El respaldo no puede superar 10 MB.'), 'archivo.extensions' => __('Sube el archivo .sql que exporta phpMyAdmin.')],
        );

        $this->quitarRespaldo();
        $nombre = Str::limit(basename((string) $this->archivo->getClientOriginalName()), 80);
        $ruta = $this->archivo->storeAs(self::CARPETA, Str::uuid()->toString().'.sql', self::DISCO);
        $this->archivo->delete();
        $this->archivo = null;
        $this->respaldo = $ruta;
        $this->respaldoNombre = $nombre;
        $empresa = $this->empresa();

        if ($empresa !== null && MigrarCyclone::migrada((int) $empresa->id)) {
            $this->completarFotos($empresa);

            return;
        }

        try {
            $this->prevista();
        } catch (ValidationException $e) {
            $this->quitarRespaldo();

            throw $e;
        }
    }

    public function quitarRespaldo(): void
    {
        $this->authorize('migrarDatos', Empresa::class);

        if ($this->respaldo !== null) {
            Storage::disk(self::DISCO)->delete($this->respaldo);
        }

        $this->reset('respaldo', 'respaldoNombre', 'vista', 'revision', 'confirmando');
    }

    public function descargarInforme(): ?StreamedResponse
    {
        $this->authorize('migrarDatos', Empresa::class);

        if ($this->respaldo === null) {
            return null;
        }

        $empresa = $this->empresa();
        $texto = PlanMigracionCyclone::informe($empresa === null ? 'MoziStock' : "la empresa #{$empresa->id} {$empresa->nombre}", $this->plan());

        return response()->streamDownload(function () use ($texto): void {
            echo $texto;
        }, 'informe-migracion-cyclone.txt', ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function confirmar(): void
    {
        $this->authorize('migrarDatos', Empresa::class);
        $this->confirmando = $this->puedeMigrar();
    }

    public function cancelar(): void
    {
        $this->confirmando = false;
    }

    public function migrar(MigrarCyclone $migrador): void
    {
        $this->authorize('migrarDatos', Empresa::class);
        $this->confirmando = false;
        $empresa = $this->empresa();

        if ($empresa === null || $this->respaldo === null) {
            $this->addError('migracion', __('Elige la empresa y sube el respaldo de Cyclone.'));

            return;
        }

        $actor = $this->administrador($empresa);

        if ($actor === null) {
            $this->addError('migracion', __('La empresa no tiene un administrador activo: la migración se registra a su nombre.'));

            return;
        }

        // La migración real tarda unos segundos; si el navegador se cierra, igual termina (o se deshace entera).
        // En las pruebas no: el límite valdría para todo el proceso de la suite y la cortaría a mitad.
        if (! app()->runningUnitTests()) {
            @set_time_limit(300);
        }

        ignore_user_abort(true);

        try {
            $plan = $this->plan();
            $resultado = $migrador->ejecutar($actor, $empresa, $plan, self::NOTA, [
                'desde' => 'superadmin', 'superadmin_id' => Auth::id(), 'archivo' => $this->respaldoNombre,
            ]);
        } catch (ValidationException $e) {
            $this->addError('migracion', __('No se migró nada: :motivo', ['motivo' => collect($e->errors())->flatten()->first()]));

            return;
        } catch (QueryException $e) {
            // Sin la consulta ni sus valores (traen datos de clientes): solo el motivo del motor.
            Log::error('Migración de Cyclone fallida', ['empresa_id' => $empresa->id, 'motivo' => $e->errorInfo[2] ?? $e->getCode()]);
            $this->addError('migracion', __('No se migró nada: la base de datos rechazó la operación. Revisa el registro del servidor.'));

            return;
        } catch (RuntimeException|AuthorizationException $e) {
            $this->addError('migracion', __('No se migró nada: :motivo', ['motivo' => $e->getMessage()]));

            if (isset($plan)) {
                $this->revision = $migrador->revisarDestino($empresa, $plan);
            }

            return;
        }

        Storage::disk(self::DISCO)->put("migraciones/cyclone-empresa-{$empresa->id}-".now()->format('Ymd-His').'.txt',
            PlanMigracionCyclone::informe("la empresa #{$empresa->id} {$empresa->nombre}", $plan));
        Storage::disk(self::DISCO)->delete($this->respaldo);
        $this->reset('respaldo', 'respaldoNombre', 'revision', 'vista');
        $this->resultado = $resultado;
        $this->avisar(__('Migración guardada.'));
        $this->iniciarFotos();
    }

    public function iniciarFotos(): void
    {
        $this->authorize('migrarDatos', Empresa::class);
        $empresa = $this->empresa();

        if ($empresa === null || ! MigrarCyclone::migrada((int) $empresa->id)) {
            return;
        }

        $total = count(FotosCyclone::pendientes($empresa));
        $this->fotos = ['total' => $total, 'copiadas' => 0, 'fallidas' => []];
        $this->fotosIntentadas = [];
        $this->copiandoFotos = $total > 0;
    }

    /**
     * Una tanda de fotos por llamada (la vista la repite mientras quedan): así ninguna petición se acerca al límite
     * de tiempo del hosting aunque haya que descargarlas de Cyclone.
     */
    public function copiarFotos(FotosCyclone $copiador): void
    {
        $this->authorize('migrarDatos', Empresa::class);
        $empresa = $this->empresa();

        if (! $this->copiandoFotos || $empresa === null) {
            return;
        }

        $candado = Cache::lock("migracion-cyclone-fotos:{$empresa->id}", 120);

        if (! $candado->get()) {
            return;
        }

        try {
            if (! app()->runningUnitTests()) {
                @set_time_limit(120);
            }

            $pendientes = array_diff_key(FotosCyclone::pendientes($empresa), array_flip($this->fotosIntentadas));
            $tanda = array_slice($pendientes, 0, self::FOTOS_POR_TANDA, true);

            if ($tanda !== []) {
                $copia = $copiador->copiar($empresa, $tanda);
                $this->fotosIntentadas = array_merge($this->fotosIntentadas, array_map('strval', array_keys($tanda)));
                $this->fotos['copiadas'] += $copia['copiadas'];
                $this->fotos['fallidas'] = array_merge($this->fotos['fallidas'], $copia['fallidas']);
            }

            if (count($pendientes) <= count($tanda)) {
                $this->copiandoFotos = false;
                $this->avisar(__('Fotos copiadas: :cantidad.', ['cantidad' => $this->fotos['copiadas']]));
            }
        } finally {
            $candado->release();
        }
    }

    public function render(): View
    {
        $this->authorize('migrarDatos', Empresa::class);
        $empresa = $this->empresa();
        $migradas = DB::table('migracion_origenes')->where('sistema', MigrarCyclone::SISTEMA)->distinct()->pluck('empresa_id');
        $migrada = $empresa !== null && $migradas->contains($empresa->id);

        return view('livewire.admin.migracion-cyclone', [
            'empresa' => $empresa,
            'administrador' => $empresa === null ? null : $this->administrador($empresa),
            'migrada' => $migrada,
            'sinDatoDeFoto' => $migrada ? FotosCyclone::sinDatoDeFoto($empresa) : 0,
            'fotosPendientes' => $migrada && ! $this->copiandoFotos ? count(FotosCyclone::pendientes($empresa)) : 0,
            'disponibles' => $this->empresasDisponibles($migradas->all()),
            'yaMigradas' => Empresa::query()->whereIn('id', $migradas)->orderBy('nombre')->get(['id', 'nombre']),
            'puedeMigrar' => $this->puedeMigrar(),
        ]);
    }

    /**
     * Empresas que pueden recibir la migración: sin productos, clientes ni proveedores y sin migración previa.
     *
     * @param  list<int>  $migradas
     */
    private function empresasDisponibles(array $migradas): Collection
    {
        return Empresa::query()
            ->whereNotIn('id', $migradas)
            ->whereNotExists(fn ($q) => $q->from('productos')->whereColumn('productos.empresa_id', 'empresas.id'))
            ->whereNotExists(fn ($q) => $q->from('clientes')->whereColumn('clientes.empresa_id', 'empresas.id'))
            ->whereNotExists(fn ($q) => $q->from('proveedores')->whereColumn('proveedores.empresa_id', 'empresas.id'))
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
    }

    private function puedeMigrar(): bool
    {
        return $this->empresaId !== null && $this->respaldo !== null && $this->vista !== []
            && ($this->vista['errores'] ?? []) === [] && ($this->revision['problemas'] ?? ['sin revisar']) === [];
    }

    private function empresa(): ?Empresa
    {
        return $this->empresaId === null ? null : Empresa::query()->find($this->empresaId);
    }

    private function administrador(Empresa $empresa): ?User
    {
        return User::query()->where('empresa_id', $empresa->id)->where('tipo', TipoCuenta::Administrador)
            ->where('activo', true)->orderBy('id')->first();
    }

    /**
     * Lee el respaldo subido (tablas temporales) y arma el plan; las tablas se liberan siempre al terminar.
     *
     * @return array<string, mixed>
     */
    private function plan(): array
    {
        $lector = app(RespaldoSqlCyclone::class);

        try {
            return app(PlanMigracionCyclone::class)->construir($lector->cargar(Storage::disk(self::DISCO)->path((string) $this->respaldo)));
        } finally {
            $lector->liberar();
        }
    }

    /**
     * Empresa migrada antes de que se guardara qué productos tienen foto: el respaldo solo completa ese dato (ningún
     * otro cambia), se borra enseguida y empiezan a copiarse las fotos desde Cyclone.
     */
    private function completarFotos(Empresa $empresa): void
    {
        try {
            $plan = $this->plan();
        } finally {
            $this->quitarRespaldo();
        }

        $completadas = FotosCyclone::registrarNombres($empresa, collect($plan['productos'])->pluck('foto', 'origen_id')->all());

        if ($completadas === 0) {
            $this->addError('respaldo', __('Este respaldo no tiene productos de esta empresa a los que les falte el dato de la foto.'));

            return;
        }

        $this->avisar(__('Respaldo leído: se traen las fotos desde Cyclone.'));
        $this->iniciarFotos();
    }

    private function prevista(): void
    {
        $plan = $this->plan();
        $productos = collect($plan['productos']);

        $this->vista = [
            'sucursales' => array_map(fn (array $s): array => [
                'nombre' => $s['nombre'],
                'codigo' => $s['codigo'],
                'matriz' => $s['matriz'],
                'productos' => count($plan['stock'][$s['origen_id']] ?? []),
                'unidades' => array_sum(array_map(fn (array $l): int => (int) $l['cantidad'], $plan['stock'][$s['origen_id']] ?? [])),
            ], $plan['sucursales']),
            'productos' => $productos->count(),
            'activos' => $productos->where('activo', true)->count(),
            'con_foto' => $productos->whereNotNull('foto')->count(),
            'clientes' => count($plan['clientes']),
            'proveedores' => count($plan['proveedores']),
            'observaciones' => collect($plan['observaciones'])
                ->map(fn (array $detalles, string $clave): array => ['titulo' => PlanMigracionCyclone::TITULOS[$clave] ?? $clave, 'cantidad' => count($detalles)])
                ->values()->all(),
            'errores' => $plan['errores'],
        ];

        $empresa = $this->empresa();
        $this->revision = $empresa === null ? [] : app(MigrarCyclone::class)->revisarDestino($empresa, $plan);
    }

    private function borrarRespaldosViejos(): void
    {
        $disco = Storage::disk(self::DISCO);
        $limite = now()->subDay()->getTimestamp();

        foreach ($disco->files(self::CARPETA) as $archivo) {
            if ($disco->lastModified($archivo) < $limite) {
                $disco->delete($archivo);
            }
        }
    }
}
