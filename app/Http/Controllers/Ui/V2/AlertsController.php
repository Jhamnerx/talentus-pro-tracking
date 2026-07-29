<?php

namespace App\Http\Controllers\Ui\V2;

use CustomFacades\ModalHelpers\AlertModalHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Exceptions\ValidationException;
use Tobuli\InputFields\AbstractField;
use Tobuli\InputFields\SelectField;
use Tobuli\Services\Commands\CommandService;

/**
 * TalentusPro · AlertsController
 * Alert Center: rules + recent events feed + nativo CRUD via AlertModalHelper.
 */
class AlertsController extends BaseController
{
    public function index(Request $request)
    {
        if (! $this->user->perm('alerts', 'view')) {
            abort(403, 'No tienes permiso para ver alertas.');
        }

        return View::make('ui.v2.pages.alerts', [
            'pageTitle' => 'Alerts Center',
            'canEdit'   => $this->user->perm('alerts', 'edit'),
            'canCreate' => $this->user->perm('alerts', 'edit'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        if (! $this->user->perm('alerts', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $hours = min(168, max(1, (int) $request->get('hours', 24)));
        $limit = min(500, max(50, (int) $request->get('limit', 200)));
        $type  = trim((string) $request->get('type', ''));
        $q     = trim((string) $request->get('q', ''));
        $since = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $rules = [];
        try {
            $rawRules = $this->user->alerts()->withCount('devices')->orderBy('name', 'asc')->get();
            foreach ($rawRules as $r) {
                $rules[] = [
                    'id'            => (int) $r->id,
                    'name'          => (string) $r->name,
                    'type'          => (string) $r->type,
                    'active'        => (bool) $r->active,
                    'all'           => (bool) $r->for_all_user_devices,
                    'devices_count' => (int) ($r->devices_count ?? 0),
                    // Solo canales realmente activos (antes se listaban todos los
                    // presentes en el JSON → "muro de badges" en la lista de reglas).
                    'channels'      => $this->activeChannels($r->notifications),
                ];
            }
        } catch (\Throwable $e) {
        }

        $eventsQuery = $this->user->events()
            ->where('time', '>=', $since)
            ->orderBy('time', 'desc');

        if ($type !== '') $eventsQuery->where('type', $type);
        if ($q !== '') {
            $eventsQuery->where(function ($w) use ($q) {
                $w->where('message', 'like', '%' . $q . '%')
                    ->orWhere('address', 'like', '%' . $q . '%');
            });
        }

        $rows = $eventsQuery->limit($limit)->get();

        $events = [];
        $byType = [];
        foreach ($rows as $e) {
            $t = (string) $e->type;
            $byType[$t] = ($byType[$t] ?? 0) + 1;

            $events[] = [
                'id'        => (int) $e->id,
                'type'      => $t,
                'severity'  => $this->eventSeverity($t),
                'message'   => (string) $e->message,
                'address'   => (string) $e->address,
                'device_id' => $e->device_id ? (int) $e->device_id : null,
                'time'      => (string) $e->time,
                'lat'       => $e->latitude !== null ? (float) $e->latitude : null,
                'lng'       => $e->longitude !== null ? (float) $e->longitude : null,
                'speed'     => $e->speed !== null ? (float) $e->speed : null,
            ];
        }

        return response()->json([
            'ok'     => true,
            'hours'  => $hours,
            'since'  => $since,
            'rules'  => $rules,
            'events' => $events,
            'totals' => [
                'rules'   => count($rules),
                'events'  => count($events),
                'active'  => count(array_filter($rules, function ($r) {
                    return $r['active'];
                })),
                'by_type' => $byType,
            ],
        ]);
    }

    public function meta(Request $request): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('alerts', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        try {
            AlertModalHelper::setUser($user);
            AlertModalHelper::setApi(true);
            AlertModalHelper::setData([]);
            $data = AlertModalHelper::createData();
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors() ? $e->getErrors()->toArray() : [],
                'error'  => 'validation',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'meta_failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        $devices = [];
        try {
            $rows = $user->devices()->orderBy('name', 'asc')->get(['id', 'name', 'plate_number']);
            foreach ($rows as $d) {
                $devices[] = [
                    'id'    => (int) $d->id,
                    'name'  => (string) ($d->name ?: ('#' . $d->id)),
                    'plate' => trim((string) ($d->plate_number ?? '')),
                ];
            }
        } catch (\Throwable $e) {
        }

        $types = $this->reshapeTypes($data['types'] ?? []);

        return response()->json([
            'ok'            => true,
            'types'         => $types,
            'devices'       => $devices,
            'geofences'     => $data['geofences']     ?? [],
            'alert_zones'   => $data['alert_zones']   ?? [],
            'notifications' => array_values((array) ($data['notifications'] ?? [])),
            'schedules'     => $data['schedules']     ?? [],
            'can_command'   => (bool) $user->perm('send_command', 'view'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('alerts', 'edit')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        try {
            AlertModalHelper::setUser($user);
            AlertModalHelper::setApi(true);
            AlertModalHelper::setData($request->all());
            $result = AlertModalHelper::create();
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors() ? $e->getErrors()->toArray() : [],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'server_error',
                'message' => $e->getMessage(),
            ], 500);
        }

        $alert = $result['item'] ?? null;
        return response()->json([
            'ok'   => true,
            'item' => $alert ? [
                'id'     => (int) $alert->id,
                'name'   => (string) $alert->name,
                'type'   => (string) $alert->type,
                'active' => (bool) $alert->active,
                'all'    => (bool) $alert->for_all_user_devices,
            ] : null,
        ]);
    }

    public function editData(Request $request, $id): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('alerts', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        try {
            AlertModalHelper::setUser($user);
            AlertModalHelper::setApi(true);
            AlertModalHelper::setData(['alert_id' => (int) $id]);
            $data = AlertModalHelper::editData();
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors() ? $e->getErrors()->toArray() : [],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'edit_failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        $types = $this->reshapeTypes($data['types'] ?? []);

        $devices = [];
        try {
            $rows = $user->devices()->orderBy('name', 'asc')->get(['id', 'name', 'plate_number']);
            foreach ($rows as $d) {
                $devices[] = [
                    'id'    => (int) $d->id,
                    'name'  => (string) ($d->name ?: ('#' . $d->id)),
                    'plate' => trim((string) ($d->plate_number ?? '')),
                ];
            }
        } catch (\Throwable $e) {
        }

        /** @var \Tobuli\Entities\Alert $alert */
        $alert = $data['item'];

        $devicesSelected = [];
        try {
            $devicesSelected = $alert->devices()->pluck('id')->all();
        } catch (\Throwable $e) {
        }
        $geofencesSelected = [];
        try {
            $geofencesSelected = $alert->geofences()->pluck('id')->all();
        } catch (\Throwable $e) {
        }

        $typeFieldValues = [];
        $typeMatch = collect($types)->firstWhere('key', $alert->type);
        if ($typeMatch) {
            foreach ($typeMatch['fields'] as $f) {
                try {
                    $typeFieldValues[$f['name']] = $alert->{$f['name']};
                } catch (\Throwable $e) {
                    $typeFieldValues[$f['name']] = $f['value'];
                }
            }
        }

        $item = [
            'id'                   => (int) $alert->id,
            'name'                 => (string) $alert->name,
            'type'                 => (string) $alert->type,
            'active'               => (bool) $alert->active,
            'for_all_user_devices' => (bool) $alert->for_all_user_devices,
            'zone'                 => (int) ($alert->zone ?? 0),
            'schedule'             => (bool) $alert->schedule,
            'command'              => (array) ($alert->command ?? []),
            'devices'              => array_map('intval', $devicesSelected),
            'geofences'            => array_map('intval', $geofencesSelected),
            'notifications'        => (array) ($alert->notifications ?? []),
            'fields'               => $typeFieldValues,
        ];

        return response()->json([
            'ok'            => true,
            'item'          => $item,
            'types'         => $types,
            'devices'       => $devices,
            'geofences'     => $data['geofences']   ?? [],
            'alert_zones'   => $data['alert_zones'] ?? [],
            'notifications' => array_values((array) ($data['notifications'] ?? [])),
            'schedules'     => $data['schedules']   ?? [],
            'can_command'   => (bool) $user->perm('send_command', 'view'),
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('alerts', 'edit')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        try {
            $payload = array_merge($request->all(), ['id' => (int) $id]);
            AlertModalHelper::setUser($user);
            AlertModalHelper::setApi(true);
            AlertModalHelper::setData($payload);
            AlertModalHelper::edit();
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors() ? $e->getErrors()->toArray() : [],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'server_error',
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json(['ok' => true]);
    }

    public function toggle(Request $request, $id): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('alerts', 'edit')) {
            return response()->json(['ok' => false], 403);
        }

        $alert = $user->alerts()->where('id', (int) $id)->first();
        if (! $alert) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $alert->active = $alert->active ? 0 : 1;
        $alert->save();

        return response()->json(['ok' => true, 'active' => (bool) $alert->active]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('alerts', 'remove')) {
            return response()->json(['ok' => false], 403);
        }

        try {
            AlertModalHelper::setUser($user);
            AlertModalHelper::setApi(true);
            AlertModalHelper::setData(['id' => (int) $id]);
            AlertModalHelper::destroy();
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'server_error',
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * GPRS commands available for the given devices (Command tab).
     * Requires the `send_command` permission. Reshapes each command +
     * its attributes into a JSON-friendly structure for the JS modal.
     */
    public function commands(Request $request): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('send_command', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $ids = array_values(array_filter(array_map('intval', (array) $request->get('devices', []))));

        try {
            $devices  = $ids ? $user->devices()->whereIn('id', $ids)->get() : collect();
            $commands = (new CommandService($user))->getGprsCommands($devices);
            $out      = $this->reshapeCommands($commands);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'commands_failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json(['ok' => true, 'commands' => $out]);
    }

    /**
     * Reshape a GPRS-commands collection (each {type,title,attributes?}) into
     * a JSON structure: [{type,title,fields:[{name,type,title,value,required,
     * description,options?}]}].
     */
    private function reshapeCommands($commands): array
    {
        $out = [];
        foreach ($commands as $c) {
            $fields = [];
            $attrs  = $c['attributes'] ?? null;
            if ($attrs) {
                foreach ($attrs as $attr) {
                    if (! $attr instanceof AbstractField) continue;
                    $f = [
                        'name'        => $attr->getName(),
                        'type'        => $attr->getType(),
                        'title'       => (string) $attr->getTitle(),
                        'value'       => $attr->getDefault(),
                        'required'    => $attr->isRequired(),
                        'description' => (string) $attr->getDescription(),
                    ];
                    if ($attr instanceof SelectField) {
                        $f['options'] = $attr->getOptions();
                    }
                    $fields[] = $f;
                }
            }
            $out[] = [
                'type'   => $c['type'],
                'title'  => (string) ($c['title'] ?? $c['type']),
                'fields' => $fields,
            ];
        }
        return $out;
    }

    /**
     * Keys of the notification channels that are actually enabled on a rule
     * (notifications JSON stores {name:{active,input}} — array_filter alone
     * keeps every present key, active or not).
     */
    private function activeChannels($notifications): array
    {
        if (! is_array($notifications)) return [];
        $out = [];
        foreach ($notifications as $key => $val) {
            $active = is_array($val) ? ($val['active'] ?? false) : $val;
            if ($active) $out[] = $key;
        }
        return $out;
    }

    /**
     * Translate the raw alert-types catalog (with AbstractField/SelectField
     * instances) into a JSON-friendly array consumed by the JS modal.
     */
    private function reshapeTypes(array $rawTypes): array
    {
        $out = [];
        foreach ($rawTypes as $t) {
            $fields = [];
            if (! empty($t['attributes'])) {
                foreach ($t['attributes'] as $attr) {
                    if (! $attr instanceof AbstractField) continue;
                    $f = [
                        'name'  => $attr->getName(),
                        'type'  => $attr->getType(),
                        'title' => (string) $attr->getTitle(),
                        'value' => $attr->getDefault(),
                    ];
                    if ($attr instanceof SelectField) {
                        $f['options'] = $attr->getOptions();
                    }
                    $fields[] = $f;
                }
            }
            $out[] = [
                'key'    => $t['type'],
                'label'  => (string) $t['title'],
                'fields' => $fields,
            ];
        }
        return $out;
    }
}
