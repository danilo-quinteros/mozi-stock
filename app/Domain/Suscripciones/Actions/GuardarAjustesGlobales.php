<?php

namespace App\Domain\Suscripciones\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\AjusteGlobal;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Datos de cobro de MoziStudios: receptor, correo de contacto (responde los avisos) y QR,
 * que se guarda en el disco privado con nombre aleatorio. Solo el superadministrador.
 */
final class GuardarAjustesGlobales
{
    public function __construct(private readonly Auditor $auditor) {}

    public function ejecutar(User $actor, ?string $receptor, ?string $correo, ?UploadedFile $qr, bool $quitarQr): AjusteGlobal
    {
        Gate::forUser($actor)->authorize('configurar', Suscripcion::class);

        $ajustes = AjusteGlobal::actual();
        $antes = $this->resumen($ajustes);
        $qrAnterior = $ajustes->qr_path;
        $ajustes->receptor_nombre = filled($receptor) ? trim((string) $receptor) : null;
        $ajustes->correo_contacto = filled($correo) ? Str::lower(trim((string) $correo)) : null;
        $qrNuevo = null;

        if ($qr !== null) {
            $qrNuevo = $qr->storeAs('mozistudios', 'qr-'.Str::random(32).'.'.$qr->extension(), AjusteGlobal::DISCO);

            if ($qrNuevo === false) {
                throw ValidationException::withMessages(['qr' => __('No se pudo guardar el QR.')]);
            }

            $ajustes->qr_path = $qrNuevo;
        } elseif ($quitarQr) {
            $ajustes->qr_path = null;
        }

        try {
            DB::transaction(function () use ($actor, $ajustes, $antes): void {
                $ajustes->save();
                $this->auditor->registrar('mozistudios.ajustes_actualizados', $ajustes, $actor, null, $antes, $this->resumen($ajustes));
            });
        } catch (Throwable $e) {
            if (is_string($qrNuevo)) {
                Storage::disk(AjusteGlobal::DISCO)->delete($qrNuevo);
            }

            throw $e;
        }

        if (is_string($qrAnterior) && $qrAnterior !== $ajustes->qr_path) {
            Storage::disk(AjusteGlobal::DISCO)->delete($qrAnterior);
        }

        return $ajustes;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(AjusteGlobal $ajustes): array
    {
        return ['receptor_nombre' => $ajustes->receptor_nombre, 'correo_contacto' => $ajustes->correo_contacto, 'qr' => $ajustes->qr_path !== null];
    }
}
