<?php

namespace App\Domain\AgenteMozi\Ia\Proveedores;

use App\Domain\AgenteMozi\Ia\Contratos\ProveedorIa;
use App\Domain\AgenteMozi\Ia\DTOs\CapacidadesIa;
use App\Domain\AgenteMozi\Ia\DTOs\LlamadaHerramienta;
use App\Domain\AgenteMozi\Ia\DTOs\MensajeIa;
use App\Domain\AgenteMozi\Ia\DTOs\RespuestaIa;
use App\Domain\AgenteMozi\Ia\DTOs\SolicitudIa;
use App\Domain\AgenteMozi\Ia\DTOs\UsoIa;
use App\Domain\AgenteMozi\Ia\ErrorProveedorIa;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Adaptador de Google Gemini sobre la API REST generateContent (sin SDK). Comportamiento verificado
 * con la API el 30/10/2026: las llamadas a funciones de los modelos 3.x traen una firma de
 * razonamiento (thoughtSignature) que debe reenviarse idéntica en el turno siguiente o la API responde
 * 400; por eso el turno del modelo viaja en MensajeIa::$nativo sin modificar. La clave va en la
 * cabecera x-goog-api-key, nunca en la URL ni en los registros.
 */
final class GeminiProveedor implements ProveedorIa
{
    public const ID = 'gemini';

    /** Errores que justifican reintentar: tiempo agotado, límite de uso y saturación del servicio. */
    private const TRANSITORIOS = [408, 429, 500, 502, 503, 504];

    /**
     * @param  array<string, mixed>  $config  config('agente_mozi.proveedores.gemini')
     */
    public function __construct(
        private readonly array $config,
        private readonly int $reintentos = 2,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function nombre(): string
    {
        return (string) ($this->config['nombre'] ?? 'Google Gemini');
    }

    public function configurado(): bool
    {
        return trim((string) ($this->config['api_key'] ?? '')) !== '';
    }

    public function modelos(): array
    {
        return array_map('strval', array_keys((array) ($this->config['modelos'] ?? [])));
    }

    public function capacidades(string $modelo): CapacidadesIa
    {
        $config = $this->config['modelos'][$modelo] ?? null;

        if (! is_array($config)) {
            throw new ErrorProveedorIa("Modelo no habilitado: {$modelo}.", 'modelo_no_habilitado');
        }

        return CapacidadesIa::desde($config);
    }

    public function generar(SolicitudIa $solicitud): RespuestaIa
    {
        $capacidades = $this->capacidades($solicitud->modelo);
        $cuerpo = $this->cuerpo($solicitud, $capacidades);

        for ($intento = 0; ; $intento++) {
            try {
                return $this->interpretar($this->enviar('post', "models/{$solicitud->modelo}:generateContent", $cuerpo), $solicitud->modelo);
            } catch (ErrorProveedorIa $e) {
                if (! $e->transitorio || $intento >= $this->reintentos) {
                    throw $e;
                }

                // Verificado: con alta demanda Gemini responde 503 por unos segundos; 1 s y 2 s con variación.
                Sleep::for(1000 * (2 ** $intento) + random_int(0, 400))->milliseconds();
            }
        }
    }

    public function comprobar(string $modelo): string
    {
        $this->capacidades($modelo);
        $datos = $this->enviar('get', "models/{$modelo}")->json();

        return is_array($datos) && isset($datos['version'])
            ? $modelo.' · '.$datos['version']
            : $modelo;
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     */
    private function enviar(string $metodo, string $ruta, array $cuerpo = []): Response
    {
        if (! $this->configurado()) {
            throw new ErrorProveedorIa('Falta GEMINI_API_KEY en el servidor.', 'sin_credencial');
        }

        $url = rtrim((string) $this->config['url'], '/').'/'.$ruta;

        try {
            $respuesta = $metodo === 'get'
                ? $this->cliente()->get($url)
                : $this->cliente()->post($url, $cuerpo);
        } catch (ConnectionException $e) {
            throw new ErrorProveedorIa('Sin conexión con Gemini o tiempo de espera agotado.', 'conexion', true, null, $e);
        }

        if ($respuesta->failed()) {
            $estado = $respuesta->status();
            $error = $respuesta->json('error.status');
            $espera = $this->esperaSugerida($respuesta);

            // Verificado: al agotar la cuota diaria, Gemini responde 429 con RetryInfo de horas.
            // Reintentar en segundos no sirve; solo se reintenta una espera corta (límite por minuto).
            if ($estado === 429 && $espera !== null && $espera > 60) {
                throw new ErrorProveedorIa('Gemini respondió HTTP 429 · cuota agotada (reintentar en '.$espera.' s).', 'cuota_proveedor', false, $estado);
            }

            throw new ErrorProveedorIa(
                'Gemini respondió HTTP '.$estado.(is_string($error) ? ' · '.$error : '').'.',
                'http_'.$estado,
                in_array($estado, self::TRANSITORIOS, true),
                $estado,
            );
        }

        return $respuesta;
    }

    /** Segundos de google.rpc.RetryInfo.retryDelay («58771s»), si la respuesta los trae. */
    private function esperaSugerida(Response $respuesta): ?int
    {
        foreach ((array) $respuesta->json('error.details') as $detalle) {
            if (is_array($detalle) && is_string($detalle['retryDelay'] ?? null) && preg_match('/^(\d+)(?:\.\d+)?s$/', $detalle['retryDelay'], $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private function cliente(): PendingRequest
    {
        return Http::withHeaders(['x-goog-api-key' => (string) $this->config['api_key']])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(max(5, (int) ($this->config['timeout'] ?? 45)));
    }

    /**
     * @return array<string, mixed>
     */
    private function cuerpo(SolicitudIa $solicitud, CapacidadesIa $capacidades): array
    {
        $cuerpo = [
            'systemInstruction' => ['parts' => [['text' => $solicitud->sistema]]],
            'contents' => array_map($this->contenido(...), $solicitud->mensajes),
            'generationConfig' => array_filter([
                'temperature' => $solicitud->temperatura,
                'maxOutputTokens' => min($solicitud->maxTokens, $capacidades->salidaMaxima ?: $solicitud->maxTokens),
                'thinkingConfig' => $capacidades->razonamiento === null ? null : ['thinkingLevel' => $capacidades->razonamiento],
            ], fn ($valor) => $valor !== null),
        ];

        if ($solicitud->herramientas !== []) {
            $cuerpo['tools'] = [['functionDeclarations' => array_map(fn ($herramienta) => [
                'name' => $herramienta->nombre,
                'description' => $herramienta->descripcion,
                'parametersJsonSchema' => EsquemaJson::objetos($herramienta->parametros),
            ], $solicitud->herramientas)]];

            $cuerpo['toolConfig'] = ['functionCallingConfig' => array_filter([
                'mode' => $solicitud->herramientaObligatoria ? 'ANY' : 'AUTO',
                'allowedFunctionNames' => $solicitud->herramientaObligatoria ? $solicitud->permitidas : null,
            ], fn ($valor) => $valor !== null)];
        }

        return $cuerpo;
    }

    /**
     * @return array<string, mixed>
     */
    private function contenido(MensajeIa $mensaje): array
    {
        return match ($mensaje->rol) {
            MensajeIa::USUARIO => ['role' => 'user', 'parts' => [['text' => $mensaje->texto]]],
            MensajeIa::MODELO => $mensaje->nativo !== null && $mensaje->origenNativo === self::ID
                ? self::reenviable($mensaje->nativo)
                : ['role' => 'model', 'parts' => array_values(array_filter([
                    $mensaje->texto === '' ? null : ['text' => $mensaje->texto],
                    ...array_map(fn (LlamadaHerramienta $llamada) => ['functionCall' => array_filter([
                        'id' => $llamada->id, 'name' => $llamada->nombre, 'args' => (object) $llamada->argumentos,
                    ], fn ($valor) => $valor !== null)], $mensaje->llamadas),
                ]))],
            default => ['role' => 'user', 'parts' => array_map(fn ($resultado) => ['functionResponse' => array_filter([
                'id' => $resultado->id, 'name' => $resultado->nombre, 'response' => (object) $resultado->contenido,
            ], fn ($valor) => $valor !== null)], $mensaje->resultados)],
        };
    }

    private function interpretar(Response $respuesta, string $modelo): RespuestaIa
    {
        $datos = (array) $respuesta->json();
        $candidato = $datos['candidates'][0] ?? null;

        if (! is_array($candidato) || ! is_array($candidato['content']['parts'] ?? null)) {
            $motivo = $datos['promptFeedback']['blockReason'] ?? ($candidato['finishReason'] ?? 'SIN_CANDIDATOS');

            throw new ErrorProveedorIa('Gemini no devolvió contenido ('.(is_string($motivo) ? $motivo : 'desconocido').').', 'sin_contenido');
        }

        $texto = '';
        $llamadas = [];

        foreach ($candidato['content']['parts'] as $parte) {
            if (! is_array($parte)) {
                continue;
            }

            if (isset($parte['functionCall']['name']) && is_string($parte['functionCall']['name'])) {
                $argumentos = $parte['functionCall']['args'] ?? [];
                $llamadas[] = new LlamadaHerramienta(
                    isset($parte['functionCall']['id']) ? (string) $parte['functionCall']['id'] : null,
                    $parte['functionCall']['name'],
                    is_array($argumentos) ? $argumentos : [],
                );
            } elseif (isset($parte['text']) && is_string($parte['text']) && ($parte['thought'] ?? false) !== true) {
                // Las partes «thought» son razonamiento privado: nunca se muestran al usuario (§ 6.5).
                $texto .= $parte['text'];
            }
        }

        $uso = (array) ($datos['usageMetadata'] ?? []);

        return new RespuestaIa(
            mensaje: new MensajeIa(MensajeIa::MODELO, trim($texto), $llamadas, nativo: ['role' => 'model', 'parts' => $candidato['content']['parts']], origenNativo: self::ID),
            uso: new UsoIa(
                tokensEntrada: (int) ($uso['promptTokenCount'] ?? 0),
                tokensSalida: (int) ($uso['candidatesTokenCount'] ?? 0) + (int) ($uso['thoughtsTokenCount'] ?? 0),
            ),
            proveedor: self::ID,
            modelo: is_string($datos['modelVersion'] ?? null) ? $datos['modelVersion'] : $modelo,
            finalizacion: is_string($candidato['finishReason'] ?? null) ? $candidato['finishReason'] : null,
            referenciaProveedor: is_string($datos['responseId'] ?? null) ? $datos['responseId'] : null,
        );
    }

    /**
     * Al decodificar JSON como arreglo, {} se vuelve []: los argumentos de una llamada deben volver a
     * enviarse como objeto o la API rechaza el turno.
     *
     * @param  array<string, mixed>  $contenido
     * @return array<string, mixed>
     */
    private static function reenviable(array $contenido): array
    {
        foreach ($contenido['parts'] ?? [] as $i => $parte) {
            if (is_array($parte) && isset($parte['functionCall']) && is_array($parte['functionCall'])) {
                $contenido['parts'][$i]['functionCall']['args'] = (object) ($parte['functionCall']['args'] ?? []);
            }
        }

        return $contenido;
    }
}
