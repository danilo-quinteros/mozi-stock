<?php

namespace App\Domain\Facturacion;

use App\Domain\Auditoria\Auditor;
use App\Models\Empresa;
use App\Models\SiatConfiguracion;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSincronizacion;
use App\Models\SiatSucursal;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Configuracion\DatosToken;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Constantes\Ambiente;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Excepciones\ErrorComunicacion;
use MoziStudios\Siat\Excepciones\SiatException;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Estado de cada paso del asistente SIAT y su verificación contra el SIN.
 *
 * Lo que se puede comprobar en el SIN se comprueba ahí: se pide el CUIS de la casa matriz (sucursal 0,
 * sin punto de venta), que el SIN solo entrega si el NIT, el token delegado, el código de sistema y la
 * modalidad corresponden. No cambia nada en el SIN (si ya hay un CUIS vigente devuelve el mismo) y
 * no se guarda: el CUIS de cada punto lo sigue gestionando CodigosSiat.
 *
 * Lo que el SIN no expone (firma digital, vigencia del token) se revisa en MoziStock y se informa
 * como «correcto» sin decir que lo verificó el SIN. Sin habilitación de MoziStudios no se consulta al SIN.
 */
final class VerificacionSiat
{
    /** El SIN lo confirmó. */
    public const VERIFICADO = 'verificado';

    /** Correcto según lo que MoziStock puede revisar; el SIN no lo confirmó (todavía). */
    public const CORRECTO = 'correcto';

    public const ERROR = 'error';

    public const PENDIENTE = 'pendiente';

    public const NO_APLICA = 'no_aplica';

    public function __construct(
        private readonly ContribuyenteEmpresa $contribuyentes,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Consulta al SIN y guarda el resultado. Un doble clic espera a la primera consulta.
     *
     * @return array<string, mixed> el mismo resultado que estado()
     *
     * @throws ErrorFacturacion
     */
    public function verificar(User $actor, Empresa $empresa): array
    {
        Gate::forUser($actor)->authorize('configurar-facturacion', $empresa);

        try {
            Cache::lock("siat:verificar:{$empresa->id}", 60)->block(20, fn () => $this->consultarSin($actor, $empresa));
        } catch (LockTimeoutException) {
            throw new ErrorFacturacion(__('Ya hay una verificación en curso. Intenta de nuevo en unos segundos.'));
        }

        return $this->estado($empresa);
    }

    /**
     * Estado de los pasos sin consultar al SIN: usa la última verificación si sigue valiendo.
     *
     * @return array{
     *     servidor: bool, habilitada: bool, sistema: ConfiguracionSistema|null,
     *     sin: array{resultado: string, mensajes: list<string>, en: Carbon|null}|null,
     *     catalogos: list<string>,
     *     pasos: array<string, array{estado: string, detalle: string}>,
     *     revision: list<array{clave: string, punto: string, estado: string, detalle: string}>,
     *     listo: bool
     * }
     */
    public function estado(Empresa $empresa): array
    {
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresa->id)->first();
        $sistema = self::sistema();
        $sin = $configuracion !== null && $sistema !== null ? self::ultimaVerificacion($configuracion, $sistema) : null;
        $sinOk = $sin !== null && $sin['resultado'] === 'aceptado';
        $sinRechazo = $sin !== null && $sin['resultado'] === 'rechazado';

        $modalidad = match (true) {
            $configuracion === null => [self::PENDIENTE, __('Elige cómo factura tu empresa.')],
            $sinOk => [self::VERIFICADO, __(':modalidad · el SIN la aceptó.', ['modalidad' => $configuracion->modalidad->etiqueta()])],
            default => [self::CORRECTO, $configuracion->modalidad->etiqueta()],
        };

        $catalogos = self::catalogosFaltantes($empresa->id);
        $token = $this->pasoToken($configuracion, $sistema, $sinOk, $sinRechazo, $sin);

        // Con el SIN aceptando el token, los catálogos son parte del paso 2: se sincronizan al validarlo.
        if ($token['estado'] === self::VERIFICADO && $catalogos !== []) {
            $token = ['estado' => self::PENDIENTE, 'detalle' => __('Faltan catálogos del SIN')];
        }

        $pasos = [
            'modalidad' => ['estado' => $modalidad[0], 'detalle' => $modalidad[1]],
            'token' => $token,
            'firma' => $this->pasoFirma($configuracion),
        ];

        $pasosListos = $sistema !== null && collect($pasos)->every(fn (array $paso): bool => in_array($paso['estado'], [self::VERIFICADO, self::CORRECTO, self::NO_APLICA], true));
        $revision = $this->revision($empresa, $configuracion, $pasos, $sin);
        // Lo mismo que exige RegistrarFacturaVenta: sin esto, la venta queda con la factura pendiente.
        $paraFacturar = collect($revision)->whereIn('clave', ['sucursales', 'actividad'])->every(fn (array $punto): bool => $punto['estado'] === self::CORRECTO);
        $listo = $pasosListos && $paraFacturar;

        $pasos['revisar'] = match (true) {
            ! $pasosListos => ['estado' => self::PENDIENTE, 'detalle' => __('Completa los pasos anteriores.')],
            ! $paraFacturar => ['estado' => self::PENDIENTE, 'detalle' => __('Falta sucursal o actividad')],
            $sinOk => ['estado' => self::VERIFICADO, 'detalle' => __('Verificado con el SIN.')],
            default => ['estado' => self::CORRECTO, 'detalle' => __('Falta confirmarlo con el SIN.')],
        };

        return [
            'servidor' => $sistema !== null,
            'habilitada' => (bool) $empresa->siat_habilitado,
            'sistema' => $sistema,
            'sin' => $sin,
            'catalogos' => $catalogos,
            'pasos' => $pasos,
            'revision' => $revision,
            'listo' => $listo,
        ];
    }

    private function consultarSin(User $actor, Empresa $empresa): void
    {
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresa->id)->first();
        $sistema = self::sistema();

        if ($configuracion === null || $sistema === null || ! $configuracion->tieneToken() || $configuracion->tokenVencido()) {
            return;
        }

        try {
            // Lanza ErrorFacturacion si la empresa no está habilitada: entonces no se consulta al SIN.
            $contribuyente = $this->contribuyentes->para($empresa);
        } catch (ErrorFacturacion) {
            return;
        }

        try {
            $respuesta = app(ClienteSiat::class)
                ->conModalidad($configuracion->modalidad->siat())
                ->codigos($contribuyente)
                ->cuis(new PuntoEmision(0, 0));

            // Con un CUIS vigente el SIN responde transaccion=false y el mismo código (mensaje 980): también es aceptación.
            [$resultado, $mensajes] = $respuesta->codigo !== null
                ? ['aceptado', []]
                : ['rechazado', array_map(strval(...), $respuesta->mensajes) ?: [__('El SIN no entregó el código de inicio (CUIS).')]];
        } catch (ErrorComunicacion $error) {
            // Un 401/403 del SIN es un token rechazado; cualquier otra falla es de conexión y no dice nada de los datos.
            [$resultado, $mensajes] = preg_match('/\b40[13]\b|unauthori[sz]ed|forbidden/i', $error->getMessage()) === 1
                ? ['rechazado', [__('El SIN no aceptó el token delegado (acceso no autorizado).')]]
                : ['sin_conexion', [__('No se pudo conectar con el SIN. Intenta de nuevo en unos minutos.')]];
        } catch (DatosInvalidos $error) {
            [$resultado, $mensajes] = ['rechazado', [$error->getMessage()]];
        } catch (SiatException) {
            [$resultado, $mensajes] = ['sin_conexion', [__('No se pudo conectar con el SIN. Intenta de nuevo en unos minutos.')]];
        }

        $verificacion = ['huella' => self::huella($configuracion, $sistema), 'resultado' => $resultado, 'mensajes' => $mensajes];

        DeEmpresa::consulta(SiatConfiguracion::class, $empresa->id)->whereKey($configuracion->id)->update([
            'verificacion' => json_encode($verificacion, JSON_UNESCAPED_UNICODE),
            'verificado_en' => Carbon::now(),
        ]);

        $this->auditor->registrar('siat.configuracion_verificada', $configuracion, $actor, $empresa->id, null, [
            'resultado' => $resultado,
            'modalidad' => $configuracion->modalidad->value,
            'token_sufijo' => $configuracion->token_sufijo,
        ]);
    }

    /**
     * @param  array{resultado: string, mensajes: list<string>, en: Carbon|null}|null  $sin
     * @return array{estado: string, detalle: string}
     */
    private function pasoToken(?SiatConfiguracion $configuracion, ?ConfiguracionSistema $sistema, bool $sinOk, bool $sinRechazo, ?array $sin): array
    {
        if ($configuracion?->nit === null || ! $configuracion->tieneToken()) {
            return ['estado' => self::PENDIENTE, 'detalle' => __('Código y token delegado')];
        }

        if ($configuracion->tokenVencido()) {
            return ['estado' => self::ERROR, 'detalle' => __('El token venció. Carga uno nuevo.')];
        }

        if ($sistema !== null && ($error = self::errorDeToken($configuracion, $sistema)) !== null) {
            return ['estado' => self::ERROR, 'detalle' => $error];
        }

        return match (true) {
            $sinOk => ['estado' => self::VERIFICADO, 'detalle' => __('NIT :nit · token …:sufijo', ['nit' => $configuracion->nit, 'sufijo' => $configuracion->token_sufijo])],
            $sinRechazo => ['estado' => self::ERROR, 'detalle' => implode(' ', $sin['mensajes'] ?? [])],
            default => ['estado' => self::CORRECTO, 'detalle' => __('NIT :nit · token …:sufijo', ['nit' => $configuracion->nit, 'sufijo' => $configuracion->token_sufijo])],
        };
    }

    /**
     * @return array{estado: string, detalle: string}
     */
    private function pasoFirma(?SiatConfiguracion $configuracion): array
    {
        if ($configuracion !== null && ! $configuracion->esElectronica()) {
            return ['estado' => self::NO_APLICA, 'detalle' => __('No hace falta en computarizada')];
        }

        if ($configuracion === null) {
            return ['estado' => self::PENDIENTE, 'detalle' => __('Llave y certificado')];
        }

        $revision = CertificadoDigital::revisar($configuracion);

        if (! $configuracion->tieneCertificado()) {
            return ['estado' => self::PENDIENTE, 'detalle' => __('Llave y certificado')];
        }

        if (! $revision['valido']) {
            return ['estado' => self::ERROR, 'detalle' => (string) $revision['error']];
        }

        return ['estado' => self::CORRECTO, 'detalle' => $revision['vence_en'] === null ? __('Certificado válido')
            : __('Válido hasta el :fecha', ['fecha' => $revision['vence_en']->timezone((string) config('mozistock.business_timezone'))->format('d/m/Y')])];
    }

    /**
     * Lista del paso «Revisar»: cada punto dice si lo confirmó el SIN o solo MoziStock. Incluye lo que la
     * factura de cada venta exige además de la conexión: la sucursal de la venta registrada en el SIN con un
     * punto de venta activo, y la actividad y el producto SIN por defecto.
     *
     * @param  array<string, array{estado: string, detalle: string}>  $pasos
     * @param  array{resultado: string, mensajes: list<string>, en: Carbon|null}|null  $sin
     * @return list<array{clave: string, punto: string, estado: string, detalle: string}>
     */
    private function revision(Empresa $empresa, ?SiatConfiguracion $configuracion, array $pasos, ?array $sin): array
    {
        $zona = (string) config('mozistock.business_timezone');

        $conexion = match (true) {
            ! $empresa->siat_habilitado => [self::PENDIENTE, __('Se verificará cuando MoziStudios habilite la facturación de tu empresa.')],
            $pasos['token']['estado'] === self::PENDIENTE && ! $configuracion?->tieneToken() => [self::PENDIENTE, __('Carga el NIT y el token para verificar.')],
            $sin === null => [self::PENDIENTE, __('Sin verificar todavía.')],
            $sin['resultado'] === 'aceptado' => [self::VERIFICADO, __('El SIN aceptó el NIT, el token, el sistema y la modalidad el :fecha.', ['fecha' => $sin['en']?->timezone($zona)->format('d/m/Y H:i')])],
            $sin['resultado'] === 'rechazado' => [self::ERROR, implode(' ', $sin['mensajes'])],
            default => [self::PENDIENTE, implode(' ', $sin['mensajes'])],
        };

        $token = match (true) {
            ! $configuracion?->tieneToken() => [self::PENDIENTE, __('Falta cargarlo en el paso 2.')],
            $pasos['token']['estado'] === self::ERROR => [self::ERROR, $pasos['token']['detalle']],
            default => [$sin !== null && $sin['resultado'] === 'aceptado' ? self::VERIFICADO : self::CORRECTO,
                $configuracion->token_vence_en === null ? __('Termina en …:sufijo.', ['sufijo' => $configuracion->token_sufijo])
                    : __('Termina en …:sufijo · vence el :fecha.', ['sufijo' => $configuracion->token_sufijo, 'fecha' => $configuracion->token_vence_en->timezone($zona)->format('d/m/Y')])],
        };

        return [
            self::punto('modalidad', __('Modalidad'), $pasos['modalidad']['estado'], $pasos['modalidad']['detalle']),
            self::punto('nit', __('NIT y razón social'), $configuracion?->nit === null ? self::PENDIENTE : ($sin !== null && $sin['resultado'] === 'aceptado' ? self::VERIFICADO : self::CORRECTO),
                $configuracion?->nit === null ? __('Falta cargarlos en el paso 2.') : $configuracion->nit.' · '.$configuracion->razon_social),
            self::punto('token', __('Token delegado'), $token[0], $token[1]),
            self::punto('firma', __('Firma digital'), $pasos['firma']['estado'], $pasos['firma']['detalle']),
            self::punto('conexion', __('Conexión con el SIN'), $conexion[0], $conexion[1]),
            $this->puntoSucursales($empresa->id),
            $this->puntoActividad($empresa->id, $configuracion),
        ];
    }

    /**
     * Cada sucursal activa de la empresa debe estar registrada en el SIN con un punto de venta activo:
     * la factura de una venta usa el punto de la sucursal donde se vendió.
     *
     * @return array{clave: string, punto: string, estado: string, detalle: string}
     */
    private function puntoSucursales(int $empresaId): array
    {
        $sucursales = DeEmpresa::consulta(Sucursal::class, $empresaId)->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']);
        $conPunto = DeEmpresa::consulta(SiatSucursal::class, $empresaId)
            ->whereIn('id', DeEmpresa::consulta(SiatPuntoVenta::class, $empresaId)->where('activo', true)->select('siat_sucursal_id'))
            ->pluck('sucursal_id')->all();
        $faltan = $sucursales->reject(fn (Sucursal $sucursal): bool => in_array($sucursal->id, $conPunto, true));

        return match (true) {
            $sucursales->isEmpty() => self::punto('sucursales', __('Sucursales en el SIN'), self::PENDIENTE, __('La empresa no tiene sucursales activas.')),
            $faltan->isEmpty() => self::punto('sucursales', __('Sucursales en el SIN'), self::CORRECTO,
                trans_choice(':nombres: registrada con punto de venta activo.|:nombres: registradas con punto de venta activo.', $sucursales->count(), ['nombres' => $sucursales->pluck('nombre')->implode(', ')])),
            default => self::punto('sucursales', __('Sucursales en el SIN'), self::PENDIENTE,
                __('Sin registrar en el SIN o sin punto de venta activo: :nombres. Sus ventas no se pueden facturar.', ['nombres' => $faltan->pluck('nombre')->implode(', ')])),
        };
    }

    /**
     * @return array{clave: string, punto: string, estado: string, detalle: string}
     */
    private function puntoActividad(int $empresaId, ?SiatConfiguracion $configuracion): array
    {
        $actividad = $configuracion?->actividad_economica;
        $producto = $configuracion?->producto_sin;

        if ($actividad === null || $producto === null) {
            return self::punto('actividad', __('Actividad y producto SIN'), self::PENDIENTE, __('Elígelos en «Valores de la factura».'));
        }

        if (! CatalogoEmpresa::existe($empresaId, Catalogo::Actividades, $actividad) || ! CatalogoEmpresa::existe($empresaId, Catalogo::ProductosServicios, $producto)) {
            return self::punto('actividad', __('Actividad y producto SIN'), self::ERROR, __('No están en los catálogos sincronizados del SIN. Vuelve a elegirlos.'));
        }

        return self::punto('actividad', __('Actividad y producto SIN'), self::CORRECTO, __('Actividad :actividad · producto :producto.', ['actividad' => $actividad, 'producto' => $producto]));
    }

    /**
     * Catálogos sin los que no se factura y que todavía no se sincronizaron.
     *
     * @return list<string>
     */
    public static function catalogosFaltantes(int $empresaId): array
    {
        $sincronizados = DeEmpresa::consulta(SiatSincronizacion::class, $empresaId)->whereNotNull('sincronizado_en')->pluck('catalogo')->all();

        return array_values(array_map(fn (Catalogo $catalogo): string => __($catalogo->etiqueta()), array_filter(
            PreparacionFacturacion::CATALOGOS_NECESARIOS,
            fn (Catalogo $catalogo): bool => ! in_array($catalogo->name, $sincronizados, true),
        )));
    }

    /**
     * Comprobaciones locales del token guardado frente al sistema actual (el código de sistema puede cambiar en el servidor).
     */
    private static function errorDeToken(SiatConfiguracion $configuracion, ConfiguracionSistema $sistema): ?string
    {
        try {
            $datos = DatosToken::leer(new TokenDelegado($configuracion->tokenDescifrado()));
        } catch (\Throwable) {
            return __('No se pudo leer el token guardado. Vuelve a cargarlo.');
        }

        if ($datos !== null && $datos->codigoSistema !== null && $datos->codigoSistema !== $sistema->codigoSistema) {
            return __('El token es de otro sistema. Genéralo para el sistema :sistema.', ['sistema' => $sistema->codigoSistema]);
        }

        if ($datos !== null && $configuracion->nit !== null && ! $datos->incluyeNit($configuracion->nit)) {
            return __('El token no corresponde al NIT :nit.', ['nit' => $configuracion->nit]);
        }

        return null;
    }

    /**
     * Resultado guardado, solo si se verificó con los mismos datos que hay ahora.
     *
     * @return array{resultado: string, mensajes: list<string>, en: Carbon|null}|null
     */
    private static function ultimaVerificacion(SiatConfiguracion $configuracion, ConfiguracionSistema $sistema): ?array
    {
        $guardada = $configuracion->verificacion;

        if (! is_array($guardada) || ($guardada['huella'] ?? null) !== self::huella($configuracion, $sistema)) {
            return null;
        }

        return [
            'resultado' => (string) ($guardada['resultado'] ?? ''),
            'mensajes' => array_map('strval', (array) ($guardada['mensajes'] ?? [])),
            'en' => $configuracion->verificado_en,
        ];
    }

    /** Datos que el SIN verificó. No incluye el token: basta con su sufijo y la fecha en que se cargó. */
    private static function huella(SiatConfiguracion $configuracion, ConfiguracionSistema $sistema): string
    {
        return hash('sha256', implode('|', [
            $sistema->ambiente->value, $sistema->codigoSistema, $configuracion->nit, $configuracion->modalidad->value,
            $configuracion->token_sufijo, $configuracion->token_actualizado_en?->getTimestamp(),
        ]));
    }

    private static function sistema(): ?ConfiguracionSistema
    {
        try {
            return app(ConfiguracionSistema::class);
        } catch (DatosInvalidos) {
            return null;
        }
    }

    public static function ambiente(ConfiguracionSistema $sistema): string
    {
        return $sistema->ambiente === Ambiente::Produccion ? __('Producción') : __('Pruebas (piloto del SIN)');
    }

    /**
     * @return array{clave: string, punto: string, estado: string, detalle: string}
     */
    private static function punto(string $clave, string $punto, string $estado, string $detalle): array
    {
        return compact('clave', 'punto', 'estado', 'detalle');
    }
}
