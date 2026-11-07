<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro que el administrador puede anular una sola vez (anulado_at, anulado_por,
 * motivo_anulacion). Anular no borra: el registro queda en su historial como anulado.
 */
trait Anulable
{
    public function estaAnulado(): bool
    {
        return $this->anulado_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    /**
     * «Anulado el 31/10/2026 14:05 por Ana: motivo», o null si está vigente.
     */
    public function descripcionAnulacion(): ?string
    {
        if ($this->anulado_at === null) {
            return null;
        }

        return __('Anulado el :fecha por :name: :motivo', [
            'fecha' => $this->anulado_at->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i'),
            'name' => $this->anuladoPor->name ?? '—',
            'motivo' => $this->motivo_anulacion,
        ]);
    }

    /**
     * La única escritura admitida sobre un registro inmutable: anularlo, una vez.
     */
    protected function soloSeAnula(): bool
    {
        return $this->getOriginal('anulado_at') === null
            && array_diff(array_keys($this->getDirty()), ['anulado_at', 'anulado_por', 'motivo_anulacion']) === [];
    }
}
