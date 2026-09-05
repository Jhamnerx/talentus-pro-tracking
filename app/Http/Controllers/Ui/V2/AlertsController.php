<?php

namespace App\Http\Controllers\Ui\V2;

use CustomFacades\ModalHelpers\AlertModalHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Exceptions\ValidationException;
use Tobuli\InputFields\AbstractField;
use Tobuli\InputFields\SelectField;
use Tobuli\Reports\ReportManager;
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
            'pageTitle' => 'Alertas',
            'canEdit'   => $this->user->perm('alerts', 'edit'),
            'canCreate' => $this->user->perm('alerts', 'edit'),
            'canRemove' => $this->user->perm('alerts', 'remove'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        if (! $this->user->perm('alerts', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        // La página de Alertas ahora es una tabla índice de REGLAS. El feed de
        // eventos vive en su propio módulo (/ui/v2/events), así que aquí solo
        // devolvemos las reglas + totales.
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
                    'schedule'      => (bool) $r->schedule,
                    // Solo canales realmente activos (antes se listaban todos los
                    // presentes en el JSON → "muro de badges").
                    'channels'      => $this->activeChannels($r->notifications),
                ];
            }
        } catch (\Throwable $e) {
        }

        return response()->json([
            'ok'     => true,
            'rules'  => $rules,
            'totals' => [
                'rules'  => count($rules),
                'active' => count(array_filter($rules, function ($r) {
                    return $r['active'];
                })),
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
            ], $this->statusFor($e));
        }

        $dl      = $this->deviceListPayload();
        $devices = $dl['devices'];
        $groups  = $dl['groups'];

        $types = $this->reshapeTypes($data['types'] ?? []);

        return response()->json([
            'ok'            => true,
            'types'         => $types,
            'devices'       => $devices,
            'groups'        => $groups,
            'geofences'     => $data['geofences']     ?? [],
            'alert_zones'   => $data['alert_zones']   ?? [],
            'notifications' => array_values((array) ($data['notifications'] ?? [])),
            'schedules'     => $data['schedules']     ?? [],
            'can_command'   => (bool) $user->perm('send_command', 'view'),
            // Para el canal "reporte por correo": que reporte y en que formato
            'report_types'   => $this->reportTypes($user),
            'report_formats' => ReportManager::getFormats(),
        ]);
    }

    /* =================================================================
     * Plantillas de alerta
     * -----------------------------------------------------------------
     * Se capturan de una alerta YA configurada en vez de tener su propio
     * formulario: asi se reutiliza el editor completo (tipos, parametros,
     * canales, horarios) en lugar de mantener dos.
     *
     * Al dar de alta un usuario se le crean las alertas de las plantillas
     * que le tocan por su plan. Es una COPIA: lo que el cliente edite
     * despues manda, y volver a sembrar no le pisa nada.
     * ================================================================= */

    /** Listado de plantillas (solo admin). */
    public function templates(Request $request): JsonResponse
    {
        if (! isAdmin()) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $planes = \Tobuli\Entities\BillingPlan::pluck('title', 'id')->all();

        $items = \Tobuli\Entities\AlertTemplate::orderBy('name')->get()->map(function ($t) use ($planes) {
            return [
                'id'          => (int) $t->id,
                'active'      => (bool) $t->active,
                'name'        => (string) $t->name,
                'type'        => (string) $t->type,
                'plan_id'     => $t->billing_plan_id ? (int) $t->billing_plan_id : null,
                'plan'        => $t->billing_plan_id ? ($planes[$t->billing_plan_id] ?? '?') : null,
                'all_devices' => (bool) $t->for_all_user_devices,
                'description' => (string) ($t->description ?? ''),
            ];
        })->all();

        return response()->json([
            'ok'    => true,
            'items' => $items,
            'plans' => array_map(
                fn($id, $title) => ['id' => (int) $id, 'title' => (string) $title],
                array_keys($planes),
                array_values($planes)
            ),
        ]);
    }

    /** Guarda una alerta existente como plantilla. */
    public function templateStore(Request $request, $id): JsonResponse
    {
        try {
            if (! isAdmin()) {
                return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
            }

            $alert = $this->user->alerts()->find((int) $id);

            if (! $alert) {
                return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            }

            $plan = $request->input('plan_id');

            $plantilla = (new \Tobuli\Services\AlertTemplateService())->captureFrom($alert, [
                'billing_plan_id' => $plan ? (int) $plan : null,
                'description'     => (string) $request->input('description', ''),
            ]);

            return response()->json(['ok' => true, 'id' => (int) $plantilla->id]);
        } catch (\Throwable $e) {
            return response()->json(
                ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
                $this->statusFor($e)
            );
        }
    }

    /** Activar/desactivar o cambiar el plan de una plantilla. */
    public function templateUpdate(Request $request, $id): JsonResponse
    {
        try {
            if (! isAdmin()) {
                return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
            }

            $plantilla = \Tobuli\Entities\AlertTemplate::find((int) $id);

            if (! $plantilla) {
                return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            }

            if ($request->has('active')) {
                $plantilla->active = (bool) $request->input('active');
            }

            if ($request->has('plan_id')) {
                $plan = $request->input('plan_id');
                $plantilla->billing_plan_id = $plan ? (int) $plan : null;
            }

            if ($request->has('all_devices')) {
                $plantilla->for_all_user_devices = (bool) $request->input('all_devices');
            }

            $plantilla->save();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(
                ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
                $this->statusFor($e)
            );
        }
    }

    public function templateDestroy($id): JsonResponse
    {
        try {
            if (! isAdmin()) {
                return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
            }

            $plantilla = \Tobuli\Entities\AlertTemplate::find((int) $id);

            if ($plantilla) {
                $plantilla->delete();
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(
                ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
                $this->statusFor($e)
            );
        }
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
            ], $this->statusFor($e));
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
            ], $this->statusFor($e));
        }

        $types = $this->reshapeTypes($data['types'] ?? []);

        $dl      = $this->deviceListPayload();
        $devices = $dl['devices'];
        $groups  = $dl['groups'];

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
            'report_type'           => $alert->report_type ? (int) $alert->report_type : null,
            'report_format'         => (string) ($alert->report_format ?: 'pdf'),
            'report_minutes_before' => (int) $alert->report_minutes_before,
            'report_minutes_after'  => (int) $alert->report_minutes_after,
            'fields'               => $typeFieldValues,
        ];

        return response()->json([
            'ok'            => true,
            'item'          => $item,
            'types'         => $types,
            'devices'       => $devices,
            'groups'        => $groups,
            'geofences'     => $data['geofences']   ?? [],
            'alert_zones'   => $data['alert_zones'] ?? [],
            'notifications' => array_values((array) ($data['notifications'] ?? [])),
            'schedules'     => $data['schedules']   ?? [],
            'can_command'   => (bool) $user->perm('send_command', 'view'),
            'report_types'   => $this->reportTypes($user),
            'report_formats' => ReportManager::getFormats(),
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

            // Salvaguarda del modo api: AlertModalHelper::edit() hace
            // devices()->sync(Arr::get(data,'devices',[])) — si la clave falta,
            // sync([]) DESASIGNA todos los vehículos (el path web con loader no
            // tocaría nada). Si el cliente no la envía, preservamos la actual.
            if (! $request->has('devices')) {
                $current = $user->alerts()->find((int) $id);
                if ($current) {
                    $payload['devices'] = $current->devices()->pluck('devices.id')->all();
                }
            }

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
            ], $this->statusFor($e));
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
            ], $this->statusFor($e));
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Devices + groups payload for the Devices tab (agrupado + paginado en JS).
     * Cada device trae su group_id (pivot user_device_pivot.group_id).
     */
    private function deviceListPayload(): array
    {
        $devices = [];
        $used    = [];
        try {
            $rows = $this->user->devices()
                ->withPivot('group_id')
                ->orderBy('devices.name', 'asc')
                ->get(['devices.id', 'devices.name', 'devices.plate_number']);
            foreach ($rows as $d) {
                $gid = (int) (optional($d->pivot)->group_id ?? 0);
                $used[$gid] = true;
                $devices[] = [
                    'id'       => (int) $d->id,
                    'name'     => (string) ($d->name ?: ('#' . $d->id)),
                    'plate'    => trim((string) ($d->plate_number ?? '')),
                    'group_id' => $gid,
                ];
            }
        } catch (\Throwable $e) {
        }

        $titles = [];
        try {
            $rows = \Tobuli\Entities\DeviceGroup::where('user_id', $this->user->id)
                ->orderBy('title', 'asc')->get(['id', 'title']);
            foreach ($rows as $g) {
                $titles[(int) $g->id] = (string) $g->title;
            }
        } catch (\Throwable $e) {
        }

        // Solo grupos que tienen algún device; el bucket 0 = "Sin grupo".
        $groups = [];
        if (! empty($used[0])) {
            $groups[] = ['id' => 0, 'title' => 'Sin grupo'];
        }
        foreach ($titles as $gid => $title) {
            if (! empty($used[$gid])) {
                $groups[] = ['id' => $gid, 'title' => $title];
            }
        }

        return ['devices' => $devices, 'groups' => $groups];
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
            ], $this->statusFor($e));
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
    /** Reportes que el usuario puede generar, para el canal de reporte. */
    private function reportTypes($user): array
    {
        $lista = [];

        foreach ((new ReportManager())->getUserEnabledNameList($user) as $id => $nombre) {
            $lista[] = ['id' => (int) $id, 'name' => (string) $nombre];
        }

        return $lista;
    }

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
