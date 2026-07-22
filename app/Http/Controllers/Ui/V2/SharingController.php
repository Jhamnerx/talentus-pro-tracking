<?php

namespace App\Http\Controllers\Ui\V2;

use Carbon\Carbon;
use CustomFacades\Validators\SharingFormValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\Sharing;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\EntityLoader\UserDevicesGroupLoader;
use Tobuli\Services\SharingService;

/**
 * TalentusPro · SharingController (ui/v2)
 * -----------------------------------------------------------------
 * Compartir vehículos por link público. Reutiliza la lógica legacy
 * end-to-end: SharingService (create/update/remove/syncDevices/
 * sendEmail), SharingFormValidator (escenario "send": expiration_by
 * none|duration|date) y el UserDevicesGroupLoader (la selección de
 * devices viaja como secuencia selected_devices[] del EntityLoader,
 * p.ej. ["s;0;", "id;1;<deviceId>", ...]).
 * La página pública del link es la legacy (route('sharing', hash)).
 * -----------------------------------------------------------------
 */
class SharingController extends BaseController
{
    /** @var SharingService */
    private $service;

    protected function afterAuth($user)
    {
        $this->service = new SharingService();
    }

    public function page(Request $request)
    {
        if (! $this->user->perm('sharing', 'view')) {
            abort(403);
        }

        return View::make('ui.v2.pages.sharing', [
            'pageTitle' => 'Compartir',
            'deviceId'  => (int) $request->get('device_id') ?: null,
            'canEdit'   => (bool) $this->user->perm('sharing', 'edit'),
            'canRemove' => (bool) $this->user->perm('sharing', 'remove'),
        ]);
    }

    /** Lista paginada (búsqueda + filtro por device). */
    public function data(Request $request): JsonResponse
    {
        $this->checkException('sharing', 'view');

        $perPage = min(100, max(10, (int) $request->get('per_page', 20)));

        $query = Sharing::where('user_id', $this->user->id);

        if ($s = trim((string) $request->get('s'))) {
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%")
                    ->orWhere('hash', 'like', "%{$s}%");
            });
        }

        if ($deviceId = (int) $request->get('device_id')) {
            $query->whereHas('devices', function ($q) use ($deviceId) {
                $q->where('devices.id', $deviceId);
            });
        }

        $items = $query->orderBy('id', 'desc')->paginate($perPage);

        $rows = [];
        foreach ($items as $sharing) {
            $rows[] = $this->present($sharing);
        }

        return response()->json([
            'ok'    => true,
            'items' => $rows,
            'page'  => $items->currentPage(),
            'last'  => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    /** Datos del formulario: duraciones + devices agrupados (+ item si edit). */
    public function meta(Request $request): JsonResponse
    {
        $this->checkException('sharing', 'view');

        // Duraciones (minutos). Fallback si config('tobuli.object_online_timeouts')
        // está vacío (el default del paquete es []).
        $durations = config('tobuli.object_online_timeouts');
        if (empty($durations)) {
            $durations = [
                30    => '30 min',
                60    => '1 h',
                120   => '2 h',
                360   => '6 h',
                720   => '12 h',
                1440  => '24 h',
                10080 => '7 d',
                43200 => '30 d',
            ];
        }

        $groups = $this->user->deviceGroups()
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(function ($g) {
                return ['id' => (int) $g->id, 'title' => (string) $g->title];
            })
            ->all();

        $devices = $this->user->devices()
            ->clearOrdersBy()
            ->orderBy('name')
            ->get()
            ->map(function ($d) {
                return [
                    'id'       => (int) $d->id,
                    'name'     => (string) $d->name,
                    'group_id' => (int) ($d->pivot->group_id ?? 0),
                ];
            })
            ->all();

        $out = [
            'ok'        => true,
            'durations' => collect($durations)->map(function ($label, $minutes) {
                return ['value' => (int) $minutes, 'label' => (string) $label];
            })->values()->all(),
            'groups'    => $groups,
            'devices'   => $devices,
            'item'      => null,
        ];

        if ($id = (int) $request->get('id')) {
            $sharing = $this->findOwn($id);

            $expirationDate = $sharing->expiration_date
                ? substr((string) $sharing->expiration_date, 0, 10)
                : '';

            $out['item'] = [
                'id'                      => (int) $sharing->id,
                'name'                    => (string) $sharing->name,
                'description'             => (string) ($sharing->description ?? ''),
                'active'                  => (bool) $sharing->active,
                'delete_after_expiration' => (bool) $sharing->delete_after_expiration,
                'expiration_by'           => $sharing->expiration_date ? 'date' : 'none',
                'expiration_date'         => $expirationDate,
                'expiration_time'         => $sharing->expiration_date
                    ? substr((string) $sharing->expiration_date, 11, 5)
                    : '',
                'link'                    => (string) $sharing->link,
                'device_ids'              => $sharing->devices()->pluck('devices.id')->map(function ($v) {
                    return (int) $v;
                })->all(),
            ];
        }

        return response()->json($out);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkException('sharing', 'create');

        try {
            $data = $this->validated($request);

            $sharing = $this->service->create($this->user->id, $this->payload($data, true));

            $this->syncDevices($sharing);
            $this->maybeSendEmail($sharing, $data);

            return response()->json(['ok' => true, 'item' => $this->present($sharing->fresh())]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $sharing = $this->findOwn((int) $id);

        $this->checkException('sharing', 'update', $sharing);

        try {
            $data = $this->validated($request);

            $this->service->update($sharing, $this->payload($data, false));

            $this->syncDevices($sharing);
            $this->maybeSendEmail($sharing, $data);

            return response()->json(['ok' => true, 'item' => $this->present($sharing->fresh())]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $sharing = $this->findOwn((int) $id);

        $this->checkException('sharing', 'remove', $sharing);

        $this->service->remove($sharing);

        return response()->json(['ok' => true]);
    }

    /* ----------------------------------------------------------------- */

    private function findOwn(int $id): Sharing
    {
        $sharing = Sharing::where('user_id', $this->user->id)->find($id);
        if (! $sharing) {
            abort(404);
        }

        return $sharing;
    }

    /**
     * Valida con el escenario legacy "send" (expiration_by none|duration|date,
     * selected_devices requerido) y normaliza la expiración igual que el
     * SharingController legacy.
     */
    private function validated(Request $request): array
    {
        $data = $request->all();

        SharingFormValidator::validate('send', $data);

        switch ($data['expiration_by'] ?? 'none') {
            case 'date':
                // "YYYY-MM-DD HH:MM" respeta la hora; solo fecha → fin del día.
                $exp = Carbon::parse($data['expiration_date']);
                if (strlen(trim((string) $data['expiration_date'])) <= 10) {
                    $exp = $exp->endOfDay();
                }
                $data['expiration_date'] = $exp;
                break;
            case 'duration':
                $data['expiration_date'] = Carbon::now()->addMinutes((int) $data['duration']);
                break;
            default:
                $data['expiration_date'] = null;
        }

        return $data;
    }

    private function payload(array $data, bool $creating): array
    {
        $payload = [
            'active'                  => ! empty($data['active']) ? 1 : 0,
            'expiration_date'         => $data['expiration_date'],
            'description'             => (string) ($data['description'] ?? ''),
            'delete_after_expiration' => ! empty($data['delete_after_expiration']) ? 1 : 0,
        ];

        // En create, si el nombre viene vacío el service genera uno; en update
        // solo se pisa si se envió un valor.
        $name = trim((string) ($data['name'] ?? ''));
        if ($name !== '') {
            $payload['name'] = $name;
        } elseif (! $creating) {
            $payload['name'] = null;
        }
        if (! $creating && $payload['name'] === null) {
            unset($payload['name']);
        }

        return $payload;
    }

    private function syncDevices(Sharing $sharing): void
    {
        $loader = new UserDevicesGroupLoader($this->user);
        $loader->setRequestKey('devices'); // lee request('selected_devices')
        $loader->setQueryStored($sharing->devices());

        if ($loader->hasSelect()) {
            $this->service->syncDevices($sharing, $loader);
        }
    }

    private function maybeSendEmail(Sharing $sharing, array $data): void
    {
        if (empty($data['send_email']) || empty($data['email'])) {
            return;
        }

        try {
            $this->service->sendEmail($sharing, semicol_explode($data['email']));
        } catch (\Throwable $e) {
            // El guardado no debe fallar por el envío del correo.
        }
    }

    private function present(Sharing $sharing): array
    {
        $expired = $sharing->expiration_date
            && strtotime((string) $sharing->expiration_date) < time();

        return [
            'id'              => (int) $sharing->id,
            'name'            => (string) $sharing->name,
            'description'     => (string) ($sharing->description ?? ''),
            'link'            => (string) $sharing->link,
            'active'          => (bool) $sharing->active,
            'expired'         => (bool) $expired,
            'expiration_date' => $sharing->expiration_date
                ? substr((string) $sharing->expiration_date, 0, 16)
                : '',
        ];
    }
}
