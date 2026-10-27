<?php

namespace App\Livewire\Admin;

use App\Domain\Suscripciones\Actions\GuardarAjustesGlobales;
use App\Jobs\EnviarAvisoSuscripcion;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\AjusteGlobal;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Configuración global (ajustes-globales.html): datos de cobro y QR de MoziStudios. Los
 * recordatorios y la regla de acceso manual son fijos por especificación.
 */
#[Title('Ajustes globales')]
class AjustesGlobales extends Component
{
    use MuestraAvisos, WithFileUploads;

    private const REGLAS_QR = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];

    public string $receptor_nombre = '';

    public string $correo_contacto = '';

    /** @var TemporaryUploadedFile|null */
    public $qr = null;

    public bool $quitarQr = false;

    public function mount(): void
    {
        $this->authorize('configurar', Suscripcion::class);
        $ajustes = AjusteGlobal::actual();
        $this->receptor_nombre = (string) $ajustes->receptor_nombre;
        $this->correo_contacto = (string) $ajustes->correo_contacto;
    }

    public function updatedQr(): void
    {
        try {
            $this->validate(['qr' => self::REGLAS_QR], ['qr.max' => __('El QR no puede superar 2 MB.')]);
            $this->quitarQr = false;
        } catch (ValidationException $e) {
            $this->qr = null;

            throw $e;
        }
    }

    public function quitar(): void
    {
        $this->qr = null;
        $this->quitarQr = true;
    }

    public function guardar(GuardarAjustesGlobales $accion): void
    {
        $this->validate([
            'receptor_nombre' => ['nullable', 'string', 'max:150'],
            'correo_contacto' => ['nullable', 'email', 'max:255'],
            'qr' => self::REGLAS_QR,
        ], ['qr.max' => __('El QR no puede superar 2 MB.')], ['receptor_nombre' => __('nombre del receptor'), 'correo_contacto' => __('correo de contacto'), 'qr' => 'QR']);

        /** @var User $actor */
        $actor = Auth::user();
        $accion->ejecutar($actor, $this->receptor_nombre, $this->correo_contacto, $this->qr, $this->quitarQr);
        $this->qr = null;
        $this->quitarQr = false;
        $this->avisar(__('Configuración guardada.'));
    }

    public function render(): View
    {
        return view('livewire.admin.ajustes-globales', [
            'ajustes' => AjusteGlobal::actual(),
            'correoConfigurado' => EnviarAvisoSuscripcion::correoConfigurado(),
            'remitente' => (string) config('mail.from.address'),
        ]);
    }
}
