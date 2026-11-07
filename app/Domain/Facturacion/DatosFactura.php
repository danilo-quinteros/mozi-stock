<?php

namespace App\Domain\Facturacion;

use App\Enums\MetodoPago;
use App\Models\Cliente;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use MoziStudios\Siat\Factura\NumeroTarjeta;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Datos del comprador y del pago que el usuario confirma para una factura. Aquí solo se valida su
 * forma; la pertenencia a los catálogos del SIN se comprueba al registrar la factura (si falla, la
 * factura queda bloqueada con el motivo, sin detener la venta).
 */
final class DatosFactura
{
    /** Paramétrica TipoDocumentoIdentidad del anexo: 1 = CI, 5 = NIT (el paquete valida los mismos). */
    public const TIPO_CI = 1;

    public const TIPO_NIT = 5;

    /**
     * Comprador que no da sus datos: código especial 99002 con tipo NIT, excepción 1 y nominatividad oficial
     * exigida por el SIN: «CONTROL TRIBUTARIO» (RND 102100000011).
     */
    public const SIN_DATOS_DOCUMENTO = '99002';

    public const SIN_DATOS_RAZON_SOCIAL = 'CONTROL TRIBUTARIO';

    private const CODIGOS_ESPECIALES = ['99001', '99002', '99003'];

    public function __construct(
        public readonly int $tipoDocumento,
        public readonly string $numeroDocumento,
        public readonly ?string $complemento,
        public readonly string $razonSocial,
        public readonly ?int $metodoPago,
        public readonly ?string $numeroTarjeta = null,
        public readonly int $codigoExcepcion = 0,
    ) {}

    /**
     * Reglas de forma; $prefijo permite validar un arreglo anidado de Livewire («factura.»).
     *
     * @return array<string, list<mixed>>
     */
    public static function reglas(string $prefijo = ''): array
    {
        return [
            $prefijo.'tipo_documento' => ['required', 'integer', 'min:1', 'max:255'],
            $prefijo.'numero_documento' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
            $prefijo.'complemento' => ['nullable', 'string', 'max:5', 'regex:/^[A-Za-z0-9]+$/'],
            $prefijo.'razon_social' => ['required', 'string', 'max:500'],
            $prefijo.'metodo_pago' => ['required', 'integer', 'min:1', 'max:65535'],
            $prefijo.'tarjeta_inicio' => ['nullable', 'digits:4', 'required_with:'.$prefijo.'tarjeta_fin'],
            $prefijo.'tarjeta_fin' => ['nullable', 'digits:4', 'required_with:'.$prefijo.'tarjeta_inicio'],
            $prefijo.'nit_sin_validar' => ['boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $datos  campos de reglas(), sin prefijo
     *
     * @throws ValidationException
     */
    public static function desde(array $datos, string $prefijo = ''): self
    {
        $validos = Validator::make($datos, self::reglas(), [
            'numero_documento.regex' => __('El número de documento solo admite letras, números y guiones.'),
            'complemento.regex' => __('El complemento solo admite letras y números.'),
            'tarjeta_inicio.digits' => __('Escribe los primeros 4 dígitos de la tarjeta.'),
            'tarjeta_fin.digits' => __('Escribe los últimos 4 dígitos de la tarjeta.'),
        ], [
            'tipo_documento' => __('tipo de documento'),
            'numero_documento' => __('número de documento'),
            'razon_social' => __('nombre o razón social'),
            'metodo_pago' => __('método de pago fiscal'),
        ])->validate();

        $tipo = (int) $validos['tipo_documento'];
        $numero = strtoupper(trim((string) $validos['numero_documento']));
        $especial = in_array($numero, self::CODIGOS_ESPECIALES, true);

        if ($especial && $tipo !== self::TIPO_NIT) {
            throw ValidationException::withMessages([$prefijo.'tipo_documento' => __('Los códigos 99001, 99002 y 99003 van con tipo de documento NIT.')]);
        }

        if (in_array($tipo, [self::TIPO_CI, self::TIPO_NIT], true) && ! ctype_digit($numero)) {
            throw ValidationException::withMessages([$prefijo.'numero_documento' => __('Con CI o NIT el número de documento tiene solo dígitos.')]);
        }

        $complemento = filled($validos['complemento'] ?? null) ? strtoupper(trim((string) $validos['complemento'])) : null;
        $tarjeta = filled($validos['tarjeta_inicio'] ?? null)
            ? NumeroTarjeta::ofuscado((string) $validos['tarjeta_inicio'], (string) $validos['tarjeta_fin'])
            : null;
        // Excepción 1: el SIN no valida el NIT (obligatoria con códigos especiales; opcional con un NIT que el SIN no reconoce).
        $excepcion = $especial || ($tipo === self::TIPO_NIT && (bool) ($validos['nit_sin_validar'] ?? false)) ? 1 : 0;
        $razonSocial = trim((string) $validos['razon_social']);
        if ($numero === self::SIN_DATOS_DOCUMENTO && ($razonSocial === '' || $razonSocial === 'S/N')) {
            $razonSocial = self::SIN_DATOS_RAZON_SOCIAL;
        }

        return new self($tipo, $numero, $complemento, $razonSocial,
            (int) $validos['metodo_pago'], $tarjeta, $excepcion);
    }

    /**
     * Valores para precargar el formulario: los del cliente de la venta o los del comprador sin datos.
     * Un CI boliviano «1234567-1B» se separa en número y complemento.
     *
     * @return array{tipo_documento: string, numero_documento: string, complemento: string, razon_social: string, metodo_pago: string, tarjeta_inicio: string, tarjeta_fin: string, nit_sin_validar: bool}
     */
    public static function sugeridos(?Cliente $cliente, ?int $metodoPago): array
    {
        $documento = strtoupper(trim((string) $cliente?->documento));
        [$numero, $complemento] = array_pad(explode('-', $documento, 2), 2, '');

        if ($cliente === null || ! ctype_digit($numero)) {
            $tipo = self::TIPO_NIT;
            $numero = self::SIN_DATOS_DOCUMENTO;
            $complemento = '';
            $razonSocial = self::SIN_DATOS_RAZON_SOCIAL;
        } else {
            // Propuesta: hasta 8 dígitos suele ser un CI; más, un NIT. El usuario la corrige si no es así.
            $tipo = strlen($numero) <= 8 ? self::TIPO_CI : self::TIPO_NIT;
            $complemento = $tipo === self::TIPO_CI ? $complemento : '';
            $razonSocial = $cliente->nombre;
        }

        return [
            'tipo_documento' => (string) $tipo,
            'numero_documento' => $numero,
            'complemento' => $complemento,
            'razon_social' => $razonSocial,
            'metodo_pago' => $metodoPago === null ? '' : (string) $metodoPago,
            'tarjeta_inicio' => '',
            'tarjeta_fin' => '',
            'nit_sin_validar' => false,
        ];
    }

    /**
     * Método de pago fiscal propuesto según cómo se cobró:
     * - Efectivo: «EFECTIVO» (código 1 del SIN).
     * - Electrónico: «TRANSFERENCIA BANCARIA» (código 7 del SIN) o el configurado por la empresa.
     * - Mixto: «EFECTIVO – TRANSFERENCIA BANCARIA» (código 13 del SIN).
     * - Crédito sin cuota inicial: «PAGO POSTERIOR» (código 6 del SIN).
     * - Crédito con cuota inicial en efectivo: «EFECTIVO – PAGO POSTERIOR» (código 34 del SIN) o combinación de catálogo.
     * - Crédito con cuota inicial electrónica: «PAGO POSTERIOR – TRANSFERENCIA BANCARIA» (código 57 del SIN) o combinación de catálogo.
     * - Crédito con cuota inicial mixta: «EFECTIVO – PAGO POSTERIOR - TRANSFERENCIA BANCARIA» (código 106 del SIN) o combinación de catálogo.
     */
    public static function metodoSugerido(int $empresaId, ?MetodoPago $metodo, bool $conSaldo, ?int $electronicoEmpresa = null): ?int
    {
        if ($conSaldo) {
            return match ($metodo) {
                null => CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'PAGO POSTERIOR')
                    ?? CatalogoEmpresa::buscarPorTerminos($empresaId, Catalogo::TiposMetodoPago, ['POSTERIOR'])
                    ?? 6,

                MetodoPago::Efectivo => CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'EFECTIVO - PAGO POSTERIOR')
                    ?? CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'EFECTIVO – PAGO POSTERIOR')
                    ?? CatalogoEmpresa::buscarPorTerminos($empresaId, Catalogo::TiposMetodoPago, ['EFECTIVO', 'POSTERIOR'])
                    ?? CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'PAGO POSTERIOR')
                    ?? CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'EFECTIVO')
                    ?? 34,

                MetodoPago::Electronico => self::sugerirElectronicoConSaldo($empresaId, $electronicoEmpresa),

                MetodoPago::Mixto => CatalogoEmpresa::buscarPorTerminos($empresaId, Catalogo::TiposMetodoPago, ['EFECTIVO', 'POSTERIOR', 'TRANSFERENCIA'])
                    ?? CatalogoEmpresa::buscarPorTerminos($empresaId, Catalogo::TiposMetodoPago, ['EFECTIVO', 'POSTERIOR', 'TARJETA'])
                    ?? CatalogoEmpresa::buscarPorTerminos($empresaId, Catalogo::TiposMetodoPago, ['EFECTIVO', 'POSTERIOR'])
                    ?? CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'PAGO POSTERIOR')
                    ?? CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'EFECTIVO – TRANSFERENCIA BANCARIA')
                    ?? 106,
            };
        }

        return match ($metodo) {
            MetodoPago::Efectivo => CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'EFECTIVO') ?? 1,
            MetodoPago::Electronico => $electronicoEmpresa
                ?? CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'TRANSFERENCIA BANCARIA')
                ?? 7,
            MetodoPago::Mixto => CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'EFECTIVO – TRANSFERENCIA BANCARIA')
                ?? CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'EFECTIVO - TRANSFERENCIA BANCARIA')
                ?? CatalogoEmpresa::buscarPorTerminos($empresaId, Catalogo::TiposMetodoPago, ['EFECTIVO', 'TRANSFERENCIA'])
                ?? 13,
            default => null,
        };
    }

    private static function sugerirElectronicoConSaldo(int $empresaId, ?int $electronicoEmpresa): int
    {
        $desc = $electronicoEmpresa !== null
            ? CatalogoEmpresa::descripcion($empresaId, Catalogo::TiposMetodoPago, $electronicoEmpresa)
            : null;

        if ($desc !== null && str_contains(mb_strtoupper($desc), 'TARJETA')) {
            $codigo = CatalogoEmpresa::buscarPorTerminos($empresaId, Catalogo::TiposMetodoPago, ['TARJETA', 'POSTERIOR']);
            if ($codigo !== null) {
                return $codigo;
            }
        }

        return CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'PAGO POSTERIOR – TRANSFERENCIA BANCARIA')
            ?? CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'PAGO POSTERIOR - TRANSFERENCIA BANCARIA')
            ?? CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'TRANSFERENCIA BANCARIA - PAGO POSTERIOR')
            ?? CatalogoEmpresa::buscarPorTerminos($empresaId, Catalogo::TiposMetodoPago, ['POSTERIOR', 'TRANSFERENCIA'])
            ?? CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'PAGO POSTERIOR')
            ?? $electronicoEmpresa
            ?? CatalogoEmpresa::codigoPorDescripcion($empresaId, Catalogo::TiposMetodoPago, 'TRANSFERENCIA BANCARIA')
            ?? 57;
    }

    /**
     * Código del comprador en la factura: su documento (con complemento si lo tiene).
     */
    public function codigoCliente(): string
    {
        return $this->complemento === null ? $this->numeroDocumento : "{$this->numeroDocumento}-{$this->complemento}";
    }
}
