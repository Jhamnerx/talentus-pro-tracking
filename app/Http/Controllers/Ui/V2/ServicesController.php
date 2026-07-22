<?php

namespace App\Http\Controllers\Ui\V2;

use CustomFacades\ModalHelpers\ServiceModalHelper;
use CustomFacades\Repositories\DeviceRepo;
use CustomFacades\Repositories\UserRepo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\DeviceService;
use Tobuli\Exceptions\ValidationException;

/**
 * UI v2 · Servicios / Mantenimiento.
 *
 * Reutiliza ServiceModalHelper (create/edit/destroy + catálogos) y los
 * cálculos de la entidad DeviceService (left/percentage/expiration) para:
 *  - la página /ui/v2/maintenance (vista global de servicios),
 *  - el modal de LISTA por vehículo (widget de Live),
 *  - el modal de FORMULARIO (crear/editar) — compartidos por todos los módulos.
 */
class ServicesController extends BaseController
{
    /** Página Mantenimiento (vista global). */
    public function page(Request $request)
    {
        if (! $this->user->perm('maintenance', 'view')) {
            abort(403);
        }

        return View::make('ui.v2.pages.maintenance', [
            'pageTitle' => trans('front.maintenance'),
        ]);
    }

    /** Feed global: todos los servicios del usuario (tabla Mantenimiento). */
    public function data(Request $request): JsonResponse
    {
        if (! $this->user->perm('maintenance', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $rows = [];
        $devices = UserRepo::getDevicesWithServices($this->user->id);
        foreach ($devices as $device) {
            foreach ($device->services as $service) {
                $rows[] = $this->present($service, $device);
            }
        }

        return response()->json(['ok' => true, 'items' => $rows]);
    }

    /** Servicios de UN vehículo (modal de lista del widget). */
    public function deviceServices(Request $request, $deviceId): JsonResponse
    {
        $device = DeviceRepo::find((int) $deviceId);
        if (! $device || ! $device->users->contains($this->user->id)) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $rows = [];
        foreach ($device->services()->orderBy('id', 'desc')->get() as $service) {
            $rows[] = $this->present($service, $device);
        }

        return response()->json(['ok' => true, 'device' => ['id' => $device->id, 'name' => $device->name], 'items' => $rows]);
    }

    /** Datos para el formulario (crear o editar). */
    public function formMeta(Request $request): JsonResponse
    {
        $deviceId  = (int) $request->get('device_id');
        $serviceId = (int) $request->get('id');

        try {
            ServiceModalHelper::setUser($this->user);
            ServiceModalHelper::setApi(true);
            ServiceModalHelper::setData($request->all());

            if ($serviceId) {
                $data = ServiceModalHelper::editData($serviceId);
                $item = $data['item'];
                $out  = [
                    'ok'                 => true,
                    'device_id'          => (int) $data['device_id'],
                    'odometer_value'     => (float) $data['odometer_value'],
                    'engine_hours_value' => (float) $data['engine_hours_value'],
                    'expiration_by'      => $data['expiration_by'],
                    'item'               => [
                        'id'                     => (int) $item->id,
                        'name'                   => (string) $item->name,
                        'expiration_by'          => (string) $item->expiration_by,
                        'interval'               => $item->interval,
                        'last_service'           => $item->expiration_by === 'days'
                            ? substr((string) $item->last_service, 0, 10)
                            : $item->last_service,
                        'trigger_event_left'     => $item->trigger_event_left,
                        'renew_after_expiration' => (bool) $item->renew_after_expiration,
                        'description'            => (string) ($item->description ?? ''),
                        'email'                  => (string) ($item->email ?? ''),
                    ],
                ];
            } else {
                $data = ServiceModalHelper::createData($deviceId);
                $out  = [
                    'ok'                 => true,
                    'device_id'          => (int) $data['device_id'],
                    'odometer_value'     => (float) $data['odometer_value'],
                    'engine_hours_value' => (float) $data['engine_hours_value'],
                    'expiration_by'      => $data['expiration_by'],
                    'item'               => null,
                ];
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json($out);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        return $this->save($request, (int) $id);
    }

    private function save(Request $request, $id): JsonResponse
    {
        try {
            ServiceModalHelper::setUser($this->user);
            ServiceModalHelper::setApi(true);
            $payload = $request->all();
            $payload['mobile_phone'] = $payload['mobile_phone'] ?? '';
            if ($id) {
                $payload['id'] = $id;
            }
            ServiceModalHelper::setData($payload);
            $res = $id ? ServiceModalHelper::edit($id) : ServiceModalHelper::create();
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors() ? $e->getErrors()->toArray() : [],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'id' => $res['id'] ?? $id]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            ServiceModalHelper::setUser($this->user);
            ServiceModalHelper::setApi(true);
            ServiceModalHelper::setData(['id' => (int) $id]);
            $res = ServiceModalHelper::destroy((int) $id);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        if (empty($res['status'])) {
            return response()->json(['ok' => false, 'errors' => $res['errors'] ?? []], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Fila normalizada (usa los cálculos de la entidad, como /maintenance legacy). */
    private function present(DeviceService $service, $device): array
    {
        $left = null;
        try {
            $left = $service->getLeft();
        } catch (\Throwable $e) {
        }

        return [
            'id'            => (int) $service->id,
            'device_id'     => (int) $device->id,
            'device_name'   => (string) $device->name,
            'name'          => (string) $service->name,
            'expiration_by' => (string) $service->expiration_by,
            'expires_text'  => (string) ($service->expires_formated() ?? '-'),
            'left_text'     => (string) $service->left_formated(),
            'expiration'    => (string) ($service->expiration() ?? '-'),
            'percentage'    => (int) $service->getPercentage(),
            'expired'       => (bool) ($left !== null && $left <= 0),
            'expiring'      => (bool) ($left !== null && $service->isExpiring()),
        ];
    }
}
