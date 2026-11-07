<?php

namespace App\Livewire\Admin;

use App\Domain\CatalogoExterno\Actions\DescargarCatalogoCentral;
use App\Domain\CatalogoExterno\Actions\SincronizarEmpresa;
use App\Domain\CatalogoExterno\Actions\VincularEmpresaProveedor;
use App\Domain\Facturacion\Actions\CambiarFacturacionEmpresa;
use App\Domain\Suscripciones\Actions\ConfigurarSuscripcion;
use App\Domain\Tenancy\Actions\ActualizarEmpresa;
use App\Domain\Tenancy\Actions\CambiarAccesoEmpresa;
use App\Domain\Tenancy\Actions\CrearEmpresa;
use App\Domain\Tenancy\DatosEmpresa;
use App\Enums\Rol;
use App\Enums\Rubro;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\CatalogoExternoProducto;
use App\Models\Empresa;
use App\Models\EmpresaProveedorExterno;
use App\Models\Plan;
use App\Models\ProveedorExterno;
use App\Models\Scopes\EmpresaScope;
use App\Models\Suscripcion;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Empresas')]
class Empresas extends Component
{
    use MuestraAvisos, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: '')]
    public string $estado = '';

    public bool $formularioAbierto = false;

    #[Locked]
    public ?int $editandoId = null;

    #[Locked]
    public ?int $confirmandoAccesoId = null;

    #[Locked]
    public ?int $confirmandoSiatId = null;

    #[Locked]
    public ?int $confirmandoCycloneId = null;

    public string $nombre = '';

    public string $razon_social = '';

    public string $nit = '';

    public string $email = '';

    public string $telefono = '';

    /** Tipo de negocio: obligatorio al crear, sin valor por defecto (como en el registro público). */
    public string $rubro = '';

    public string $admin_nombre = '';

    public string $admin_email = '';

    public string $admin_password = '';

    public string $admin_password_confirmation = '';

    // Suscripción SaaS: opcional al crear; una vez configurada, precio y vencimiento son obligatorios.
    public ?int $plan_id = null;

    public string $precio_mensual = '';

    public string $vencimiento = '';

    public string $motivo_vencimiento = '';

    #[Locked]
    public ?string $vencimientoGuardado = null;

    /**
     * Enlace «Editar» desde Suscripciones: abre directamente el formulario de esa empresa.
     */
    #[Url(as: 'editar', except: null)]
    public ?int $abrirId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Empresa::class);

        if ($this->abrirId !== null) {
            $id = $this->abrirId;
            $this->abrirId = null;
            $this->editar($id);
        }
    }

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    public function updatedEstado(): void
    {
        $this->resetPage();
    }

    public function crear(): void
    {
        $this->authorize('create', Empresa::class);
        $this->limpiarFormulario();
        $this->formularioAbierto = true;
    }

    public function editar(int $id): void
    {
        $empresa = Empresa::query()->findOrFail($id);
        $this->authorize('update', $empresa);
        $this->limpiarFormulario();
        $this->editandoId = $empresa->id;
        $this->nombre = $empresa->nombre;
        $this->razon_social = (string) $empresa->razon_social;
        $this->nit = (string) $empresa->nit;
        $this->email = (string) $empresa->email;
        $this->telefono = (string) $empresa->telefono;
        $suscripcion = Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresa->id)->first();

        if ($suscripcion !== null) {
            $this->plan_id = $suscripcion->plan_id;
            $this->precio_mensual = Decimal::simple((string) $suscripcion->precio_mensual);
            $this->vencimiento = $this->vencimientoGuardado = $suscripcion->vencimiento->format('Y-m-d');
        }

        $this->formularioAbierto = true;
    }

    public function updatedPlanId(?int $valor): void
    {
        if ($valor !== null) {
            $plan = Plan::query()->find($valor);
            if ($plan !== null) {
                $this->precio_mensual = Decimal::simple((string) $plan->precio_mensual);
            }
        }
    }

    public function guardar(CrearEmpresa $crear, ActualizarEmpresa $actualizar, ConfigurarSuscripcion $configurar): void
    {
        $reglas = DatosEmpresa::reglas();
        $atributos = DatosEmpresa::atributos();
        $conSuscripcion = $this->vencimientoGuardado !== null || filled($this->precio_mensual) || filled($this->vencimiento);
        $reglas += [
            'precio_mensual' => [$conSuscripcion ? 'required' : 'nullable', 'regex:/^\d{1,15}([.,]\d{1,2})?$/'],
            'vencimiento' => [$conSuscripcion ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'motivo_vencimiento' => [$this->cambiaVencimiento() ? 'required' : 'nullable', 'string', 'max:300'],
        ];
        $atributos += ['precio_mensual' => __('precio mensual'), 'vencimiento' => __('próximo vencimiento'), 'motivo_vencimiento' => __('motivo del cambio')];

        if ($this->editandoId === null) {
            $reglas += [
                'rubro' => ['required', 'string', Rule::enum(Rubro::class)],
                'admin_nombre' => ['required', 'string', 'max:255'],
                'admin_email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
                'admin_password' => ['required', 'string', Password::min(12)->letters()->numbers(), 'confirmed'],
            ];
            $atributos += [
                'rubro' => __('tipo de negocio'),
                'admin_nombre' => __('nombre del administrador'),
                'admin_email' => __('correo del administrador'),
                'admin_password' => __('contraseña inicial'),
            ];
        }

        $validados = $this->validate($reglas, ['precio_mensual.regex' => __('El precio mensual admite hasta 2 decimales.')], $atributos);
        $datos = DatosEmpresa::normalizar($validados);
        $suscripcion = $conSuscripcion ? ['precio_mensual' => $this->precio_mensual, 'vencimiento' => $this->vencimiento, 'plan_id' => $this->plan_id] : null;

        if ($this->editandoId === null) {
            $crear->ejecutar($this->actor(), $datos + ['rubro' => Rubro::from($this->rubro)], [
                'name' => trim($this->admin_nombre),
                'email' => trim($this->admin_email),
                'password' => $this->admin_password,
            ], $suscripcion);
            $this->avisar(__('Empresa registrada con su administrador.'));
        } else {
            $empresa = Empresa::query()->findOrFail($this->editandoId);
            // Datos y suscripción se guardan juntos o no se guarda nada.
            DB::transaction(function () use ($actualizar, $configurar, $empresa, $datos, $suscripcion): void {
                $actualizar->ejecutar($this->actor(), $empresa, $datos);

                if ($suscripcion !== null) {
                    $configurar->ejecutar($this->actor(), $empresa, $suscripcion['precio_mensual'], $suscripcion['vencimiento'], $this->motivo_vencimiento, $this->plan_id);
                }
            });
            $this->avisar(__('Empresa actualizada.'));
        }

        $this->cerrarFormulario();
    }

    public function cerrarFormulario(): void
    {
        $this->formularioAbierto = false;
        $this->limpiarFormulario();
    }

    public function confirmarAcceso(int $id): void
    {
        $empresa = Empresa::query()->findOrFail($id);
        $this->authorize('cambiarAcceso', $empresa);
        $this->confirmandoAccesoId = $empresa->id;
    }

    public function cambiarAcceso(CambiarAccesoEmpresa $accion): void
    {
        if ($this->confirmandoAccesoId === null) {
            return;
        }

        $actual = Empresa::query()->findOrFail($this->confirmandoAccesoId);
        $empresa = $accion->ejecutar($this->actor(), $actual, ! $actual->activa);
        $this->confirmandoAccesoId = null;
        $this->avisar($empresa->activa ? __('Acceso reactivado. Los datos se conservaron.') : __('Acceso desactivado. Ningún dato fue borrado.'));
    }

    public function cancelarAcceso(): void
    {
        $this->confirmandoAccesoId = null;
    }

    public function confirmarSiat(int $id): void
    {
        $empresa = Empresa::query()->findOrFail($id);
        $this->authorize('habilitarFacturacion', $empresa);
        $this->confirmandoSiatId = $empresa->id;
    }

    public function cambiarSiat(CambiarFacturacionEmpresa $accion): void
    {
        if ($this->confirmandoSiatId === null) {
            return;
        }

        $actual = Empresa::query()->findOrFail($this->confirmandoSiatId);
        $empresa = $accion->ejecutar($this->actor(), $actual, ! $actual->siat_habilitado);
        $this->confirmandoSiatId = null;
        $this->avisar($empresa->siat_habilitado ? __('Facturación SIAT habilitada.') : __('Facturación SIAT deshabilitada. Sus datos fiscales se conservan.'));
    }

    public function cancelarSiat(): void
    {
        $this->confirmandoSiatId = null;
    }

    public function confirmarCyclone(int $id): void
    {
        $empresa = Empresa::query()->findOrFail($id);
        $this->authorize('cambiarAcceso', $empresa);
        $this->confirmandoCycloneId = $empresa->id;
    }

    public function cambiarCyclone(VincularEmpresaProveedor $accion, SincronizarEmpresa $sincronizar, DescargarCatalogoCentral $descargador): void
    {
        if ($this->confirmandoCycloneId === null) {
            return;
        }

        $empresa = Empresa::query()->findOrFail($this->confirmandoCycloneId);
        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->firstOrFail();

        $vinculoActual = EmpresaProveedorExterno::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('proveedor_externo_id', $proveedor->id)
            ->first();

        $nuevoEstado = ! ($vinculoActual->activo ?? false);
        $accion->ejecutar($this->actor(), $empresa, $proveedor, $nuevoEstado);

        if ($nuevoEstado) {
            // Si el catálogo central está vacío, descargarlo automáticamente
            if (CatalogoExternoProducto::query()->where('proveedor_externo_id', $proveedor->id)->count() === 0) {
                try {
                    $descargador->ejecutar($proveedor);
                } catch (\Throwable $e) {
                    $this->avisar(__('Empresa vinculada, pero falló la conexión con Cyclone: :error', ['error' => $e->getMessage()]));
                    $this->confirmandoCycloneId = null;

                    return;
                }
            }

            $this->avisar(__('Vinculada a Cyclone como revendedora. La empresa puede elegir qué productos vender desde el Catálogo Cyclone.'));
        } else {
            $this->avisar(__('Desvinculada de Cyclone. Sus productos se conservan intactos.'));
        }

        $this->confirmandoCycloneId = null;
    }

    public function cancelarCyclone(): void
    {
        $this->confirmandoCycloneId = null;
    }

    public function sincronizarCyclone(int $id, SincronizarEmpresa $sincronizar, DescargarCatalogoCentral $descargador): void
    {
        $empresa = Empresa::query()->findOrFail($id);
        $this->authorize('cambiarAcceso', $empresa);
        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->firstOrFail();

        // Si el catálogo central está vacío, descargarlo automáticamente
        if (CatalogoExternoProducto::query()->where('proveedor_externo_id', $proveedor->id)->count() === 0) {
            try {
                $descargador->ejecutar($proveedor);
            } catch (\Throwable $e) {
                $this->avisar(__('No se pudo conectar con la API de Cyclone: :error', ['error' => $e->getMessage()]));

                return;
            }
        }

        $res = $sincronizar->ejecutar($empresa, $proveedor, $this->actor(), soloActualizarVinculados: true);
        $this->avisar(__('Sincronización con Cyclone: :creados nuevos, :act actualizados, :sin sin cambios.', [
            'creados' => $res['creados'],
            'act' => $res['actualizados'],
            'sin' => $res['sin_cambios'],
        ]));
    }

    public function descargarCatalogoCyclone(DescargarCatalogoCentral $descargador): void
    {
        $this->authorize('viewAny', Empresa::class);
        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->firstOrFail();

        try {
            $res = $descargador->ejecutar($proveedor);
            $this->avisar(__('Catálogo central de Cyclone descargado (:procesados productos, :nuevos nuevos).', [
                'procesados' => $res['procesados'],
                'nuevos' => $res['nuevos'],
            ]));
        } catch (\Throwable $e) {
            $this->avisar(__('Error al descargar catálogo de Cyclone: :error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Corregir a mano un vencimiento ya configurado exige un motivo (queda en la auditoría).
     */
    public function cambiaVencimiento(): bool
    {
        return $this->vencimientoGuardado !== null && $this->vencimiento !== $this->vencimientoGuardado;
    }

    public function render(): View
    {
        $termino = trim($this->buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';

        $empresas = Empresa::query()
                // Panel global: la suscripción se lee omitiendo el scope de empresa de forma explícita.
            ->with(['suscripcion' => fn ($q) => $q->withoutGlobalScope(EmpresaScope::class)])
            ->when($termino !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('nombre', 'like', $patron)
                ->orWhere('razon_social', 'like', $patron)
                ->orWhere('email', 'like', $patron)
                ->orWhere('nit', 'like', $patron)))
            ->when($this->estado !== '', fn ($q) => $q->where('activa', $this->estado === 'activa'))
            ->orderBy('nombre')
            ->paginate(25);

        $proveedorCyclone = ProveedorExterno::query()->where('codigo', 'cyclone')->first();
        $revendedorasCyclone = $proveedorCyclone === null ? collect() : EmpresaProveedorExterno::withoutGlobalScopes()
            ->where('proveedor_externo_id', $proveedorCyclone->id)
            ->where('activo', true)
            ->get(['empresa_id', 'ultima_sincronizacion_en'])
            ->keyBy('empresa_id');

        return view('livewire.admin.empresas', [
            'empresas' => $empresas,
            // Administrador activo más antiguo de cada empresa de la página, para «Entrar como».
            'administradores' => User::query()->whereIn('empresa_id', $empresas->pluck('id'))
                ->where('rol', Rol::Administrador)->where('activo', true)->orderBy('id')
                ->get(['id', 'empresa_id'])->unique('empresa_id')->pluck('id', 'empresa_id'),
            'confirmando' => $this->confirmandoAccesoId === null ? null : Empresa::query()->find($this->confirmandoAccesoId),
            'confirmandoSiat' => $this->confirmandoSiatId === null ? null : Empresa::query()->find($this->confirmandoSiatId),
            'confirmandoCyclone' => $this->confirmandoCycloneId === null ? null : Empresa::query()->find($this->confirmandoCycloneId),
            'revendedorasCyclone' => $revendedorasCyclone,
            'planes' => Plan::query()->where('activo', true)->orderBy('orden')->get(),
            'rubros' => Rubro::cases(),
        ]);
    }

    private function limpiarFormulario(): void
    {
        $this->reset('editandoId', 'nombre', 'razon_social', 'nit', 'email', 'telefono', 'rubro',
            'admin_nombre', 'admin_email', 'admin_password', 'admin_password_confirmation',
            'plan_id', 'precio_mensual', 'vencimiento', 'motivo_vencimiento', 'vencimientoGuardado');
        $this->resetValidation();
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
