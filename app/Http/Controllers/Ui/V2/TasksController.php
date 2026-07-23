<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\Task;
use Tobuli\Entities\TaskStatus;
use Tobuli\Exceptions\ValidationException;
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

    protected function afterAuth($user)
    {
        $this->service = new TaskUserService($user);
    }

    public function page()
    {
        if (! $this->user->perm('tasks', 'view')) {
            abort(403);
        }

        return View::make('ui.v2.pages.tasks', [
            'pageTitle' => trans('front.tasks'),
            'statuses'  => TaskStatus::getList(),
            'canEdit'   => (bool) $this->user->perm('tasks', 'edit'),
            'canRemove' => (bool) $this->user->perm('tasks', 'remove'),
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
            ];
        }

        return response()->json($out);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $task = $this->service->create($request->all());

            return response()->json(['ok' => true, 'id' => (int) $task->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $task = Task::find($id);
        if (! $task) {
            abort(404);
        }

        try {
            $this->service->edit($task, $request->all());

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
