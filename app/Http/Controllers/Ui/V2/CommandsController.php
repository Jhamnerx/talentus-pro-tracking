<?php

namespace App\Http\Controllers\Ui\V2;

use CustomFacades\ModalHelpers\SendCommandModalHelper;
use CustomFacades\ModalHelpers\UserGprsTemplateModalHelper;
use CustomFacades\ModalHelpers\UserSmsTemplateModalHelper;
use CustomFacades\Validators\SchedulesValidator;
use CustomFacades\Validators\SendCommandFormValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tobuli\Entities\CommandSchedule;
use Tobuli\Entities\SentCommand;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Protocols\Commands;
use Tobuli\Services\Commands\CommandService;
use Tobuli\Services\Commands\SendCommandService;
use Tobuli\Services\EntityLoader\UserDevicesLoader;
use Tobuli\Services\Schedule\Scheduler;

/**
 * UI v2 · Enviar comando (GPRS / SMS).
 *
 * Reutiliza la lógica legacy: CommandService (catálogo dinámico por
 * protocolo + plantillas GPRS/SMS del usuario) y SendCommandModalHelper
 * (validación EntityLoaderLimit + SendCommandService para el envío).
 * Lo consume el modal compartido tp-commands.js (rail + kebab de Live).
 */
class CommandsController extends BaseController
{
    /** Catálogo base del modal: gateway SMS, permisos y devices (flag sms). */
    public function meta(Request $request): JsonResponse
    {
        if (! $this->user->perm('send_command', 'view')) {
            return response()->json(['ok' => false, 'message' => trans('front.dont_have_permission')], 403);
        }

        $devices = [];
        foreach ($this->user->devices()->orderBy('name')->get(['devices.id', 'name', 'sim_number']) as $d) {
            $devices[] = [
                'id'   => (int) $d->id,
                'name' => (string) ($d->name ?: ('#' . $d->id)),
                'sms'  => ! empty($d->sim_number),
            ];
        }

        return response()->json([
            'ok'          => true,
            'sms_gateway' => (bool) $this->user->sms_gateway,
            'devices'     => $devices,
        ]);
    }

    /**
     * Catálogo de comandos para los devices elegidos (intersección) según
     * la conexión: gprs (protocolo Traccar + plantillas GPRS) o sms
     * (custom + plantillas SMS). Shape legacy: {type,title,attributes[]}.
     */
    public function commands(Request $request): JsonResponse
    {
        if (! $this->user->perm('send_command', 'view')) {
            return response()->json([], 403);
        }

        $ids = array_values(array_filter(array_map('intval', (array) $request->get('devices', []))));
        if (! $ids) {
            return response()->json([]);
        }

        $devices = $this->user->devices()->whereIn('devices.id', $ids)->get();
        if ($devices->isEmpty()) {
            return response()->json([]);
        }

        try {
            $connection = $request->get('connection') === 'sms' ? 'sms' : 'gprs';
            $list = (new CommandService($this->user))->getCommands($devices, true, $connection);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }

        return response()->json($list->values()->all());
    }

    /**
     * Envío. El payload replica el del form legacy: devices[], type y los
     * atributos dinámicos del comando; `connection` decide GPRS o SMS.
     * El helper valida (EntityLoaderLimit por límites de config) y envía.
     */
    public function send(Request $request): JsonResponse
    {
        try {
            $this->mergeSelectedDevices($request);

            // Auto-send when online (solo GPRS): los devices desconectados se
            // ENCOLAN (pending_commands) en vez de fallar; el resto sigue el
            // flujo normal. commands:dispatch-pending los envía al reconectar.
            $queued = [];
            if ($request->boolean('auto_online') && $request->get('connection') !== 'sms') {
                $queued = $this->queueOffline($request);
                if ($request->get('devices') === []) {
                    // Todos quedaron en cola: no hay nada que enviar ahora.
                    return response()->json([
                        'ok'       => true,
                        'message'  => trans('front.queued_for_online'),
                        'warnings' => [],
                        'queued'   => $queued,
                        'results'  => [],
                    ]);
                }
            }

            SendCommandModalHelper::setUser($this->user);
            SendCommandModalHelper::setApi(true);
            SendCommandModalHelper::setData($request->all());

            $res = $request->get('connection') === 'sms'
                ? SendCommandModalHelper::create()
                : SendCommandModalHelper::gprsCreate();
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors() ? $e->getErrors()->toArray() : [],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }

        // Contrato legacy: status=0 siempre; el resultado real va en
        // message (éxito) o warnings (errores por device). OJO: warnings es
        // una Collection — castearla con (array) rompe (expone `items` como
        // un único elemento array); normalizar con collect()->flatten().
        $warnings = collect($res['warnings'] ?? [])
            ->flatten()
            ->map(function ($w) {
                return is_scalar($w) ? (string) $w : json_encode($w);
            })
            ->values()
            ->all();

        return response()->json([
            'ok'       => true,
            'message'  => (string) ($res['message'] ?? trans('front.command_sent')),
            'warnings' => $warnings,
            'queued'   => $queued,
            'results'  => collect($res['results'] ?? [])->values()->all(),
        ]);
    }

    /**
     * Encola los devices seleccionados que NO están conectados (mismo criterio
     * que usa SendCommandService: isConnected) y deja en el request solo los
     * conectados para el envío inmediato. Devuelve los nombres encolados.
     */
    protected function queueOffline(Request $request): array
    {
        if (! $this->user->perm('send_command', 'view')) {
            return [];
        }

        // Valida ANTES de encolar (mismas reglas que el envío normal: límite
        // de devices vía loader + type requerido).
        SendCommandFormValidator::validate('gprs', $request->all());

        $ids = array_values(array_filter(array_map('intval', (array) $request->get('devices', []))));
        if (! $ids) {
            return [];
        }

        $devices = $this->user->devices()->whereIn('devices.id', $ids)->get();

        $parameters = collect($request->all())
            ->except(['devices', 'selected_devices', 'connection', 'auto_online'])
            ->all();

        $queued = [];
        $onlineIds = [];
        foreach ($devices as $device) {
            if ($device->isConnected()) {
                $onlineIds[] = (int) $device->id;
                continue;
            }
            \Tobuli\Entities\PendingCommand::create([
                'user_id'    => $this->user->id,
                'device_id'  => $device->id,
                'connection' => 'gprs',
                'command'    => (string) $request->get('type'),
                'parameters' => $parameters,
            ]);
            $queued[] = (string) $device->name;
        }

        // Solo los conectados siguen al envío inmediato.
        $request->merge(['devices' => array_map('strval', $onlineIds)]);
        $this->mergeSelectedDevices($request);

        return $queued;
    }

    /* =================================================================
     * Página · /ui/v2/commands — hub con tabs Horario | Registros |
     * Plantillas GPRS | Plantillas SMS (paridad Ubimak).
     * =================================================================
     */
    public function page(Request $request)
    {
        if (! $this->user->perm('send_command', 'view')) {
            abort(403);
        }

        return View::make('ui.v2.pages.commands', [
            'pageTitle' => trans('front.send_command'),
        ]);
    }

    /* =================================================================
     * Schedules (envío programado) — reusa SchedulesValidator +
     * Scheduler + CommandSchedule legacy (misma lógica que
     * CommandSchedulesController@store/update, aquí en JSON).
     * =================================================================
     */

    public function schedules(Request $request): JsonResponse
    {
        if (! $this->user->perm('send_command', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $rows = [];
        $items = $this->user->commandSchedules()->with(['schedule', 'devices:devices.id'])->get();
        foreach ($items as $cs) {
            $rows[] = [
                'id'          => (int) $cs->id,
                'connection'  => (string) $cs->connection,
                'command'     => (string) $cs->command,
                'parameters'  => (string) $cs->parameters_string,
                'params_raw'  => $cs->parameters ?: (object) [],
                'type'        => (string) ($cs->schedule->type ?? ''),
                'type_params' => $cs->schedule->parameters ?? (object) [],
                'schedule_at' => (string) ($cs->schedule->schedule_at ?? ''),
                'devices'     => $cs->devices->pluck('id')->all(),
            ];
        }

        return response()->json(['ok' => true, 'items' => $rows]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        return $this->saveSchedule($request, null);
    }

    public function updateSchedule(Request $request, $id): JsonResponse
    {
        return $this->saveSchedule($request, (int) $id);
    }

    protected function saveSchedule(Request $request, $id): JsonResponse
    {
        if (! $this->user->perm('send_command', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $this->mergeSelectedDevices($request);

        $data = $request->all();
        $isGprs = ($data['connection'] ?? 'gprs') !== 'sms';

        try {
            SchedulesValidator::validate($id ? 'update' : 'create', $data);
            SendCommandFormValidator::validate($isGprs ? 'gprs' : 'sms', $data);

            $loader = new UserDevicesLoader($this->user);
            $loader->setRequestKey('devices');

            $payload = [
                'connection' => $isGprs ? SendCommandService::CONNECTION_GPRS : SendCommandService::CONNECTION_SMS,
                'command'    => $data['type'],
                'parameters' => $isGprs ? $this->gprsParameters($data) : Arr::only($data, 'message'),
            ];

            // Auto-send when online: el flag viaja en parameters; al ejecutarse
            // el schedule (RunCommandScheduleJob) los devices offline se encolan.
            if ($isGprs && $request->boolean('auto_online')) {
                $payload['parameters'] = (array) ($payload['parameters'] ?? []) + ['auto_online' => 1];
            }

            beginTransaction();
            try {
                if ($id) {
                    $cs = CommandSchedule::find($id);
                    if (! $cs || $cs->user_id != $this->user->id) {
                        rollbackTransaction();
                        return response()->json(['ok' => false], 404);
                    }
                    $cs->update($payload);
                    $cs->devices()->syncLoader($loader);
                    app(Scheduler::class)->update($cs, $data);
                } else {
                    $cs = CommandSchedule::create($payload + ['user_id' => $this->user->id]);
                    $cs->devices()->syncLoader($loader);
                    app(Scheduler::class)->create($cs, $data, $this->user);
                }
            } catch (ValidationException $e) {
                rollbackTransaction();
                throw $e;
            }
            commitTransaction();
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors() ? $e->getErrors()->toArray() : [],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }

        return response()->json(['ok' => true, 'id' => (int) $cs->id]);
    }

    public function destroySchedule(Request $request, $id): JsonResponse
    {
        $cs = CommandSchedule::find((int) $id);
        if (! $cs || $cs->user_id != $this->user->id) {
            return response()->json(['ok' => false], 404);
        }

        if ($cs->schedule) {
            $cs->schedule->delete();
        }
        $cs->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * El UserDevicesLoader legacy NO lee `devices[]`: parsea
     * `selected_devices` = ["id;1;<deviceId>", …] (campo;estado;valor) desde
     * el request global. La v2 manda ids planos → aquí se traducen al
     * formato del loader antes de validar/enviar/sincronizar.
     */
    protected function mergeSelectedDevices(Request $request): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->get('devices', []))));
        // "s;0;" primero = deselect-all → la selección es EXACTA. Sin él la
        // secuencia son solo attaches y syncLoader nunca entra en la rama
        // detach: quitar un vehículo de un horario no lo desasignaba jamás.
        $request->merge([
            'selected_devices' => array_merge(['s;0;'], array_map(function ($id) {
                return 'id;1;' . $id;
            }, $ids)),
        ]);
    }

    /**
     * Extrae los parámetros del comando GPRS (copia documentada de
     * CommandSchedulesController::getGprsParameters — es private allá).
     */
    protected function gprsParameters(array $data): ?array
    {
        if (Str::startsWith($data['type'], 'template_')) {
            list($data['type'], $data['template_id']) = explode('_', $data['type']);
        }

        $command = (new Commands())->get($data['type']);

        $parameters = [];
        if (isset($command['attributes'])) {
            $parameters = $command['attributes']->map(function ($attribute) {
                return $attribute->getName();
            })->all();
        }

        if ($data['type'] == 'template') {
            $parameters[] = 'data';
        }

        $keys = array_intersect(array_keys($data), [
            Commands::KEY_DATA,
            Commands::KEY_INDEX,
            Commands::KEY_DEVICE_PASSWORD,
            Commands::KEY_ENABLE,
            Commands::KEY_FREQUENCY,
            Commands::KEY_MESSAGE,
            Commands::KEY_PHONE,
            Commands::KEY_PORT,
            Commands::KEY_RADIUS,
            Commands::KEY_TIMEZONE,
            Commands::KEY_UNIT,
        ]);

        if ($keys) {
            $parameters = array_merge($parameters, $keys);
        }

        return empty($parameters) ? null : Arr::only($data, $parameters);
    }

    /* =================================================================
     * Logs (SentCommand) — paginación en servidor, filtro por conexión
     * y columna de usuario para admin/manager (paridad legacy).
     * =================================================================
     */
    public function logs(Request $request): JsonResponse
    {
        if (! $this->user->perm('send_command', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $limit = min(200, max(10, (int) $request->get('limit', 20)));
        $q = trim((string) $request->get('q', ''));
        $conn = (string) $request->get('connection', '');
        $showUser = $this->user->isAdmin() || $this->user->isManager();

        // OJO: la relación device es por device_imei→imei; el select
        // restringido DEBE incluir `imei` o la relación no matchea.
        $query = SentCommand::userAccessible($this->user)
            ->with('device:id,name,imei')
            ->orderBy('created_at', 'desc');
        if ($q !== '') {
            $query->search($q);
        }
        if (in_array($conn, ['gprs', 'sms'], true)) {
            $query->where('connection', $conn);
        }
        if ($showUser) {
            $query->with('user:id,email');
        }

        $p = $query->paginate($limit);

        $rows = [];
        foreach ($p->items() as $log) {
            $row = [
                'id'         => (int) $log->id,
                'device'     => (string) ($log->device->name ?? '—'),
                'connection' => (string) $log->connection,
                'command'    => (string) ($log->command_title ?? $log->type),
                'status'     => (bool) $log->status,
                'date'       => (string) $log->created_at,
                'parameters' => (string) $log->stringifiedAttribute('parameters'),
                'response'   => (string) $log->stringifiedAttribute('response'),
            ];
            if ($showUser) {
                $row['user'] = (string) ($log->user->email ?? '—');
            }
            $rows[] = $row;
        }

        return response()->json([
            'ok'        => true,
            'items'     => $rows,
            'page'      => $p->currentPage(),
            'last'      => $p->lastPage(),
            'total'     => $p->total(),
            'show_user' => $showUser,
        ]);
    }

    /* =================================================================
     * Cola "Auto-send when online" (pending_commands): listado + cancelar.
     * =================================================================
     */
    public function pendings(Request $request): JsonResponse
    {
        if (! $this->user->perm('send_command', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $rows = [];
        $items = \Tobuli\Entities\PendingCommand::pending()
            ->where('user_id', $this->user->id)
            ->with('device:id,name')
            ->orderBy('id', 'desc')
            ->limit(500)
            ->get();

        foreach ($items as $p) {
            $params = [];
            foreach ((array) ($p->parameters ?? []) as $k => $v) {
                $params[] = $k . ': ' . (is_scalar($v) ? $v : json_encode($v));
            }
            $rows[] = [
                'id'         => (int) $p->id,
                'device'     => (string) ($p->device->name ?? '—'),
                'command'    => (string) $p->command,
                'parameters' => implode(', ', $params),
                'attempts'   => (int) $p->attempts,
                'last_error' => (string) ($p->last_error ?? ''),
                'date'       => (string) $p->created_at,
            ];
        }

        return response()->json(['ok' => true, 'items' => $rows]);
    }

    public function destroyPending(Request $request, $id): JsonResponse
    {
        $p = \Tobuli\Entities\PendingCommand::find((int) $id);
        if (! $p || $p->user_id != $this->user->id) {
            return response()->json(['ok' => false], 404);
        }
        $p->delete();

        return response()->json(['ok' => true]);
    }

    /** Registros de ejecución de UN horario (paridad command_schedules.logs). */
    public function scheduleLogs(Request $request, $id): JsonResponse
    {
        $cs = CommandSchedule::find((int) $id);
        if (! $cs || $cs->user_id != $this->user->id) {
            return response()->json(['ok' => false], 404);
        }

        $limit = min(100, max(5, (int) $request->get('limit', 15)));
        // device es belongsTo por imei: incluir `imei` en el select restringido
        $p = $cs->sentCommands()->with('device:id,name,imei')->latest()->paginate($limit);

        $rows = [];
        foreach ($p->items() as $log) {
            $rows[] = [
                'device'     => (string) ($log->device->name ?? '—'),
                'connection' => (string) $log->connection,
                'command'    => (string) ($log->command_title ?? $log->type),
                'status'     => (bool) $log->status,
                'date'       => (string) $log->created_at,
                'parameters' => (string) $log->stringifiedAttribute('parameters'),
                'response'   => (string) $log->stringifiedAttribute('response'),
            ];
        }

        return response()->json([
            'ok'    => true,
            'items' => $rows,
            'page'  => $p->currentPage(),
            'last'  => $p->lastPage(),
            'total' => $p->total(),
        ]);
    }

    /* =================================================================
     * Plantillas GPRS / SMS — reusa los ModalHelpers legacy (validación
     * + sync de devices/deviceTypes). type = gprs|sms.
     * =================================================================
     */

    protected function templateHelper(string $type)
    {
        return $type === 'sms' ? UserSmsTemplateModalHelper::getFacadeRoot() : UserGprsTemplateModalHelper::getFacadeRoot();
    }

    public function templates(Request $request): JsonResponse
    {
        $type = $request->get('type') === 'sms' ? 'sms' : 'gprs';

        $module = $type === 'sms' ? 'user_sms_templates' : 'user_gprs_templates';
        if (! $this->user->perm($module, 'view')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        try {
            $helper = $this->templateHelper($type);
            $helper->setUser($this->user);
            $helper->setApi(true);
            $helper->setData([]);

            // createData() exige perm 'create': un usuario con view pero sin edit
            // recibía 500 al abrir el tab. El listado no necesita el catálogo.
            $meta = [];
            if ($this->user->perm($module, 'edit')) {
                $meta = $helper->createData();
            }

            $entity = $type === 'sms' ? \Tobuli\Entities\UserSmsTemplate::class : \Tobuli\Entities\UserGprsTemplate::class;
            $rows = [];
            foreach ($entity::userOwned($this->user)->with('devices:devices.id')->orderBy('id', 'desc')->get() as $t) {
                $rows[] = [
                    'id'            => (int) $t->id,
                    'title'         => (string) $t->title,
                    'message'       => (string) $t->message,
                    'adapted'       => (string) ($t->adapted ?? ''),
                    'adapted_title' => (string) $t->adapted_title,
                    'protocol'      => (string) ($t->protocol ?? ''),
                    'lock_action'   => (string) ($t->lock_action ?? ''),
                    'devices'       => $t->devices->pluck('id')->all(),
                ];
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }

        return response()->json([
            'ok'           => true,
            'items'        => $rows,
            'adapties'     => $meta['adaptedies'] ?? [],
            'protocols'    => $meta['protocols'] ?? [],
            'device_types' => $meta['device_types'] ?? [],
        ]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        return $this->saveTemplate($request, null);
    }

    public function updateTemplate(Request $request, $id): JsonResponse
    {
        return $this->saveTemplate($request, (int) $id);
    }

    protected function saveTemplate(Request $request, $id): JsonResponse
    {
        $type = $request->get('type') === 'sms' ? 'sms' : 'gprs';

        try {
            $helper = $this->templateHelper($type);
            $helper->setUser($this->user);
            $helper->setApi(true);
            $payload = $request->all();
            if ($id) {
                $payload['id'] = $id;
            }

            // En modo api el helper hace devices()->sync(Arr::get(data,'devices',[])):
            // con la plantilla adaptada por protocolo/tipo el form manda [] y
            // DESASOCIABA los devices guardados. El path web (syncLoader) no los
            // tocaría, así que al editar preservamos la asignación actual salvo
            // que el usuario esté editando explícitamente la lista de devices.
            if ($id && ($payload['adapted'] ?? '') !== 'devices') {
                $entity  = $type === 'sms' ? \Tobuli\Entities\UserSmsTemplate::class : \Tobuli\Entities\UserGprsTemplate::class;
                $current = $entity::userOwned($this->user)->find($id);
                if ($current) {
                    $payload['devices'] = $current->devices()->pluck('devices.id')->all();
                }
            }

            $helper->setData($payload);
            $res = $id ? $helper->edit() : $helper->create();
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors() ? $e->getErrors()->toArray() : [],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }

        return response()->json(['ok' => (bool) ($res['status'] ?? 0)]);
    }

    public function destroyTemplate(Request $request, $id): JsonResponse
    {
        $type = $request->get('type') === 'sms' ? 'sms' : 'gprs';

        try {
            $helper = $this->templateHelper($type);
            $helper->setUser($this->user);
            $helper->setApi(true);
            $helper->setData(['id' => (int) $id]);
            $res = $helper->destroy();
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }

        return response()->json(['ok' => (bool) ($res['status'] ?? 0)]);
    }
}
