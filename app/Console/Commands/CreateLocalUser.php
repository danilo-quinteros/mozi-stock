<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateLocalUser extends Command
{
    protected $signature = 'mozistock:usuario-local';

    protected $description = 'Crea una cuenta de acceso local sin roles comerciales ni contraseña predeterminada';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Este comando solo está disponible en el entorno local.');

            return self::FAILURE;
        }

        $name = $this->ask('Nombre');
        $email = Str::lower(trim((string) $this->ask('Correo electrónico')));
        $password = $this->secret('Contraseña (mínimo 12 caracteres, letras y números)');
        $confirmation = $this->secret('Confirmar contraseña');
        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->name = (string) $name;
        $user->email = $email;
        $user->password = (string) $password;
        // Aprovisionamiento manual de una cuenta local. No concede roles ni tenant.
        $user->email_verified_at = Carbon::now();
        $user->save();

        $this->info('Cuenta local creada. Ya puedes iniciar sesión.');

        return self::SUCCESS;
    }
}
