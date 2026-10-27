<?php

namespace App\Domain\Facturacion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Facturacion\DeEmpresa;
use App\Models\Empresa;
use App\Models\SiatCuis;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSucursal;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Asocia una sucursal de MoziStock con su código en el padrón del SIN, municipio y teléfono. Al
 * asociarla crea su punto de venta 0. El código queda fijo cuando ya se obtuvieron códigos del SIN.
 */
final class GuardarSucursalSiat
{
    private const CAMPOS = ['codigo', 'municipio', 'telefono'];

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @return array<string, list<string>>
     */
    public static function reglas(): array
    {
        return [
            'codigo' => ['required', 'integer', 'min:0', 'max:9999'],
            'municipio' => ['required', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:25', 'regex:/^[0-9 +()-]+$/'],
        ];
    }

    /**
     * @param  array{codigo: int|string, municipio: string, telefono?: string|null}  $datos
     */
    public function ejecutar(User $actor, Sucursal $sucursal, array $datos): SiatSucursal
    {
        $empresa = Empresa::query()->findOrFail($sucursal->empresa_id);
        Gate::forUser($actor)->authorize('configurar-facturacion', $empresa);

        return DB::transaction(function () use ($actor, $empresa, $sucursal, $datos): SiatSucursal {
            $registro = DeEmpresa::consulta(SiatSucursal::class, $empresa->id)->where('sucursal_id', $sucursal->id)->lockForUpdate()->first();
            $codigo = (int) $datos['codigo'];

            if ($registro === null && ! $sucursal->activa) {
                throw ValidationException::withMessages(['codigo' => __('Activa la sucursal antes de registrarla en el SIN.')]);
            }

            if (DeEmpresa::consulta(SiatSucursal::class, $empresa->id)->where('codigo', $codigo)
                ->when($registro !== null, fn ($q) => $q->whereKeyNot($registro?->id))->exists()) {
                throw ValidationException::withMessages(['codigo' => __('Otra sucursal de tu empresa ya usa el código SIN :codigo.', ['codigo' => $codigo])]);
            }

            if ($registro !== null && $registro->codigo !== $codigo && DeEmpresa::consulta(SiatCuis::class, $empresa->id)
                ->whereIn('siat_punto_venta_id', DeEmpresa::consulta(SiatPuntoVenta::class, $empresa->id)->select('id')->where('siat_sucursal_id', $registro->id))
                ->exists()) {
                throw ValidationException::withMessages(['codigo' => __('El código SIN no puede cambiar: esta sucursal ya obtuvo códigos del SIN.')]);
            }

            $nuevo = $registro === null;

            if ($registro === null) {
                $registro = new SiatSucursal;
                $registro->empresa_id = $empresa->id;
                $registro->sucursal_id = $sucursal->id;
            }

            $antes = $nuevo ? null : $registro->only(self::CAMPOS);
            $telefono = trim((string) ($datos['telefono'] ?? ''));
            $registro->codigo = $codigo;
            $registro->municipio = trim($datos['municipio']);
            $registro->telefono = $telefono === '' ? null : $telefono;

            if (! $registro->isDirty()) {
                return $registro;
            }

            $registro->save();

            if ($nuevo) {
                $punto = new SiatPuntoVenta;
                $punto->empresa_id = $empresa->id;
                $punto->siat_sucursal_id = $registro->id;
                $punto->codigo = 0;
                $punto->nombre = __('Sucursal (sin punto de venta)');
                $punto->save();
            }

            $this->auditor->registrar($nuevo ? 'siat.sucursal_registrada' : 'siat.sucursal_actualizada', $registro, $actor, $empresa->id,
                $antes, $registro->only(self::CAMPOS));

            return $registro;
        });
    }
}
