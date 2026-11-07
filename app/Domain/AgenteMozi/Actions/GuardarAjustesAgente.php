<?php

namespace App\Domain\AgenteMozi\Actions;

use App\Domain\AgenteMozi\Ia\GestorProveedores;
use App\Domain\Auditoria\Auditor;
use App\Models\AjusteGlobal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Motor, modelo y cuotas diarias de Agente Mozi (§ 4.4). Solo el superadministrador; cada cambio se
 * audita sin secretos. Las claves de API no se editan aquí: viven en el entorno del servidor.
 */
final class GuardarAjustesAgente
{
    public function __construct(
        private readonly GestorProveedores $gestor,
        private readonly Auditor $auditor,
    ) {}

    public function ejecutar(User $actor, string $motor, ?string $modelo, int|string|null $mensajesDiarios, int|string|null $tokensDiarios): AjusteGlobal
    {
        Gate::forUser($actor)->authorize('configurar-agente-mozi');

        $datos = Validator::make(compact('motor', 'modelo', 'mensajesDiarios', 'tokensDiarios'), [
            'motor' => ['required', 'string', Rule::in(array_keys($this->gestor->motores()))],
            'modelo' => ['nullable', 'string', 'max:80'],
            'mensajesDiarios' => ['required', 'integer', 'between:1,100000'],
            'tokensDiarios' => ['required', 'integer', 'between:1000,1000000000'],
        ], [], [
            'motor' => __('motor'), 'modelo' => __('modelo'), 'mensajesDiarios' => __('consultas diarias'), 'tokensDiarios' => __('tokens diarios'),
        ])->validate();

        if ($datos['motor'] !== GestorProveedores::DEMO) {
            $proveedor = $this->gestor->proveedor($datos['motor']);

            if (! $proveedor->configurado()) {
                throw ValidationException::withMessages(['motor' => __('Configura la clave de :proveedor en el servidor antes de activarlo.', ['proveedor' => $proveedor->nombre()])]);
            }

            if (! in_array($datos['modelo'], $proveedor->modelos(), true)) {
                throw ValidationException::withMessages(['modelo' => __('Elige uno de los modelos habilitados.')]);
            }
        }

        $ajustes = AjusteGlobal::actual();
        $antes = $this->resumen($ajustes);
        $ajustes->agente_motor = $datos['motor'];
        $ajustes->agente_modelo = $datos['motor'] === GestorProveedores::DEMO ? $ajustes->agente_modelo : $datos['modelo'];
        $ajustes->agente_mensajes_diarios = (int) $datos['mensajesDiarios'];
        $ajustes->agente_tokens_diarios = (int) $datos['tokensDiarios'];

        DB::transaction(function () use ($actor, $ajustes, $antes): void {
            $ajustes->save();
            $this->auditor->registrar('agente_mozi.ajustes_actualizados', $ajustes, $actor, null, $antes, $this->resumen($ajustes));
        });

        return $ajustes;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(AjusteGlobal $ajustes): array
    {
        return [
            'motor' => $ajustes->agente_motor,
            'modelo' => $ajustes->agente_modelo,
            'mensajes_diarios' => $ajustes->agente_mensajes_diarios,
            'tokens_diarios' => $ajustes->agente_tokens_diarios,
        ];
    }
}
