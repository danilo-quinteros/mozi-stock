<?php

namespace App\Console\Commands;

use App\Domain\Auditoria\Auditor;
use App\Enums\Rol;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Aprovisiona al superadministrador de MoziStudios desde el servidor. No existe
 * ninguna ruta web para concederse este rol.
 */
class CreateSuperadmin extends Command
{
    protected $signature = 'mozistock:superadmin';

    protected $description = 'Crea el superadministrador de MoziStudios o concede el rol a una cuenta sin empresa';

    public function handle(Auditor $auditor): int
    {
        if (config('mozistock.deployment_mode') !== 'saas') {
            $this->error('El modo standalone no utiliza superadministrador.');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->ask('Correo electrónico')));
        $existente = User::query()->where('email', $email)->first();

        if ($existente !== null) {
            if ($existente->empresa_id !== null) {
                $this->error('Esa cuenta pertenece a una empresa y no puede ser superadministrador.');

                return self::FAILURE;
            }

            if ($existente->esSuperadmin()) {
                $this->info('La cuenta ya es superadministrador.');

                return self::SUCCESS;
            }

            if (! $this->confirm("¿Conceder el rol de superadministrador a {$existente->name}?")) {
                return self::FAILURE;
            }

            DB::transaction(function () use ($existente, $auditor): void {
                $existente->rol = Rol::Superadmin;
                $existente->save();
                $auditor->registrar('usuario.rol_superadmin', $existente, null, null,
                    ['rol' => null], ['rol' => Rol::Superadmin->value]);
            });
            $this->info('Rol de superadministrador concedido.');

            return self::SUCCESS;
        }

        $name = $this->ask('Nombre');
        $password = $this->secret('Contraseña (mínimo 12 caracteres, letras y números)');
        $confirmation = $this->secret('Confirmar contraseña');
        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password, $auditor): void {
            $user = new User;
            $user->name = (string) $name;
            $user->email = $email;
            $user->password = (string) $password;
            $user->rol = Rol::Superadmin;
            $user->activo = true;
            $user->email_verified_at = Carbon::now();
            $user->save();
            $auditor->registrar('usuario.creado', $user, null, null,
                despues: ['name' => $user->name, 'email' => $user->email, 'rol' => Rol::Superadmin->value]);
        });

        $this->info('Superadministrador creado. Ya puedes iniciar sesión.');

        return self::SUCCESS;
    }
}
