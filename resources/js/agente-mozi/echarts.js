// Apache ECharts 6 (Apache-2.0) con importación selectiva: solo los tipos de gráfico del protocolo del Lienzo
// (bar, line/area, donut, scatter) y el renderizador SVG. Se carga bajo demanda desde graficos.js.
import * as echarts from 'echarts/core';
import { BarChart, LineChart, PieChart, ScatterChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { LabelLayout } from 'echarts/features';
import { SVGRenderer } from 'echarts/renderers';

echarts.use([BarChart, LineChart, PieChart, ScatterChart, GridComponent, LegendComponent, TooltipComponent, LabelLayout, SVGRenderer]);

export default echarts;
