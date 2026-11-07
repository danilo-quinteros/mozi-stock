<?php

namespace App\Http\Controllers;

use App\Domain\AgenteMozi\Lienzo\DocumentoSandbox;
use App\Enums\TipoArtefacto;
use App\Models\AgenteArtefacto;
use App\Models\AgenteDataset;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Datos y documentos del Lienzo de Agente Mozi. La empresa sale de la sesión (EmpresaScope) y además
 * se exige ser el autor de la conversación: otra empresa u otro usuario reciben 404, aunque conozcan
 * la referencia o el ID. Nada se guarda en cachés compartidas.
 */
class AgenteMoziController extends Controller
{
    /** Dataset tipado (§ 6.3) que sustenta los artefactos de una respuesta. */
    public function dataset(Request $request, string $ref): JsonResponse
    {
        $dataset = $this->buscarDataset($request, $ref);

        return response()->json([
            'dataset_ref' => $dataset->ref,
            'schema_version' => '1.0',
            'columns' => $dataset->columnas,
            'rows' => $dataset->filas,
            'row_count' => $dataset->total_filas,
            'meta' => $dataset->meta,
        ], 200, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function csv(Request $request, string $ref): StreamedResponse
    {
        $dataset = $this->buscarDataset($request, $ref);
        $campos = array_column($dataset->columnas, 'field');

        return Csv::descargar('agente-mozi-'.$dataset->id.'.csv', array_column($dataset->columnas, 'label'),
            fn () => array_map(fn (array $fila) => array_map(fn (string $campo) => $fila[$campo] ?? null, $campos), $dataset->filas));
    }

    /** Documento aislado de un artefacto sandbox_web, con su CSP (origen opaco, sin red). */
    public function sandbox(Request $request, AgenteArtefacto $artefacto, DocumentoSandbox $documento): Response
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $artefacto->conversacion)
            && $artefacto->tipo === TipoArtefacto::SandboxWeb && (bool) config('agente_mozi.sandbox_web'), 404);

        return response($documento->construir($artefacto), 200, DocumentoSandbox::cabeceras());
    }

    private function buscarDataset(Request $request, string $ref): AgenteDataset
    {
        Gate::forUser($request->user())->authorize('usar-agente-mozi');
        abort_unless(preg_match('/^ds_[A-Za-z0-9]{32}$/', $ref) === 1, 404);

        $dataset = AgenteDataset::query()->where('ref', $ref)->where('usuario_id', $request->user()?->id)->firstOrFail();
        abort_if($dataset->expirado(), 410);

        return $dataset;
    }
}
