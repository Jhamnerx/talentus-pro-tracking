<?php

namespace App\Http\Controllers\Ui\V2;

use CustomFacades\Validators\TasksFormValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\Device;
use Tobuli\Entities\Task;
use Tobuli\Entities\TaskSet;
use Tobuli\Entities\TaskStatus;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Importers\ImportUtils;
use Tobuli\Importers\Task\TaskImportManager;
use Tobuli\Services\TaskSetUserService;
use Tobuli\Services\TaskUserService;

/**
 * TalentusPro · TasksController (ui/v2)
 * -----------------------------------------------------------------
 * Tareas de recogida/entrega. CRUD delegado en TaskUserService
 * legacy (validación TasksFormValidator: pickup/delivery address +
 * lat/lng + rango de tiempos obligatorios; task_set dedicado a un
 * device; onlyEditables). Autorización igual al legacy: módulo
 * 'tasks' + policy por modelo (can('remove')) y devices own en el
 * propio service. Listado con el scope accessibleUser (join
 * user_device_pivot: tareas de devices accesibles al usuario).
 * -----------------------------------------------------------------
 */
class TasksController extends BaseController
{
    private TaskUserService $service;
    private TaskSetUserService $setService;

    protected function afterAuth($user)
    {
        $this->service = new TaskUserService($user);
        $this->setService = new TaskSetUserService($user);
    }

    public function page()
    {
        if (! $this->user->perm('tasks', 'view')) {
            abort(403);
        }

        return View::make('ui.v2.pages.tasks', [
            'pageTitle'   => trans('front.tasks'),
            'statuses'    => TaskStatus::getList(),
            'canEdit'     => (bool) $this->user->perm('tasks', 'edit'),
            'canRemove'   => (bool) $this->user->perm('tasks', 'remove'),
            'canTaskSets' => (bool) $this->user->perm('task_sets', 'view'),
            'mapConfig'   => $this->buildMapConfig(),
        ]);
    }

    /** Lista paginada (búsqueda título/factura + filtros device/estado). */
    public function data(Request $request): JsonResponse
    {
        $this->checkException('tasks', 'view');

        $perPage = min(100, max(10, (int) $request->get('per_page', 20)));

        $query = Task::accessibleUser($this->user->id)
            ->select('tasks.*')
            ->with(['device', 'lastStatus']);

        if ($s = trim((string) $request->get('s'))) {
            $query->where(function ($q) use ($s) {
                $q->where('tasks.title', 'like', "%{$s}%")
                    ->orWhere('tasks.invoice_number', 'like', "%{$s}%");
            });
        }

        if ($deviceId = (int) $request->get('device_id')) {
            $query->where('tasks.device_id', $deviceId);
        }

        if ($status = (int) $request->get('status')) {
            $query->where('tasks.status', $status);
        }

        $items = $query->orderBy('tasks.id', 'desc')->paginate($perPage);

        $rows = [];
        foreach ($items as $task) {
            $rows[] = $this->present($task);
        }

        return response()->json([
            'ok'    => true,
            'items' => $rows,
            'page'  => $items->currentPage(),
            'last'  => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    /** Catálogos del formulario (+ item si edit). */
    public function meta(Request $request): JsonResponse
    {
        $this->checkException('tasks', 'view');

        $ac = settings('plugins.task_status_auto_change_by_device_position');

        $out = [
            'ok'         => true,
            'devices'    => $this->user->devices()->clearOrdersBy()->orderBy('name')
                ->get()
                ->map(function ($d) {
                    return ['id' => (int) $d->id, 'name' => (string) $d->name];
                })->all(),
            'statuses'   => $this->options(TaskStatus::getList()),
            'priorities' => $this->options(array_map(function ($v) {
                return trans($v);
            }, Task::$priorities)),
            'task_sets'  => $this->user->perm('task_sets', 'view')
                ? $this->options($this->user->taskSets()->orderBy('title')->pluck('title', 'id')->all())
                : null,
            'ac_enabled' => (bool) ($ac['status'] ?? false),
            'ac_values'  => (array) ($ac['options'] ?? []),
            // Campos personalizados de tarea (addon custom_fields_task), igual
            // que Tasks/index.blade + Tasks/edit.blade del legacy.
            'custom_fields' => $this->customFieldsMeta(),
            'item'       => null,
        ];

        if ($id = (int) $request->get('id')) {
            $task = Task::find($id);
            if (! $task) {
                abort(404);
            }
            $this->checkException('tasks', 'edit', $task);

            $out['item'] = [
                'id'                   => (int) $task->id,
                'device_id'            => (int) $task->device_id,
                'task_set_id'          => $task->task_set_id ? (int) $task->task_set_id : null,
                'title'                => (string) $task->title,
                'status'               => (int) $task->status,
                'priority'             => (int) $task->priority,
                'invoice_number'       => (string) ($task->invoice_number ?? ''),
                'comment'              => (string) ($task->comment ?? ''),
                'pickup_address'       => (string) ($task->pickup_address ?? ''),
                'pickup_address_lat'   => $task->pickup_address_lat,
                'pickup_address_lng'   => $task->pickup_address_lng,
                'pickup_time_from'     => (string) $task->pickup_time_from,
                'pickup_time_to'       => (string) $task->pickup_time_to,
                'pickup_ac'            => (int) $task->pickup_ac,
                'pickup_ac_radius'     => $task->pickup_ac_radius,
                'pickup_ac_duration'   => $task->pickup_ac_duration,
                'pickup_ac_status'     => $task->pickup_ac_status,
                'delivery_address'     => (string) ($task->delivery_address ?? ''),
                'delivery_address_lat' => $task->delivery_address_lat,
                'delivery_address_lng' => $task->delivery_address_lng,
                'delivery_time_from'   => (string) $task->delivery_time_from,
                'delivery_time_to'     => (string) $task->delivery_time_to,
                'delivery_ac'          => (int) $task->delivery_ac,
                'delivery_ac_radius'   => $task->delivery_ac_radius,
                'delivery_ac_duration' => $task->delivery_ac_duration,
                'delivery_ac_status'   => $task->delivery_ac_status,
                'custom_fields'        => $this->customFieldsValues($task),
            ];
        }

        return response()->json($out);
    }

    /**
     * Definición de los campos personalizados de tarea para el formulario.
     * Vacío si el addon está apagado (paridad @if config('addon.custom_fields_task')).
     */
    private function customFieldsMeta(): array
    {
        if (! config('addon.custom_fields_task')) {
            return [];
        }

        return (new Task())->customFields()->get()
            ->map(function ($f) {
                return [
                    'slug'        => (string) $f->slug,
                    'title'       => (string) $f->title,
                    'data_type'   => (string) $f->data_type,
                    'description' => (string) ($f->description ?? ''),
                    'required'    => (bool) $f->required,
                    'options'     => (array) ($f->options ?? []),
                    'default'     => $f->defaultValue,
                ];
            })->all();
    }

    /** Valores actuales de los campos personalizados de una tarea. */
    private function customFieldsValues(Task $task): array
    {
        if (! config('addon.custom_fields_task')) {
            return [];
        }

        $out = [];
        foreach ($task->customFields()->get() as $field) {
            $out[$field->slug] = $task->getCustomValue($field->slug);
        }

        return $out;
    }

    /**
     * Guarda los valores enviados. Sin la clave `custom_fields` no toca nada,
     * igual que TasksController legacy (que pasa `?? []`).
     */
    private function saveCustomFields(Task $task, Request $request): void
    {
        if (! config('addon.custom_fields_task')) {
            return;
        }

        (new \Tobuli\Services\CustomValuesService())
            ->saveCustomValues($task, (array) $request->input('custom_fields', []));
    }

    public function store(Request $request): JsonResponse
    {
        if ($error = $this->vehiculoInvalido($request)) {
            return $error;
        }

        try {
            $task = $this->service->create($request->all());
            $this->saveCustomFields($task, $request);

            return response()->json(['ok' => true, 'id' => (int) $task->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /**
     * El vehiculo se comprueba ANTES de llamar al servicio.
     *
     * TaskUserService::create() lee $data['device_id'] sin mirar si existe y,
     * cuando falta, lanza ResourseNotFoundException: la respuesta era un 404
     * "Dispositivo no fue encontrado", que ni es el codigo correcto ni le
     * dice al usuario lo que pasa de verdad (que no eligio vehiculo). Con un
     * 422 por campo entra por el camino que el formulario ya sabe pintar.
     */
    private function vehiculoInvalido(Request $request): ?JsonResponse
    {
        $id = (int) $request->input('device_id');

        if (! $id) {
            return response()->json([
                'ok'     => false,
                'errors' => ['device_id' => [trans('ui.select_a_vehicle_dot')]],
            ], 422);
        }

        if (! $this->user->devices()->where('devices.id', $id)->exists()) {
            return response()->json([
                'ok'     => false,
                'errors' => ['device_id' => [trans('ui.invalid_vehicle')]],
            ], 422);
        }

        return null;
    }

    public function update(Request $request, $id): JsonResponse
    {
        $task = Task::find($id);
        if (! $task) {
            abort(404);
        }

        if ($error = $this->vehiculoInvalido($request)) {
            return $error;
        }

        try {
            $this->service->edit($task, $request->all());
            $this->saveCustomFields($task, $request);

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $task = Task::find($id);
        if (! $task) {
            abort(404);
        }
        if (! $this->user->can('remove', $task)) {
            abort(403);
        }

        $task->delete();

        return response()->json(['ok' => true]);
    }

    /* ================= Asignación masiva ================= */

    /** Reasigna las tareas seleccionadas a un device (paridad legacy assign). */
    public function assign(Request $request): JsonResponse
    {
        $data = $request->all();

        try {
            TasksFormValidator::validate('assign', $data);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }

        $device = Device::find($data['device_id']);

        $this->checkException('devices', 'own', $device);

        $tasks = Task::whereIn('id', $data['tasks'])->get();

        $assigned = 0;
        foreach ($tasks as $task) {
            if (! $this->user->can('edit', $task)) {
                continue;
            }

            $task->device()->associate($device);
            $task->save();
            $assigned++;
        }

        return response()->json(['ok' => true, 'assigned' => $assigned]);
    }

    /* ================= Importación (CSV / XLS / XLSX) ================= */

    /** Cabeceras del archivo + campos del importador (para el mapeo). */
    public function importFields(Request $request): JsonResponse
    {
        $this->checkException('tasks', 'edit');

        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['ok' => false, 'errors' => ['file' => trans('validation.attributes.file')]], 422);
        }

        $manager  = new TaskImportManager();
        $importer = $manager->getImporter();

        $fileFields = $manager->getImportFields($file);
        $defaults   = (new ImportUtils())->getDefaultValues($importer->getImportFields(), $fileFields);

        $fields = [];
        foreach ($defaults as $name => $default) {
            $fields[] = ['name' => (string) $name, 'default' => $default !== null ? (string) $default : null];
        }

        return response()->json([
            'ok'      => true,
            'headers' => array_values(array_map('strval', $fileFields)),
            'fields'  => $fields,
        ]);
    }

    /** Ejecuta la importación (paridad legacy importSet). */
    public function import(Request $request): JsonResponse
    {
        $this->checkException('tasks', 'edit');

        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['ok' => false, 'errors' => ['file' => trans('validation.attributes.file')]], 422);
        }

        try {
            $manager = new TaskImportManager();
            $manager->setFieldsReadMap($request->get('fields', []))
                ->import($file->getPathName());
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }

        return response()->json(['ok' => true]);
    }

    /* ================= Conjuntos de tareas (TaskSets) ================= */

    public function taskSets(Request $request): JsonResponse
    {
        $this->checkException('task_sets', 'view');

        $perPage = min(100, max(10, (int) $request->get('per_page', 20)));

        $query = $this->user->taskSets()->selectDeviceId();

        if ($s = trim((string) $request->get('s'))) {
            $query->where('task_sets.title', 'like', "%{$s}%");
        }

        $items = $query->orderBy('task_sets.id', 'desc')->paginate($perPage);

        $devices = Device::whereIn('id', $items->pluck('device_id')->filter())->get();

        $rows = [];
        foreach ($items as $set) {
            $device = $set->device_id ? $devices->find($set->device_id) : null;
            $rows[] = [
                'id'     => (int) $set->id,
                'title'  => (string) $set->title,
                'device' => $device ? (string) $device->getDisplayName() : '',
            ];
        }

        return response()->json([
            'ok'    => true,
            'items' => $rows,
            'page'  => $items->currentPage(),
            'last'  => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    public function taskSetStore(Request $request): JsonResponse
    {
        $this->checkException('task_sets', 'store');

        $title = trim((string) $request->get('title'));
        if ($title === '') {
            return response()->json(['ok' => false, 'errors' => ['title' => trans('validation.attributes.title')]], 422);
        }

        $item = $this->setService->store([
            'title'   => $title,
            'user_id' => $this->user->id,
        ]);

        return response()->json(['ok' => true, 'id' => (int) $item->id]);
    }

    public function taskSetUpdate(Request $request, $id): JsonResponse
    {
        $item = $this->user->taskSets()->findOrFail((int) $id);

        $this->checkException('task_sets', 'edit', $item);

        $title = trim((string) $request->get('title'));
        if ($title === '') {
            return response()->json(['ok' => false, 'errors' => ['title' => trans('validation.attributes.title')]], 422);
        }

        $this->setService->update($item, ['title' => $title]);

        return response()->json(['ok' => true]);
    }

    public function taskSetDestroy(Request $request, $id): JsonResponse
    {
        $item = $this->user->taskSets()->findOrFail((int) $id);

        $this->checkException('task_sets', 'remove', $item);

        $this->setService->delete($item);

        return response()->json(['ok' => true]);
    }

    /* ----------------------------------------------------------------- */

    /** ['id' => label] → [{id, title}] preservando el orden del mapa. */
    private function options(array $map): array
    {
        $out = [];
        foreach ($map as $id => $title) {
            $out[] = ['id' => $id, 'title' => (string) $title];
        }

        return $out;
    }

    private function present(Task $task): array
    {
        $signature = ($task->lastStatus && $task->lastStatus->signature)
            ? route('tasks.signature', $task->lastStatus->id)
            : null;

        return [
            'id'               => (int) $task->id,
            'title'            => (string) $task->title,
            'device'           => (string) ($task->device->name ?? ''),
            'status'           => (int) $task->status,
            'status_name'      => trans(TaskStatus::$statuses[$task->status] ?? 'front.status'),
            'priority'         => (int) $task->priority,
            'priority_name'    => trans(Task::$priorities[$task->priority] ?? ''),
            'invoice'          => (string) ($task->invoice_number ?? ''),
            'pickup_address'   => (string) ($task->pickup_address ?? ''),
            'delivery_address' => (string) ($task->delivery_address ?? ''),
            'signature_url'    => $signature,
        ];
    }
}
