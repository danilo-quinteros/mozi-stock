<?php

namespace App\Domain\AgenteMozi\Ia;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Contratos\Respondedor;
use App\Domain\AgenteMozi\DTOs\RespuestaAgente;
use App\Domain\AgenteMozi\Herramientas\RegistroHerramientas;
use App\Domain\AgenteMozi\Ia\Contratos\ProveedorIa;
use App\Domain\AgenteMozi\Ia\DTOs\DefinicionHerramienta;
use App\Domain\AgenteMozi\Ia\DTOs\LlamadaHerramienta;
use App\Domain\AgenteMozi\Ia\DTOs\MensajeIa;
use App\Domain\AgenteMozi\Ia\DTOs\RespuestaIa;
use App\Domain\AgenteMozi\Ia\DTOs\ResultadoHerramienta;
use App\Domain\AgenteMozi\Ia\DTOs\SolicitudIa;
use App\Domain\AgenteMozi\Ia\DTOs\UsoIa;
use App\Domain\AgenteMozi\RespuestaNoDisponible;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * AiOrchestrator de § 4.2 y § 5: arma el contexto y las herramientas autorizadas, pide al proveedor,
 * ejecuta en Laravel las llamadas validadas y termina cuando el modelo llama a «responder». Topes por
 * turno: rondas, herramientas, tokens y duración; al llegar al tope solo se permite «responder».
 * Ninguna herramienta de esta etapa tiene efectos, así que reintentar un turno no duplica acciones.
 */
final class Orquestador implements Respondedor
{
    public function __construct(
        private readonly GestorProveedores $gestor,
        private readonly RegistroHerramientas $registro,
        private readonly ProtocoloRespuesta $protocolo,
        private readonly InstruccionesSistema $instrucciones,
        private readonly MedidorUso $medidor,
    ) {}

    public function responder(ContextoAgente $contexto, string $pregunta, array $historial = [], ?Closure $avance = null): RespuestaAgente
    {
        $id = $this->gestor->motor();
        $proveedor = $this->gestor->proveedor($id);
        $modelo = $this->gestor->modelo($id);

        if (! $proveedor->configurado()) {
            throw new RespuestaNoDisponible(__('Agente Mozi no tiene un proveedor de IA configurado. Avisa al administrador del sistema.'), $id, $modelo);
        }

        if (! $proveedor->capacidades($modelo)->herramientas) {
            throw new RespuestaNoDisponible(__('El modelo de IA configurado no admite herramientas. Avisa al administrador del sistema.'), $id, $modelo);
        }

        $this->medidor->verificar($contexto, $id);

        $usuario = User::query()->whereKey($contexto->usuarioId)->where('empresa_id', $contexto->empresaId)->firstOrFail();
        $limites = (array) config('agente_mozi.ia');
        $duracion = (int) $limites['duracion_maxima'];
        // Solo amplía un límite existente: en consola (0 = sin límite) no se impone uno nuevo.
        if ((int) ini_get('max_execution_time') > 0) {
            @set_time_limit($duracion + 30);
        }

        $sandbox = $this->gestor->sandboxIa();
        $disponibles = $this->registro->disponibles($usuario);
        $definiciones = [...array_map(fn ($herramienta) => new DefinicionHerramienta($herramienta->nombre(), $herramienta->descripcion(), $herramienta->parametros()), array_values($disponibles)), $this->protocolo->definicion($sandbox)];
        $sistema = $this->instrucciones->texto($contexto, $sandbox);
        $mensajes = [...$this->historial($historial), MensajeIa::usuario($pregunta)];

        $inicio = hrtime(true);
        $uso = new UsoIa;
        $datasets = [];
        $ejecutadas = 0;
        $ultimoModelo = $modelo;

        for ($ronda = 0; ; $ronda++) {
            $agotado = $ronda >= (int) $limites['rondas_herramientas'] || $ejecutadas >= (int) $limites['herramientas_por_turno']
                || $this->segundos($inicio) >= $duracion * 0.7;
            $avance?->__invoke($ronda === 0 ? __('Analizando tu consulta…') : __('Preparando el Lienzo…'));

            $respuesta = $this->pedir($proveedor, new SolicitudIa(
                modelo: $modelo,
                sistema: $sistema,
                mensajes: $mensajes,
                herramientas: $definiciones,
                herramientaObligatoria: true,
                permitidas: $agotado ? [ProtocoloRespuesta::HERRAMIENTA] : null,
                maxTokens: (int) $limites['tokens_salida'],
                referencia: $contexto->referencia,
            ), $id, $modelo, $uso, $ejecutadas, $inicio);

            $uso = $uso->mas($respuesta->uso);
            $ultimoModelo = $respuesta->modelo;
            $mensajes[] = $respuesta->mensaje;
            $llamadas = $respuesta->mensaje->llamadas;
            $final = array_values(array_filter($llamadas, fn (LlamadaHerramienta $llamada) => $llamada->nombre === ProtocoloRespuesta::HERRAMIENTA))[0] ?? null;

            if ($final !== null || $llamadas === [] || $agotado) {
                $uso = $uso->cerrar($this->milisegundos($inicio), $ejecutadas);

                if ($final === null) {
                    // Sin «responder»: se usa el texto del modelo, sin artefactos (no hay datos validados que mostrar).
                    return $this->protocolo->respuesta(['message' => $respuesta->mensaje->texto], [], $id, $ultimoModelo, $uso, $sandbox);
                }

                return $this->protocolo->respuesta($final->argumentos, $datasets, $id, $ultimoModelo, $uso, $sandbox);
            }

            $resultados = [];

            foreach ($llamadas as $llamada) {
                if ($ejecutadas >= (int) $limites['herramientas_por_turno']) {
                    $resultados[] = new ResultadoHerramienta($llamada->id, $llamada->nombre, ['error' => 'Se alcanzó el límite de herramientas de este turno. Llama a responder con lo obtenido.']);

                    continue;
                }

                $herramienta = $disponibles[$llamada->nombre] ?? null;

                if ($herramienta !== null) {
                    $avance?->__invoke($herramienta->avance());
                }

                $ejecutadas++;
                $salida = $this->registro->ejecutar($llamada, $disponibles, $usuario, $contexto);

                if (isset($salida['error'])) {
                    Log::info('agente-mozi.herramienta-rechazada', ['referencia' => $contexto->referencia, 'empresa_id' => $contexto->empresaId, 'herramienta' => $llamada->nombre, 'motivo' => $salida['error']]);
                    $resultados[] = new ResultadoHerramienta($llamada->id, $llamada->nombre, ['error' => $salida['error']]);

                    continue;
                }

                $entregados = [];

                foreach ($salida['datasets'] as ['dataset' => $dataset, 'descripcion' => $descripcion]) {
                    $clave = 'd'.(count($datasets) + 1);
                    $datasets[$clave] = $dataset;
                    $entregados[] = $this->protocolo->paraModelo($clave, $dataset, $descripcion);
                }

                $resultados[] = new ResultadoHerramienta($llamada->id, $llamada->nombre, ['datasets' => $entregados]);
            }

            $mensajes[] = MensajeIa::resultados($resultados);
        }
    }

    /**
     * Conversación previa (solo textos): permite preguntas de seguimiento sin reenviar datasets.
     *
     * @param  list<array{rol: string, texto: string}>  $historial
     * @return list<MensajeIa>
     */
    private function historial(array $historial): array
    {
        $limite = (int) config('agente_mozi.ia.mensajes_de_historial');

        return array_map(
            fn (array $mensaje) => $mensaje['rol'] === MensajeIa::USUARIO ? MensajeIa::usuario($mensaje['texto']) : MensajeIa::modelo($mensaje['texto']),
            array_slice($historial, -$limite),
        );
    }

    private function pedir(ProveedorIa $proveedor, SolicitudIa $solicitud, string $id, string $modelo, UsoIa $uso, int $ejecutadas, int $inicio): RespuestaIa
    {
        try {
            return $proveedor->generar($solicitud);
        } catch (ErrorProveedorIa $e) {
            Log::warning('agente-mozi.proveedor-fallido', [
                'referencia' => $solicitud->referencia, 'proveedor' => $id, 'modelo' => $modelo, 'codigo' => $e->codigo, 'estado' => $e->estadoHttp,
            ]);

            $mensaje = match (true) {
                $e->codigo === 'cuota_proveedor' => __('El servicio de IA alcanzó su límite de uso por hoy. Vuelve a intentarlo más tarde; si se repite, avisa al administrador del sistema.'),
                $e->estadoHttp === 429 => __('El servicio de IA está recibiendo demasiadas consultas. Espera un momento y vuelve a enviar tu pregunta.'),
                default => __('El servicio de IA no respondió a tiempo. Tu pregunta quedó guardada: vuelve a enviarla en un momento.'),
            };

            throw new RespuestaNoDisponible($mensaje, $id, $modelo, $uso->cerrar($this->milisegundos($inicio), $ejecutadas), $e);
        }
    }

    private function segundos(int $inicio): float
    {
        return (hrtime(true) - $inicio) / 1e9;
    }

    private function milisegundos(int $inicio): int
    {
        return (int) round((hrtime(true) - $inicio) / 1e6);
    }
}
