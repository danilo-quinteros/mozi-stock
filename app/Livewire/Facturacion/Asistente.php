<?php

namespace App\Livewire\Facturacion;

use App\Domain\Facturacion\Actions\GuardarDatosFiscales;
use App\Domain\Facturacion\Actions\GuardarSucursalSiat;
use App\Domain\Facturacion\Actions\GuardarTokenDelegado;
use App\Domain\Facturacion\CatalogoEmpresa;
use App\Domain\Facturacion\CertificadoDigital;
use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Facturacion\ErrorFacturacion;
use App\Domain\Facturacion\SincronizarCatalogos;
use App\Domain\Facturacion\VerificacionSiat;
use App\Enums\ModalidadFacturacion;
use App\Enums\ModoFacturacion;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Facturacion\Concerns\EmpresaDeFacturacion;
use App\Models\Empresa;
use App\Models\SiatCatalogo;
use App\Models\SiatConfiguracion;
use App\Models\SiatSucursal;
use App\Models\Sucursal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use MoziStudios\Siat\Excepciones\SiatException;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Asistente de conexión con el SIN (Facturación → Datos fiscales): modalidad, NIT y token, firma digital
 * y revisión. Cada paso se guarda con las acciones de siempre (permisos, validación y auditoría) y luego
 * se verifica con VerificacionSiat: con el SIN cuando se puede, si no en MoziStock.
 * El token y la contraseña del certificado nunca vuelven al navegador.
 */
class Asistente extends Component
{
    use EmpresaDeFacturacion, MuestraAvisos, WithFileUploads;

    public const PASOS = ['modalidad', 'token', 'firma', 'revisar'];

    /** Dentro de la guía del Inicio: la sucursal se registra en el popup de la guía y cada guardado la recalcula. */
    #[Locked]
    public bool $embebido = false;

    public string $paso = 'modalidad';

    public string $modalidad = 'computarizada';

    public string $nit = '';

    public string $razon_social = '';

    public string $token = '';

    /** Municipio de la sucursal por defecto, que se vincula a la casa matriz (sucursal 0) del SIN. */
    public string $municipio = '';

    /** @var TemporaryUploadedFile|null */
    public $certificado = null;

    public string $certificado_pass = '';

    public string $modo = 'manual';

    public string $actividad_economica = '';

    public string $producto_sin = '';

    public function mount(int $empresaId, bool $embebido = false): void
    {
        $this->empresaId = $empresaId;
        $this->embebido = $embebido;
        $empresa = $this->empresa();
        $configuracion = $this->configuracion($empresa);

        if ($configuracion === null) {
            // Primera vez: se proponen los datos generales de la empresa, si el NIT es numérico.
            $this->nit = preg_match('/^[1-9]\d{3,12}$/', (string) $empresa->nit) === 1 ? (string) $empresa->nit : '';
            $this->razon_social = (string) ($empresa->razon_social ?? $empresa->nombre);
        } else {
            $this->modalidad = $configuracion->modalidad->value;
            $this->nit = (string) $configuracion->nit;
            $this->razon_social = (string) $configuracion->razon_social;
            $this->modo = $configuracion->modo->value;
            $this->actividad_economica = (string) $configuracion->actividad_economica;
            $this->producto_sin = (string) $configuracion->producto_sin;
        }

        // Se abre en el primer paso que falta (o en «Revisar» si todo está en orden).
        $estado = app(VerificacionSiat::class)->estado($empresa);
        $this->paso = collect(['modalidad', 'token', 'firma'])
            ->first(fn (string $paso): bool => in_array($estado['pasos'][$paso]['estado'], [VerificacionSiat::PENDIENTE, VerificacionSiat::ERROR], true))
            ?? 'revisar';
    }

    public function ir(string $paso): void
    {
        $this->paso = in_array($paso, self::PASOS, true) ? $paso : 'modalidad';
        $this->resetErrorBag();
    }

    public function elegirModalidad(string $modalidad): void
    {
        $this->modalidad = ModalidadFacturacion::tryFrom($modalidad)->value ?? $this->modalidad;
    }

    /** Paso 2: guarda modalidad, NIT, razón social y (si se pegó) el token; después verifica con el SIN. */
    public function guardarConexion(GuardarDatosFiscales $datosFiscales, GuardarTokenDelegado $guardarToken, GuardarSucursalSiat $guardarSucursal, VerificacionSiat $verificacion, SincronizarCatalogos $sincronizar): void
    {
        $token = trim($this->token);
        $this->token = '';

        $this->validate([
            'modalidad' => ['required', Rule::enum(ModalidadFacturacion::class)],
            'nit' => ['required', 'regex:/^[1-9]\d{3,12}$/'],
            'razon_social' => ['required', 'string', 'max:200'],
        ], ['nit.regex' => __('El NIT tiene solo dígitos, sin guiones ni complemento.')], [
            'nit' => __('NIT'), 'razon_social' => __('razón social'), 'modalidad' => __('modalidad'),
        ]);

        $empresa = $this->empresa();
        $configuracion = $this->configuracion($empresa);
        $sucursal = $this->sucursalPorVincular($empresa);

        if ($token === '' && ! $configuracion?->tieneToken()) {
            throw ValidationException::withMessages(['token' => __('Pega el token delegado que generaste en el portal SIAT.')]);
        }

        if ($sucursal !== null) {
            $this->validate(['municipio' => ['required', 'string', 'max:100']], [], ['municipio' => __('municipio')]);
        }

        $datosFiscales->ejecutar($this->actor(), $empresa, [
            'nit' => $this->nit,
            'razon_social' => $this->razon_social,
            'modalidad' => $this->modalidad,
        ] + $this->valoresActuales($configuracion));

        if ($token !== '') {
            $guardarToken->ejecutar($this->actor(), $empresa, $token);
        }

        // La sucursal por defecto queda como la casa matriz del SIN (código 0, con su punto de venta 0).
        if ($sucursal !== null) {
            $guardarSucursal->ejecutar($this->actor(), $sucursal, ['codigo' => 0, 'municipio' => $this->municipio]);
            $this->municipio = '';
        }

        $estado = $this->verificar($verificacion, $empresa);

        // Con el token aceptado por el SIN se sincronizan los catálogos (si el token es nuevo o falta alguno).
        if ($estado !== null && ($estado['sin']['resultado'] ?? null) === 'aceptado' && ($token !== '' || $estado['catalogos'] !== [])) {
            $estado = $this->sincronizarCatalogos($sincronizar, $verificacion, $empresa);
        }

        if ($estado !== null && in_array($estado['pasos']['token']['estado'], [VerificacionSiat::VERIFICADO, VerificacionSiat::CORRECTO], true)) {
            $this->paso = $this->modalidad === ModalidadFacturacion::Electronica->value ? 'firma' : 'revisar';
        }

        $this->dispatch('siat-actualizado');
    }

    /** Paso 3: sube el .p12 (o cambia su contraseña). Se revisa en MoziStock: el SIN valida la firma con la primera factura. */
    public function guardarFirma(GuardarDatosFiscales $datosFiscales, VerificacionSiat $verificacion): void
    {
        $this->validate([
            'certificado' => ['nullable', 'file', 'max:10240'],
            'certificado_pass' => ['nullable', 'string', 'max:255'],
        ], ['certificado.max' => __('El archivo del certificado digital no puede superar 10 MB.')], [
            'certificado' => __('archivo de certificado (.p12)'), 'certificado_pass' => __('contraseña del certificado'),
        ]);

        $empresa = $this->empresa();
        $configuracion = $this->configuracion($empresa);

        if ($configuracion?->nit === null) {
            throw ValidationException::withMessages(['certificado' => __('Primero completa el paso 2: NIT y token.')]);
        }

        if ($this->certificado === null && ! $configuracion->tieneCertificado()) {
            throw ValidationException::withMessages(['certificado' => __('Sube el archivo .p12 de tu firma digital.')]);
        }

        $preparado = CertificadoDigital::preparar($empresa, $configuracion, $this->certificado, $this->certificado_pass);

        try {
            $datosFiscales->ejecutar($this->actor(), $empresa, [
                'nit' => $configuracion->nit,
                'razon_social' => (string) $configuracion->razon_social,
                'modalidad' => ModalidadFacturacion::Electronica->value,
            ] + $this->valoresActuales($configuracion) + $preparado['datos']);
        } catch (\Throwable $error) {
            CertificadoDigital::descartar($preparado);

            throw $error;
        }

        CertificadoDigital::confirmar($preparado);
        $this->dispatch('siat-actualizado');
        $this->certificado = null;
        $this->certificado_pass = '';
        $this->modalidad = ModalidadFacturacion::Electronica->value;

        // Cambiar a electrónica desde aquí cambia la modalidad que el SIN debe aceptar: se vuelve a verificar.
        $estado = $this->verificar($verificacion, $empresa, silencioso: true);

        if ($estado !== null && $estado['pasos']['firma']['estado'] === VerificacionSiat::CORRECTO) {
            $this->avisar(__('Firma digital guardada: el certificado abre con su contraseña y está vigente.'));
            $this->paso = 'revisar';
        }
    }

    /** Paso 4: vuelve a consultar al SIN (y completa los catálogos que falten). */
    public function verificarAhora(VerificacionSiat $verificacion, SincronizarCatalogos $sincronizar): void
    {
        $empresa = $this->empresa();
        $estado = $this->verificar($verificacion, $empresa);

        if ($estado !== null && ($estado['sin']['resultado'] ?? null) === 'aceptado' && $estado['catalogos'] !== []) {
            $this->sincronizarCatalogos($sincronizar, $verificacion, $empresa);
        }

        $this->dispatch('siat-actualizado');
    }

    /** Paso 2: reintenta los catálogos que no se pudieron sincronizar al validar el token. */
    public function reintentarCatalogos(VerificacionSiat $verificacion, SincronizarCatalogos $sincronizar): void
    {
        $empresa = $this->empresa();
        Gate::forUser($this->actor())->authorize('configurar-facturacion', $empresa);
        $estado = $this->sincronizarCatalogos($sincronizar, $verificacion, $empresa);

        if ($estado['pasos']['token']['estado'] === VerificacionSiat::VERIFICADO) {
            $this->paso = $this->modalidad === ModalidadFacturacion::Electronica->value ? 'firma' : 'revisar';
        }

        $this->dispatch('siat-actualizado');
    }

    /** La guía avisa al cerrar un popup (por ejemplo, la sucursal en el SIN): la revisión se recalcula. */
    #[On('siat-refrescar')]
    public function refrescar(): void {}

    /** Valores con que se arma cada línea de la factura (requieren los catálogos sincronizados). */
    public function guardarPreferencias(GuardarDatosFiscales $datosFiscales): void
    {
        $this->validate([
            'modo' => ['required', Rule::enum(ModoFacturacion::class)],
            'actividad_economica' => ['nullable', 'string', 'max:20'],
            'producto_sin' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
        ], [], ['modo' => __('modo de facturación'), 'actividad_economica' => __('actividad económica'), 'producto_sin' => __('producto SIN')]);

        $empresa = $this->empresa();
        $configuracion = $this->configuracion($empresa);

        if ($configuracion?->nit === null) {
            throw ValidationException::withMessages(['modo' => __('Primero completa el paso 2: NIT y token.')]);
        }

        $datosFiscales->ejecutar($this->actor(), $empresa, [
            'nit' => $configuracion->nit,
            'razon_social' => (string) $configuracion->razon_social,
            'modalidad' => $configuracion->modalidad->value,
            'metodo_pago_electronico' => $configuracion->metodo_pago_electronico,
            'unidad_medida' => $configuracion->unidad_medida,
            'modo' => $this->modo,
            'actividad_economica' => $this->actividad_economica,
            'producto_sin' => $this->producto_sin,
        ]);

        $this->avisar(__('Valores de la factura guardados.'));
        $this->dispatch('siat-actualizado');
    }

    /** Al cambiar de actividad, el producto SIN elegido deja de servir si es de otra actividad. */
    public function updatedActividadEconomica(): void
    {
        $this->producto_sin = '';
    }

    public function render(): View
    {
        $empresa = $this->empresa();
        $verificacion = app(VerificacionSiat::class);
        $configuracion = $this->configuracion($empresa);

        return view('livewire.facturacion.asistente', [
            'empresa' => $empresa,
            'configuracion' => $configuracion,
            'estado' => $verificacion->estado($empresa),
            'certificado' => $configuracion?->tieneCertificado() ? CertificadoDigital::revisar($configuracion) : null,
            'modos' => ModoFacturacion::cases(),
            'actividades' => CatalogoEmpresa::opciones($empresa->id, Catalogo::Actividades),
            'productos' => $this->actividad_economica === '' ? collect() : DeEmpresa::consulta(SiatCatalogo::class, $empresa->id)
                ->where('catalogo', Catalogo::ProductosServicios->name)
                ->where('atributos->codigoActividad', $this->actividad_economica)
                ->orderByRaw('CAST(codigo AS UNSIGNED)')
                ->get(['codigo', 'descripcion']),
            'puedeConfigurar' => $this->puedeConfigurar($empresa),
            'impersonando' => $this->impersonando(),
            'sucursalPorVincular' => $this->sucursalPorVincular($empresa),
        ]);
    }

    /**
     * Mientras la empresa no registró ninguna sucursal en el SIN, su sucursal por defecto (la primera
     * activa, «Casa Matriz» al crear la empresa) se vincula sola a la sucursal 0 del SIN en el paso 2.
     */
    private function sucursalPorVincular(Empresa $empresa): ?Sucursal
    {
        if (DeEmpresa::consulta(SiatSucursal::class, $empresa->id)->exists()) {
            return null;
        }

        return DeEmpresa::consulta(Sucursal::class, $empresa->id)->where('activa', true)->orderBy('id')->first();
    }

    /**
     * Verifica y avisa el resultado en palabras simples. Devuelve el estado, o null si no se pudo consultar.
     *
     * @return array<string, mixed>|null
     */
    private function verificar(VerificacionSiat $verificacion, Empresa $empresa, bool $silencioso = false): ?array
    {
        try {
            $estado = $verificacion->verificar($this->actor(), $empresa);
        } catch (ErrorFacturacion $error) {
            $this->avisar($error->getMessage(), 'danger');

            return null;
        }

        if ($silencioso) {
            return $estado;
        }

        $sin = $estado['sin'];

        if ($estado['pasos']['token']['estado'] === VerificacionSiat::ERROR) {
            $this->avisar(__('Revisa el token: :detalle', ['detalle' => $estado['pasos']['token']['detalle']]), 'danger');

            return $estado;
        }

        $this->avisar(match (true) {
            $sin !== null && $sin['resultado'] === 'aceptado' => __('Listo: el SIN aceptó tu NIT, el token y la modalidad.'),
            ! $estado['habilitada'] => __('Datos guardados y correctos. Se verificarán con el SIN cuando MoziStudios habilite la facturación.'),
            $sin !== null => __('Datos guardados. :detalle', ['detalle' => implode(' ', $sin['mensajes'])]),
            default => __('Datos guardados.'),
        });

        return $estado;
    }

    /**
     * Descarga los catálogos del SIN y avisa el resultado. Si algo falla, el paso 2 queda pendiente con un botón para reintentar.
     *
     * @return array<string, mixed> estado después de sincronizar
     */
    private function sincronizarCatalogos(SincronizarCatalogos $sincronizar, VerificacionSiat $verificacion, Empresa $empresa): array
    {
        try {
            set_time_limit(300);
            $resultado = $sincronizar->ejecutar($empresa);
            $resultado['fallidos'] === []
                ? $this->avisar(__('Listo: el SIN aceptó tu configuración y se sincronizaron :correctos catálogos.', ['correctos' => $resultado['correctos']]))
                : $this->avisar(__('No se sincronizaron: :fallidos. Reintenta en unos minutos.', ['fallidos' => implode(', ', $resultado['fallidos'])]), 'danger');
        } catch (ErrorFacturacion|SiatException $error) {
            $this->avisar(__('No se pudieron sincronizar los catálogos: :error', ['error' => $error->getMessage()]), 'danger');
        }

        return $verificacion->estado($empresa);
    }

    /**
     * Lo que este paso no cambia se guarda tal como está.
     *
     * @return array{modo: string, metodo_pago_electronico: int|null, actividad_economica: string|null, producto_sin: int|null, unidad_medida: int|null}
     */
    private function valoresActuales(?SiatConfiguracion $configuracion): array
    {
        return [
            'modo' => $configuracion?->modo->value ?? ModoFacturacion::Manual->value,
            'metodo_pago_electronico' => $configuracion?->metodo_pago_electronico,
            'actividad_economica' => $configuracion?->actividad_economica,
            'producto_sin' => $configuracion?->producto_sin,
            'unidad_medida' => $configuracion?->unidad_medida,
        ];
    }

    private function configuracion(Empresa $empresa): ?SiatConfiguracion
    {
        return DeEmpresa::consulta(SiatConfiguracion::class, $empresa->id)->first();
    }
}
