<?php

namespace App\Http\Controllers\Ui\V2;

use App\Transformers\Device\DeviceMapTransformer;
use CustomFacades\GeoLocation;
use CustomFacades\Repositories\DeviceRepo;
use ModalHelpers\DeviceModalHelper;
use Tobuli\Entities\File\DeviceCameraMedia;
use FractalTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Exceptions\ValidationException;

/**
 * TalentusPro · DevicesController
 * -----------------------------------------------------------------
 * Owns every endpoint that lives under /talentus for the *device*
 * domain: the Live Ops map, the Fleet table, the data feeds that
 * back them, and the lightweight CRUD modal shared by Live, Fleet
 * and Admin → Fleet.
 *
 * The heavy CRUD logic is delegated to the legacy DeviceModalHelper
 * so we keep validation, permissions and side effects identical to
 * the legacy form. The controller only translates HTTP ↔ ModalHelper.
 * -----------------------------------------------------------------
 */
class DevicesController extends BaseController
{
    /**
     * Live Ops page (operational map + virtualized device list).
     */
    public function live(Request $request)
    {
        if (! $this->user->perm('devices', 'view')) {
            abort(403, 'No tienes permiso para ver dispositivos.');
        }

        return View::make('ui.v2.pages.live', [
            'pageTitle'     => trans('front.map'),
            'socketPort'    => config('server.socket_port', env('SOCKET_PORT', 3000)),
            'socketSslPort' => config('server.socket_ssl_port', env('SOCKET_SSL_PORT', 3001)),
            'mapConfig'     => $this->buildMapConfig(),
            'canCreate'     => $this->user->perm('devices', 'edit'),
        ]);
    }

    /**
     * Live feed (polled by the JS client every ~15s).
     * Returns full device + last position payloads.
     */
    public function liveData(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['data' => [], 'meta' => ['pagination' => ['total' => 0]]], 403);
        }

        // Paginación estándar (length-aware): expone el TOTAL real en
        // meta.pagination.total (mismo conteo que /admin/users/objects) y
        // page links para que el cliente cargue TODAS las páginas (>1000).
        $perPage = min(2000, max(100, (int) $request->get('limit', 500)));
        $page    = max(1, (int) $request->get('page', 1));

        // NB: sin ->wasConnected() a propósito → el conteo refleja TODAS las
        // unidades del usuario (devices()->count(), igual que el total del
        // admin), incluidas las que aún no han reportado (se muestran offline).
        $devices = $this->user
            ->devices()
            ->filter($request->all())
            ->clearOrdersBy()
            ->paginate($perPage, ['*'], 'page', $page);

        $payload = FractalTransformer::paginate($devices, DeviceMapTransformer::class)->toArray();

        if (! empty($payload['data']) && is_array($payload['data'])) {
            foreach ($payload['data'] as &$row) {
                if (isset($row['id'])) {
                    $row['channel'] = md5('device_' . $row['id']);
                }
            }
            unset($row);
        }

        // Grupos del usuario (id → título) para agrupar la lista como el legacy.
        // Solo en la primera página (no cambian entre páginas). El group_id de
        // cada device viene del pivot vía DeviceMapTransformer.
        if ($page === 1) {
            $payload['groups'] = $this->user->deviceGroups()
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(function ($g) {
                    return ['id' => (int) $g->id, 'title' => $g->title];
                })
                ->all();
        }

        return response()->json($payload);
    }

    /**
     * Monitoreo de grupo: grilla 3×3 de mini-mapas, cada celda sigue a un
     * vehículo elegido (selector agrupado). Reusa el feed de Live.
     */
    public function monitor(Request $request)
    {
        if (! $this->user->perm('devices', 'view')) {
            abort(403);
        }

        return \Illuminate\Support\Facades\View::make('ui.v2.pages.monitor', [
            'pageTitle' => 'Monitoreo de grupo',
            'mapConfig' => $this->buildMapConfig(),
        ]);
    }

    /**
     * Fleet page (virtualized table).
     */
    public function fleet(Request $request)
    {
        if (! $this->user->perm('devices', 'view')) {
            abort(403, 'No tienes permiso para ver la flota.');
        }

        return View::make('ui.v2.pages.fleet', [
            'pageTitle' => trans('front.objects'),
            'canCreate' => $this->user->perm('devices', 'edit'),
        ]);
    }

    /**
     * Fleet data feed (paginated, filterable).
     */
    public function fleetData(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $limit  = min(500, max(25, (int) $request->get('limit', 100)));
        $page   = max(1, (int) $request->get('page', 1));
        $q      = trim((string) $request->get('q', ''));
        $status = (string) $request->get('status', '');
        // Filtros del panel (paridad con el listado del ERP): grupo, modelo de
        // equipo y protocolo. El grupo vive en el pivot y es POR USUARIO.
        $group    = $request->get('group', '');
        $model    = trim((string) $request->get('model', ''));
        $protocol = trim((string) $request->get('protocol', ''));

        $query = $this->user->devices()->clearOrdersBy();

        // La lista excluye por defecto los equipos que nunca conectaron… salvo
        // cuando son justo lo que se pide (tabla Lookup NeverConnected).
        if ($status !== 'never_connected') {
            $query->wasConnected();
        }

        // TODOS los estados se filtran en SQL con los scopes de la entidad.
        // Antes los cuatro básicos se filtraban en PHP DESPUÉS de paginar: la
        // página traía 25 vehículos y descartaba los que no coincidían, así que
        // la tabla salía vacía mientras el contador seguía diciendo 1411.
        switch ($status) {
            case 'moving':          $query->move();           break;
            case 'idle':            $query->idle();           break;
            case 'stopped':         $query->park();           break;
            case 'offline':         $query->offline();        break;
            case 'inactive':        $query->inactive();       break;
            case 'never_connected': $query->neverConnected(); break;
            case 'expired':         $query->expired();        break;
        }

        if ($group !== '' && $group !== null) {
            $query->wherePivot('group_id', (int) $group);
        }

        if ($model !== '') {
            $query->where('devices.device_model', $model);
        }

        if ($protocol !== '') {
            $query->traccarJoin()->where('traccar_devices.protocol', $protocol);
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('devices.name', 'like', '%' . $q . '%')
                    ->orWhere('devices.imei', 'like', '%' . $q . '%')
                    ->orWhere('devices.plate_number', 'like', '%' . $q . '%')
                    ->orWhere('devices.vin', 'like', '%' . $q . '%');
            });
        }

        $total = (clone $query)->count();

        $rows = $query->orderBy('devices.name', 'asc')
            ->forPage($page, $limit)
            ->get();

        // Títulos de grupo del usuario (la columna 'group' del listview legacy).
        $groupTitles = $this->user->deviceGroups()->pluck('title', 'id')->all();

        // Las columnas caras (combustible y último evento) solo se calculan si
        // el usuario las tiene activas: no se paga por lo que no se mira.
        $activeCols = array_column(
            (array) ($this->user->getSettings('listview')['columns'] ?? []),
            'field'
        );
        $wantFuel   = in_array('fuel', $activeCols, true);
        // Columnas de sensor activas: se calcula su valor por vehículo.
        $sensorHashes = [];
        foreach ((array) ($this->user->getSettings('listview')['columns'] ?? []) as $c) {
            if (is_array($c) && ($c['class'] ?? '') === 'sensor' && ! empty($c['field'])) {
                $sensorHashes[] = (string) $c['field'];
            }
        }
        $wantEvents = (bool) array_intersect(['last_event_title', 'last_event_type', 'last_event_time'], $activeCols);

        // Último evento por vehículo, en UNA consulta para toda la página.
        // Se agrupa por MAX(id) y no por MAX(time): sobre 1,2 M de eventos el
        // MAX(time) con join tarda ~5,8 s y este resuelve en ~125 ms.
        $lastEvents = [];
        $pageIds = $rows->pluck('id')->all();
        if ($pageIds && $wantEvents) {
            try {
                $maxIds = \DB::table('events')
                    ->selectRaw('MAX(id) as mid')
                    ->whereIn('device_id', $pageIds)
                    ->where('user_id', $this->user->id)
                    ->groupBy('device_id')
                    ->pluck('mid');

                foreach (\Tobuli\Entities\Event::whereIn('id', $maxIds)->get() as $ev) {
                    $lastEvents[$ev->device_id] = [
                        'title' => (string) $ev->title,
                        'type'  => (string) $ev->type_title,
                        'time'  => $ev->time ? \Formatter::time()->convert($ev->time) : null,
                    ];
                }
            } catch (\Throwable $e) {
                $lastEvents = [];
            }
        }

        // Una sola instancia para toda la pagina: la regla del video es la
        // misma para todos los vehiculos.
        $videoService = new \Tobuli\Services\DeviceVideoService();

        $items = [];
        foreach ($rows as $device) {
            $st = $device->getStatus();
            $fuel = $wantFuel ? $this->fuelColumns($device) : ['percentage' => null, 'quantity' => null, 'price' => null];
            $sensorVals = $this->sensorValues($device, $sensorHashes);

            $items[] = [
                'id'              => $device->id,
                'name'            => $device->name,
                'imei'            => $device->imei,
                'plate'           => $device->plate_number,
                'model'           => $device->device_model,
                'sim'             => $device->sim_number,
                'icon_color'      => $device->getStatusColor($st),
                'status'          => $st,
                'status_group'    => $this->groupStatus($st),
                'active'          => (bool) $device->active,
                'speed'           => (float) $device->getSpeed(),
                'engine_hours'    => (float) ($device->engine_hours ?? 0),
                'moved_at'        => $device->moved_at,
                'expiration_date' => $device->expiration_date,
                // Flags para el menú de acciones (template estático + data-need):
                'vt'              => (bool) $device->vehicle_type_id,
                'chat'            => (bool) $device->canChat(),
                'plans'           => (bool) $device->isPlanAble(),
                'app_uuid'        => ! empty($device->app_uuid),
                'general'         => (bool) $device->isGeneral(),
                // Misma regla que el widget y que el visor. Antes esta lista
                // de protocolos se habia quedado corta respecto a la del
                // servicio (le faltaba jt1078).
                'video'           => $videoService->isEnabled($device) && $device->traccar_device_id,

                // Campos extra para las columnas configurables (catálogo
                // config('tobuli.listview_fields')). Solo los que salen del
                // propio device/traccar: los derivados caros (dirección
                // geocodificada, combustible, duraciones, último evento) no se
                // incluyen para no penalizar la lista.
                'protocol'            => (string) ($device->protocol ?? ''),
                'time'                => $device->time ? (string) $device->time : null,
                'position'            => ($device->lat && $device->lng)
                    ? round((float) $device->lat, 6) . ', ' . round((float) $device->lng, 6)
                    : null,
                // Para la columna Dirección: el cliente geocodifica solo las
                // filas visibles (el legacy lo hace en servidor, fila a fila).
                'lat'                 => $device->lat ? (float) $device->lat : null,
                'lng'                 => $device->lng ? (float) $device->lng : null,
                'vin'                 => (string) ($device->vin ?? ''),
                'registration_number' => (string) ($device->registration_number ?? ''),
                'object_owner'        => (string) ($device->object_owner ?? ''),
                'additional_notes'    => (string) ($device->additional_notes ?? ''),
                'device_type'         => (string) (optional($device->device_type)->title ?? ''),
                'group'               => (string) (optional($device->pivot)->group_id
                    ? ($groupTitles[$device->pivot->group_id] ?? '')
                    : ''),
                // ── Columnas que el listado configurable del legacy ofrece y
                // aquí faltaban. Todas salen de traccar_devices o de sensores ya
                // cargados; la única cara de verdad (dirección geocodificada) se
                // resuelve en el cliente sobre las filas visibles.
                'stop_duration'     => (string) $device->stop_duration,
                'idle_duration'     => (string) $device->idle_duration,
                'ignition_duration' => (string) $device->ignition_duration,
                'ignition'          => (string) $device->getEngineStatus(true),
                // Combustible: el legacy lo despliega en tres columnas
                // (porcentaje, cantidad y precio) a partir del sensor de depósito.
                'fuel_percentage'   => $fuel['percentage'],
                'fuel_quantity'     => $fuel['quantity'],
                'fuel_price'        => $fuel['price'],
                // Último evento (una sola consulta para toda la página, ver arriba)
                'last_event_title'  => $lastEvents[$device->id]['title'] ?? null,
                'last_event_type'   => $lastEvents[$device->id]['type'] ?? null,
                'last_event_time'   => $lastEvents[$device->id]['time'] ?? null,
                // Valores de los sensores elegidos como columna: { hash: {text, value} }
                'sensors'               => $sensorVals ?: null,
                'sim_activation_date'   => $this->cleanDate($device->sim_activation_date),
                'sim_expiration_date'   => $this->cleanDate($device->sim_expiration_date),
                'installation_date'     => $this->cleanDate($device->installation_date),
            ];
        }

        $payload = [
            'ok'    => true,
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
            'items' => $items,
            'pages' => (int) ceil($total / $limit),
        ];

        // Opciones del panel de filtros: solo en la primera carga, no cambian
        // al paginar. Grupos del usuario + modelos y protocolos realmente
        // presentes en SU flota (no el catalogo entero).
        if ($request->boolean('with_filters')) {
            $payload['filters'] = [
                'groups'    => $this->fleetGroupOptions(),
                'models'    => $this->fleetDistinct('devices.device_model'),
                'protocols' => $this->fleetDistinct('traccar_devices.protocol'),
            ];
        }

        return response()->json($payload);
    }

    /* =================================================================
     * Copiar sensores entre vehiculos
     * -----------------------------------------------------------------
     * No existe en el legacy: dar de alta una flota igual obligaba a
     * repetir la misma configuracion vehiculo a vehiculo. El duplicado se
     * detecta por el `hash` del sensor (md5(type.name)), el mismo criterio
     * con el que la lista de flota agrupa sensores equivalentes.
     * ================================================================= */

    /** Vehiculos a los que se puede copiar (los que el usuario puede editar). */
    public function sensorCopyTargets(Request $request, $id): JsonResponse
    {
        try {
            $source = \Tobuli\Entities\Device::find((int) $id);
            $this->checkException('devices', 'show', $source);

            $items = [];
            foreach ($this->user->devices()->orderBy('name')->get(['devices.id', 'name', 'plate_number']) as $d) {
                if ((int) $d->id === (int) $source->id) {
                    continue;
                }
                if (! $this->user->can('edit', $d)) {
                    continue;
                }

                $items[] = [
                    'id'    => (int) $d->id,
                    'name'  => (string) ($d->name ?: ('#' . $d->id)),
                    'plate' => (string) ($d->plate_number ?? ''),
                ];
            }

            return response()->json(['ok' => true, 'items' => $items]);
        } catch (\Throwable $e) {
            return response()->json(
                ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
                $this->statusFor($e)
            );
        }
    }

    /** Copia los sensores elegidos del vehiculo a los vehiculos destino. */
    public function sensorsCopy(Request $request, $id): JsonResponse
    {
        try {
            $source = \Tobuli\Entities\Device::find((int) $id);
            $this->checkException('devices', 'show', $source);

            $sensorIds = array_values(array_unique(array_filter(
                array_map('intval', (array) $request->input('sensors', []))
            )));
            $deviceIds = array_values(array_unique(array_filter(
                array_map('intval', (array) $request->input('devices', []))
            )));
            // Que hacer con lo que el destino YA tiene:
            //   keep     conservarlo (solo se copia lo que falta)
            //   replace  reemplazar los que coincidan en tipo+nombre
            //   mirror   dejar el destino EXACTAMENTE igual al origen
            // Se acepta el `overwrite` antiguo por compatibilidad.
            $mode = (string) $request->input('mode', '');
            if ($mode === '') {
                $mode = $request->boolean('overwrite') ? 'replace' : 'keep';
            }
            if (! in_array($mode, ['keep', 'replace', 'mirror'], true)) {
                $mode = 'keep';
            }
            $overwrite = ($mode === 'replace');

            if (! $sensorIds || ! $deviceIds) {
                return response()->json([
                    'ok'     => false,
                    'errors' => ['sensors' => [trans('front.nothing_selected')]],
                ], 422);
            }

            // Solo sensores del vehiculo de origen: no se copia lo ajeno.
            $sensors = \Tobuli\Entities\DeviceSensor::where('device_id', $source->id)
                ->whereIn('id', $sensorIds)
                ->get();

            if ($sensors->isEmpty()) {
                return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            }

            $targets = $this->user->devices()->whereIn('devices.id', $deviceIds)->get();

            $copied = 0;
            $replaced = 0;
            $skipped = 0;
            $denied = 0;
            $deleted = 0;
            // Sensores del destino con el MISMO nombre que uno copiado pero
            // distinto tipo: no cuentan como duplicado (el hash es
            // tipo+nombre) y por eso el destino acaba con mas sensores que el
            // origen. Se avisa en vez de dejar al usuario contando.
            $colisiones = [];
            $detalle = [];

            $nombresOrigen = [];
            foreach ($sensors as $sen) {
                $nombresOrigen[mb_strtolower(trim((string) $sen->name))] = $sen->hash;
            }

            beginTransaction();

            try {
                foreach ($targets as $target) {
                    if (! $this->user->can('edit', $target)) {
                        $denied++;
                        continue;
                    }

                    $propios = \Tobuli\Entities\DeviceSensor::where('device_id', $target->id)->get();

                    if ($mode === 'mirror') {
                        // El destino queda identico al origen: fuera todo lo
                        // suyo antes de copiar.
                        foreach ($propios as $own) {
                            $own->delete();
                            $deleted++;
                        }
                        $propios = collect();
                    }

                    // Hashes ya presentes en el destino, para no duplicar.
                    $existing = [];
                    foreach ($propios as $own) {
                        $existing[$own->hash] = $own;

                        $n = mb_strtolower(trim((string) $own->name));
                        if (isset($nombresOrigen[$n]) && $nombresOrigen[$n] !== $own->hash) {
                            $colisiones[$own->name] = true;
                        }
                    }

                    $n = 0;
                    foreach ($sensors as $sensor) {
                        if (isset($existing[$sensor->hash])) {
                            if (! $overwrite) {
                                $skipped++;
                                continue;
                            }
                            $existing[$sensor->hash]->delete();
                            $replaced++;
                        } else {
                            $copied++;
                        }

                        // replicate() y no create(): hay columnas fuera de
                        // $fillable (icon_id, on_value/off_value, formulas)
                        // que con asignacion masiva se perderian.
                        $copy = $sensor->replicate();
                        $copy->device_id = $target->id;
                        $copy->user_id   = $this->user->id;
                        $copy->save();

                        $n++;
                    }

                    $detalle[] = [
                        'device_id' => (int) $target->id,
                        'name'      => (string) $target->name,
                        'copied'    => $n,
                    ];
                }

                commitTransaction();
            } catch (\Throwable $e) {
                rollbackTransaction();
                throw $e;
            }

            return response()->json([
                'ok'         => true,
                'mode'       => $mode,
                'copied'     => $copied,
                'replaced'   => $replaced,
                'skipped'    => $skipped,
                'denied'     => $denied,
                'deleted'    => $deleted,
                'collisions' => array_keys($colisiones),
                'devices'    => $detalle,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(
                ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
                $this->statusFor($e)
            );
        }
    }

    /**
     * Imagen del vehiculo: subir. Paridad con DeviceImagesController@store
     * del legacy (mismo validador y mismo servicio); la v2 solo la mostraba.
     */
    public function deviceImageStore(Request $request, $id): JsonResponse
    {
        try {
            $device = \Tobuli\Entities\Device::find((int) $id);
            $this->checkException('devices', 'update', $device);

            \CustomFacades\Validators\DeviceImageValidator::validate('upload', $request->all());

            (new \Tobuli\Services\DeviceImageService($device))->save($request->file('image'));

            $device->refresh();

            return response()->json([
                'ok'    => true,
                'image' => $device->image ? url($device->image) : null,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(
                ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
                $this->statusFor($e)
            );
        }
    }

    /** Imagen del vehiculo: borrar (DeviceImagesController@destroy). */
    public function deviceImageDestroy($id): JsonResponse
    {
        try {
            $device = \Tobuli\Entities\Device::find((int) $id);
            $this->checkException('devices', 'update', $device);

            (new \Tobuli\Services\DeviceImageService($device))->delete();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(
                ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
                $this->statusFor($e)
            );
        }
    }

    /**
     * Envio de una plantilla webhook (DeviceWidgetsController@templateWebhookSend).
     * Va al servicio externo configurado en addon.widget_template.
     */
    public function templateWebhookSend(Request $request, $id): JsonResponse
    {
        try {
            if (! config('addon.widget_template')
                || ! $this->user->perm('widget_template_webhook', 'view')
            ) {
                return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
            }

            $device = \Tobuli\Entities\Device::find((int) $id);
            $this->checkException('devices', 'show', $device);

            $template = null;
            if (\Illuminate\Support\Facades\Schema::hasTable('templates')) {
                $template = \DB::table('templates')
                    ->where('device_id', $device->id)
                    ->where('template_id', $request->get('template_id'))
                    ->first();
            }

            if (! $template) {
                return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            }

            (new \Curl())->post(config('addon.widget_template'), [
                'device_id'   => $device->id,
                'template_id' => $template->template_id,
                'wox_user_id' => $this->user->id,
            ]);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(
                ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
                $this->statusFor($e)
            );
        }
    }

    /* =================================================================
     * Historial de cambios del vehiculo
     * -----------------------------------------------------------------
     * Lee model_change_logs, que ya se venia llenando. Incluye lo que le
     * pasa a sus PARTES —sensores, servicios, mantenimientos—, no solo a
     * la ficha: es lo que uno espera al preguntar "que se hizo con este
     * vehiculo".
     *
     * Se excluyen los inicios de sesion (login_success / login_fail): son
     * el 90% de la tabla y no son cambios.
     * ================================================================= */
    public function deviceChanges(Request $request, $id): JsonResponse
    {
        try {
            $device = DeviceRepo::find((int) $id);
            $this->checkException('devices', 'show', $device);

            // El vehiculo usa alias del morphMap; sus partes, el nombre completo
            $condiciones = [
                ['device', [(int) $device->id]],
            ];

            $sensores = \Tobuli\Entities\DeviceSensor::where('device_id', $device->id)->pluck('id')->all();
            if ($sensores) {
                $condiciones[] = [\Tobuli\Entities\DeviceSensor::class, $sensores];
            }

            try {
                $servicios = \Tobuli\Entities\DeviceService::where('device_id', $device->id)->pluck('id')->all();
                if ($servicios) {
                    $condiciones[] = [\Tobuli\Entities\DeviceService::class, $servicios];
                }
            } catch (\Throwable $e) {
                // sin servicios, se sigue
            }

            try {
                $mantenimientos = \Tobuli\Entities\DeviceMaintenance::where('device_id', $device->id)->pluck('id')->all();
                if ($mantenimientos) {
                    $condiciones[] = [\Tobuli\Entities\DeviceMaintenance::class, $mantenimientos];
                }
            } catch (\Throwable $e) {
                // sin mantenimientos, se sigue
            }

            $query = \Tobuli\Entities\ModelChangeLog::query()
                ->whereIn('description', ['created', 'updated', 'deleted'])
                ->where(function ($q) use ($condiciones) {
                    foreach ($condiciones as [$tipo, $ids]) {
                        $q->orWhere(function ($w) use ($tipo, $ids) {
                            $w->where('subject_type', $tipo)->whereIn('subject_id', $ids);
                        });
                    }
                })
                ->with('causer')
                ->orderBy('id', 'desc')
                ->limit(200);

            $items = [];

            foreach ($query->get() as $log) {
                // properties viene como Collection (asi lo castea spatie). Con
                // (array) se obtienen sus propiedades internas, no el
                // contenido: los cambios salian siempre vacios.
                $props = $log->properties instanceof \Illuminate\Support\Collection
                    ? $log->properties->toArray()
                    : (array) $log->properties;
                $nuevos = (array) ($props['attributes'] ?? []);
                $viejos = (array) ($props['old'] ?? []);

                $cambios = [];
                foreach ($nuevos as $campo => $valor) {
                    $cambios[] = [
                        'field'  => (string) $campo,
                        'before' => $this->valorLegible($viejos[$campo] ?? null),
                        'after'  => $this->valorLegible($valor),
                    ];
                }

                $items[] = [
                    'id'      => (int) $log->id,
                    'at'      => $log->created_at ? \Formatter::time()->convert($log->created_at) : null,
                    'what'    => $this->etiquetaSujeto((string) $log->subject_type),
                    'action'  => (string) $log->description,
                    'who'     => $this->quienLoHizo($log),
                    'ip'      => (string) ($log->ip ?? ''),
                    'changes' => $cambios,
                ];
            }

            return response()->json([
                'ok'     => true,
                'device' => ['id' => (int) $device->id, 'name' => (string) $device->name],
                'items'  => $items,
            ]);
        } catch (\Throwable $e) {
            return response()->json(
                ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
                $this->statusFor($e)
            );
        }
    }

    /** Nombre de quien hizo el cambio; si ya no existe, se dice. */
    private function quienLoHizo($log): string
    {
        try {
            $causer = $log->causer;

            if (! $causer) {
                return trans('front.unknown');
            }

            return (string) ($causer->email ?? $causer->name ?? ('#' . $log->causer_id));
        } catch (\Throwable $e) {
            return trans('front.unknown');
        }
    }

    /** De qué entidad es el cambio, en palabras. */
    private function etiquetaSujeto(string $tipo): string
    {
        $mapa = [
            'device'                => trans('front.object'),
            \Tobuli\Entities\DeviceSensor::class      => trans('front.sensor'),
            \Tobuli\Entities\DeviceService::class     => trans('front.service'),
            \Tobuli\Entities\DeviceMaintenance::class => trans('front.maintenance'),
        ];

        return $mapa[$tipo] ?? class_basename($tipo);
    }

    /** Valores legibles: los arrays y objetos se resumen, no se vuelcan. */
    private function valorLegible($valor): string
    {
        if ($valor === null) {
            return '';
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        if (is_array($valor) || is_object($valor)) {
            $json = json_encode($valor, JSON_UNESCAPED_UNICODE);

            return mb_strlen($json) > 120 ? mb_substr($json, 0, 117) . '...' : $json;
        }

        $texto = (string) $valor;

        return mb_strlen($texto) > 120 ? mb_substr($texto, 0, 117) . '...' : $texto;
    }

    /** Grupos del usuario con su numero de vehiculos (para el filtro). */
    private function fleetGroupOptions(): array
    {
        $counts = \DB::table('user_device_pivot')
            ->where('user_id', $this->user->id)
            ->selectRaw('group_id, COUNT(*) as n')
            ->groupBy('group_id')
            ->pluck('n', 'group_id')
            ->all();

        $out = [];
        foreach ($this->user->deviceGroups()->orderBy('title')->get(['id', 'title']) as $g) {
            $out[] = [
                'id'    => (int) $g->id,
                'title' => (string) $g->title,
                'count' => (int) ($counts[$g->id] ?? 0),
            ];
        }

        return $out;
    }

    /** Valores distintos de una columna dentro de la flota del usuario. */
    private function fleetDistinct(string $column): array
    {
        try {
            $query = $this->user->devices()->clearOrdersBy();

            if (strpos($column, 'traccar_devices.') === 0) {
                $query->traccarJoin();
            }

            // toBase(): sobre el builder de Eloquent, pluck() de una columna
            // cualificada devuelve vacios (busca un atributo del modelo con
            // ese nombre). Sobre el query builder si resuelve la columna.
            $alias = substr($column, strrpos($column, '.') + 1);

            return $query->whereNotNull($column)
                ->where($column, '<>', '')
                ->toBase()
                ->select($column)
                ->distinct()
                ->orderBy($column)
                ->pluck($alias)
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* =================================================================
     * Columnas configurables de la lista de flota (paridad ObjectsList)
     * Se guardan en el MISMO ajuste que el legacy — setSettings('listview')
     * con {columns: [{field, class}]} — así ambas UIs comparten la config.
     * ================================================================= */

    /** Columnas que la vista de flota sabe pintar (subconjunto del catálogo). */
    private const FLEET_COLUMNS = [
        'name', 'status', 'plate_number', 'imei', 'device_model', 'sim_number',
        'speed', 'time', 'protocol', 'position', 'group', 'device_type_id',
        'vin', 'registration_number', 'object_owner', 'additional_notes',
        'active', 'sim_activation_date', 'sim_expiration_date',
        'installation_date', 'expiration_date',
        // Añadidas 19-ago: el listado configurable del legacy ya las ofrecía.
        'stop_duration', 'idle_duration', 'ignition_duration', 'fuel',
        'last_event_title', 'last_event_type', 'last_event_time',
        // La dirección se resuelve en el cliente (ver feed): el legacy la
        // geocodifica fila a fila en servidor y son ~0,5 s por vehículo.
        'address',
    ];

    /**
     * Columnas de sensor del usuario para el selector: una por combinación
     * tipo+nombre (el `hash` del legacy es md5(type.name)), así una misma
     * columna sirve para todos los vehículos que tengan ese sensor.
     */
    private function sensorColumns(): array
    {
        $numeric = (array) config('tobuli.numeric_sensors', []);
        $titles  = [];

        try {
            $titles = (new \Tobuli\Sensors\SensorsManager())->getEnabledListTitles();
        } catch (\Throwable $e) {
            $titles = [];
        }

        $out = [];
        try {
            foreach (\CustomFacades\Repositories\DeviceSensorRepo::whereUserId($this->user->id) as $sensor) {
                $hash = $sensor->hash;
                if (isset($out[$hash])) {
                    continue;
                }

                $out[$hash] = [
                    'field'   => (string) $hash,
                    'class'   => 'sensor',
                    'type'    => (string) $sensor->type,
                    'label'   => $sensor->name . ' (' . ($titles[$sensor->type] ?? $sensor->type) . ')',
                    // Solo los numéricos admiten rangos de color, igual que el
                    // configurador del legacy.
                    'numeric' => in_array($sensor->type, $numeric, true),
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return array_values($out);
    }

    /** Valor actual de cada sensor activo, por vehículo (para las columnas de sensor). */
    private function sensorValues($device, array $hashes): array
    {
        if (! $hashes) {
            return [];
        }

        $out = [];
        try {
            foreach ($device->sensors as $sensor) {
                $hash = $sensor->hash;
                if (! in_array($hash, $hashes, true)) {
                    continue;
                }

                $value = $sensor->getValueCurrent($device);
                $out[$hash] = [
                    'text'  => (string) $value->getFormatted(),
                    // El valor crudo decide el color según los rangos definidos.
                    'value' => $value->getValue(),
                ];
            }
        } catch (\Throwable $e) {
            return $out;
        }

        return $out;
    }

    /**
     * Galería de iconos de un grupo (icon / rotating). Va aparte del formulario
     * porque el catálogo llega a superar el millar de iconos y embeberlo hacía
     * que el modal de vehículo pesara ~460 KB.
     */
    public function deviceIcons(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $group    = (string) $request->get('group', '');
        $selected = (int) $request->get('selected', 0);

        $icons = \Tobuli\Entities\DeviceIcon::where('type', $group)
            ->orderBy('order', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'path']);

        return response()->json([
            'ok'    => true,
            'items' => $icons->map(function ($i) use ($selected) {
                return [
                    'id'       => (int) $i->id,
                    'url'      => asset($i->path),
                    'selected' => (int) $i->id === $selected,
                ];
            })->all(),
        ]);
    }

    public function fleetColumns(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        if (function_exists('listviewTrans')) {
            // Etiquetas traducidas, igual que ObjectsList/edit.blade
            $settings = ['columns' => []];
            $fields   = config('tobuli.listview_fields', []);
            listviewTrans($this->user->id, $settings, $fields);
        } else {
            $fields = config('tobuli.listview_fields', []);
        }

        $available = [];
        foreach ($fields as $key => $def) {
            if (! in_array($key, self::FLEET_COLUMNS, true)) {
                continue;
            }
            $available[] = [
                'field' => (string) ($def['field'] ?? $key),
                'class' => (string) ($def['class'] ?? 'device'),
                // listviewTrans() devuelve las etiquetas con entidades HTML
                // ("Ultima conexi&oacute;n"); el JS las escapa y se veían crudas.
                'label' => html_entity_decode((string) ($def['title'] ?? $def['text'] ?? $key), ENT_QUOTES, 'UTF-8'),
            ];
        }

        // Columnas de sensor del usuario (una por tipo+nombre)
        $sensorCols = $this->sensorColumns();
        $sensorHashes = array_column($sensorCols, 'field');
        $available = array_merge($available, $sensorCols);

        $saved = (array) ($this->user->getSettings('listview')['columns'] ?? []);
        $selected = [];
        $colors   = [];
        foreach ($saved as $c) {
            $field = is_array($c) ? ($c['field'] ?? null) : $c;
            if (! $field) {
                continue;
            }
            if (in_array($field, self::FLEET_COLUMNS, true) || in_array($field, $sensorHashes, true)) {
                $selected[] = (string) $field;
                // Rangos de color de esa columna (solo sensores numéricos)
                if (is_array($c) && ! empty($c['color'])) {
                    $colors[(string) $field] = array_values((array) $c['color']);
                }
            }
        }

        return response()->json([
            'ok'        => true,
            'available' => $available,
            // Sin configuración previa (o toda ella fuera de esta vista): el
            // set por defecto de la tabla de flota.
            'selected'  => $selected ?: ['name', 'status', 'plate_number', 'imei', 'device_model', 'sim_number', 'speed', 'time', 'expiration_date'],
            // { hash: [{from, to, color}] } — pinta el valor del sensor según el tramo
            'colors'    => $colors,
        ]);
    }

    public function updateFleetColumns(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        if (isDemoUser()) {
            return response()->json(['ok' => true, 'demo' => true]);
        }

        $fields = config('tobuli.listview_fields', []);
        $wanted = (array) $request->input('columns', []);
        $colors = (array) $request->input('colors', []);

        // Columnas de sensor válidas del usuario (hash => definición)
        $sensorCols = [];
        foreach ($this->sensorColumns() as $sc) {
            $sensorCols[$sc['field']] = $sc;
        }

        $columns = [];
        foreach ($wanted as $field) {
            $field = (string) $field;

            if (isset($sensorCols[$field])) {
                $col = [
                    'field' => $field,
                    'class' => 'sensor',
                    'type'  => $sensorCols[$field]['type'],
                ];

                // Rangos de color: solo para sensores numéricos, y saneados
                // (from/to numéricos y color en formato #rrggbb).
                if ($sensorCols[$field]['numeric'] && ! empty($colors[$field])) {
                    $ranges = [];
                    foreach ((array) $colors[$field] as $r) {
                        $from  = $r['from']  ?? null;
                        $to    = $r['to']    ?? null;
                        $color = (string) ($r['color'] ?? '');
                        if (! is_numeric($from) || ! is_numeric($to)) {
                            continue;
                        }
                        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                            continue;
                        }
                        $ranges[] = ['from' => (float) $from, 'to' => (float) $to, 'color' => $color];
                    }
                    if ($ranges) {
                        $col['color'] = $ranges;
                    }
                }

                $columns[] = $col;
                continue;
            }

            if (! isset($fields[$field]) || ! in_array($field, self::FLEET_COLUMNS, true)) {
                continue;
            }
            $columns[] = [
                'field' => (string) ($fields[$field]['field'] ?? $field),
                'class' => (string) ($fields[$field]['class'] ?? 'device'),
            ];
        }

        if (! $columns) {
            return response()->json(['ok' => false, 'errors' => ['columns' => ['Elige al menos una columna.']]], 422);
        }

        // Se conserva lo que el legacy guarde ahí y no gestione esta vista
        // (p. ej. groupby), para no pisar su configuración.
        $current = (array) $this->user->getSettings('listview');
        $current['columns'] = $columns;

        $this->user->setSettings('listview', $current);

        return response()->json(['ok' => true]);
    }

    /* =================================================================
     * Galería de medios del vehículo (fotos subidas por las cámaras)
     * -----------------------------------------------------------------
     * Paridad DeviceMediaController: los archivos no están en base de
     * datos, viven en disco (cameras_media_path) y los enumera la entidad
     * DeviceCameraMedia. Aquí solo se expone en JSON; servir, descargar y
     * borrar sigue en las rutas legacy, que ya resuelven rutas y permisos.
     * ================================================================= */

    public function deviceMedia(Request $request, $id): JsonResponse
    {
        $this->checkException('camera', 'view');

        $device = DeviceRepo::find((int) $id);
        $this->checkException('devices', 'show', $device);

        $limit  = min(60, max(6, (int) $request->get('limit', 24)));
        $page   = max(1, (int) $request->get('page', 1));
        $sortBy = $request->get('sort_by', 'date_modified');
        $sort   = $request->get('sort') === 'asc' ? 'asc' : 'desc';

        $categoriesEnabled = (bool) $this->user->perm('media_categories', 'view');

        $items = [];
        $total = 0;

        try {
            \Tobuli\Entities\File\DeviceCameraMedia::setCategoriesEnabled($categoriesEnabled);
            $query = \Tobuli\Entities\File\DeviceCameraMedia::setEntity($device);

            $sorter = $query->getEntityFileSorter()->sortBy($sortBy, $sort);

            if ($from = $request->get('date_from')) {
                $sorter->from(\Formatter::time()->reverse($from));
            }
            if ($to = $request->get('date_to')) {
                $sorter->to(\Formatter::time()->reverse($to));
            }
            if ($categoriesEnabled && ($cat = $request->get('media_category'))) {
                $sorter->nameStartsWith($cat . '.');
            }

            $paginator = $query->paginate($limit, $sorter);
            $total = $paginator->total();

            foreach ($paginator as $file) {
                // `name` es el identificador cifrado (Crypt::encrypt del path
                // relativo) que esperan las rutas legacy; `basename` es el
                // nombre legible que se muestra.
                $ref = (string) $file->name;
                $items[] = [
                    'ref'        => $ref,
                    'name'       => basename((string) $file->path),
                    'size'       => $file->size,
                    'created_at' => $file->created_at ? \Formatter::time()->convert($file->created_at) : null,
                    'is_video'   => (bool) $file->isVideo(),
                    'camera'     => (string) ($file->camera_name ?? ''),
                    'category'   => (string) ($file->category ?? ''),
                    // Rutas legacy: sirven el binario con los mismos permisos.
                    'url'        => url('/device_media/file/' . $device->id . '/' . $ref),
                    'download'   => url('/device_media/download/' . $device->id . '/' . $ref),
                ];
            }
        } catch (\Throwable $e) {
            // Sin directorio de medios (equipo sin cámara o almacén no montado)
            // el legacy también devuelve una lista vacía.
            $items = [];
            $total = 0;
        }

        $categories = [];
        if ($categoriesEnabled) {
            foreach (\Tobuli\Entities\MediaCategory::userAccessible($this->user)->get() as $c) {
                $categories[] = ['id' => (int) $c->id, 'title' => (string) $c->title];
            }
        }

        return response()->json([
            'ok'         => true,
            'page'       => $page,
            'limit'      => $limit,
            'total'      => $total,
            'pages'      => (int) ceil($total / $limit),
            'items'      => $items,
            'categories' => $categories,
            'can_remove' => (bool) $this->user->perm('camera', 'remove'),
        ]);
    }

    public function destroyDeviceMedia(Request $request, $id): JsonResponse
    {
        $this->checkException('camera', 'remove');

        $device = DeviceRepo::find((int) $id);
        $this->checkException('devices', 'show', $device);

        // Llegan los `ref` cifrados que devuelve la galería (find() los descifra).
        $refs = array_filter((array) $request->input('files', []));
        if (! $refs) {
            return response()->json(['ok' => false, 'error' => 'no_files'], 422);
        }

        $deleted = 0;
        foreach ($refs as $ref) {
            try {
                $file = \Tobuli\Entities\File\DeviceCameraMedia::setEntity($device)->find((string) $ref);
                if ($file && $file->delete()) {
                    $deleted++;
                    continue;
                }

                // find() resuelve el ref como ruta RELATIVA al directorio del
                // equipo. Cuando el ref ya trae la ruta absoluta (pasa si los
                // separadores del sistema no coinciden con los del path), se
                // borra directamente, pero solo si el archivo está realmente
                // dentro del directorio de medios de ESTE equipo.
                $decoded = \Illuminate\Support\Facades\Crypt::decrypt(urldecode((string) $ref));
                $real    = realpath($decoded);
                $base    = realpath(rtrim(cameras_media_path(), '/\\') . DIRECTORY_SEPARATOR . $device->imei);

                if ($real && $base && strncmp($real, $base, strlen($base)) === 0 && is_file($real)) {
                    if (@unlink($real)) {
                        $deleted++;
                    }
                }
            } catch (\Throwable $e) {
                // se ignora el archivo que ya no exista o un ref manipulado
            }
        }

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    /**
     * Combustible en las tres columnas del legacy (porcentaje, cantidad y
     * precio), calculadas desde el sensor de depósito. Sin sensor: '-'.
     */
    private function fuelColumns($device): array
    {
        $none = ['percentage' => '-', 'quantity' => '-', 'price' => '-'];

        try {
            $sensor = $device->getFuelTankSensor();
            if (! $sensor) {
                return $none;
            }

            $value = $sensor->getValueCurrent($device)->getValue();
            $full  = $sensor->getMaxTankValue();

            $pct = $full ? floatval($value) * 100 / $full : 0;
            $pct = max(0, min(100, $pct));

            return [
                'percentage' => round($pct) . '%',
                'quantity'   => (string) $sensor->getValueCurrent($device)->getFormatted(),
                'price'      => (string) round(floatval($value) * (float) $device->fuel_price, 2),
            ];
        } catch (\Throwable $e) {
            return $none;
        }
    }

    /** '0000-00-00' (placeholder legacy) se trata como vacío. */
    private function cleanDate($value): ?string
    {
        $v = (string) $value;

        return ($v === '' || strpos($v, '0000-00-00') === 0) ? null : $v;
    }

    /* =================================================================
     * CRUD modal (shared by Live, Fleet, Admin → Fleet)
     * =================================================================
     */


    /**
     * Render server-side form HTML (create + edit) reusing legacy
     * DeviceModalHelper::createData()/editData() catalogs. The JS
     * modal just injects this HTML and wires submit/tabs — no
     * templating duplicated client-side.
     */
    public function form(Request $request, $id = null): JsonResponse
    {
        if (! $this->user->perm('devices', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso.'], 403);
        }

        try {
            $helper = app(DeviceModalHelper::class);
            $helper->setUser($this->user);
            $helper->setApi(false);

            if ($id) {
                $helper->setData(['id' => (int) $id]);
                $data = $helper->editData();

                // Tab Sensores: lista server-rendered (se re-hidrata por AJAX tras cada CRUD).
                $data['sensors_list'] = \Tobuli\Entities\DeviceSensor::where('device_id', (int) $id)
                    ->orderBy('id', 'desc')
                    ->get()
                    ->map(function ($s) {
                        return ['id' => (int) $s->id, 'name' => (string) $s->name, 'type' => (string) $s->type_title];
                    })
                    ->all();

                $data['services_list'] = $this->presentDeviceServices((int) $id);
                $data['cameras_list']  = $this->presentDeviceCameras((int) $id);
                $data['expenses_list'] = $this->presentDeviceExpenses((int) $id);
            } else {
                $helper->setData([]);
                $data = $helper->createData();
            }

            // Catálogo de tipos de gasto (para el form nativo del tab Gastos).
            // La columna es `name` (el legacy hace pluck('name','id')).
            $data['expenses_types_list'] = function_exists('expensesTypesExist') && expensesTypesExist()
                ? \Tobuli\Entities\DeviceExpensesType::orderBy('name')->get()
                    ->map(function ($t) {
                        return ['id' => (int) $t->id, 'title' => (string) $t->name];
                    })
                    ->all()
                : [];

            // Caps for conditional tabs (mirror Talentus permission gates).
            $data['caps'] = [
                'is_admin'             => (bool) $this->user->isAdmin(),
                'can_edit_users'       => (bool) ($this->user->isAdmin() && $this->user->perm('users', 'view')),
                'can_view_sensors'     => (bool) $this->user->isAdmin(),
                'can_view_cameras'     => (bool) $this->user->perm('device_camera', 'view'),
                'has_expenses'         => (bool) (function_exists('expensesTypesExist') && expensesTypesExist()),
                'can_view_webservices' => (bool) (
                    $this->user->perm('webservice.consatel',   'view')
                    || $this->user->perm('webservice.mininter', 'view')
                    || $this->user->perm('webservice.sutran',   'view')
                    || $this->user->perm('webservice.osinergmin', 'view')
                    || $this->user->perm('webservice.wisetrack', 'view')
                    || $this->user->perm('webservice.sidersales', 'view')
                    || $this->user->perm('webservice.epscenter', 'view')
                ),
                // Campos personalizados: mismo gate que Devices/edit.blade del
                // legacy — can('view', $item, 'custom_fields') (que ya considera
                // el addon vía PermissionService) + que existan campos definidos.
                // Beacon: el modal reduce tabs/campos (paridad edit_beacon.blade)
                'is_beacon'            => (bool) (
                    isset($data['item'])
                    && $data['item'] instanceof \Tobuli\Entities\Device
                    && $data['item']->isBeacon()
                ) || $request->get('kind') === 'beacon',
                'beacons_plugin'       => (bool) settings('plugins.beacons.status'),
                'has_custom_fields'    => (bool) (
                    isset($data['item'])
                    && $data['item'] instanceof \Tobuli\Entities\Device
                    && $this->user->can('view', $data['item'], 'custom_fields')
                    && $data['item']->hasCustomFields()
                ),
            ];

            $html = View::make('ui.v2.modals.devices.form', $data)->render();

            return response()->json([
                'ok'   => true,
                'id'   => $id ? (int) $id : null,
                'html' => $html,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], $this->statusFor($e));
        }
    }

    /**
     * Create a device (mirrors legacy DeviceModalHelper::create()).
     */
    public function store(Request $request): JsonResponse
    {
        // Paridad legacy DevicesController@store: a los usuarios con el módulo
        // 'custom_device_add' se les PROHÍBE el alta estándar (deben pasar por
        // el flujo custom/pagos).
        if ($this->user->perm('custom_device_add', 'view')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        if (! $this->user->perm('devices', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso para crear vehículos.'], 403);
        }

        try {
            $helper = app(DeviceModalHelper::class);
            $helper->setUser($this->user);
            $helper->setData($request->all());
            $helper->setApi(false);

            $result = $helper->create();

            return response()->json([
                'ok'   => true,
                'id'   => (int) ($result['id'] ?? 0),
                'item' => $this->presentDevice((int) ($result['id'] ?? 0)),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], $this->statusFor($e));
        }
    }


    /**
     * Update a device (mirrors legacy DeviceModalHelper::edit()).
     */
    public function update(Request $request, $id): JsonResponse
    {
        if (! $this->user->perm('devices', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso para editar vehículos.'], 403);
        }

        try {
            $data = $request->all();
            $data['id'] = (int) $id;

            $helper = app(DeviceModalHelper::class);
            $helper->setUser($this->user);
            $helper->setData($data);
            $helper->setApi(false);

            $result = $helper->edit();

            return response()->json([
                'ok'   => true,
                'id'   => (int) ($result['id'] ?? $id),
                'item' => $this->presentDevice((int) ($result['id'] ?? $id)),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], $this->statusFor($e));
        }
    }

    /* =================================================================
     * Beacons (plugin beacons) — paridad BeaconsController
     * -----------------------------------------------------------------
     * Un beacon es un Device con kind = KIND_BEACON. No pasa por
     * DeviceModalHelper (su edit() rechaza beacons explícitamente): se
     * valida con BeaconFormValidator y se guarda con DeviceService.
     * ================================================================= */

    /** El plugin manda: sin él, el legacy responde 404 desde el constructor. */
    private function assertBeaconsEnabled(): void
    {
        if (! settings('plugins.beacons.status')) {
            abort(404);
        }
    }

    /** Colores admitidos por estado (mismo mapa que BeaconsController). */
    private function beaconIconColors(?\Tobuli\Entities\Device $item, array $data): array
    {
        $allowed = ['green', 'yellow', 'red', 'blue', 'orange', 'black'];

        $colors = $item
            ? $item->icon_colors + settings('device.status_colors.colors')
            : settings('device.status_colors.colors');

        foreach ($colors as $status => $color) {
            $key = "icon_$status";
            if (array_key_exists($key, $data) && in_array($data[$key], $allowed, true)) {
                $colors[$status] = $data[$key];
            }
        }

        return $colors;
    }

    public function storeBeacon(Request $request): JsonResponse
    {
        $this->assertBeaconsEnabled();
        $this->checkException('devices', 'store');

        // Mismo veto que el alta estándar y que BeaconsController@store.
        if ($this->user->perm('custom_device_add', 'view')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        try {
            $data = $request->all();
            $data['group_id'] = empty($data['group_id']) ? null : $data['group_id'];
            $data['icon_colors'] = $this->beaconIconColors(null, $data);

            \CustomFacades\Validators\BeaconFormValidator::validate('create', $data);

            $data['kind'] = \Tobuli\Entities\Device::KIND_BEACON;

            $device = app(\Tobuli\Services\DeviceService::class)->create($data);

            return response()->json(['ok' => true, 'id' => (int) $device->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function updateBeacon(Request $request, $id): JsonResponse
    {
        $this->assertBeaconsEnabled();

        $beacon = \Tobuli\Entities\Device::kindBeacon()->find((int) $id);
        if (! $beacon) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('devices', 'edit', $beacon);

        try {
            $data = $request->all();
            $data['group_id'] = empty($data['group_id']) ? null : $data['group_id'];
            $data['icon_colors'] = $this->beaconIconColors($beacon, $data);

            \CustomFacades\Validators\BeaconFormValidator::validate('update', $data, $beacon->id);

            app(\Tobuli\Services\DeviceService::class)->update($beacon, $data);

            return response()->json(['ok' => true, 'id' => (int) $beacon->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /* =================================================================
     * Tipos de trayecto por periodo (DeviceRouteType) — paridad
     * DeviceRoutesTypeController. Marca tramos como Privado / Negocio.
     * Las fechas viajan en la TZ del usuario: reverse() al guardar y
     * convert() al pintar, igual que el legacy.
     * ================================================================= */

    public function routeTypes(Request $request, $id): JsonResponse
    {
        $device = DeviceRepo::find((int) $id);
        $this->checkException('devices', 'show', $device);

        $rows = \Tobuli\Entities\DeviceRouteType::where('device_id', $device->id)
            ->orderBy('started_at')
            ->get();

        return response()->json([
            'ok'    => true,
            'types' => collect(\Tobuli\Entities\DeviceRouteType::types())
                ->map(function ($label, $id) {
                    return ['id' => (int) $id, 'label' => (string) $label];
                })->values()->all(),
            'items' => $rows->map(function ($r) {
                return [
                    'id'         => (int) $r->id,
                    'type'       => (int) $r->type,
                    'type_label' => \Tobuli\Entities\DeviceRouteType::types()[$r->type] ?? '',
                    'started_at' => \Formatter::time()->convert($r->started_at),
                    'ended_at'   => \Formatter::time()->convert($r->ended_at),
                ];
            })->all(),
        ]);
    }

    public function storeRouteType(Request $request, $id): JsonResponse
    {
        $device = DeviceRepo::find((int) $id);
        $this->checkException('devices', 'show', $device);

        try {
            $data = $request->all();
            \Facades\Tobuli\Validation\DeviceRoutesTypeFormValidator::validate('create', $data);

            \Tobuli\Entities\DeviceRouteType::create([
                'user_id'    => $this->user->id,
                'device_id'  => $device->id,
                'type'       => $data['type'],
                'started_at' => \Formatter::time()->reverse($data['started_at']),
                'ended_at'   => \Formatter::time()->reverse($data['ended_at']),
            ]);

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function updateRouteType(Request $request, $id): JsonResponse
    {
        $route = \Tobuli\Entities\DeviceRouteType::find((int) $id);
        if (! $route) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('device_route_types', 'update', $route);

        try {
            $data = $request->all();
            \Facades\Tobuli\Validation\DeviceRoutesTypeFormValidator::validate('update', $data);

            $route->update([
                'type'       => $data['type'],
                'started_at' => \Formatter::time()->reverse($data['started_at']),
                'ended_at'   => \Formatter::time()->reverse($data['ended_at']),
            ]);

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function destroyRouteType(Request $request, $id): JsonResponse
    {
        $route = \Tobuli\Entities\DeviceRouteType::find((int) $id);
        if (! $route) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('device_route_types', 'remove', $route);

        $route->delete();

        return response()->json(['ok' => true]);
    }

    /* =================================================================
     * Alertas del vehículo con periodo de vigencia (pivote alert_device)
     * Paridad DeviceAlertsController: lista y edición de active_from /
     * active_to por alerta+vehículo (fechas en la TZ del usuario).
     * ================================================================= */

    public function deviceAlerts(Request $request, $id): JsonResponse
    {
        $this->checkException('alerts', 'view');

        $device = DeviceRepo::find((int) $id);
        $this->checkException('devices', 'show', $device);

        // withCount: el listado del legacy muestra a cuantos vehiculos
        // aplica cada alerta (DeviceAlerts/table.blade).
        $alerts = $device->alerts()
            ->where('alerts.user_id', $this->user->id)
            ->withCount('devices')
            ->get();

        return response()->json([
            'ok'       => true,
            // El legacy deja editar la alerta desde aqui; en la v2 el editor
            // completo vive en su propia pantalla, asi que se enlaza.
            'can_edit' => (bool) $this->user->perm('alerts', 'edit'),
            'edit_url' => url('/ui/v2/alerts') . '?edit=',
            'items' => $alerts->map(function ($a) {
                return [
                    'id'          => (int) $a->id,
                    'name'        => (string) $a->name,
                    'type'        => (string) $a->type,
                    'active'      => (bool) $a->active,
                    'active_from' => $a->pivot->active_from ? \Formatter::time()->convert($a->pivot->active_from) : null,
                    'active_to'   => $a->pivot->active_to ? \Formatter::time()->convert($a->pivot->active_to) : null,
                    'devices'     => (int) ($a->devices_count ?? 0),
                ];
            })->all(),
        ]);
    }

    public function updateDeviceAlertPeriod(Request $request, $id, $alertId): JsonResponse
    {
        $device = DeviceRepo::find((int) $id);
        $this->checkException('devices', 'show', $device);

        $alert = $device->alerts()->find((int) $alertId);
        $this->checkException('alerts', 'show', $alert);

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()->toArray()], 422);
        }

        $from = $request->get('date_from');
        $to   = $request->get('date_to');

        $device->alerts()->updateExistingPivot($alert->id, [
            'active_from' => $from ? \Formatter::time()->reverse($from) : null,
            'active_to'   => $to ? \Formatter::time()->reverse($to) : null,
        ]);

        return response()->json(['ok' => true]);
    }

    /* =================================================================
     * Configuración remota del GPS por SMS (DeviceConfigController)
     * Envía los comandos de la marca + APN al SIM del vehículo.
     * ================================================================= */

    public function deviceConfigMeta(Request $request): JsonResponse
    {
        if (! $this->user->able('configure_device')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        return response()->json([
            'ok'      => true,
            // devices_sms: solo los que tienen SIM (el legacy usa esa relación)
            'devices' => $this->user->devices_sms
                ->map(function ($d) {
                    return ['id' => (int) $d->id, 'name' => (string) $d->nameWithSimNumber];
                })->values()->all(),
            'configs' => \Tobuli\Entities\DeviceConfig::active()->get()
                ->map(function ($c) {
                    return ['id' => (int) $c->id, 'name' => (string) $c->fullName];
                })->values()->all(),
            'apns'    => \Tobuli\Entities\ApnConfig::active()->get()
                ->map(function ($a) {
                    return [
                        'id'           => (int) $a->id,
                        'name'         => (string) $a->name,
                        'apn_name'     => (string) $a->apn_name,
                        'apn_username' => (string) $a->apn_username,
                        'apn_password' => (string) $a->apn_password,
                    ];
                })->values()->all(),
        ]);
    }

    public function deviceConfigure(Request $request): JsonResponse
    {
        if (! $this->user->able('configure_device')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        try {
            $data = $request->all();
            \CustomFacades\Validators\DeviceConfiguratorFormValidator::validate('configure', $data);

            $device = DeviceRepo::find((int) ($data['device_id'] ?? 0));
            $this->checkException('devices', 'show', $device);

            $config = \Tobuli\Entities\DeviceConfig::find($data['config_id']);
            if (! $config) {
                return response()->json(['ok' => false, 'errors' => ['config_id' => ['Configuración no encontrada.']]], 422);
            }

            $manager = new \Tobuli\Helpers\SMS\SMSGatewayManager();
            $gatewayArgs = settings('sms_gateway.use_as_system_gateway') ? ['request_method' => 'system'] : null;
            $sender = $manager->loadSender($this->user, $gatewayArgs);

            $apnData = [
                'apn_name'     => $data['apn_name'] ?? null,
                'apn_username' => $data['apn_username'] ?? null,
                'apn_password' => $data['apn_password'] ?? null,
            ];

            $sent = app(\Tobuli\Services\DeviceConfigService::class)
                ->setSmsManager($sender)
                ->configureDevice($device->sim_number, $apnData, $config->commands);

            if (! $sent) {
                return response()->json(['ok' => false, 'message' => trans('validation.cant_configure_device')], 500);
            }

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * Delete a device.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (! $this->user->perm('devices', 'remove')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso para eliminar vehículos.'], 403);
        }

        // Candado anti-borrado. DIVERGE del legacy a propósito: el legacy compara contra la
        // clave FIJA del .env (addon.object_delete_pass) compartida por todos; la v2 valida la
        // CONTRASEÑA DE LOGIN del propio usuario (Hash::check contra su hash bcrypt). Aquí
        // addon.object_delete_pass funciona solo como interruptor on/off del candado.
        if (config('addon.object_delete_pass') && isAdmin()
            && ! \Hash::check((string) $request->input('password'), (string) $this->user->password)) {
            return response()->json(['ok' => false, 'message' => trans('front.login_failed')], 422);
        }

        try {
            $helper = app(DeviceModalHelper::class);
            $helper->setUser($this->user);
            $helper->setData(['id' => (int) $id]);
            $helper->setApi(false);

            $helper->destroy();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], $this->statusFor($e));
        }
    }

    /**
     * Filtro avanzado del sidebar (paridad addon.object_filter + Objects/filters
     * legacy). Resuelve el CONJUNTO de IDs que pasan el filtro usando los MISMOS
     * scopes legacy (filterStatus/filterOnline/filterOffline vía ->filter(), igual
     * que DevicesSidebarController). El front lo aplica a la lista y/o al mapa
     * según filter_sidebar/filter_map — sin re-fetch de toda la lista.
     */
    public function filterIds(Request $request): JsonResponse
    {
        $filters = [];

        $status = array_values(array_filter(
            (array) $request->input('status', []),
            function ($s) { return $s !== '' && $s !== null; }
        ));
        if ($status) {
            $filters['status'] = $status;
        }
        if ($request->filled('online')) {
            $filters['online'] = (int) $request->input('online');
        }
        if ($request->filled('offline')) {
            $filters['offline'] = (int) $request->input('offline');
        }

        $ids = [];
        if ($filters) {
            $ids = $this->user->devices()
                ->filter($filters)
                ->pluck('devices.id')
                ->map(function ($id) { return (int) $id; })
                ->all();
        }

        return response()->json([
            'ok'       => true,
            'filtered' => (bool) $filters,
            'ids'      => $ids,
        ]);
    }

    /* =================================================================
     * Grupos de dispositivos (patrón espejo de los grupos de rutas v2)
     * -----------------------------------------------------------------
     * El grupo vive en user_device_pivot.group_id (POR USUARIO; 0 = sin
     * grupo). La "sincronización" es siempre un UPDATE del pivote
     * filtrado por user_id — nunca se insertan/borran filas (paridad con
     * DevicesGroupsController::syncDevices legacy). Gate: perm devices
     * edit (devices_groups no es módulo de permisos; solo ownership).
     * ================================================================= */

    public function storeDeviceGroup(Request $request): JsonResponse
    {
        // Gate legacy: 'devices_groups' está mapeado a DeviceGroup en el
        // Manager, así que resuelve por DeviceGroupPolicy (bloquea demo), no
        // por perm(). Antes se usaba perm('devices','edit') = gate distinto.
        $this->checkException('devices_groups', 'store');

        $title = trim((string) $request->get('title'));
        if ($title === '') {
            return response()->json(['ok' => false, 'errors' => ['title' => trans('validation.required', ['attribute' => trans('validation.attributes.title')])]], 422);
        }

        $group = \Tobuli\Entities\DeviceGroup::create([
            'title'   => $title,
            'user_id' => $this->user->id,
            'open'    => 1,
        ]);

        $this->syncGroupDevices($group, $request->get('devices'));
        $this->syncGroupForwards($group, $request->get('forwards'));

        return response()->json(['ok' => true, 'id' => (int) $group->id]);
    }

    public function updateDeviceGroup(Request $request, $id): JsonResponse
    {
        $group = \Tobuli\Entities\DeviceGroup::where('user_id', $this->user->id)->find((int) $id);
        if (! $group) {
            abort(404);
        }

        $this->checkException('devices_groups', 'edit', $group);

        $title = trim((string) $request->get('title'));
        if ($title === '') {
            return response()->json(['ok' => false, 'errors' => ['title' => trans('validation.required', ['attribute' => trans('validation.attributes.title')])]], 422);
        }

        $group->update(['title' => $title]);

        $this->syncGroupDevices($group, $request->get('devices'));
        $this->syncGroupForwards($group, $request->get('forwards'));

        return response()->json(['ok' => true]);
    }

    public function destroyDeviceGroup(Request $request, $id): JsonResponse
    {
        $group = \Tobuli\Entities\DeviceGroup::where('user_id', $this->user->id)->find((int) $id);
        if (! $group) {
            abort(404);
        }

        $this->checkException('devices_groups', 'remove', $group);

        // Paridad legacy: los devices vuelven a "Sin grupo" (0, no NULL)
        \DB::table('user_device_pivot')->where('group_id', $group->id)->update(['group_id' => 0]);
        $group->delete();

        return response()->json(['ok' => true]);
    }

    /** Ids del device del grupo (para precargar el modal de edición). */
    public function deviceGroupItems(Request $request, $id): JsonResponse
    {
        $group = \Tobuli\Entities\DeviceGroup::where('user_id', $this->user->id)->find((int) $id);
        if (! $group) {
            abort(404);
        }

        $ids = \DB::table('user_device_pivot')
            ->where('user_id', $this->user->id)
            ->where('group_id', $group->id)
            ->pluck('device_id')
            ->map(function ($v) {
                return (int) $v;
            })
            ->all();

        // Reenvíos asignados al grupo (pivote device_group_forward). Paridad
        // legacy: solo si el usuario tiene permiso 'forwards' view.
        $forwardIds = [];
        if ($this->user->perm('forwards', 'view')) {
            $forwardIds = $group->forwards()->pluck('forwards.id')
                ->map(function ($v) { return (int) $v; })
                ->all();
        }

        return response()->json([
            'ok'          => true,
            'title'       => (string) $group->title,
            'device_ids'  => $ids,
            'forward_ids' => $forwardIds,
        ]);
    }

    /** Lista de grupos del usuario con su conteo de vehículos (tab Grupos del modal de cuenta). */
    public function deviceGroupsList(Request $request): JsonResponse
    {
        $counts = \DB::table('user_device_pivot')
            ->where('user_id', $this->user->id)
            ->where('group_id', '>', 0)
            ->selectRaw('group_id, COUNT(*) as c')
            ->groupBy('group_id')
            ->pluck('c', 'group_id');

        $groups = \Tobuli\Entities\DeviceGroup::where('user_id', $this->user->id)
            ->orderBy('title')
            ->get(['id', 'title']);

        $rows = [];
        foreach ($groups as $g) {
            $rows[] = [
                'id'    => (int) $g->id,
                'title' => (string) $g->title,
                'count' => (int) ($counts[$g->id] ?? 0),
            ];
        }

        return response()->json(['ok' => true, 'items' => $rows]);
    }

    /* =================================================================
     * Estado de vehículo (tipo con estados: VACÍO/CARGADO…) — cambiador
     * rápido desde el drawer del device (botón/selector). El badge del
     * estado se pinta en la fila/marcador/drawer.
     * ================================================================= */

    /** Estados del TIPO del device + estado actual (para el selector). */
    public function vehicleStates(Request $request, $id): JsonResponse
    {
        $device = $this->user->devices()->find((int) $id);
        if (! $device) {
            abort(404);
        }

        $states   = [];
        $typeName = null;
        if ($device->vehicle_type_id && $device->vehicleType) {
            $typeName = (string) $device->vehicleType->name;
            $states = $device->vehicleType->states->map(function ($s) {
                return [
                    'id'    => (int) $s->id,
                    'name'  => (string) $s->name,
                    'label' => (string) ($s->label ?: $s->name),
                    'color' => (string) ($s->color ?: '#64748b'),
                ];
            })->all();
        }

        return response()->json([
            'ok'      => true,
            'type'    => $typeName,
            'states'  => $states,
            'current' => $device->vehicle_state_id ? (int) $device->vehicle_state_id : null,
        ]);
    }

    /** Cambia el estado (valida que pertenezca al tipo del device). */
    public function changeVehicleState(Request $request, $id): JsonResponse
    {
        if (! $this->user->perm('devices', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso.'], 403);
        }

        $device = $this->user->devices()->find((int) $id);
        if (! $device) {
            abort(404);
        }

        $stateId = (int) $request->get('vehicle_state_id');
        $vs      = null;

        if ($stateId > 0) {
            $vs = \Tobuli\Entities\VehicleState::where('id', $stateId)
                ->where('vehicle_type_id', $device->vehicle_type_id)
                ->first();
            if (! $vs) {
                return response()->json(['ok' => false, 'message' => 'Estado inválido para el tipo del vehículo.'], 422);
            }
        }

        $device->vehicle_state_id = $stateId > 0 ? $stateId : null;
        $device->save();

        return response()->json([
            'ok'            => true,
            'vehicle_state' => $vs ? [
                'name'  => (string) $vs->name,
                'label' => (string) ($vs->label ?: $vs->name),
                'color' => (string) ($vs->color ?: '#64748b'),
            ] : null,
        ]);
    }

    /** UPDATE del pivote (attach → group_id; detach → 0), solo filas del usuario. */
    private function syncGroupDevices(\Tobuli\Entities\DeviceGroup $group, $deviceIds): void
    {
        if (! is_array($deviceIds)) {
            return; // sin clave devices → no tocar asignaciones
        }

        $ids = array_values(array_filter(array_map('intval', $deviceIds)));

        \DB::table('user_device_pivot')
            ->where('user_id', $this->user->id)
            ->where('group_id', $group->id)
            ->when($ids, function ($q) use ($ids) {
                $q->whereNotIn('device_id', $ids);
            })
            ->update(['group_id' => 0]);

        if ($ids) {
            \DB::table('user_device_pivot')
                ->where('user_id', $this->user->id)
                ->whereIn('device_id', $ids)
                ->update(['group_id' => $group->id]);
        }
    }

    /**
     * Sincroniza los reenvíos del grupo (pivote device_group_forward).
     * Paridad legacy DevicesGroupsController: solo si el usuario tiene
     * permiso 'forwards' view; restringe a los Forward accesibles por el
     * usuario (seguridad — el sync no confía en los ids del cliente).
     */
    private function syncGroupForwards(\Tobuli\Entities\DeviceGroup $group, $forwardIds): void
    {
        if (! $this->user->perm('forwards', 'view')) {
            return;
        }

        $ids = array_values(array_filter(array_map('intval', (array) $forwardIds)));

        if ($ids) {
            $allowed = \Tobuli\Entities\Forward::userAccessible($this->user)
                ->pluck('id')
                ->map(function ($v) { return (int) $v; })
                ->all();
            $ids = array_values(array_intersect($ids, $allowed));
        }

        $group->forwards()->sync($ids);
    }

    /* =================================================================
     * Sensores del modal device (tab Sensores)
     * -----------------------------------------------------------------
     * CRUD delegado 100% en el SensorModalHelper legacy (createData /
     * editData / create / edit / destroy / preview): misma validación,
     * mismos permisos (checkException devices edit/update) y mismos
     * name= que el form legacy. Este bloque solo traduce HTTP ↔ helper.
     * ================================================================= */

    /**
     * Resuelve el device y verifica permiso de edición (el helper legacy
     * no valida ownership en create/list, así que lo hacemos aquí).
     */
    private function sensorDevice(int $deviceId)
    {
        $device = DeviceRepo::find($deviceId);
        if (! $device) {
            abort(404);
        }

        $this->checkException('devices', 'edit', $device);

        return $device;
    }

    private function sensorHelper(array $data): \ModalHelpers\SensorModalHelper
    {
        $helper = app(\ModalHelpers\SensorModalHelper::class);
        $helper->setUser($this->user);
        $helper->setData($data);
        $helper->setApi(false);

        return $helper;
    }

    /**
     * Lista de sensores del device (re-hidrata la tabla del tab).
     */
    /**
     * Catálogo de iconos de sensor para el picker del mapeo de valores.
     * GET sensors/icons  — espeja SensorIconController::getBaseQuery():
     * los del sistema (user_id null) + los del propio usuario.
     */
    public function sensorIcons(Request $request): JsonResponse
    {
        $rows = \Tobuli\Entities\SensorIcon::where(function ($q) {
                $q->whereNull('user_id')->orWhere('user_id', $this->user->id);
            })
            ->orderBy('order', 'DESC')
            ->orderBy('id', 'ASC')
            ->get();

        return response()->json([
            'ok'    => true,
            'items' => $rows->map(function ($i) {
                return ['id' => (int) $i->id, 'url' => asset($i->path)];
            })->all(),
        ]);
    }

    /**
     * URL del SVG del tipo de sensor, o null si el tema no trae ese icono.
     * Espeja DeviceSensor::getIconAsset() pero sin devolver rutas rotas.
     */
    private function sensorTypeIcon(\Tobuli\Entities\DeviceSensor $sensor, $value): ?string
    {
        try {
            $url  = $sensor->getIconAsset($value);
            $file = public_path('assets/icons/' . basename((string) parse_url($url, PHP_URL_PATH)));

            return is_file($file) ? $url : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function sensorsList(Request $request, $id): JsonResponse
    {
        $this->sensorDevice((int) $id);

        $rows = \Tobuli\Entities\DeviceSensor::where('device_id', (int) $id)
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($s) {
                return ['id' => (int) $s->id, 'name' => (string) $s->name, 'type' => (string) $s->type_title];
            })
            ->all();

        return response()->json(['ok' => true, 'items' => $rows]);
    }

    /**
     * HTML del form de sensor (crear). GET devices/{id}/sensors/form
     */
    public function sensorFormCreate(Request $request, $id): JsonResponse
    {
        $this->sensorDevice((int) $id);

        $data = $this->sensorHelper([])->createData((int) $id);
        $data['item'] = null;

        return response()->json([
            'ok'   => true,
            'html' => View::make('ui.v2.modals.devices.sensor-form', $data)->render(),
        ]);
    }

    /**
     * HTML del form de sensor (editar). GET sensors/{id}/form
     * El helper resuelve el device desde el sensor y valida el permiso.
     */
    public function sensorFormEdit(Request $request, $id): JsonResponse
    {
        $data = $this->sensorHelper(['sensor_id' => (int) $id])->editData();

        return response()->json([
            'ok'   => true,
            'html' => View::make('ui.v2.modals.devices.sensor-form', $data)->render(),
        ]);
    }

    /**
     * Crear sensor. POST devices/{id}/sensors
     */
    public function sensorStore(Request $request, $id): JsonResponse
    {
        $this->sensorDevice((int) $id);

        try {
            $data = $request->all();
            $data['device_id'] = (int) $id;

            $this->sensorHelper($data)->create();

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /**
     * Actualizar sensor. PUT sensors/{id}
     */
    public function sensorUpdate(Request $request, $id): JsonResponse
    {
        try {
            $data = $request->all();
            $data['sensor_id'] = (int) $id;

            $this->sensorHelper($data)->edit();

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /**
     * Eliminar sensor. DELETE sensors/{id}
     */
    public function sensorDestroy(Request $request, $id): JsonResponse
    {
        $this->sensorHelper(['sensor_id' => (int) $id])->destroy();

        return response()->json(['ok' => true]);
    }

    /**
     * Vista previa del valor del sensor con un input de prueba.
     * POST sensors/preview
     */
    public function sensorPreview(Request $request): JsonResponse
    {
        try {
            $res = $this->sensorHelper($request->all())->preview();

            return response()->json([
                'ok'        => true,
                'value'     => $res['value'] ?? null,
                'formatted' => $res['formatted'] ?? null,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /**
     * Importa calibraciones desde CSV (paridad con
     * SensorCalibrationsImportController del legacy). Devuelve los pares
     * x/y para que el JS los pinte en la tabla del panel; no toca la BD:
     * el sensor se guarda despues con el resto del formulario.
     */
    public function sensorCalibrationsImport(Request $request): JsonResponse
    {
        try {
            // Sin modelo: checkException('devices','edit') resolveria la policy
            // y exigiria un device concreto. Aqui solo se parsea un CSV (el
            // legacy ni siquiera comprueba permisos), asi que basta el permiso
            // de modulo; el sensor se guarda despues por su propia ruta.
            if (! $this->user->perm('devices', 'edit')) {
                return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
            }

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'file' => 'required|file',
            ]);

            if ($validator->fails()) {
                return response()->json(['ok' => false, 'errors' => $validator->errors()->toArray()], 422);
            }

            $manager = app(\Tobuli\Importers\DeviceSensorCalibrations\DeviceSensorCalibrationsImportManager::class);
            $rows    = $manager->import($request->file('file'));

            $items = [];
            foreach ((array) $rows as $row) {
                $x = is_array($row) ? ($row['x'] ?? null) : ($row->x ?? null);
                $y = is_array($row) ? ($row['y'] ?? null) : ($row->y ?? null);

                if ($x === null || $y === null || ! is_numeric($x) || ! is_numeric($y)) {
                    continue;
                }

                $items[] = ['x' => (float) $x, 'y' => (float) $y];
            }

            return response()->json([
                'ok'      => true,
                'items'   => $items,
                'append'  => (bool) $request->input('append'),
                'skipped' => count((array) $rows) - count($items),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // El Importer valida fila a fila y lanza la excepcion de Laravel,
            // no la de Tobuli: sin este catch un CSV con una fila mala salia
            // como 500 en vez de como error de validacion.
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            return response()->json(
                ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
                $this->statusFor($e)
            );
        }
    }

    /* =================================================================
     * Servicios / Cámaras / Gastos del modal device
     * ================================================================= */

    private function presentDeviceServices(int $deviceId): array
    {
        return \Tobuli\Entities\DeviceService::where('device_id', $deviceId)
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($s) {
                $expiration = '';
                try {
                    $expiration = (string) $s->expiration();
                } catch (\Throwable $e) {
                }

                return ['id' => (int) $s->id, 'name' => (string) $s->name, 'expiration' => $expiration];
            })
            ->all();
    }

    private function presentDeviceCameras(int $deviceId): array
    {
        return \Tobuli\Entities\DeviceCamera::where('device_id', $deviceId)
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($c) {
                return [
                    'id'           => (int) $c->id,
                    'name'         => (string) $c->name,
                    'show_widget'  => (bool) $c->show_widget,
                    'ftp_username' => (string) ($c->ftp_username ?? ''),
                ];
            })
            ->all();
    }

    private function presentDeviceExpenses(int $deviceId): array
    {
        return \Tobuli\Entities\DeviceExpense::where('device_id', $deviceId)
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($e) {
                return [
                    'id'        => (int) $e->id,
                    'name'      => (string) $e->name,
                    'type_id'   => (int) $e->type_id,
                    'quantity'  => (float) $e->quantity,
                    'unit_cost' => (float) $e->unit_cost,
                    'total'     => (float) $e->total,
                    'date'      => (string) $e->date,
                    'supplier'  => (string) ($e->supplier ?? ''),
                    'buyer'     => (string) ($e->buyer ?? ''),
                    'additional' => (string) ($e->additional ?? ''),
                ];
            })
            ->all();
    }

    /** Lista de cámaras del device. GET devices/{id}/cameras */
    public function camerasList(Request $request, $id): JsonResponse
    {
        $this->cameraDevice((int) $id);

        return response()->json(['ok' => true, 'items' => $this->presentDeviceCameras((int) $id)]);
    }

    private function cameraDevice(int $deviceId)
    {
        if (! $this->user->perm('device_camera', 'view')) {
            abort(403);
        }

        $device = DeviceRepo::find($deviceId);
        if (! $device) {
            abort(404);
        }

        $this->checkException('devices', 'edit', $device);

        return $device;
    }

    /** Crear cámara. POST devices/{id}/cameras */
    public function cameraStore(Request $request, $id): JsonResponse
    {
        $this->cameraDevice((int) $id);

        if (! $this->user->perm('device_camera', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso.'], 403);
        }

        $name = trim((string) $request->get('name'));
        if ($name === '') {
            return response()->json(['ok' => false, 'errors' => ['name' => trans('validation.required', ['attribute' => trans('validation.attributes.name')])]], 422);
        }

        \Tobuli\Entities\DeviceCamera::create([
            'device_id'   => (int) $id,
            'name'        => $name,
            'show_widget' => (int) (bool) $request->get('show_widget'),
        ]);

        return response()->json(['ok' => true]);
    }

    /** Actualizar cámara. PUT cameras/{id} */
    public function cameraUpdate(Request $request, $id): JsonResponse
    {
        $camera = \Tobuli\Entities\DeviceCamera::find((int) $id);
        if (! $camera) {
            abort(404);
        }

        $this->cameraDevice((int) $camera->device_id);

        if (! $this->user->perm('device_camera', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso.'], 403);
        }

        $name = trim((string) $request->get('name'));
        if ($name === '') {
            return response()->json(['ok' => false, 'errors' => ['name' => trans('validation.required', ['attribute' => trans('validation.attributes.name')])]], 422);
        }

        $camera->update([
            'name'        => $name,
            'show_widget' => (int) (bool) $request->get('show_widget'),
        ]);

        return response()->json(['ok' => true]);
    }

    /** Eliminar cámara. DELETE cameras/{id} */
    public function cameraDestroy(Request $request, $id): JsonResponse
    {
        $camera = \Tobuli\Entities\DeviceCamera::find((int) $id);
        if (! $camera) {
            abort(404);
        }

        $this->cameraDevice((int) $camera->device_id);

        if (! $this->user->perm('device_camera', 'remove') && ! $this->user->perm('device_camera', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso.'], 403);
        }

        $camera->delete();

        return response()->json(['ok' => true]);
    }

    /** Lista de gastos del device. GET devices/{id}/expenses */
    public function expensesList(Request $request, $id): JsonResponse
    {
        $this->expensesGate('view');
        $this->sensorDevice((int) $id);

        return response()->json(['ok' => true, 'items' => $this->presentDeviceExpenses((int) $id)]);
    }

    /** Gate del módulo de gastos (paridad legacy DeviceExpensesController:
     *  checkException('device_expenses', …) además del acceso al device). */
    private function expensesGate(string $action): void
    {
        if (! $this->user->perm('device_expenses', $action)) {
            abort(403);
        }
    }

    private function expenseInput(Request $request, string $scenario = 'create', ?int $deviceId = null): array
    {
        $data = $request->only(['name', 'type_id', 'quantity', 'unit_cost', 'supplier', 'buyer', 'additional', 'date']);

        // Validador legacy en vez de reglas propias: así el tipo de gasto se
        // comprueba contra device_expense_types (antes solo se exigía que fuera
        // un entero, y un id inexistente pasaba).
        if ($deviceId !== null) {
            $data['device_id'] = $deviceId;
        }

        \CustomFacades\Validators\DeviceExpensesFormValidator::validate($scenario, $data);

        // datetime-local llega como "YYYY-MM-DDTHH:mm" → formato MySQL
        $data['date'] = date('Y-m-d H:i:s', strtotime($data['date']));

        return $data;
    }

    /** Crear gasto. POST devices/{id}/expenses */
    public function expenseStore(Request $request, $id): JsonResponse
    {
        $this->expensesGate('edit');
        $this->sensorDevice((int) $id);

        try {
            $data = $this->expenseInput($request, 'create', (int) $id);
            $data['device_id'] = (int) $id;
            $data['user_id'] = (int) $this->user->id;

            \Tobuli\Entities\DeviceExpense::create($data);

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /** Actualizar gasto. PUT devices/expenses/{id} */
    public function expenseUpdate(Request $request, $id): JsonResponse
    {
        $this->expensesGate('edit');

        $expense = \Tobuli\Entities\DeviceExpense::find((int) $id);
        if (! $expense) {
            abort(404);
        }

        $this->sensorDevice((int) $expense->device_id);

        try {
            $expense->update($this->expenseInput($request, 'update'));

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /** Eliminar gasto. DELETE devices/expenses/{id} */
    public function expenseDestroy(Request $request, $id): JsonResponse
    {
        $this->expensesGate('remove');

        $expense = \Tobuli\Entities\DeviceExpense::find((int) $id);
        if (! $expense) {
            abort(404);
        }

        $this->sensorDevice((int) $expense->device_id);

        $expense->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Lightweight presentation of a device for refreshing UI rows
     * after a create / update without re-fetching the whole list.
     */
    protected function presentDevice(int $id): array
    {
        $device = DeviceRepo::find($id);
        if (! $device) {
            return [];
        }
        $st = $device->getStatus();
        return [
            'id'           => (int) $device->id,
            'name'         => (string) $device->name,
            'imei'         => (string) $device->imei,
            'plate'        => (string) $device->plate_number,
            'model'        => (string) $device->device_model,
            'sim'          => (string) $device->sim_number,
            'icon_color'   => $device->getStatusColor($st),
            'status'       => $st,
            'status_group' => $this->groupStatus($st),
            'active'       => (bool) $device->active,
        ];
    }

    /**
     * Device detail widgets for the Live drawer.
     *
     * Server-renders the new ui/v2 widget cards reusing legacy data
     * sources (GeoLocation, events, cameras, services, driver, sensors)
     * and honoring the user's active-widget configuration
     * (getSettings('widgets'), same as the legacy bottombar).
     */
    public function widgets(Request $request, $id): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $device = DeviceRepo::find((int) $id);
        if (! $device) {
            return response()->json(['ok' => false, 'message' => 'Vehículo no encontrado.'], 404);
        }

        // Active widgets per user config (fallback to global settings) — mirrors Widgets/index.blade.
        $cfg = $this->user->getSettings('widgets');
        if (empty($cfg) || ! empty($cfg['default'])) {
            $cfg = settings('widgets');
        }
        $active = (! empty($cfg['list']) && is_array($cfg['list'])) ? array_values($cfg['list']) : [];

        $has = function ($key) use ($active) {
            return in_array($key, $active, true) && config("lists.widgets.$key");
        };

        $data = ['device' => $device, 'active' => $active];

        // Dirección: geocode compartido por los widgets Ubicación y Objeto
        // (evita pedir la dirección dos veces al mismo proveedor).
        $address = null;
        if (($has('location') || $has('device')) && $device->lat && $device->lng) {
            try {
                $loc = GeoLocation::byCoordinates($device->lat, $device->lng);
                $address = $loc ? $loc->toArray() : null;
            } catch (\Throwable $e) {
                $address = null;
            }
        }

        if ($has('location')) {
            $data['location'] = $address;
        }

        // Objeto: ficha UNIFICADA del vehículo (fusiona la antigua "Telemetría" del
        // drawer con "Objeto"). La duración de parada vive en la KPI "Detenido".
        if ($has('device')) {
            $engineStatus = $device->getEngineStatus();
            $engineText = ($engineStatus === true || $engineStatus === 'on')
                ? 'Encendido'
                : ($engineStatus !== null ? 'Apagado' : null);

            $data['device_info'] = [
                'status'      => $device->getStatus(),
                'status_text' => trans('front.' . $device->getStatus()),
                'address'     => $address['address'] ?? null,
                'time'        => $device->time ? \Formatter::time()->format($device->time) : null,
                'coords'      => ($device->lat && $device->lng)
                    ? number_format((float) $device->lat, 5) . ', ' . number_format((float) $device->lng, 5)
                    : null,
                'altitude'    => ($device->altitude !== null && $device->altitude !== '')
                    ? round((float) $device->altitude) . ' m'
                    : null,
                'engine'      => $engineText,
                'model'       => $device->device_model,
                'imei'        => $device->imei,
                'sim'         => $device->sim_number,
                'is_admin'    => (bool) $this->user->isAdmin(),
                'driver'      => $device->driver->name ?? null,
            ];
        }

        // Combustible: serie temporal (últimas 24 h) de los sensores fuel_tank.
        // Reutiliza el MISMO pipeline que el legacy (DeviceWidgetsController::
        // fuelGraph → DeviceHistory + SensorsValues): setSensors + get() y
        // getSensorsData() devuelve [id => {id,name,unit,values:{ts:{t,v}}}].
        if ($has('fuel_graph')) {
            $fuel = [];
            try {
                $fuelSensors = $device->sensors->filter(function ($s) {
                    return $s->type === 'fuel_tank';
                });
                if ($fuelSensors->isNotEmpty()) {
                    $history = new \Tobuli\History\DeviceHistory($device);
                    $history->setSensors($fuelSensors);
                    $history->setRange(\Carbon\Carbon::now()->subDay(1), \Carbon\Carbon::now());
                    $history->registerActions([]);
                    $history->get();

                    $porId = $fuelSensors->keyBy('id');

                    foreach ((array) $history->getSensorsData() as $s) {
                        $values = array_values($s['values'] ?? []);
                        $last = ! empty($values) ? end($values) : null;

                        // Capacidad del tanque (full_tank, o el tope de la
                        // curva de calibracion). Va al front para que la
                        // grafica se dibuje contra el tanque y no contra el
                        // minimo y maximo de las ultimas 24 h: sin esto, bajar
                        // del 62% al 61% se pinta como un tanque vaciandose.
                        $sensor = $porId->get($s['id'] ?? null);
                        $max = $sensor ? $sensor->getMaxTankValue() : null;

                        $fuel[] = [
                            'name'   => $s['name'] ?? '',
                            'unit'   => $s['unit'] ?? '',
                            'values' => $values,                       // [{t,v}]
                            'last'   => $last ? $last['v'] : null,
                            'max'    => is_numeric($max) ? (float) $max : null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $fuel = [];
            }
            $data['fuel'] = $fuel;
        }

        // Comando GPRS: solo se necesita el flag; el card abre el modal de
        // envío de comando (tp-commands) para este device.
        if ($has('gprs_command') && $this->user->perm('send_command', 'view')) {
            $data['gprs_command'] = true;
        }

        if ($has('sensors')) {
            // Mismo cálculo que el legacy (DeviceSensorsTransformer): valor actual
            // desde la última posición (traccar), con fallback al valor almacenado.
            $rows = [];
            $traccar = $device->traccar;
            foreach ($device->sensors as $sensor) {
                $formatted = '-';
                $icon = null;
                try {
                    if ($traccar) {
                        $value = $sensor->getValueCurrent($traccar);
                        $formatted = $value->getFormatted();
                        // Paridad legacy (DeviceMapFullTransformer::formatSensors +
                        // device.js dataSensorsDOM): 1) icono personalizado del mapeo
                        // de valores (admin → sensor_icons), 2) si no hay, el SVG del
                        // tipo de sensor (con escalón para battery/gsm).
                        // Solo el icono PERSONALIZADO llega como imagen; el del
                        // tipo lo pinta el partial sensor-icon (SVG de trazo).
                        $custom = $value->getIcon();
                        $icon = $custom ? asset($custom->path) : null;
                    }
                } catch (\Throwable $e) {
                    $formatted = '-';
                }
                $rows[] = [
                    'name'     => $sensor->formatName(),
                    'formated' => $formatted,
                    'type'        => (string) $sensor->type,
                    'custom_icon' => $icon,
                ];
            }
            $data['sensors'] = $rows;
        }

        if ($has('image')) {
            // Un ARRAY, no la url suelta: con `null` el @isset del blade era
            // falso y el widget no se pintaba nunca sin foto (ni el aviso
            // "Sin imagen" ni, ahora, el boton de subir).
            $data['image'] = [
                'url' => $device->image ? url($device->image) : null,
                // El legacy ofrece subir/borrar sobre el widget si puede
                // editar el objeto (Widgets/image.blade).
                'can_edit' => (bool) $this->user->can('edit', $device),
                'store'    => url('/ui/v2/api/devices/' . $device->id . '/image'),
                'destroy'  => url('/ui/v2/api/devices/' . $device->id . '/image'),
            ];
        }

        // Widget de plantillas webhook. MISMO gate que el legacy: sin
        // addon.widget_template el permiso ni existe (PermissionService lo
        // quita) y el widget no se ofrece.
        if ($has('template_webhook')
            && config('addon.widget_template')
            && $this->user->perm('widget_template_webhook', 'view')
        ) {
            $templates = [];
            try {
                // La tabla `templates` la trae el addon; si no esta, no hay
                // plantillas y el widget se queda vacio en vez de reventar.
                if (\Illuminate\Support\Facades\Schema::hasTable('templates')) {
                    $templates = \DB::table('templates')
                        ->where('device_id', $device->id)
                        ->pluck('template_name', 'template_id')
                        ->all();
                }
            } catch (\Throwable $e) {
                $templates = [];
            }

            $data['template_webhook'] = [
                'templates' => $templates,
                'send_url'  => url('/ui/v2/api/devices/' . $device->id . '/template-webhook'),
            ];
        }

        if ($has('services')) {
            try {
                $data['services'] = $device->services()->get();
            } catch (\Throwable $e) {
                $data['services'] = collect();
            }
        }

        if ($has('recent_events') && $this->user->perm('events', 'view')) {
            try {
                $data['recent_events'] = $device->events()
                    ->where('user_id', $this->user->id)
                    // Con el plugin activo, Event::getTitleAttribute() lee
                    // $event->alert->name: eager-load para evitar N+1
                    // (idéntico a DeviceWidgetsController legacy).
                    ->when(settings('plugins.event_section_alert.status'), function ($query) {
                        $query->with('alert');
                    })
                    ->latest()->limit(10)->get();
            } catch (\Throwable $e) {
                $data['recent_events'] = collect();
            }
        }

        if ($has('streetview') && $device->lat && $device->lng) {
            $svApi     = settings('main_settings.streetview_api');
            $svLat     = $device->lat;
            $svLng     = $device->lng;
            $svHeading = (int) round($device->course ?? 0);

            if ($svApi) {
                // Imagen estática (proxy streetview.jpg → StreetviewService): fallback si
                // no aplica el embed. Aplica la key server-side y devuelve un placeholder.
                $data['streetview'] = route('streetview', [
                    'location' => $svLat . ',' . $svLng,
                    'size'     => '600x240',
                    'heading'  => $svHeading,
                ]);

                // Embed INTERACTIVO (paridad legacy street_view_frame): a diferencia de la
                // Static API (que exige panorámica en el punto EXACTO y devuelve "no imagery"
                // en zonas rurales), el embed encuentra la panorámica más cercana. Es el que
                // el legacy carga en el iframe (convertStreetViewUrl → maps/embed?pb=...).
                if (config('addon.street_view_frame')) {
                    $data['streetview_frame'] = 'https://www.google.com/maps/embed?pb=!4v' . time()
                        . '!6m8!1m7!!2m2!1d' . $svLat . '!2d' . $svLng
                        . '!3f' . $svHeading . '!4f0!5f0';
                }
            } else {
                // Sin proveedor configurado (Ajustes del servidor → Street View API).
                $data['streetview'] = null;
            }
        }

        if ($has('camera')) {
            try {
                $images = [];
                foreach ($device->deviceCameras()->showWidget()->get() as $camera) {
                    $img = DeviceCameraMedia::setEntity($camera)->findLatest();
                    if ($img) {
                        $images[$camera->id] = ['camera_name' => $camera->name, 'image' => $img];
                    }
                }
                $data['camera_images']       = $images;
                $data['camera_device_image'] = DeviceCameraMedia::setEntity($device)->setRecursiveSearch(false)->findLatest();
            } catch (\Throwable $e) {
                $data['camera_images']       = [];
                $data['camera_device_image'] = null;
            }
        }

        if ($has('driver')) {
            $data['driver'] = $device->driver; // hasOne UserDriver (current_driver_id)
        }

        // El widget SOLO en vehiculos con video: protocolo compatible y
        // canales configurados. Antes bastaba con tener el widget activado y
        // salia en toda la flota, incluidos 1.429 equipos sin una sola camara.
        if ($has('live_video') && (new \Tobuli\Services\DeviceVideoService())->isEnabled($device)) {
            $data['video_live_url']     = url('/device_video/' . $device->id . '/live/modal');
            $data['video_playback_url'] = url('/device_video/' . $device->id . '/playback/modal');
        }

        // Widget de bloqueo de motor (plugin locking_widget). Gateado por el plugin
        // (no por la config de widgets): al activarlo aparece por device. Lee el
        // estado del parámetro de la última posición (igual que LockStatusController
        // legacy) y expone los comandos preconfigurados para Cortar/Restablecer.
        if (settings('plugins.locking_widget.status')) {
            $lockStatus = null; // null = desconocido
            // 1) Fuente preferida: el sensor "blocked" del device (misma que el candado
            //    de la lista). Así se configura UNA vez en el sensor y sirve para todo.
            if (\Tobuli\Sensors\Types\Blocked::isEnabled() && ($bs = $device->getSensorByType('blocked'))) {
                $lockStatus = (bool) $bs->getValueCurrent($device)->getValue();
            } else {
                // 2) Fallback: parámetro crudo configurado en el plugin (parameter/value_on/off).
                try {
                    $lastPos = $device->positionTraccar();
                    $params  = $lastPos ? $lastPos->parameters : [];
                    $p    = settings('plugins.locking_widget.options.parameter');
                    $vOn  = settings('plugins.locking_widget.options.value_on');
                    $vOff = settings('plugins.locking_widget.options.value_off');
                    if (isset($params[$p]) && (string) $params[$p] === (string) $vOn) {
                        $lockStatus = true;
                    } elseif (isset($params[$p]) && (string) $params[$p] === (string) $vOff) {
                        $lockStatus = false;
                    }
                } catch (\Throwable $e) {
                    $lockStatus = null;
                }
            }

            $data['locking'] = [
                'status'        => $lockStatus, // true=bloqueado, false=libre, null=desconocido
                'can_sms'       => $this->user->canSendSMS() && ! empty($device->sim_number),
                'command_url'   => route('ui.v2.api.devices.lock_command', $device->id),
                'history_url'   => route('ui.v2.api.devices.lock_history', $device->id),
                // Comandos desde las Plantillas de GPRS adaptadas al equipo (por
                // protocolo/tipo): diferentes modelos → distinto mensaje.
                'templates_url' => route('ui.v2.api.devices.lock_templates', $device->id),
            ];
        }

        $html = View::make('ui.v2.partials.device-widgets', $data)->render();

        return response()->json(['ok' => true, 'id' => (int) $device->id, 'html' => $html]);
    }

    // NOTA: el menú de acciones del kebab ya NO usa AJAX. Es un TEMPLATE
    // estático de sesión (ui.v2.partials.device-actions dentro de
    // <template> en live.blade): los permisos se resuelven al renderizar la
    // página y los flags por device (vt/chat/sim/plans/app_uuid/general/
    // video) viajan en el payload de liveData; live.js clona y filtra.

    /**
     * Incremental device updates (delta) — espeja el legacy objects/items_json.
     * Reutiliza DeviceModalHelper::itemsJson(): devuelve SOLO los dispositivos
     * que cambiaron desde ?time= (vía ->updatedAfter) + eventos nuevos + el
     * nuevo `time` del servidor. El JS hace polling y fusiona los deltas en el
     * mapa en vivo (posición, estado, engine, stop_duration, total_distance…).
     */
    public function updates(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['items' => [], 'events' => [], 'time' => time()], 403);
        }

        $helper = app(DeviceModalHelper::class);
        $helper->setUser($this->user);
        $helper->setApi(false);
        $helper->setData([
            'time'    => $request->get('time'),
            'filters' => $request->get('filters'),
        ]);

        $payload = $helper->itemsJson();

        // Desde 3.7.13 itemsJson() pagina por cursor (1000 por pagina) para
        // aliviar la carga del mapa legacy. La v2 espera la lista plana, asi
        // que aqui se recorren todas las paginas en el servidor y se conserva
        // el contrato {items: [...], events, time, version} que consume live.js.
        if (isset($payload['items']['data'])) {
            $result = $payload;
            $items = $payload['items']['data'];

            $guard = 0;
            while (($next = $payload['items']['pagination']['next_page_url'] ?? null) && $guard < 50) {
                parse_str(parse_url($next, PHP_URL_QUERY) ?: '', $params);

                if (empty($params['cursor'])) {
                    break;
                }

                request()->merge(['cursor' => $params['cursor']]);
                $helper->setData([
                    'time'    => $request->get('time'),
                    'filters' => $request->get('filters'),
                    'cursor'  => $params['cursor'],
                ]);

                $payload = $helper->itemsJson();
                $items = array_merge($items, $payload['items']['data'] ?? []);
                $guard++;
            }

            $result['items'] = $items;

            return response()->json($result);
        }

        return response()->json($payload);
    }

    /**
     * Widget de bloqueo (v2): envía el comando Cortar/Restablecer por GPRS o SMS,
     * reutilizando SendCommandService (misma tubería que el LockStatus legacy).
     */
    public function lockCommand(Request $request, $id): JsonResponse
    {
        if (! settings('plugins.locking_widget.status')) {
            return response()->json(['ok' => false, 'message' => 'Función no habilitada.'], 403);
        }
        $device = DeviceRepo::find((int) $id);
        if (! $device) {
            return response()->json(['ok' => false, 'message' => 'Vehículo no encontrado.'], 404);
        }
        $this->checkException('devices', 'show', $device);

        $type    = $request->input('type', 'gprs');
        $message = trim((string) $request->input('message', ''));
        if ($message === '') {
            return response()->json(['ok' => false, 'message' => 'El comando está vacío.'], 422);
        }

        $svc = app(\Tobuli\Services\Commands\SendCommandService::class);
        try {
            if ($type === 'sms') {
                if (! $this->user->canSendSMS() || empty($device->sim_number)) {
                    return response()->json(['ok' => false, 'message' => 'SMS no disponible para este vehículo.'], 422);
                }
                $result = $svc->sms($device, ['type' => 'custom', 'message' => $message], $this->user);
            } else {
                $result = $svc->gprs($device, ['type' => 'custom', 'data' => $message], $this->user);
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }

        $resp = is_array($result) ? ($result[0] ?? []) : [];
        if (! empty($resp['error'])) {
            return response()->json(['ok' => false, 'message' => $resp['error']], 422);
        }

        return response()->json(['ok' => true, 'message' => trans('front.command_sent')]);
    }

    /**
     * Plantillas de GPRS del usuario ADAPTADAS a este device (por protocolo /
     * tipo / dispositivo, vía isAdaptedFromDevice). El widget de bloqueo las
     * ofrece para Cortar/Restablecer — así cada modelo usa su propio comando.
     */
    public function lockTemplates(Request $request, $id): JsonResponse
    {
        $device = DeviceRepo::find((int) $id);
        if (! $device) {
            return response()->json(['ok' => false], 404);
        }
        $this->checkException('devices', 'show', $device);

        // Plantillas GPRS del usuario + las comunes (admin), adaptadas al equipo.
        $items = \Tobuli\Entities\UserGprsTemplate::userAccessible($this->user)
            ->get()
            ->filter(function ($t) use ($device) {
                return $t->isAdaptedFromDevice($device);
            })
            ->map(function ($t) {
                return [
                    'id'          => (int) $t->id,
                    'title'       => (string) $t->title,
                    'message'     => (string) $t->message,
                    'lock_action' => (string) ($t->lock_action ?? ''),
                ];
            })
            ->values()
            ->all();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /** Historial de bloqueo del device (periodos), paridad LockStatusController legacy. */
    public function lockHistory(Request $request, $id): JsonResponse
    {
        $device = DeviceRepo::find((int) $id);
        if (! $device) {
            return response()->json(['ok' => false], 404);
        }
        $this->checkException('devices', 'show', $device);

        $period = getPeriodByPhrase($request->get('period', 'today'));

        $history = new \Tobuli\History\DeviceHistory($device);
        $history->registerActions([
            \Tobuli\History\Actions\AppendLockStatus::class,
            \Tobuli\History\Actions\Duration::class,
            \Tobuli\History\Actions\GroupLockOffStatus::class,
        ]);
        $history->setRange($period['start'], $period['end']);

        $result = $history->get();
        $groups = isset($result['groups']) ? ($result['groups']->all() ?? []) : [];

        $rows = array_map(function ($g) {
            return [
                'time'     => $g->getStartAt(),
                'duration' => $g->stats()->human('duration'),
                'lat'      => $g->getStartPosition()->latitude,
                'lng'      => $g->getStartPosition()->longitude,
            ];
        }, $groups);

        return response()->json(['ok' => true, 'items' => $rows]);
    }
}
