<?php

namespace Database\Factories;

use App\Domain\Identidad\Actions\CrearRolesPorDefecto;
use App\Enums\TipoCuenta;
use App\Models\Empresa;
use App\Models\Rol;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    public function superadmin(): static
    {
        return $this->state(fn () => ['tipo' => TipoCuenta::Superadmin, 'empresa_id' => null, 'sucursal_id' => null]);
    }

    public function administrador(Empresa $empresa): static
    {
        return $this->state(fn () => ['tipo' => TipoCuenta::Administrador, 'empresa_id' => $empresa->id, 'sucursal_id' => null]);
    }

    /**
     * Usuario con el rol por defecto «Vendedor» de su empresa (ESPECIFICACION_PERMISOS.md § 5).
     */
    public function vendedor(Sucursal $sucursal): static
    {
        return $this->conPlantilla($sucursal, Rol::PLANTILLA_VENDEDOR);
    }

    /**
     * Usuario con el rol por defecto «Encargado de sucursal» de su empresa.
     */
    public function encargado(Sucursal $sucursal): static
    {
        return $this->conPlantilla($sucursal, Rol::PLANTILLA_ENCARGADO);
    }

    /**
     * Usuario con un rol cualquiera de la empresa de la sucursal.
     */
    public function conRol(Rol $rol, Sucursal $sucursal): static
    {
        return $this->state(fn () => [
            'tipo' => TipoCuenta::Usuario,
            'rol_id' => $rol->id,
            'empresa_id' => $sucursal->empresa_id,
            'sucursal_id' => $sucursal->id,
            'caja_id' => $sucursal->cajaPrincipal()?->id,
        ]);
    }

    private function conPlantilla(Sucursal $sucursal, string $plantilla): static
    {
        return $this->state(function () use ($sucursal, $plantilla): array {
            app(CrearRolesPorDefecto::class)->ejecutar((int) $sucursal->empresa_id);

            return [
                'tipo' => TipoCuenta::Usuario,
                'rol_id' => (int) DB::table('roles')->where('empresa_id', $sucursal->empresa_id)->where('plantilla', $plantilla)->value('id'),
                'empresa_id' => $sucursal->empresa_id,
                'sucursal_id' => $sucursal->id,
                'caja_id' => $sucursal->cajaPrincipal()?->id,
            ];
        });
    }

    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
