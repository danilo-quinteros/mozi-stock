<?php

namespace App\Livewire\Admin;

use App\Domain\AgenteMozi\Actions\GuardarAjustesAgente;
use App\Domain\AgenteMozi\Ia\ErrorProveedorIa;
use App\Domain\AgenteMozi\Ia\GestorProveedores;
use App\Domain\Reportes\Periodo;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\AgenteMensaje;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Superadministración › Agente Mozi (§ 4.4 y Fase 2): motor activo, modelo, cuotas diarias por empresa,
 * prueba de conexión y uso agregado. Nunca muestra claves: solo si están configuradas en el servidor.
 */
#[Title('Agente Mozi')]
class AgenteMozi extends Component
{
    use MuestraAvisos;

    public string $motor = GestorProveedores::DEMO;

    public string $modelo = '';

    public int|string $mensajesDiarios = '';

    public int|string $tokensDiarios = '';

    /** @var array{proveedor: string, ok: bool, texto: string}|null */
    public ?array $prueba = null;

    public function mount(GestorProveedores $gestor): void
    {
        $this->authorize('configurar-agente-mozi');
        $this->motor = $gestor->motor();
        $this->modelo = $this->motor === GestorProveedores::DEMO ? $this->modeloInicial($gestor) : $gestor->modelo($this->motor);
        $cuotas = $gestor->cuotas();
        $this->mensajesDiarios = $cuotas['mensajes'];
        $this->tokensDiarios = $cuotas['tokens'];
    }

    public function guardar(GuardarAjustesAgente $accion): void
    {
        /** @var User $actor */
        $actor = Auth::user();
        $this->resetValidation();
        $accion->ejecutar($actor, $this->motor, $this->modelo === '' ? null : $this->modelo, $this->mensajesDiarios, $this->tokensDiarios);
        $this->avisar(__('Configuración de Agente Mozi guardada.'));
    }

    public function probar(string $proveedor, GestorProveedores $gestor): void
    {
        $this->authorize('configurar-agente-mozi');

        if (! array_key_exists($proveedor, $gestor->motores()) || $proveedor === GestorProveedores::DEMO) {
            return;
        }

        $llave = 'agente-mozi-prueba:'.Auth::id();

        if (RateLimiter::tooManyAttempts($llave, 6)) {
            $this->prueba = ['proveedor' => $proveedor, 'ok' => false, 'texto' => __('Demasiadas pruebas seguidas. Espera un minuto.')];

            return;
        }

        RateLimiter::hit($llave, 60);
        $adaptador = $gestor->proveedor($proveedor);
        $modelo = $this->motor === $proveedor && in_array($this->modelo, $adaptador->modelos(), true) ? $this->modelo : $gestor->modelo($proveedor);
        $inicio = hrtime(true);

        try {
            $version = $adaptador->comprobar($modelo);
            $this->prueba = ['proveedor' => $proveedor, 'ok' => true, 'texto' => __('Conexión correcta con :modelo en :ms ms.', ['modelo' => $version, 'ms' => (int) round((hrtime(true) - $inicio) / 1e6)])];
        } catch (ErrorProveedorIa $e) {
            $this->prueba = ['proveedor' => $proveedor, 'ok' => false, 'texto' => __('No se pudo conectar: :error', ['error' => $e->getMessage()])];
        }
    }

    public function render(): View
    {
        $gestor = app(GestorProveedores::class);
        $proveedores = [];

        foreach (array_keys($gestor->motores()) as $id) {
            if ($id === GestorProveedores::DEMO) {
                continue;
            }

            $adaptador = $gestor->proveedor($id);
            $proveedores[$id] = [
                'nombre' => $adaptador->nombre(),
                'configurado' => $adaptador->configurado(),
                'modelos' => array_map(fn (string $modelo) => ['id' => $modelo, 'capacidades' => $adaptador->capacidades($modelo)], $adaptador->modelos()),
            ];
        }

        return view('livewire.admin.agente-mozi', [
            'motores' => $gestor->motores(),
            'proveedores' => $proveedores,
            'uso' => $this->uso(),
            'sandboxWeb' => (bool) config('agente_mozi.sandbox_web'),
            'sandboxIa' => $gestor->sandboxIa(),
        ]);
    }

    /**
     * Uso agregado por empresa de los últimos 7 días de La Paz (solo conteos: nunca contenido).
     *
     * @return list<object{nombre: string, hoy: int, semana: int, fallidas: int, tokens: int, latencia: int|null}>
     */
    private function uso(): array
    {
        $hoy = Periodo::hoy();
        [$desdeHoy] = $hoy->limitesUtc();
        [$desde] = Periodo::rango($hoy->desde->subDays(6)->format('Y-m-d'), $hoy->desde->format('Y-m-d'))->limitesUtc();

        /** @var list<object{nombre: string, hoy: int, semana: int, fallidas: int, tokens: int, latencia: int|null}> $filas */
        $filas = DB::table('agente_mensajes as m')
            ->join('empresas as e', 'e.id', '=', 'm.empresa_id')
            ->where('m.rol', AgenteMensaje::ROL_AGENTE)
            ->whereNotNull('m.proveedor')
            ->where('m.proveedor', '!=', GestorProveedores::DEMO)
            ->where('m.created_at', '>=', $desde)
            ->groupBy('e.id', 'e.nombre')
            ->selectRaw('e.nombre, SUM(m.created_at >= ? AND m.estado = ?) AS hoy, SUM(m.estado = ?) AS semana, SUM(m.estado = ?) AS fallidas, '
                .'COALESCE(SUM(m.tokens_entrada), 0) + COALESCE(SUM(m.tokens_salida), 0) AS tokens, ROUND(AVG(CASE WHEN m.estado = ? THEN m.latencia_ms END)) AS latencia',
                [$desdeHoy, AgenteMensaje::COMPLETADO, AgenteMensaje::COMPLETADO, AgenteMensaje::FALLIDO, AgenteMensaje::COMPLETADO])
            ->orderByDesc('semana')
            ->limit(20)
            ->get()
            ->all();

        return $filas;
    }

    private function modeloInicial(GestorProveedores $gestor): string
    {
        foreach (array_keys($gestor->motores()) as $id) {
            if ($id !== GestorProveedores::DEMO) {
                return $gestor->modelo($id);
            }
        }

        return '';
    }
}
