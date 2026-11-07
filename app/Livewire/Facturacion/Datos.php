<?php

namespace App\Livewire\Facturacion;

use App\Domain\Facturacion\Actions\GuardarDatosFiscales;
use App\Domain\Facturacion\Actions\GuardarTokenDelegado;
use App\Domain\Facturacion\CatalogoEmpresa;
use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Facturacion\Firma\FirmadorXmlDSig;
use App\Domain\Facturacion\SincronizarCatalogos;
use App\Enums\ModalidadFacturacion;
use App\Enums\ModoFacturacion;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Facturacion\Concerns\EmpresaDeFacturacion;
use App\Models\SiatCatalogo;
use App\Models\SiatConfiguracion;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Datos fiscales y token delegado de la empresa. El token se escribe pero nunca se devuelve al
 * navegador: la propiedad se vacía siempre, también cuando la validación falla.
 * En modalidad electrónica, gestiona la subida del archivo .p12 y su contraseña cifrada.
 */
class Datos extends Component
{
    use EmpresaDeFacturacion, MuestraAvisos, WithFileUploads;

    /** «todo» en Facturación; «datos» o «token» muestra solo ese formulario en un popup de la guía del Inicio. */
    #[Locked]
    public string $parte = 'todo';

    public string $nit = '';

    public string $razon_social = '';

    public string $modo = 'automatica';

    public string $modalidad = 'computarizada';

    public $certificado = null;

    public string $certificado_pass = '';

    public string $actividad_economica = '';

    public string $producto_sin = '';

    public string $token = '';

    public function mount(int $empresaId): void
    {
        $this->empresaId = $empresaId;
        $empresa = $this->empresa();
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresa->id)->first();

        if ($configuracion === null) {
            // Primera vez: se proponen los datos generales de la empresa, si el NIT es numérico.
            $this->nit = preg_match('/^[1-9]\d{3,12}$/', (string) $empresa->nit) === 1 ? (string) $empresa->nit : '';
            $this->razon_social = (string) ($empresa->razon_social ?? $empresa->nombre);
            $this->modalidad = 'computarizada';

            return;
        }

        $this->nit = (string) $configuracion->nit;
        $this->razon_social = (string) $configuracion->razon_social;
        $this->modo = $configuracion->modo->value;
        $this->modalidad = $configuracion->modalidad?->value ?? 'computarizada';
        $this->actividad_economica = (string) $configuracion->actividad_economica;
        $this->producto_sin = (string) $configuracion->producto_sin;
    }

    /** Al cambiar de actividad, el producto SIN elegido deja de servir si es de otra actividad. */
    public function updatedActividadEconomica(): void
    {
        $this->producto_sin = '';
    }

    public function guardarDatos(GuardarDatosFiscales $accion): void
    {
        $datos = $this->validate([
            'nit' => ['required', 'regex:/^[1-9]\d{3,12}$/'],
            'razon_social' => ['required', 'string', 'max:200'],
            'modo' => ['required', Rule::enum(ModoFacturacion::class)],
            'modalidad' => ['required', Rule::enum(ModalidadFacturacion::class)],
            'actividad_economica' => ['nullable', 'string', 'max:20'],
            'producto_sin' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'certificado' => ['nullable', 'file', 'max:10240'],
            'certificado_pass' => ['nullable', 'string', 'max:255'],
        ], [
            'nit.regex' => __('El NIT tiene solo dígitos, sin guiones ni complemento.'),
            'certificado.max' => __('El archivo del certificado digital no puede superar 10 MB.'),
        ], [
            'nit' => __('NIT'),
            'razon_social' => __('razón social'),
            'modo' => __('modo de facturación'),
            'modalidad' => __('modalidad de facturación'),
            'actividad_economica' => __('actividad económica'),
            'producto_sin' => __('producto SIN'),
            'certificado' => __('archivo de certificado (.p12)'),
            'certificado_pass' => __('contraseña del certificado'),
        ]);

        $empresa = $this->empresa();
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresa->id)->first();

        if ($this->modalidad === 'electronica') {
            if ($this->certificado !== null) {
                $ext = strtolower((string) $this->certificado->getClientOriginalExtension());
                if (! in_array($ext, ['p12', 'pfx'], true)) {
                    throw ValidationException::withMessages([
                        'certificado' => __('El archivo debe ser un certificado digital en formato .p12 o .pfx.'),
                    ]);
                }

                if (blank($this->certificado_pass)) {
                    throw ValidationException::withMessages([
                        'certificado_pass' => __('Digita la contraseña para verificar y abrir el archivo .p12.'),
                    ]);
                }

                $p12Contenido = (string) file_get_contents($this->certificado->getRealPath());
                $inspeccion = FirmadorXmlDSig::inspeccionarP12($p12Contenido, $this->certificado_pass);

                if (! $inspeccion['valido']) {
                    throw ValidationException::withMessages([
                        'certificado_pass' => __('La contraseña no corresponde al archivo .p12 o el certificado es inválido: :error', [
                            'error' => $inspeccion['error'] ?? __('verificación fallida'),
                        ]),
                    ]);
                }

                $nombreOriginal = (string) $this->certificado->getClientOriginalName();
                $nombreArchivo = 'empresa_'.$empresa->id.'_'.Str::random(16).'.p12';

                // Guardado en disco privado local: storage/app/private/certificados
                $rutaGuardada = Storage::disk('local')->putFileAs('certificados', $this->certificado, $nombreArchivo);

                // Eliminar certificado previo si existía para no acumular archivos huérfanos
                if ($configuracion?->certificado_ruta && Storage::disk('local')->exists($configuracion->certificado_ruta)) {
                    Storage::disk('local')->delete($configuracion->certificado_ruta);
                }

                $datos['certificado_ruta'] = $rutaGuardada;
                $datos['certificado_nombre'] = $nombreOriginal;
                $datos['certificado_pass'] = $this->certificado_pass;
                $datos['certificado_vence_en'] = $inspeccion['vence_en'];
            } elseif ($configuracion?->tieneCertificado()) {
                if (filled($this->certificado_pass)) {
                    $p12Ruta = $configuracion->certificadoRutaAbsoluta();
                    if ($p12Ruta && file_exists($p12Ruta)) {
                        $inspeccion = FirmadorXmlDSig::inspeccionarP12((string) file_get_contents($p12Ruta), $this->certificado_pass);
                        if (! $inspeccion['valido']) {
                            throw ValidationException::withMessages([
                                'certificado_pass' => __('La nueva contraseña no corresponde al archivo .p12 guardado.'),
                            ]);
                        }
                        $datos['certificado_pass'] = $this->certificado_pass;
                    }
                }
            } else {
                throw ValidationException::withMessages([
                    'certificado' => __('Debes subir un archivo .p12 y digitar su contraseña para la modalidad electrónica en línea.'),
                ]);
            }
        }

        $accion->ejecutar($this->actor(), $empresa, $datos);

        $this->certificado = null;
        $this->certificado_pass = '';

        $this->avisar(__('Datos fiscales guardados.'));
    }

    public function guardarToken(GuardarTokenDelegado $accion, SincronizarCatalogos $sincronizar): void
    {
        $token = trim($this->token);
        $this->token = '';

        if ($token === '') {
            throw ValidationException::withMessages(['token' => __('Pega el token delegado que generaste en el portal SIAT.')]);
        }

        $empresa = $this->empresa();
        $accion->ejecutar($this->actor(), $empresa, $token);

        try {
            set_time_limit(300);
            $resultado = $sincronizar->ejecutar($empresa);
            if ($resultado['fallidos'] === []) {
                $this->avisar(__('Token delegado guardado y catálogos sincronizados (:correctos).', ['correctos' => $resultado['correctos']]));
            } else {
                $this->avisar(__('Token delegado guardado. Algunos catálogos no se pudieron sincronizar: :fallidos.', ['fallidos' => implode(', ', $resultado['fallidos'])]));
            }
        } catch (\Throwable) {
            $this->avisar(__('Token delegado guardado.'));
        }
    }

    public function render(): View
    {
        $empresa = $this->empresa();
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresa->id)->first();

        try {
            $sistema = app(ConfiguracionSistema::class);
        } catch (DatosInvalidos) {
            $sistema = null;
        }

        return view('livewire.facturacion.datos', [
            'empresa' => $empresa,
            'configuracion' => $configuracion,
            'sistema' => $sistema,
            'modos' => ModoFacturacion::cases(),
            'modalidades' => ModalidadFacturacion::cases(),
            'actividades' => CatalogoEmpresa::opciones($empresa->id, Catalogo::Actividades),
            // Solo los productos de la actividad elegida (el catálogo del SIN los asocia por codigoActividad).
            'productos' => $this->actividad_economica === '' ? collect() : DeEmpresa::consulta(SiatCatalogo::class, $empresa->id)
                ->where('catalogo', Catalogo::ProductosServicios->name)
                ->where('atributos->codigoActividad', $this->actividad_economica)
                ->orderByRaw('CAST(codigo AS UNSIGNED)')
                ->get(['codigo', 'descripcion']),
            'actualizadoPor' => $configuracion?->token_actualizado_por === null ? null
                : User::query()->whereKey($configuracion->token_actualizado_por)->value('name'),
            'puedeConfigurar' => $this->puedeConfigurar($empresa),
            'impersonando' => $this->impersonando(),
        ]);
    }
}
