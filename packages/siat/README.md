# mozistudios/siat

Cliente PHP para la facturación computarizada en línea del SIN de Bolivia (SIAT, servicios V3 `/sfd/`, IVA transparente según la Ley 1733).

No depende de Laravel ni de ningún framework, y no usa base de datos. Cualquier sistema PHP puede integrarlo. MoziStock lo conecta a Laravel en `app/Providers/SiatServiceProvider.php`.

**Estado: versión 0.1.0, fase 1.** Todavía no se probó contra el piloto del SIN; el CUF y el XML solo están comprobados con los ejemplos oficiales del anexo. No emitir en producción sin autorización del SIN.

## Qué incluye

| Área | Clases |
|---|---|
| Configuración del sistema proveedor | `ConfiguracionSistema` (código de sistema, ambiente, URL, tiempos de espera) |
| Emisor por empresa | `Contribuyente` (NIT y `TokenDelegado`), `PuntoEmision` (sucursal y punto de venta del SIN), `DatosToken` (NIT, sistema y vencimiento leídos del token, sin verificar la firma) |
| Códigos | `ServicioCodigos`: comunicación, CUIS, CUFD con `codigoInterno` y verificación de NIT |
| Sincronización | `ServicioSincronizacion`: los 17 catálogos de `Catalogo` (con `etiqueta()` en español) y la fecha y hora oficial |
| Operaciones | `ServicioOperaciones`: registrar, consultar y cerrar puntos de venta; registro y consulta de eventos significativos (`RespuestaListaEventos`, `EventoSignificativoSin`) |
| Factura compra-venta | `FacturaCompraVenta`, `DetalleCompraVenta`, `GeneradorXmlCompraVenta`, `ValidadorXsd` (XSD oficial incluido) |
| Montos | `VerificadorMontos` (fórmulas oficiales) y `Decimal` (bcmath con redondeo HALF-UP, sin `float`) |
| Envío | `PreparadorFactura` (CUF, XML, XSD, gzip y SHA-256) y `ServicioCompraVenta` (recepción individual, recepción y validación de paquetes, estado, anulación, reversión) |
| Respuestas | `RespuestaRecepcion` con `validada()`, `observada()`, `pendiente()`, `rechazada()`, `anulacionConfirmada()`, `reversionConfirmada()`; `RespuestaListaEventos` |
| Representación gráfica | `EnlaceQr`: enlace de consulta del SIN para el QR (`?nit=&cuf=&numero=&t=`, `ROLLO` = 1, `MEDIA_PAGINA` = 2). La base del piloto es `EnlaceQr::URL_PILOTO`; la de producción se recibe como parámetro. El paquete no dibuja el QR ni la factura: eso queda en quien integra |
| Algoritmos | `GeneradorCuf`, `Modulo11`, `Base16`, `Archivo` (GZIP, SHA-256, empaquetado TAR ustar con `PharData`) |
| Pruebas | `Testing\TransporteFalso` (sin red) |

Pendiente para fases siguientes:
- Emisión masiva y CUFD masivo (si el portal lo exige).
- Cierre de operaciones del sistema (`FacturacionOperaciones`).
- Nota de Crédito-Débito.

## Requisitos

PHP 8.3 o superior con las extensiones `soap`, `bcmath`, `dom`, `libxml` y `zlib`. No tiene otras dependencias.

## Instalación en otro proyecto

Mientras no se publique, se instala como repositorio local de Composer:

```json
"repositories": [{ "type": "path", "url": "packages/siat", "options": { "symlink": true } }],
"require": { "mozistudios/siat": "^0.1" }
```

## Uso

```php
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Configuracion\{ConfiguracionSistema, Contribuyente, PuntoEmision, TokenDelegado};
use MoziStudios\Siat\Factura\{DetalleCompraVenta, FacturaCompraVenta};
use MoziStudios\Siat\Fecha\FechaSiat;
use MoziStudios\Siat\Soap\SoapTransporte;

// Una vez por sistema: datos del proveedor, que no son secretos.
$siat = new ClienteSiat(new ConfiguracionSistema('CODIGO_SISTEMA'), new SoapTransporte());

// Una vez por empresa y por solicitud: su NIT y su token (descifrado solo en memoria).
$empresa = new Contribuyente(1234567019, new TokenDelegado($tokenDescifrado));
$punto = new PuntoEmision(sucursal: 0, puntoVenta: 0);

$cuis = $siat->codigos($empresa)->cuis($punto)->codigo;
$cufd = $siat->codigos($empresa)->cufd($punto, $cuis)->cufd;   // guardar con su vigencia y codigoInterno

$factura = new FacturaCompraVenta(
    nitEmisor: 1234567019,
    razonSocialEmisor: 'Mi Empresa S.R.L.',
    // ... resto de la cabecera ...
    fechaEmision: FechaSiat::desde(new DateTimeImmutable('now', new DateTimeZone('America/La_Paz'))),
    cufd: $cufd,
    detalles: [new DetalleCompraVenta(/* actividad, producto SIN, código, descripción, cantidad, unidad, precio neto, subtotal */)],
);

$preparada = $siat->preparador()->preparar($factura);   // guardar cuf, xml y hashArchivo ANTES de enviar
$respuesta = $siat->compraVenta($empresa)->recepcionFactura($preparada, $cuis);

if ($respuesta->validada()) { /* 908 */ }
```

Antes de guardar un token cargado por el usuario conviene revisarlo con `DatosToken`:

```php
use MoziStudios\Siat\Configuracion\DatosToken;

$datos = DatosToken::leer(new TokenDelegado($tokenPegado));   // null si no es un JWT legible

if ($datos === null || ! $datos->incluyeNit($nit) || $datos->codigoSistema !== 'CODIGO_SISTEMA' || $datos->vencido(new DateTimeImmutable())) {
    // rechazar; nunca mostrar el token, solo su sufijo
}
```

## Reglas para quien integra

- **Un `Contribuyente` por empresa.** Cada servicio queda atado al NIT y al token con que se creó, y `recepcionFactura` rechaza una factura de otro NIT.
- **Guardar antes de enviar.** Primero se guardan el número de factura, el CUF, el XML y el hash; después se llama al SIN, fuera de las transacciones de stock o dinero. Si la respuesta no llega, se consulta con `verificacionEstado`; no se reenvía con otro número.
- **Montos.** El paquete no calcula precios: recibe montos ya calculados, y `PreparadorFactura` rechaza los que no cumplen las fórmulas oficiales. `preparar($factura, false)` existe solo para pruebas controladas en el piloto.
- **Token.** `TokenDelegado` no se muestra en `var_dump`, `print_r`, `var_export` ni `json_encode`, y no se serializa. Se guarda cifrado y se descifra solo para la llamada.
- **Errores.** Todas las excepciones heredan de `SiatException`:
  - `DatosInvalidos`: datos de entrada incorrectos.
  - `XmlInvalido`: el XML no cumple el XSD; los detalles están en `->errores`.
  - `ErrorComunicacion`: el SIN no respondió o respondió mal; el mensaje nunca incluye el token.
- **Bitácora.** Se puede pasar un `ObservadorLlamadas` a `SoapTransporte` para registrar la duración y el resultado de cada llamada. No recibe el token ni el XML.

## Pruebas

En MoziStock se ejecutan desde `app/`:

```bash
php artisan test --testsuite=Siat
```

Los tests no usan la red. Cubren:
- Los ejemplos oficiales de CUF, módulo 11, base 16 y redondeo.
- El XML de ejemplo del anexo validado contra el XSD.
- La forma de cada solicitud SOAP.
- Que el token no se filtre.
