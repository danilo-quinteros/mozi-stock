<?php

namespace App\Domain\AgenteMozi\Actions;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Contratos\Respondedor;
use App\Domain\AgenteMozi\DTOs\RespuestaAgente;
use App\Domain\AgenteMozi\Ia\DTOs\UsoIa;
use App\Domain\AgenteMozi\Lienzo\ArtefactoInvalido;
use App\Domain\AgenteMozi\Lienzo\ValidadorLienzo;
use App\Domain\AgenteMozi\RespuestaNoDisponible;
use App\Enums\TipoArtefacto;
use App\Models\AgenteArtefacto;
use App\Models\AgenteConversacion;
use App\Models\AgenteDataset;
use App\Models\AgenteMensaje;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Un turno de Agente Mozi: guarda la pregunta, pide la respuesta al Respondedor, valida cada dataset
 * y artefacto, y guarda el resultado con el uso del proveedor. La llamada al respondedor (API externa
 * con un proveedor de IA) queda fuera de transacciones. Repetir el envío con la misma clave devuelve
 * la misma respuesta; si el respondedor falla, la pregunta queda guardada con una respuesta «fallida».
 */
final class EnviarMensaje
{
    public function __construct(
        private readonly Respondedor $respondedor,
        private readonly ValidadorLienzo $validador,
    ) {}

    /**
     * @param  (Closure(string): void)|null  $avance  estados de trabajo para la interfaz mientras responde el motor
     */
    public function ejecutar(User $actor, ?int $conversacionId, string $texto, string $clave, ?Closure $avance = null): AgenteMensaje
    {
        Gate::forUser($actor)->authorize('usar-agente-mozi');

        // Texto plano: sin caracteres de control (salvo saltos de línea y tabulaciones).
        $texto = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto));
        Validator::make(['texto' => $texto, 'clave' => $clave], [
            'texto' => ['required', 'string', 'max:'.config('agente_mozi.limites.caracteres_mensaje')],
            'clave' => ['required', 'uuid'],
        ], [], ['texto' => __('mensaje')])->validate();

        $conversacion = $conversacionId === null ? null : $this->conversacion($actor, $conversacionId);

        // El bloqueo dura todo el turno (un proveedor de IA puede tardar): un reintento con la misma
        // clave espera y devuelve la misma respuesta en lugar de pedir otra.
        $duracion = (int) config('agente_mozi.ia.duracion_maxima') + 30;

        try {
            return Cache::lock('agente-mozi:envio:'.$actor->empresa_id.':'.$clave, $duracion)->block(20, function () use ($actor, $conversacion, $texto, $clave, $avance) {
                $pregunta = AgenteMensaje::query()->where('clave', $clave)->first();

                if ($pregunta !== null) {
                    $previa = $this->respuestaPrevia($actor, $pregunta);

                    if ($previa !== null) {
                        return $previa;
                    }
                } else {
                    $pregunta = $this->guardarPregunta($actor, $conversacion, $texto, $clave);
                }

                return $this->responder($actor, $pregunta, $avance);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['texto' => __('Tu mensaje anterior todavía se está procesando. Espera la respuesta antes de reenviarlo.')]);
        }
    }

    private function conversacion(User $actor, int $id): AgenteConversacion
    {
        $conversacion = AgenteConversacion::query()->findOrFail($id);
        Gate::forUser($actor)->authorize('view', $conversacion);

        $limite = (int) config('agente_mozi.limites.mensajes_por_conversacion');

        if ($conversacion->mensajes()->count() >= $limite) {
            throw ValidationException::withMessages(['texto' => __('Esta charla llegó a su límite de mensajes. Inicia una nueva charla.')]);
        }

        return $conversacion;
    }

    /**
     * La misma clave ya se envió: devuelve su respuesta si existe. Una clave ajena nunca se reutiliza.
     */
    private function respuestaPrevia(User $actor, AgenteMensaje $pregunta): ?AgenteMensaje
    {
        if ($pregunta->rol !== AgenteMensaje::ROL_USUARIO || ! Gate::forUser($actor)->allows('view', $pregunta->conversacion)) {
            throw ValidationException::withMessages(['texto' => __('No se pudo enviar el mensaje. Recarga la página e inténtalo de nuevo.')]);
        }

        return AgenteMensaje::query()->where('respuesta_de_id', $pregunta->id)->first();
    }

    private function guardarPregunta(User $actor, ?AgenteConversacion $conversacion, string $texto, string $clave): AgenteMensaje
    {
        return DB::transaction(function () use ($actor, $conversacion, $texto, $clave) {
            if ($conversacion === null) {
                $conversacion = new AgenteConversacion;
                $conversacion->empresa_id = (int) $actor->empresa_id;
                $conversacion->usuario_id = $actor->id;
                $conversacion->titulo = Str::limit(Str::squish($texto), 80);
            }

            $conversacion->ultima_actividad_en = now();
            $conversacion->save();

            $pregunta = new AgenteMensaje;
            $pregunta->empresa_id = $conversacion->empresa_id;
            $pregunta->conversacion_id = $conversacion->id;
            $pregunta->rol = AgenteMensaje::ROL_USUARIO;
            $pregunta->texto = $texto;
            $pregunta->estado = AgenteMensaje::COMPLETADO;
            $pregunta->clave = $clave;
            $pregunta->save();

            return $pregunta;
        });
    }

    private function responder(User $actor, AgenteMensaje $pregunta, ?Closure $avance): AgenteMensaje
    {
        $referencia = (string) Str::uuid();

        try {
            $respuesta = $this->respondedor->responder(ContextoAgente::desde($actor, $referencia), $pregunta->texto, $this->historial($pregunta), $avance);
        } catch (RespuestaNoDisponible $e) {
            Log::warning('agente-mozi.respuesta-no-disponible', [
                'referencia' => $referencia, 'empresa_id' => $actor->empresa_id, 'mensaje_id' => $pregunta->id, 'proveedor' => $e->proveedor,
                'causa' => $e->getPrevious() === null ? null : $e->getPrevious()::class,
            ]);

            return $this->guardar($pregunta, $referencia, $e->getMessage(), AgenteMensaje::FALLIDO, $e->proveedor, $e->modelo, $e->uso, [], []);
        } catch (Throwable $e) {
            Log::warning('agente-mozi.respuesta-fallida', [
                'referencia' => $referencia, 'empresa_id' => $actor->empresa_id, 'mensaje_id' => $pregunta->id, 'error' => $e::class,
            ]);

            return $this->guardar($pregunta, $referencia, __('No pude completar la respuesta. Tu pregunta quedó guardada: vuelve a enviarla en un momento.'), AgenteMensaje::FALLIDO, null, null, null, [], []);
        }

        [$datasets, $artefactos] = $this->validar($respuesta, $referencia, $actor);

        return $this->guardar($pregunta, $referencia, Str::limit($respuesta->texto, 10000), AgenteMensaje::COMPLETADO, $respuesta->proveedor, $respuesta->modelo, $respuesta->uso, $datasets, $artefactos);
    }

    /**
     * Mensajes completados anteriores de la misma charla (solo texto), del más antiguo al más reciente.
     *
     * @return list<array{rol: string, texto: string}>
     */
    private function historial(AgenteMensaje $pregunta): array
    {
        $mensajes = AgenteMensaje::query()
            ->where('conversacion_id', $pregunta->conversacion_id)
            ->where('id', '<', $pregunta->id)
            ->where('estado', AgenteMensaje::COMPLETADO)
            ->orderByDesc('id')
            ->limit((int) config('agente_mozi.ia.mensajes_de_historial'))
            ->get(['rol', 'texto'])
            ->reverse()
            ->map(fn (AgenteMensaje $mensaje) => ['rol' => $mensaje->esDelAgente() ? 'modelo' : 'usuario', 'texto' => $mensaje->texto])
            ->all();

        return array_values($mensajes);
    }

    /**
     * Valida fuera de la transacción. Un dataset o artefacto inválido se descarta y se registra:
     * nunca se reemplaza por datos inventados.
     *
     * @return array{0: array<string, array{columnas: list<array{field: string, label: string, type: string, nullable: bool}>, filas: list<array<string, string|int|null>>, meta: array<string, mixed>}>, 1: list<array{tipo: TipoArtefacto, dataset: string|null, titulo: string, spec: array<string, mixed>, meta: array<string, mixed>}>}
     */
    private function validar(RespuestaAgente $respuesta, string $referencia, User $actor): array
    {
        $datasets = [];

        foreach ($respuesta->datasets as $propuesto) {
            try {
                $datasets[$propuesto->clave] = $this->validador->dataset($propuesto);
            } catch (ArtefactoInvalido $e) {
                $this->descartado($referencia, $actor, 'dataset', $e);
            }
        }

        $artefactos = [];

        foreach (array_slice($respuesta->artefactos, 0, (int) config('agente_mozi.limites.artefactos_por_mensaje')) as $propuesto) {
            try {
                $dataset = $propuesto->dataset === null ? null
                    : ($datasets[$propuesto->dataset] ?? throw new ArtefactoInvalido("Dataset inexistente: {$propuesto->dataset}."));
                $artefactos[] = ['tipo' => $propuesto->tipo, 'dataset' => $propuesto->dataset] + $this->validador->artefacto($propuesto, $dataset);
            } catch (ArtefactoInvalido $e) {
                $this->descartado($referencia, $actor, $propuesto->tipo->value, $e);
            }
        }

        // Solo se guardan los datasets que sustentan algún artefacto válido.
        $usados = array_flip(array_filter(array_column($artefactos, 'dataset')));

        return [array_intersect_key($datasets, $usados), $artefactos];
    }

    /**
     * @param  array<string, array{columnas: list<array{field: string, label: string, type: string, nullable: bool}>, filas: list<array<string, string|int|null>>, meta: array<string, mixed>}>  $datasets
     * @param  list<array{tipo: TipoArtefacto, dataset: string|null, titulo: string, spec: array<string, mixed>, meta: array<string, mixed>}>  $artefactos
     */
    private function guardar(AgenteMensaje $pregunta, string $referencia, string $texto, string $estado, ?string $proveedor, ?string $modelo, ?UsoIa $uso, array $datasets, array $artefactos): AgenteMensaje
    {
        return DB::transaction(function () use ($pregunta, $referencia, $texto, $estado, $proveedor, $modelo, $uso, $datasets, $artefactos) {
            $conversacion = $pregunta->conversacion;

            $mensaje = new AgenteMensaje;
            $mensaje->empresa_id = $pregunta->empresa_id;
            $mensaje->conversacion_id = $pregunta->conversacion_id;
            $mensaje->rol = AgenteMensaje::ROL_AGENTE;
            $mensaje->texto = $texto;
            $mensaje->estado = $estado;
            $mensaje->respuesta_de_id = $pregunta->id;
            $mensaje->proveedor = $proveedor;
            $mensaje->modelo = $modelo === null ? null : Str::limit($modelo, 80, '');
            $mensaje->tokens_entrada = $uso?->tokensEntrada;
            $mensaje->tokens_salida = $uso?->tokensSalida;
            $mensaje->latencia_ms = $uso?->latenciaMs;
            $mensaje->herramientas_usadas = $uso?->herramientas;
            $mensaje->referencia = $referencia;
            $mensaje->save();

            $ids = [];

            foreach ($datasets as $clave => $datos) {
                $dataset = new AgenteDataset;
                $dataset->empresa_id = $mensaje->empresa_id;
                $dataset->conversacion_id = $mensaje->conversacion_id;
                $dataset->mensaje_id = $mensaje->id;
                $dataset->usuario_id = $conversacion->usuario_id;
                $dataset->ref = 'ds_'.Str::random(32);
                $dataset->columnas = $datos['columnas'];
                $dataset->filas = $datos['filas'];
                $dataset->total_filas = count($datos['filas']);
                $dataset->meta = $datos['meta'];
                $dataset->save();
                $ids[$clave] = $dataset->id;
            }

            foreach ($artefactos as $orden => $datos) {
                $artefacto = new AgenteArtefacto;
                $artefacto->empresa_id = $mensaje->empresa_id;
                $artefacto->conversacion_id = $mensaje->conversacion_id;
                $artefacto->mensaje_id = $mensaje->id;
                $artefacto->dataset_id = $datos['dataset'] === null ? null : $ids[$datos['dataset']];
                $artefacto->tipo = $datos['tipo'];
                $artefacto->titulo = $datos['titulo'];
                $artefacto->spec = $datos['spec'];
                $artefacto->meta = $datos['meta'];
                $artefacto->orden = $orden;
                $artefacto->version = ValidadorLienzo::VERSION;
                $artefacto->hash = hash('sha256', $datos['tipo']->value.json_encode($datos['spec'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $artefacto->save();
            }

            $conversacion->ultima_actividad_en = now();
            $conversacion->save();

            return $mensaje;
        });
    }

    private function descartado(string $referencia, User $actor, string $tipo, ArtefactoInvalido $e): void
    {
        Log::warning('agente-mozi.artefacto-descartado', [
            'referencia' => $referencia, 'empresa_id' => $actor->empresa_id, 'tipo' => $tipo, 'motivo' => $e->getMessage(),
        ]);
    }
}
