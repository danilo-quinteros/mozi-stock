{{-- Estado de un lote con stock (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 3 y § 9). Variable: $lote con
     vencimiento (Y-m-d o null), regularizacion y vencido, como los devuelve StockLotes. Opcional: sin_stock (true si el
     lote no tiene stock en ninguna sucursal; solo la trazabilidad lo envía): el lote «Sin lote» vacío ya no tiene nada
     pendiente y se muestra «Regularizado». --}}
@php($dias = $lote['vencimiento'] === null ? null : (int) \Illuminate\Support\Carbon::parse(\App\Domain\Inventario\DatosLote::hoy())->diffInDays(\Illuminate\Support\Carbon::parse($lote['vencimiento']), false))
@if ($lote['regularizacion'] && ($lote['sin_stock'] ?? false))
    <span class="badge success" data-estado-lote="regularizado">{{ __('Regularizado') }}</span>
@elseif ($lote['regularizacion'])
    <span class="badge info" data-estado-lote="sin-fecha">{{ __('Sin fecha · por regularizar') }}</span>
@elseif ($lote['vencido'])
    <span class="badge danger" data-estado-lote="vencido">{{ __('Vencido') }}</span>
@elseif ($dias === 0)
    <span class="badge warning" data-estado-lote="por-vencer">{{ __('Vence hoy') }}</span>
@elseif ($dias !== null && $dias <= 90)
    <span class="badge warning" data-estado-lote="por-vencer">{{ trans_choice('Vence en :count día|Vence en :count días', $dias) }}</span>
@else
    <span class="badge success" data-estado-lote="vigente">{{ __('Vigente') }}</span>
@endif
