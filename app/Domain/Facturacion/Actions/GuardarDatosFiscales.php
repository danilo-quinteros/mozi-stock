<?php

namespace App\Domain\Facturacion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Facturacion\CatalogoEmpresa;
use App\Domain\Facturacion\DeEmpresa;
use App\Enums\ModoFacturacion;
use App\Models\Empresa;
use App\Models\SiatCatalogo;
use App\Models\SiatConfiguracion;
use App\Models\SiatCuis;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MoziStudios\Siat\Configuracion\DatosToken;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * NIT fiscal, razón social, modo de facturación, método «electrónico» predeterminado y la actividad,
 * producto SIN y unidad de medida con que se facturan los productos (catálogos del SIN). Lo hace el
 * administrador de la empresa o el superadmin, nunca durante «Entrar como» (decisión D6).
 */
final class GuardarDatosFiscales
{
    private const CAMPOS = ['nit', 'razon_social', 'modo', 'metodo_pago_electronico', 'actividad_economica', 'producto_sin', 'unidad_medida'];

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function reglas(): array
    {
        return [
            'nit' => ['required', 'regex:/^[1-9]\d{3,12}$/'],
            'razon_social' => ['required', 'string', 'max:200'],
            'modo' => ['required', Rule::enum(ModoFacturacion::class)],
            'metodo_pago_electronico' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'actividad_economica' => ['nullable', 'string', 'max:20'],
            'producto_sin' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'unidad_medida' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }

    /**
     * @param  array{nit: int|string, razon_social: string, modo: string, metodo_pago_electronico?: int|string|null, actividad_economica?: string|null, producto_sin?: int|string|null, unidad_medida?: int|string|null}  $datos
     */
    public function ejecutar(User $actor, Empresa $empresa, array $datos): SiatConfiguracion
    {
        Gate::forUser($actor)->authorize('configurar-facturacion', $empresa);

        return DB::transaction(function () use ($actor, $empresa, $datos): SiatConfiguracion {
            $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresa->id)->lockForUpdate()->first();
            $nit = (int) $datos['nit'];
            $metodo = filled($datos['metodo_pago_electronico'] ?? null) ? (int) $datos['metodo_pago_electronico'] : null;

            if ($configuracion !== null && $configuracion->nit !== null && $configuracion->nit !== $nit) {
                $this->validarCambioDeNit($empresa, $configuracion, $nit);
            }

            if ($metodo !== null && ! DeEmpresa::consulta(SiatCatalogo::class, $empresa->id)
                ->where('catalogo', Catalogo::TiposMetodoPago->name)->where('codigo', (string) $metodo)->exists()) {
                throw ValidationException::withMessages([
                    'metodo_pago_electronico' => __('Elige un método de pago del catálogo sincronizado del SIN.'),
                ]);
            }

            [$actividad, $producto, $unidad] = $this->datosDeProducto($empresa, $datos);

            if ($configuracion === null) {
                $configuracion = new SiatConfiguracion;
                $configuracion->empresa_id = $empresa->id;
            }

            $antes = $configuracion->exists ? $this->resumen($configuracion) : null;
            $configuracion->nit = $nit;
            $configuracion->razon_social = trim($datos['razon_social']);
            $configuracion->modo = ModoFacturacion::from($datos['modo']);
            $configuracion->metodo_pago_electronico = $metodo;
            $configuracion->actividad_economica = $actividad;
            $configuracion->producto_sin = $producto;
            $configuracion->unidad_medida = $unidad;

            if ($configuracion->isDirty()) {
                $configuracion->save();
                $this->auditor->registrar('siat.datos_fiscales', $configuracion, $actor, $empresa->id, $antes, $this->resumen($configuracion));
            }

            return $configuracion;
        });
    }

    /**
     * Los códigos del SIN y el token quedan atados al NIT: después de obtener códigos no se cambia.
     */
    private function validarCambioDeNit(Empresa $empresa, SiatConfiguracion $configuracion, int $nit): void
    {
        if (DeEmpresa::consulta(SiatCuis::class, $empresa->id)->exists()) {
            throw ValidationException::withMessages([
                'nit' => __('El NIT no puede cambiar: la empresa ya obtuvo códigos del SIN con el NIT :nit.', ['nit' => $configuracion->nit]),
            ]);
        }

        $datosToken = $configuracion->tieneToken() ? DatosToken::leer(new TokenDelegado($configuracion->tokenDescifrado())) : null;

        if ($datosToken !== null && ! $datosToken->incluyeNit($nit)) {
            throw ValidationException::withMessages([
                'nit' => __('El token cargado es de otro NIT. Carga primero el token del NIT :nit.', ['nit' => $nit]),
            ]);
        }
    }

    /**
     * Actividad, producto SIN y unidad: opcionales (sin ellos la factura queda bloqueada con el motivo),
     * pero si se eligen deben existir en los catálogos sincronizados y el producto ser de esa actividad.
     *
     * @param  array{actividad_economica?: string|null, producto_sin?: int|string|null, unidad_medida?: int|string|null}  $datos
     * @return array{0: string|null, 1: int|null, 2: int|null}
     */
    private function datosDeProducto(Empresa $empresa, array $datos): array
    {
        $actividad = filled($datos['actividad_economica'] ?? null) ? trim((string) $datos['actividad_economica']) : null;
        $producto = filled($datos['producto_sin'] ?? null) ? (int) $datos['producto_sin'] : null;
        $unidad = filled($datos['unidad_medida'] ?? null) ? (int) $datos['unidad_medida'] : null;
        $errores = [];

        if ($actividad !== null && ! CatalogoEmpresa::existe($empresa->id, Catalogo::Actividades, $actividad)) {
            $errores['actividad_economica'] = __('Elige una actividad del catálogo sincronizado del SIN.');
        }

        if ($producto !== null) {
            $actividadesDelProducto = DeEmpresa::consulta(SiatCatalogo::class, $empresa->id)
                ->where('catalogo', Catalogo::ProductosServicios->name)->where('codigo', (string) $producto)
                ->get(['atributos'])->map(fn (SiatCatalogo $fila): string => (string) ($fila->atributos['codigoActividad'] ?? ''));

            if ($actividadesDelProducto->isEmpty()) {
                $errores['producto_sin'] = __('Elige un producto del catálogo sincronizado del SIN.');
            } elseif ($actividad !== null && ! $actividadesDelProducto->contains($actividad)) {
                $errores['producto_sin'] = __('El producto SIN elegido no corresponde a la actividad :actividad.', ['actividad' => $actividad]);
            }
        }

        if ($unidad !== null && ! CatalogoEmpresa::existe($empresa->id, Catalogo::UnidadesMedida, $unidad)) {
            $errores['unidad_medida'] = __('Elige una unidad de medida del catálogo sincronizado del SIN.');
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return [$actividad, $producto, $unidad];
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(SiatConfiguracion $configuracion): array
    {
        $datos = $configuracion->only(self::CAMPOS);
        $datos['modo'] = $configuracion->modo->value;

        return $datos;
    }
}
