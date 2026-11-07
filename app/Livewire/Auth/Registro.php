<?php

namespace App\Livewire\Auth;

use App\Domain\SitioWeb\PlanesSitio;
use App\Domain\Suscripciones\CalendarioSuscripcion;
use App\Domain\Tenancy\Actions\RegistrarEmpresaPrueba;
use App\Enums\Rubro;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Registro público en tres pasos (tus datos, tu negocio, tu plan) con prueba gratis. Cada paso
 * se valida en el servidor al avanzar y todo se vuelve a validar al crear la cuenta.
 */
#[Title('Prueba gratis')]
final class Registro extends Component
{
    public const PASOS = 3;

    /** Registros permitidos por IP y hora, para frenar altas automáticas. */
    public const MAX_POR_HORA = 5;

    #[Locked]
    public int $paso = 1;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $empresa = '';

    public string $telefono = '';

    public string $nit = '';

    /** Tipo de negocio (paso 2). Obligatorio y sin valor por defecto: la persona debe elegirlo. */
    public string $rubro = '';

    #[Url(as: 'plan', except: '')]
    public string $plan = '';

    public bool $acepta = false;

    /** Campo trampa: invisible para personas, los bots lo llenan. */
    public string $sitio_web = '';

    public function mount(): void
    {
        $planes = PlanesSitio::activos();

        if ($planes->firstWhere('codigo', $this->plan) === null) {
            $this->plan = (string) ($planes->first()['codigo'] ?? '');
        }
    }

    public function avanzar(RegistrarEmpresaPrueba $accion): void
    {
        $this->normalizar();

        if ($this->paso < self::PASOS) {
            $this->validarPaso($this->paso);
            $this->paso++;

            return;
        }

        $this->registrar($accion);
    }

    public function volver(): void
    {
        $this->paso = max(1, $this->paso - 1);
        $this->resetValidation();
    }

    /** Solo permite volver a pasos ya completados. */
    public function irA(int $paso): void
    {
        if ($paso >= 1 && $paso < $this->paso) {
            $this->paso = $paso;
            $this->resetValidation();
        }
    }

    private function registrar(RegistrarEmpresaPrueba $accion): void
    {
        $clave = 'registro:'.request()->ip();

        if ($this->sitio_web !== '' || RateLimiter::tooManyAttempts($clave, self::MAX_POR_HORA)) {
            throw ValidationException::withMessages(['acepta' => __('No pudimos crear la cuenta en este momento. Intenta más tarde o escríbenos por WhatsApp.')]);
        }

        // Todo se valida otra vez: los pasos anteriores pudieron cambiar en el navegador.
        foreach (range(1, self::PASOS) as $paso) {
            try {
                $this->validarPaso($paso);
            } catch (ValidationException $e) {
                $this->paso = $paso;

                throw $e;
            }
        }

        RateLimiter::hit($clave, 3600);

        try {
            $usuario = $accion->ejecutar(
                ['nombre' => $this->empresa, 'rubro' => Rubro::from($this->rubro), 'nit' => $this->nit !== '' ? $this->nit : null, 'telefono' => $this->telefono],
                ['name' => $this->name, 'email' => $this->email, 'password' => $this->password],
                $this->plan,
            );
        } catch (ValidationException $e) {
            $this->paso = array_key_exists('email', $e->errors()) ? 1 : self::PASOS;

            throw $e;
        }

        event(new Registered($usuario));
        Auth::login($usuario);
        session()->regenerate();

        $this->redirectRoute('verification.notice');
    }

    private function normalizar(): void
    {
        $this->name = trim(preg_replace('/\s+/u', ' ', $this->name) ?? '');
        $this->email = Str::lower(trim($this->email));
        $this->empresa = trim(preg_replace('/\s+/u', ' ', $this->empresa) ?? '');
        $this->telefono = preg_replace('/\D+/', '', $this->telefono) ?? '';
        $this->nit = preg_replace('/\D+/', '', $this->nit) ?? '';
    }

    private function validarPaso(int $paso): void
    {
        $reglas = match ($paso) {
            1 => [
                'name' => ['required', 'string', 'min:3', 'max:120'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
                'password' => ['required', 'string', Password::default()],
            ],
            2 => [
                'empresa' => ['required', 'string', 'min:2', 'max:150'],
                'rubro' => ['required', 'string', Rule::enum(Rubro::class)],
                'telefono' => ['required', 'regex:/^\d{7,15}$/'],
                'nit' => ['nullable', 'regex:/^\d{4,20}$/'],
            ],
            default => [
                'plan' => ['required', Rule::exists('planes', 'codigo')->where('activo', true)],
                'acepta' => ['accepted'],
            ],
        };

        $this->validate($reglas, [
            'email.unique' => __('Ya existe una cuenta con este correo. Inicia sesión o recupera tu contraseña.'),
            'telefono.regex' => __('Escribe tu número de celular, solo números (ej. 71234567).'),
            'nit.regex' => __('El NIT solo lleva números.'),
            'rubro.required' => __('Elige qué tipo de negocio tienes.'),
            'rubro.enum' => __('Elige qué tipo de negocio tienes.'),
            'plan.required' => __('Elige uno de los planes disponibles.'),
            'plan.exists' => __('Elige uno de los planes disponibles.'),
            'acepta.accepted' => __('Para crear tu cuenta debes aceptar los términos y la política de privacidad.'),
        ], [
            'name' => __('nombre'),
            'email' => __('correo electrónico'),
            'password' => __('contraseña'),
            'empresa' => __('nombre del negocio'),
            'telefono' => __('celular'),
            'nit' => __('NIT'),
        ]);
    }

    /** Texto con las exigencias reales de la contraseña (más estrictas en producción). */
    private function requisitosClave(): string
    {
        $reglas = Password::default()->appliedRules();
        $partes = [__('Mínimo :n caracteres', ['n' => $reglas['min']])];

        if ($reglas['mixedCase']) {
            $partes[] = __('mayúsculas y minúsculas');
        }

        if ($reglas['numbers']) {
            $partes[] = __('un número');
        }

        if ($reglas['symbols']) {
            $partes[] = __('un símbolo');
        }

        return implode(', ', $partes).'.';
    }

    public function render(): View
    {
        $planes = PlanesSitio::activos();
        $elegido = $planes->firstWhere('codigo', $this->plan);

        return view('livewire.auth.registro', [
            'planes' => $planes,
            'rubros' => Rubro::cases(),
            'elegido' => $elegido,
            'requisitosClave' => $this->requisitosClave(),
            'dias' => (int) config('mozistock.prueba_gratis_dias'),
            'finPrueba' => CalendarioSuscripcion::hoy()->addDays((int) config('mozistock.prueba_gratis_dias')),
        ])->layout('layouts::auth', [
            'panel' => 'registro',
            // Precio más bajo comparando texto decimal (nunca float).
            'desde' => $planes->reduce(fn (?array $min, array $p): array => $min === null || Decimal::comparar($p['precio'], $min['precio'], 2) < 0 ? $p : $min)['entero'] ?? null,
        ]);
    }
}
