<?php

namespace App\Domain\Facturacion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Facturacion\CodigosSiat;
use App\Domain\Facturacion\DeEmpresa;
use App\Models\Empresa;
use App\Models\SiatConfiguracion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Configuracion\DatosToken;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use SensitiveParameter;

/**
 * Carga o reemplaza el token delegado de la empresa. Antes de guardarlo comprueba que sea del NIT
 * fiscal y del código de sistema de MoziStock y que no esté vencido. Se guarda cifrado; la auditoría
 * registra solo los últimos 4 caracteres y la fecha de vencimiento.
 */
final class GuardarTokenDelegado
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly ConfiguracionSistema $sistema,
    ) {}

    public function ejecutar(User $actor, Empresa $empresa, #[SensitiveParameter] string $token): SiatConfiguracion
    {
        Gate::forUser($actor)->authorize('configurar-facturacion', $empresa);

        try {
            $delegado = new TokenDelegado($token);
        } catch (DatosInvalidos) {
            throw ValidationException::withMessages(['token' => __('Pega el token completo, sin espacios ni saltos de línea.')]);
        }

        return DB::transaction(function () use ($actor, $empresa, $delegado): SiatConfiguracion {
            $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresa->id)->lockForUpdate()->first();

            if ($configuracion?->nit === null) {
                throw ValidationException::withMessages(['token' => __('Primero registra el NIT fiscal de la empresa.')]);
            }

            $this->validar($delegado, $configuracion->nit);
            $datos = DatosToken::leer($delegado);
            $antes = $configuracion->tieneToken()
                ? ['sufijo' => $configuracion->token_sufijo, 'vence_en' => $configuracion->token_vence_en?->toIso8601String()]
                : null;

            $configuracion->token = $delegado->valor();
            $configuracion->token_sufijo = mb_substr($delegado->valor(), -4);
            $configuracion->token_vence_en = $datos?->vence === null ? null : CodigosSiat::enZonaApp($datos->vence);
            $configuracion->token_actualizado_en = Carbon::now();
            $configuracion->token_actualizado_por = $actor->id;
            $configuracion->save();

            $this->auditor->registrar('siat.token_actualizado', $configuracion, $actor, $empresa->id, $antes, [
                'sufijo' => $configuracion->token_sufijo,
                'vence_en' => $configuracion->token_vence_en?->toIso8601String(),
            ]);

            return $configuracion;
        });
    }

    private function validar(TokenDelegado $token, int $nit): void
    {
        $datos = DatosToken::leer($token);

        if ($datos === null) {
            throw ValidationException::withMessages(['token' => __('No parece un token delegado del SIN. Cópialo completo desde el portal SIAT.')]);
        }

        if (! $datos->incluyeNit($nit)) {
            throw ValidationException::withMessages(['token' => __('El token no corresponde al NIT :nit.', ['nit' => $nit])]);
        }

        if ($datos->codigoSistema !== null && $datos->codigoSistema !== $this->sistema->codigoSistema) {
            throw ValidationException::withMessages(['token' => __('El token es de otro sistema. Genéralo en el portal SIAT para el sistema :sistema.', [
                'sistema' => $this->sistema->codigoSistema,
            ])]);
        }

        if ($datos->vencido(now())) {
            throw ValidationException::withMessages(['token' => __('El token ya venció. Genera uno nuevo en el portal SIAT.')]);
        }
    }
}
