<?php

namespace App\Domain\AgenteMozi\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\AgenteArtefacto;
use App\Models\AgenteConversacion;
use App\Models\AgenteDataset;
use App\Models\AgenteMensaje;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * El autor elimina una charla con Agente Mozi: se borran sus artefactos, datasets (las descargas y vistas
 * dejan de responder), el texto y las acciones de cada mensaje, y la charla sale del historial. No se borran las filas
 * de los mensajes: guardan proveedor, tokens, estado y fecha, con los que se calcula la cuota diaria de
 * la empresa (MedidorUso); borrarlas permitiría reiniciarla. La auditoría no guarda contenido.
 */
final class EliminarConversacion
{
    public function __construct(
        private readonly Auditor $auditor,
    ) {}

    public function ejecutar(User $actor, int $conversacionId): void
    {
        Gate::forUser($actor)->authorize('usar-agente-mozi');

        DB::transaction(function () use ($actor, $conversacionId) {
            // Bloqueada: un turno que termina al mismo tiempo (EnviarMensaje::guardar) espera y ve la charla eliminada.
            $conversacion = AgenteConversacion::query()->lockForUpdate()->findOrFail($conversacionId);
            Gate::forUser($actor)->authorize('delete', $conversacion);

            if ($conversacion->eliminada()) {
                return;
            }

            $artefactos = AgenteArtefacto::query()->where('conversacion_id', $conversacion->id)->delete();
            $datasets = AgenteDataset::query()->where('conversacion_id', $conversacion->id)->delete();
            $mensajes = AgenteMensaje::query()->where('conversacion_id', $conversacion->id)->update(['texto' => '', 'acciones' => null]);

            $conversacion->titulo = '';
            $conversacion->eliminada_en = now();
            $conversacion->save();

            $this->auditor->registrar('agente_mozi.conversacion_eliminada', $conversacion, $actor, $conversacion->empresa_id,
                null, ['mensajes' => $mensajes, 'datasets' => $datasets, 'artefactos' => $artefactos]);
        });
    }
}
