<?php

namespace App\Console\Commands;

use App\Domain\Caja\Actions\CrearCajaPrincipal;
use App\Models\Caja;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Console\Command;

class AsegurarCajasPrincipales extends Command
{
    protected $signature = 'mozistock:cajas-principales';

    protected $description = 'Asegura que cada sucursal existente tenga su Caja principal.';

    public function handle(CrearCajaPrincipal $accion): int
    {
        $sucursales = Sucursal::query()->get();
        $creadas = 0;

        foreach ($sucursales as $sucursal) {
            $tiene = Caja::withoutGlobalScopes()
                ->where('empresa_id', $sucursal->empresa_id)
                ->where('sucursal_id', $sucursal->id)
                ->where('es_principal', true)
                ->exists();

            if (! $tiene) {
                // Obtener un usuario de la empresa o superadmin como actor
                $actor = User::query()->where('empresa_id', $sucursal->empresa_id)->first()
                    ?? User::query()->whereNull('empresa_id')->first();

                if ($actor !== null) {
                    $accion->ejecutar($actor, $sucursal);
                    $creadas++;
                    $this->line("Caja principal creada para: {$sucursal->nombre} (Empresa #{$sucursal->empresa_id})");
                }
            }
        }

        $this->info("Proceso completado. Cajas principales creadas: {$creadas}");

        return self::SUCCESS;
    }
}
