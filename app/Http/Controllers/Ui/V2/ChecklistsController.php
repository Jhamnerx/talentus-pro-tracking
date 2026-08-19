<?php

namespace App\Http\Controllers\Ui\V2;

use CustomFacades\Validators\ChecklistTemplateFormValidator;
use CustomFacades\Validators\ChecklistValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tobuli\Entities\Checklist;
use Tobuli\Entities\ChecklistRow;
use Tobuli\Entities\ChecklistTemplate;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\ChecklistService;

/**
 * TalentusPro · Checklists (addon `checklists`)
 * -----------------------------------------------------------------
 * Paridad ChecklistTemplateController + ChecklistsController:
 *  · Plantillas (nombre, tipo y actividades) reutilizando saveRows().
 *  · Checklists de un servicio: crear desde plantilla, marcar actividades,
 *    anotar resultado, notas y firma — todo vía ChecklistService, que es
 *    quien valida las reglas (orden, permisos de firma, etc.).
 *
 * El addon está apagado en esta instalación; los endpoints responden 404
 * igual que el legacy hasta que se active.
 */
class ChecklistsController extends BaseController
{
    private ChecklistService $service;

    public function __construct(ChecklistService $service)
    {
        parent::__construct();

        $this->service = $service;
    }

    /** Sin el addon, el módulo no existe (el legacy ni siquiera monta la UI). */
    private function assertEnabled(): void
    {
        if (! config('addon.checklists')) {
            abort(404);
        }
    }

    /* ------------------------------------------------------------------
     * Plantillas
     * ---------------------------------------------------------------- */

    public function templates(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $this->checkException('checklist_template', 'view');

        $rows = ChecklistTemplate::available()->with('rows')->get();

        return response()->json([
            'ok'    => true,
            'types' => collect(ChecklistTemplate::getTypes())
                ->map(function ($label, $id) {
                    return ['id' => (int) $id, 'label' => (string) $label];
                })->values()->all(),
            'items' => $rows->map(function ($t) {
                return [
                    'id'   => (int) $t->id,
                    'name' => (string) $t->name,
                    'type' => (int) $t->type,
                    'type_label' => (string) $t->type_name,
                    'rows' => $t->rows->map(function ($r) {
                        return ['id' => (int) $r->id, 'activity' => (string) $r->activity];
                    })->all(),
                ];
            })->all(),
            'can_edit' => (bool) $this->user->perm('checklist_template', 'edit'),
        ]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $this->checkException('checklist_template', 'store');

        try {
            $data = $this->templatePayload($request);
            ChecklistTemplateFormValidator::validate('store', $data);

            $template = ChecklistTemplate::create($data);
            $template->saveRows($data['rows']);

            return response()->json(['ok' => true, 'id' => (int) $template->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function updateTemplate(Request $request, $id): JsonResponse
    {
        $this->assertEnabled();

        $template = ChecklistTemplate::find((int) $id);
        if (! $template) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('checklist_template', 'update', $template);

        try {
            $data = $this->templatePayload($request);
            ChecklistTemplateFormValidator::validate('update', $data);

            $template->update($data);
            $template->saveRows($data['rows']);

            return response()->json(['ok' => true, 'id' => (int) $template->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function destroyTemplate(Request $request, $id): JsonResponse
    {
        $this->assertEnabled();

        $template = ChecklistTemplate::find((int) $id);
        if (! $template) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('checklist_template', 'remove', $template);

        $template->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Normaliza el payload al formato que espera saveRows():
     * rows = [<id existente> => actividad, ..., 'new' => [actividades nuevas]].
     * Se exige al menos una actividad, igual que validateRows() del legacy.
     */
    private function templatePayload(Request $request): array
    {
        $existing = (array) $request->input('rows', []);
        $new      = array_values(array_filter(array_map('trim', (array) $request->input('new_rows', []))));

        $rows = [];
        foreach ($existing as $rowId => $activity) {
            $activity = trim((string) $activity);
            if ($activity !== '') {
                $rows[(int) $rowId] = $activity;
            }
        }

        if (! $rows && ! $new) {
            throw new ValidationException(['rows' => [trans('validation.min.array', [
                'attribute' => trans('front.activity'),
                'min'       => 1,
            ])]]);
        }

        $rows['new'] = $new;

        return [
            'name'    => (string) $request->input('name'),
            'type'    => (int) $request->input('type'),
            'user_id' => $this->user->id,
            'rows'    => $rows,
        ];
    }

    /* ------------------------------------------------------------------
     * Checklists de un servicio
     * ---------------------------------------------------------------- */

    public function index(Request $request, $serviceId): JsonResponse
    {
        $this->assertEnabled();
        $this->checkException('checklist', 'view');

        $items = Checklist::where('service_id', (int) $serviceId)
            ->with('rows')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'ok'        => true,
            'items'     => $items->map([$this, 'presentChecklist'])->all(),
            // Plantillas elegibles para crear uno nuevo
            'templates' => ChecklistTemplate::available()->get()
                ->map(function ($t) {
                    return ['id' => (int) $t->id, 'name' => (string) $t->name, 'type_label' => (string) $t->type_name];
                })->all(),
            'can_edit'  => (bool) $this->user->perm('checklist_activity', 'edit'),
            'can_add'   => (bool) $this->user->perm('checklist', 'edit'),
            // Una actividad solo se puede marcar con resultado y, salvo que el
            // permiso checklist_optional_image lo exima, con al menos una foto.
            'outcomes'  => [
                ['id' => \Tobuli\Entities\ChecklistRow::OUTCOME_PASS, 'label' => trans('global.pass')],
                ['id' => \Tobuli\Entities\ChecklistRow::OUTCOME_FAIL, 'label' => trans('global.fail')],
            ],
            'image_optional' => (bool) $this->user->perm('checklist_optional_image', 'view'),
        ]);
    }

    public function presentChecklist(Checklist $c): array
    {
        return [
            'id'           => (int) $c->id,
            'name'         => (string) $c->name,
            'notes'        => (string) ($c->notes ?? ''),
            'signed'       => ! empty($c->signature),
            'completed_at' => $c->completed_at ? \Formatter::time()->convert($c->completed_at) : null,
            'rows'         => $c->rows->map(function ($r) {
                return [
                    'id'           => (int) $r->id,
                    'activity'     => (string) $r->activity,
                    'completed'    => (bool) $r->completed,
                    'outcome'      => (string) ($r->outcome ?? ''),
                    'completed_at' => $r->completed_at ? \Formatter::time()->convert($r->completed_at) : null,
                    'images'       => $r->images->map(function ($i) {
                        return ['id' => (int) $i->id, 'url' => url('/checklists/image/' . $i->id)];
                    })->all(),
                ];
            })->all(),
        ];
    }

    public function store(Request $request, $serviceId): JsonResponse
    {
        $this->assertEnabled();
        $this->checkException('checklist', 'store');

        try {
            $data = ['service_id' => (int) $serviceId, 'template_id' => $request->input('template_id')];
            ChecklistValidator::validate('create', $data);

            $this->service->createChecklist((int) $serviceId, $data['template_id']);

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $this->assertEnabled();

        $item = Checklist::find((int) $id);
        if (! $item) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('checklist', 'remove', $item);

        $item->delete();

        return response()->json(['ok' => true]);
    }

    /** Marcar/desmarcar una actividad (el servicio valida el orden). */
    public function updateRow(Request $request, $rowId): JsonResponse
    {
        $this->assertEnabled();

        $row = ChecklistRow::find((int) $rowId);
        if (! $row) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('checklist_activity', 'update', $row);

        try {
            if ($request->has('completed')) {
                $completed = (bool) $request->input('completed');
                $this->service->validateUpdateRowStatus($row, $completed);
                $this->service->updateRowStatus($row, $completed);
            }

            if ($request->has('outcome')) {
                ChecklistValidator::validate('outcome', ['outcome' => $request->input('outcome')]);
                $this->service->updateRowOutcome($row, $request->input('outcome'));
            }
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Foto de una actividad (máx. 4 por fila, lo valida el servicio). */
    public function uploadRowImage(Request $request, $rowId): JsonResponse
    {
        $this->assertEnabled();

        $row = ChecklistRow::find((int) $rowId);
        if (! $row) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('checklist_activity', 'update', $row);

        try {
            ChecklistValidator::validate('upload', ['file' => $request->file('file')]);
            $this->service->saveRowFile($row, $request->file('file'));
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function destroyRowImage(Request $request, $imageId): JsonResponse
    {
        $this->assertEnabled();

        $image = \Tobuli\Entities\ChecklistImage::find((int) $imageId);
        // Las imágenes ya archivadas en un histórico no se tocan (como el legacy).
        if (! $image || $image->checklist_history_id) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('checklist_activity', 'update', $image->checklistRow);

        if (! $this->service->deleteImage($image)) {
            return response()->json(['ok' => false], 404);
        }

        return response()->json(['ok' => true]);
    }

    /** Notas + firma (cierra el checklist). */
    public function sign(Request $request, $id): JsonResponse
    {
        $this->assertEnabled();

        $checklist = Checklist::find((int) $id);
        if (! $checklist) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('checklist', 'update', $checklist);

        try {
            if ($request->has('notes')) {
                $this->service->setChecklistNotes($checklist, (string) $request->input('notes'));
            }

            $signature = $request->input('signature');

            // El legacy rechaza la firma si quedan actividades sin completar.
            if (! $this->service->canSignChecklist($checklist, $signature)) {
                return response()->json([
                    'ok'      => false,
                    'message' => trans('front.incomplete_checklist'),
                ], 422);
            }

            $this->service->signChecklist($checklist, $signature);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }
}
