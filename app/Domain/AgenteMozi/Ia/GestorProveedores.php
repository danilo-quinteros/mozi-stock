<?php

namespace App\Domain\AgenteMozi\Ia;

use App\Domain\AgenteMozi\Ia\Contratos\ProveedorIa;
use App\Domain\AgenteMozi\Ia\Proveedores\GeminiProveedor;
use App\Domain\AgenteMozi\Ia\Proveedores\OpenAiProveedor;
use App\Models\AjusteGlobal;
use InvalidArgumentException;

/**
 * AiProviderManager de § 4.2: resuelve el motor activo, el modelo y las cuotas a partir de los
 * ajustes del superadministrador (ajustes_globales) y, si no los hay, de config/agente_mozi.php.
 * Los nombres de modelos y endpoints viven en configuración, nunca en el dominio.
 */
final class GestorProveedores
{
    public const DEMO = 'demo';

    private ?AjusteGlobal $ajustes = null;

    /**
     * Motores disponibles: el demo sintético y los proveedores con adaptador.
     *
     * @return array<string, string> id => nombre
     */
    public function motores(): array
    {
        $motores = [self::DEMO => __('Demostración (datos sintéticos, sin IA)')];

        foreach (array_keys((array) config('agente_mozi.proveedores')) as $id) {
            $motores[(string) $id] = $this->proveedor((string) $id)->nombre();
        }

        return $motores;
    }

    public function motor(): string
    {
        $motor = $this->ajustes()->agente_motor ?? (string) config('agente_mozi.motor');

        return array_key_exists($motor, $this->motores()) ? $motor : self::DEMO;
    }

    public function proveedor(string $id): ProveedorIa
    {
        return match ($id) {
            GeminiProveedor::ID => new GeminiProveedor(
                (array) config('agente_mozi.proveedores.gemini'),
                (int) config('agente_mozi.ia.reintentos'),
            ),
            OpenAiProveedor::ID => new OpenAiProveedor(
                (array) config('agente_mozi.proveedores.openai'),
                (int) config('agente_mozi.ia.reintentos'),
            ),
            default => throw new InvalidArgumentException("Proveedor de IA desconocido: {$id}."),
        };
    }

    /**
     * Modelo elegido por el superadministrador si sigue habilitado; si no, el predeterminado del proveedor.
     */
    public function modelo(string $id): string
    {
        $modelos = $this->proveedor($id)->modelos();

        foreach ([$this->ajustes()->agente_modelo, config("agente_mozi.proveedores.{$id}.modelo")] as $candidato) {
            if (is_string($candidato) && in_array($candidato, $modelos, true)) {
                return $candidato;
            }
        }

        return $modelos[0] ?? throw new InvalidArgumentException("El proveedor {$id} no tiene modelos habilitados.");
    }

    /**
     * @return array{mensajes: int, tokens: int}
     */
    public function cuotas(): array
    {
        return [
            'mensajes' => $this->ajustes()->agente_mensajes_diarios ?? (int) config('agente_mozi.cuotas.mensajes_diarios'),
            'tokens' => $this->ajustes()->agente_tokens_diarios ?? (int) config('agente_mozi.cuotas.tokens_diarios'),
        ];
    }

    /** El proveedor puede escribir código de vistas sandbox_web (dos banderas del servidor). */
    public function sandboxIa(): bool
    {
        return (bool) config('agente_mozi.sandbox_web') && (bool) config('agente_mozi.sandbox_ia');
    }

    private function ajustes(): AjusteGlobal
    {
        return $this->ajustes ??= AjusteGlobal::actual();
    }
}
