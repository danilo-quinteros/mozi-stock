<?php

namespace App\Domain\Facturacion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Facturacion\CatalogoEmpresa;
use App\Domain\Facturacion\DeEmpresa;
use App\Enums\ModalidadFacturacion;
use App\Enums\ModoFacturacion;
use App\Models\Empresa;
use App\Models\SiatCatalogo;
use App\Models\SiatConfiguracion;
use App\Models\SiatCufd;
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
 * NIT fiscal, razón social, modo y modalidad de facturación, método «electrónico» predeterminado y la actividad,
 * producto SIN y unidad de medida con que se facturan los productos (catálogos del SIN). Lo hace el
 * administrador de la empresa o el superadmin, nunca durante «Entrar como» (decisión D6).
 */
final class GuardarDatosFiscales
{
    private const CAMPOS = ['nit', 'razon_social', 'modo', 'modalidad', 'metodo_pago_electronico', 'actividad_economica', 'producto_sin', 'unidad_medida'];

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
            'modalidad' => ['required', Rule::enum(ModalidadFacturacion::class)],
            'metodo_pago_electronico' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'actividad_economica' => ['nullable', 'string', 'max:20'],
            'producto_sin' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'unidad_medida' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }

    /**
     * @param  array{nit: int|string, razon_social: string, modo: string, modalidad?: string, metodo_pago_electronico?: int|string|null, actividad_economica?: string|null, producto_sin?: int|string|null, unidad_medida?: int|string|null, certificado_ruta?: string|null, certificado_nombre?: string|null, certificado_pass?: string|null, certificado_vence_en?: mixed}  $datos
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

            $modalidad = isset($datos['modalidad'])
                ? ModalidadFacturacion::from($datos['modalidad'])
                : ModalidadFacturacion::Computarizada;

            $antes = $configuracion->exists ? $this->resumen($configuracion) : null;
            $configuracion->nit = $nit;
            $configuracion->razon_social = trim($datos['razon_social']);
            $configuracion->modo = ModoFacturacion::from($datos['modo']);
            $configuracion->modalidad = $modalidad;
            $configuracion->metodo_pago_electronico = $metodo;
            $configuracion->actividad_economica = $actividad;
            $configuracion->producto_sin = $producto;
            $configuracion->unidad_medida = $unidad;

            if (array_key_exists('certificado_ruta', $datos)) {
                $configuracion->certificado_ruta = $datos['certificado_ruta'];
            }
            if (array_key_exists('certificado_nombre', $datos)) {
                $configuracion->certificado_nombre = $datos['certificado_nombre'];
            }
            if (array_key_exists('certificado_pass', $datos) && filled($datos['certificado_pass'])) {
                $configuracion->certificado_pass = $datos['certificado_pass'];
            }
            if (array_key_exists('certificado_vence_en', $datos)) {
                $configuracion->certificado_vence_en = $datos['certificado_vence_en'];
            }

            if ($configuracion->modalidad === ModalidadFacturacion::Electronica && ! $configuracion->tieneCertificado()) {
                throw ValidationException::withMessages([
                    'certificado' => __('Debes subir un archivo .p12 y digitar su contraseña para la modalidad electrónica.'),
                ]);
            }

            if ($configuracion->isDirty()) {
                $cambioModalidad = $configuracion->isDirty('modalidad');
                $configuracion->save();

                if ($cambioModalidad) {
                    DeEmpresa::consulta(SiatCufd::class, $empresa->id)
                        ->where('vence_en', '>', now())
                        ->update(['vence_en' => now()->subMinute()]);
                }

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
        $datos['modalidad'] = $configuracion->modalidad?->value;
        $datos['tiene_certificado'] = $configuracion->tieneCertificado();
        $datos['certificado_vence_en'] = $configuracion->certificado_vence_en?->toIso8601String();

        return $datos;
    }
}
