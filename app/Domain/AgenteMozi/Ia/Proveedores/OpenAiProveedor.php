<?php

namespace App\Domain\AgenteMozi\Ia\Proveedores;

use App\Domain\AgenteMozi\Ia\Contratos\ProveedorIa;
use App\Domain\AgenteMozi\Ia\DTOs\CapacidadesIa;
use App\Domain\AgenteMozi\Ia\DTOs\LlamadaHerramienta;
use App\Domain\AgenteMozi\Ia\DTOs\MensajeIa;
use App\Domain\AgenteMozi\Ia\DTOs\RespuestaIa;
use App\Domain\AgenteMozi\Ia\DTOs\ResultadoHerramienta;
use App\Domain\AgenteMozi\Ia\DTOs\SolicitudIa;
use App\Domain\AgenteMozi\Ia\DTOs\UsoIa;
use App\Domain\AgenteMozi\Ia\ErrorProveedorIa;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Adaptador de OpenAI sobre la API REST Responses (sin SDK), según la documentación del 03/11/2026;
 * todavía sin verificar en vivo (no hay clave). store=false: OpenAI no guarda el estado de la
 * conversación, así que cada petición lleva el historial completo. Los ítems de razonamiento que
 * acompañan a una llamada deben reenviarse con su contenido cifrado; por eso el turno del modelo
 * viaja en MensajeIa::$nativo sin modificar. La clave va en la cabecera Authorization, nunca en la
 * URL ni en los registros, y los mensajes de error de OpenAI no se copian (el de HTTP 401 repite
 * parte de la clave).
 */
final class OpenAiProveedor implements ProveedorIa
{
    public const ID = 'openai';

    /** Errores que justifican reintentar: tiempo agotado, límite por minuto y saturación del servicio. */
    private const TRANSITORIOS = [408, 429, 500, 502, 503, 504];

    /** HTTP 429 por saldo, gasto o cuota agotados: reintentar no devuelve el acceso. */
    private const CUOTA_AGOTADA = ['insufficient_quota', 'credit_balance_exhausted', 'organization_spend_limit_exceeded',
        'project_spend_limit_exceeded', 'organization_usage_limit_exceeded'];

    /** Espera máxima que se respeta de Retry-After dentro de un turno. */
    private const ESPERA_MAXIMA = 20;

    /**
     * @param  array<string, mixed>  $config  config('agente_mozi.proveedores.openai')
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
        return (string) ($this->config['nombre'] ?? 'OpenAI');
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
                return $this->interpretar($this->enviar('post', 'responses', $cuerpo), $solicitud->modelo);
            } catch (ErrorProveedorIa $e) {
                if (! $e->transitorio || $intento >= $this->reintentos) {
                    throw $e;
                }

                // Se respeta Retry-After si es corto; si no, 1 s y 2 s con variación.
                Sleep::for(max(min($e->espera ?? 0, self::ESPERA_MAXIMA) * 1000, 1000 * (2 ** $intento) + random_int(0, 400)))->milliseconds();
            }
        }
    }

    public function comprobar(string $modelo): string
    {
        $this->capacidades($modelo);
        $datos = $this->enviar('get', "models/{$modelo}")->json();

        return is_array($datos) && is_string($datos['id'] ?? null) && $datos['id'] !== $modelo
            ? $modelo.' · '.$datos['id']
            : $modelo;
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     */
    private function enviar(string $metodo, string $ruta, array $cuerpo = []): Response
    {
        if (! $this->configurado()) {
            throw new ErrorProveedorIa('Falta OPENAI_API_KEY en el servidor.', 'sin_credencial');
        }

        $url = rtrim((string) $this->config['url'], '/').'/'.$ruta;

        try {
            $respuesta = $metodo === 'get'
                ? $this->cliente()->get($url)
                : $this->cliente()->post($url, $cuerpo);
        } catch (ConnectionException $e) {
            throw new ErrorProveedorIa('Sin conexión con OpenAI o tiempo de espera agotado.', 'conexion', true, null, $e);
        }

        if ($respuesta->failed()) {
            $estado = $respuesta->status();
            $codigo = $respuesta->json('error.code');
            $tipo = $respuesta->json('error.type');
            $detalle = is_string($codigo) ? $codigo : (is_string($tipo) ? $tipo : null);
            $espera = $this->esperaSugerida($respuesta);

            if ($estado === 429 && (in_array($codigo, self::CUOTA_AGOTADA, true) || in_array($tipo, self::CUOTA_AGOTADA, true)
                || ($espera !== null && $espera > self::ESPERA_MAXIMA))) {
                throw new ErrorProveedorIa('OpenAI respondió HTTP 429 · '.($detalle ?? 'cuota agotada').'.', 'cuota_proveedor', false, $estado);
            }

            throw new ErrorProveedorIa(
                'OpenAI respondió HTTP '.$estado.($detalle === null ? '' : ' · '.$detalle).'.',
                'http_'.$estado,
                in_array($estado, self::TRANSITORIOS, true),
                $estado,
                espera: $espera,
            );
        }

        return $respuesta;
    }

    /** Segundos de la cabecera Retry-After, si la respuesta la trae en segundos. */
    private function esperaSugerida(Response $respuesta): ?int
    {
        $valor = trim($respuesta->header('Retry-After'));

        return preg_match('/^\d+$/', $valor) === 1 ? (int) $valor : null;
    }

    private function cliente(): PendingRequest
    {
        return Http::withToken((string) $this->config['api_key'])
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
        $cuerpo = array_filter([
            'model' => $solicitud->modelo,
            'instructions' => $solicitud->sistema,
            'input' => array_merge(...array_map($this->entrada(...), $solicitud->mensajes)),
            'max_output_tokens' => min($solicitud->maxTokens, $capacidades->salidaMaxima ?: $solicitud->maxTokens),
            'reasoning' => $capacidades->razonamiento === null ? null : ['effort' => $capacidades->razonamiento],
            'temperature' => $solicitud->temperatura,
            'store' => false,
            'include' => ['reasoning.encrypted_content'],
        ], fn ($valor) => $valor !== null);

        if ($solicitud->herramientas !== []) {
            $cuerpo['tools'] = array_map(fn ($herramienta) => [
                'type' => 'function',
                'name' => $herramienta->nombre,
                'description' => $herramienta->descripcion,
                'parameters' => EsquemaJson::objetos($herramienta->parametros === [] ? ['type' => 'object', 'properties' => []] : $herramienta->parametros),
                // Laravel valida los argumentos; el modo estricto exigiría reescribir los esquemas opcionales.
                'strict' => false,
            ], $solicitud->herramientas);

            $cuerpo['tool_choice'] = match (true) {
                ! $solicitud->herramientaObligatoria => 'auto',
                $solicitud->permitidas === null => 'required',
                count($solicitud->permitidas) === 1 => ['type' => 'function', 'name' => $solicitud->permitidas[0]],
                default => ['type' => 'allowed_tools', 'mode' => 'required', 'tools' => array_map(
                    fn (string $nombre) => ['type' => 'function', 'name' => $nombre], $solicitud->permitidas,
                )],
            };
        }

        return $cuerpo;
    }

    /**
     * Ítems de entrada de un turno: el del modelo puede ocupar varios (razonamiento, llamadas y texto).
     *
     * @return list<array<string, mixed>>
     */
    private function entrada(MensajeIa $mensaje): array
    {
        return match ($mensaje->rol) {
            MensajeIa::USUARIO => [['role' => 'user', 'content' => $mensaje->texto]],
            MensajeIa::MODELO => $mensaje->nativo !== null && $mensaje->origenNativo === self::ID
                ? array_values(array_filter((array) ($mensaje->nativo['output'] ?? []), 'is_array'))
                : [
                    ...($mensaje->texto === '' ? [] : [['role' => 'assistant', 'content' => $mensaje->texto]]),
                    ...array_map(fn (LlamadaHerramienta $llamada, int $i) => [
                        'type' => 'function_call', 'call_id' => $llamada->id ?? "call_{$i}", 'name' => $llamada->nombre,
                        'arguments' => json_encode((object) $llamada->argumentos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ], $mensaje->llamadas, array_keys($mensaje->llamadas)),
                ],
            default => array_map(fn (ResultadoHerramienta $resultado, int $i) => [
                'type' => 'function_call_output', 'call_id' => $resultado->id ?? "call_{$i}",
                'output' => json_encode($resultado->contenido, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ], $mensaje->resultados, array_keys($mensaje->resultados)),
        };
    }

    private function interpretar(Response $respuesta, string $modelo): RespuestaIa
    {
        $datos = (array) $respuesta->json();
        $salida = array_values(array_filter((array) ($datos['output'] ?? []), 'is_array'));
        $estado = $datos['status'] ?? null;
        $motivo = $datos['incomplete_details']['reason'] ?? null;

        if ($estado === 'failed' || $salida === []) {
            $causa = is_string($motivo) ? $motivo : (is_string($datos['error']['code'] ?? null) ? $datos['error']['code'] : (is_string($estado) ? $estado : 'sin_salida'));

            throw new ErrorProveedorIa('OpenAI no devolvió contenido ('.$causa.').', 'sin_contenido');
        }

        $texto = '';
        $llamadas = [];

        foreach ($salida as $item) {
            if (($item['type'] ?? null) === 'function_call' && is_string($item['name'] ?? null)) {
                $argumentos = is_string($item['arguments'] ?? null) ? json_decode($item['arguments'], true) : null;
                $llamadas[] = new LlamadaHerramienta(
                    is_string($item['call_id'] ?? null) ? $item['call_id'] : null,
                    $item['name'],
                    is_array($argumentos) ? $argumentos : [],
                );
            } elseif (($item['type'] ?? null) === 'message') {
                foreach ((array) ($item['content'] ?? []) as $parte) {
                    // Los ítems «reasoning» nunca se muestran al usuario (§ 6.5); una negativa del modelo sí.
                    $valor = is_array($parte) && in_array($parte['type'] ?? null, ['output_text', 'refusal'], true) ? ($parte['text'] ?? $parte['refusal'] ?? null) : null;
                    $texto .= is_string($valor) ? $valor : '';
                }
            }
        }

        $uso = (array) ($datos['usage'] ?? []);

        return new RespuestaIa(
            mensaje: new MensajeIa(MensajeIa::MODELO, trim($texto), $llamadas, nativo: ['output' => $salida], origenNativo: self::ID),
            // output_tokens ya incluye los tokens de razonamiento (output_tokens_details.reasoning_tokens).
            uso: new UsoIa(tokensEntrada: (int) ($uso['input_tokens'] ?? 0), tokensSalida: (int) ($uso['output_tokens'] ?? 0)),
            proveedor: self::ID,
            modelo: is_string($datos['model'] ?? null) ? $datos['model'] : $modelo,
            finalizacion: is_string($motivo) ? $motivo : (is_string($estado) ? $estado : null),
            referenciaProveedor: is_string($datos['id'] ?? null) ? $datos['id'] : null,
        );
    }
}
