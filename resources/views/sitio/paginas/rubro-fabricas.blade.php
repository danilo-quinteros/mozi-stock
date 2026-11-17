<x-sitio.rubro :pagina="$pagina" :seo="$seo" :esquema="$esquema"
    etiqueta="Rubro · Fábricas y laboratorios"
    titulo="Sistema para fábricas y laboratorios en <span class='hl'>Bolivia</span>"
    lead="Registra tus recetas, emite órdenes de producción que descuentan la materia prima con su lote y vencimiento, envasa lo producido en presentaciones y conoce el costo real de cada lote. Después vendes lo que fabricas con el mismo sistema, con facturación SIAT."
    :puntos="['Recetas con versiones', 'Órdenes de producción', 'Envasado con merma']"
    :dolores="[
        ['No sé cuánto me cuesta producir', 'Cada orden calcula su costo con el costo promedio de cada materia prima y te da el costo por unidad de lo que obtuviste. Al envasar, cada presentación suma su botella, tapa o etiqueta.'],
        ['La materia prima se acaba a mitad de la producción', 'Al emitir la orden ves qué falta en la sucursal. Al aprobarla, la materia prima se descuenta del almacén; si no alcanza, no se aprueba y el stock nunca queda en negativo.'],
        ['Cambié la fórmula y no sé con cuál se hizo cada lote', 'Cada cambio de receta crea una versión nueva. Cada orden guarda los ingredientes con los que se emitió, y el lote producido lleva el número de su orden.'],
        ['Se pierden envases y producto al envasar', 'Al envasar escribes las unidades buenas y lo que realmente usaste. La diferencia queda como merma y su costo se reparte entre las presentaciones.'],
    ]"
    :funciones="[
        ['doc', 'Recetas', 'Ingredientes para una cantidad base (100 L, 20 kg o la que uses), con historial de versiones.'],
        ['flask', 'Órdenes de producción', 'Emitida, aprobada y finalizada, con el consumo real y la cantidad obtenida.'],
        ['box', 'Materias primas', 'Insumos y envases con stock por sucursal, mínimo y máximo. Nunca aparecen en la venta.'],
        ['calendar', 'Lotes y vencimientos', 'La materia prima sale del lote que vence primero y lo producido entra con su lote y vencimiento.'],
        ['swap', 'Envasado', 'Pasa el granel a presentaciones de 1 L, 5 L o las que vendas, con su botella, tapa y etiqueta.'],
        ['chart', 'Reportes de producción', 'Producción por producto, rendimiento y costo por orden, y consumo de materias primas, en CSV.'],
    ]"
    :modulos="['inventario', 'compras', 'reportes', 'multisucursal', 'punto-de-venta', 'facturacion']"
    :preguntas="[
        ['¿Qué tipo de fábricas pueden usarlo?', 'Las que elaboran sus productos a partir de una receta con materias primas: productos de limpieza, cosméticos, alimentos, bebidas, laboratorios y otros.'],
        ['¿Cómo se calcula el costo de producción?', 'Con el costo promedio de cada materia prima, que se actualiza con cada compra. El costo de la orden es lo que realmente se consumió por ese costo; dividido entre lo obtenido, es el costo por unidad.'],
        ['¿Puedo vender lo que produzco con el mismo sistema?', 'Sí. Lo producido entra al stock de la sucursal y se vende como cualquier producto, a granel o en presentaciones, con pedidos, créditos y facturación SIAT. Las materias primas nunca aparecen en la venta.'],
        ['¿Puedo saber de qué orden salió cada lote?', 'Sí. Cada lote producido lleva el número de su orden (por ejemplo OP-0015) y su vencimiento. Desde el lote llegas a la orden, con lo que se consumió y lo que se obtuvo.'],
        ['¿Quién puede usar el módulo de producción?', 'Solo el administrador de la empresa. Los vendedores siguen vendiendo con normalidad y no ven recetas, costos ni órdenes.'],
    ]"
    cierre="Ordena tu producción desde hoy">
    <x-slot:visual>
        <div class="visual-card">
            <div class="visual-title">Orden OP-0015<small>Suavizante floral · 200 L</small></div>
            <div class="demo">
                <div class="demo-row"><span><b>Agua desmineralizada</b><small>Lote AG-112</small></span><strong>180 L</strong></div>
                <div class="demo-row"><span><b>Base suavizante</b><small>Lote BS-31 · vence 08/2027</small></span><strong>16 kg</strong></div>
                <div class="demo-row"><span><b>Fragancia floral</b><small>Lote FR-07 · vence 03/2027</small></span><strong>2,4 L</strong></div>
                <div class="demo-foot"><span>Costo de la orden</span><b class="accent">Bs 970,00</b></div>
            </div>
            <div class="seg"><span>Emitida</span><span class="on">Aprobada</span><span>Finalizada</span></div>
        </div>
    </x-slot:visual>
</x-sitio.rubro>
