<?php

namespace App\Http\Controllers\Ui\V2;

use CustomFacades\Validators\CallActionFormValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\CallAction;
use Tobuli\Entities\Device;
use Tobuli\Entities\Event;
use Tobuli\Exceptions\ValidationException;

/**
 * TalentusPro · CallActionsController (ui/v2)
 * -----------------------------------------------------------------
 * Acciones de llamada: registro de seguimiento de llamadas sobre un
 * evento (tipo de respuesta answer/no_answer/no_response + fecha de
 * llamada + notas). CRUD equivalente al CallActionsController legacy:
 * el alert_id se deriva del evento, called_at se convierte usuario→
 * almacenado con Formatter::time()->reverse(), validación con
 * CallActionFormValidator. Permisos: 'call_actions' + 'events'.
 * -----------------------------------------------------------------
 */
class CallActionsController extends BaseController
{
    public function page()
    {
        if (! $this->user->perm('call_actions', 'view') || ! $this->user->perm('events', 'view')) {
            abort(403);
        }

        return View::make('ui.v2.pages.call-actions', [
            'pageTitle' => trans('front.call_actions'),
            'canEdit'   => (bool) $this->user->perm('call_actions', 'edit'),
            'canRemove' => (bool) $this->user->perm('call_actions', 'remove'),
        ]);
    }

    /** Lista paginada (búsqueda en notas + filtros device/respuesta/fecha). */
    public function data(Request $request): JsonResponse
    {
        $this->checkException('call_actions', 'view');

        $perPage = min(100, max(10, (int) $request->get('per_page', 20)));

        $query = CallAction::byUser($this->user)->with(['device', 'event', 'user']);

        if ($deviceId = (int) $request->get('device_id')) {
            $query->where('device_id', $deviceId);
        }
        if ($resp = $request->get('response_type')) {
            $query->where('response_type', $resp);
        }
        if ($s = trim((string) $request->get('s'))) {
            $query->where('remarks', 'like', "%{$s}%");
        }
        if ($df = $request->get('date_from')) {
            $query->where('called_at', '>=', $df . ' 00:00:00');
        }
        if ($dt = $request->get('date_to')) {
            $query->where('called_at', '<=', $dt . ' 23:59:59');
        }

        $items = $query->orderByDesc('called_at')->orderByDesc('id')->paginate($perPage);

        $titles = collect(CallAction::getResponseTypes())->pluck('title', 'type');

        $rows = [];
        foreach ($items as $ca) {
            $rows[] = [
                'id'            => (int) $ca->id,
                'device'        => (string) ($ca->device->name ?? ''),
                'event'         => (string) ($ca->event->message ?? ''),
                'response_type' => (string) $ca->response_type,
                'response'      => (string) ($titles[$ca->response_type] ?? $ca->response_type),
                'called_at'     => (string) ($ca->converted_called_at ?? ''),
                'remarks'       => (string) ($ca->remarks ?? ''),
                'user'          => (string) ($ca->user->email ?? ''),
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

    /** Catálogos del formulario (+ item si edit). */
    public function meta(Request $request): JsonResponse
    {
        $this->checkException('call_actions', 'view');

        $responseTypes = [];
        foreach (CallAction::getResponseTypes() as $t) {
            $responseTypes[] = ['id' => $t['type'], 'label' => $t['title']];
        }

        $devices = $this->user->accessibleDevices()
            ->clearOrdersBy()->orderBy('name')
            ->get(['devices.id', 'devices.name'])
            ->map(function ($d) {
                return ['id' => (int) $d->id, 'name' => (string) $d->name];
            })->all();

        $out = ['ok' => true, 'responseTypes' => $responseTypes, 'devices' => $devices, 'item' => null];

        if ($id = (int) $request->get('id')) {
            $ca = CallAction::find($id);
            if (! $ca) {
                abort(404);
            }
            $this->checkException('call_actions', 'edit', $ca);
            $out['item'] = [
                'id'            => (int) $ca->id,
                'device_id'     => (int) $ca->device_id,
                'event_id'      => (int) $ca->event_id,
                'response_type' => (string) $ca->response_type,
                // convert() = 'Y-m-d H:i:s' en TZ del usuario (round-trip con el
                // reverse() de prepare). El converted_called_at humano (formato
                // del usuario, ej. d/m/Y) no lo parseaba el JS → al editar caía
                // a "ahora" y sobrescribía la fecha silenciosamente.
                'called_at'     => $ca->called_at ? \Formatter::time()->convert($ca->called_at) : '',
                'remarks'       => (string) ($ca->remarks ?? ''),
                'events'        => $this->deviceEvents($ca->device_id),
            ];
        }

        return response()->json($out);
    }

    /** Eventos recientes de un dispositivo (para el select del formulario). */
    public function events(Request $request): JsonResponse
    {
        $this->checkException('call_actions', 'view');

        $device = Device::find((int) $request->get('device_id'));
        $this->checkException('devices', 'show', $device);

        return response()->json(['ok' => true, 'events' => $this->deviceEvents($device->id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkException('call_actions', 'store');

        try {
            $data = $this->prepare($request->all());
            CallActionFormValidator::validate('create', $data);
            CallAction::create($data);

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $ca = CallAction::find($id);
        if (! $ca) {
            abort(404);
        }
        $this->checkException('call_actions', 'update', $ca);

        try {
            $data = $this->prepare($request->all());
            $data['user_id'] = $ca->user_id;
            CallActionFormValidator::validate('update', $data);
            $ca->update($data);

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $ca = CallAction::find($id);
        if (! $ca) {
            abort(404);
        }
        $this->checkException('call_actions', 'remove', $ca);

        $ca->delete();

        return response()->json(['ok' => true]);
    }

    /* ----------------------------------------------------------------- */

    /** Normaliza el payload: deriva alert_id/device_id del evento + reverse de called_at. */
    private function prepare(array $data): array
    {
        $event = Event::find($data['event_id'] ?? null);
        if (! $event) {
            throw new ValidationException(['event_id' => trans('validation.attributes.event')]);
        }

        $this->checkException('devices', 'show', $event->device);

        $data['device_id'] = $event->device_id;
        $data['alert_id']  = $event->alert_id;
        // SIEMPRE el usuario autenticado (paridad legacy CallActionsController:120,
        // que lo fuerza) — antes se aceptaba user_id del cliente (suplantación).
        $data['user_id']   = $this->user->id;
        $data['called_at'] = empty($data['called_at'])
            ? null
            : \Formatter::time()->reverse($data['called_at']);

        // Whitelist: el payload iba crudo a create()/update() (mass-assignment
        // de cualquier atributo fillable). Solo las claves del formulario.
        return \Illuminate\Support\Arr::only($data, [
            'device_id', 'event_id', 'alert_id', 'user_id',
            'response_type', 'called_at', 'remarks',
        ]);
    }

    private function deviceEvents(int $deviceId): array
    {
        $device = Device::find($deviceId);
        if (! $device) {
            return [];
        }

        $eventos = $device->events()->latest()->take(25)->get();

        // Cuáles de esos eventos ya tienen acción de llamada. Sin esto el
        // selector los ofrece todos por igual y se registra una segunda
        // acción sobre un evento ya atendido, sin que el operador lo sepa.
        $atendidos = CallAction::whereIn('event_id', $eventos->pluck('id'))
            ->pluck('event_id')
            ->flip();

        return $eventos->map(function ($e) use ($atendidos) {
            return [
                'id'       => (int) $e->id,
                'label'    => (string) $e->message,
                'alert'    => (bool) $e->alert_id,
                'attended' => $atendidos->has($e->id),
            ];
        })->all();
    }
}
