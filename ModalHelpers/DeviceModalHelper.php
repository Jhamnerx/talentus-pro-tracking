<?php

namespace ModalHelpers;

use App\Exceptions\DeviceLimitException;
use App\Exceptions\PermissionException;
use App\Services\TalentusDeviceSyncService;
use App\Transformers\ApiV1\DeviceFullJsonTransformer;
use App\Transformers\ApiV1\DeviceFullTransformer;
use App\Transformers\Device\DeviceMapFullTransformer;
use App\Transformers\Device\DeviceMapTransformer;
use App\Transformers\Event\EventLatestTransformer;
use Carbon\Carbon;
use CustomFacades\ModalHelpers\SensorModalHelper;
use CustomFacades\ModalHelpers\ServiceModalHelper;
use CustomFacades\Repositories\DeviceCameraRepo;
use CustomFacades\Repositories\DeviceGroupRepo;
use CustomFacades\Repositories\DeviceIconRepo;
use CustomFacades\Repositories\DeviceRepo;
use CustomFacades\Repositories\DeviceSensorRepo;
use CustomFacades\Repositories\SensorGroupRepo;
use CustomFacades\Repositories\TimezoneRepo;
use CustomFacades\Repositories\UserRepo;
use CustomFacades\Validators\DeviceConfiguratorFormValidator;
use CustomFacades\Validators\DeviceFormValidator;
use Formatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tobuli\Entities\ApnConfig;
use Tobuli\Entities\Device;
use Tobuli\Entities\DeviceConfig;
use Tobuli\Entities\DeviceIcon;
use Tobuli\Entities\DeviceMaintenance;
use Tobuli\Entities\DeviceModel;
use Tobuli\Entities\DeviceType;
use Tobuli\Entities\Event;
use Tobuli\Entities\UnregisteredDevice;
use Tobuli\Entities\User;
use Tobuli\Entities\VehicleState;
use Tobuli\Entities\VehicleType;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Helpers\SMS\SMSGatewayManager;
use Tobuli\Services\AlertSoundService;
use Tobuli\Services\CustomValuesService;
use Tobuli\Services\DeviceConfigService;
use Tobuli\Services\DeviceSensorsService;
use Tobuli\Services\DeviceService;
use Tobuli\Services\DeviceUsersService;
use Tobuli\Services\EntityLoader\UsersLoader;
use Tobuli\Services\FractalSerializers\WithoutDataArraySerializer;
use Tobuli\Services\FractalTransformerService;

class DeviceModalHelper extends ModalHelper
{
    private $device_fuel_measurements = [];
    private $configService;
    private $customValueService;
    private $deviceService;

    /**
     * @var DeviceUsersService
     */
    private $deviceUsersService;

    public $talentusSyncService;

    /**
     * @var FractalTransformerService
     */
    private $transformerService;

    /**
     * @var UsersLoader
     */
    private $usersLoader;

    public function __construct(
        DeviceConfigService $configService,
        DeviceService $deviceService,
        CustomValuesService $customValueService,
        FractalTransformerService $transformerService
    ) {
        parent::__construct();

        $this->device_fuel_measurements = [
            [
                'id' => 1,
                'title' => trans('front.l_km'),
                'fuel_title' => trans('front.liters'),
                'distance_title' => '100 ' . strtolower(trans('front.kilometers')),
                'cost_title' => strtolower(trans('front.liter')),
            ],
            [
                'id' => 2,
                'title' => trans('front.mpg'),
                'fuel_title' => trans('front.miles'),
                'distance_title' => strtolower(trans('front.gallon')),
                'cost_title' => strtolower(trans('front.gallon')),
            ],
            [
                'id' => 3,
                'title' => trans('front.kwh_km'),
                'fuel_title' => trans('front.kwhs'),
                'distance_title' => strtolower(trans('front.kilometer')),
                'cost_title' => strtolower(trans(trans('front.kwh'))),
            ],
            [
                'id' => 4,
                'title' => trans('front.l_h'),
                'fuel_title' => trans('front.liters'),
                'distance_title' => strtolower(trans('front.hour')),
                'cost_title' => strtolower(trans('front.liter')),
            ],
            [
                'id' => 5,
                'title' => trans('front.km_l'),
                'fuel_title' => trans('front.kilometers'),
                'distance_title' => strtolower(trans('front.liter')),
                'cost_title' => strtolower(trans('front.liter')),
            ],
            [
                'id' => 6,
                'title' => trans('front.km_gal'),
                'fuel_title' => trans('front.kilometers'),
                'distance_title' => strtolower(trans('front.gallon')),
                'cost_title' => strtolower(trans('front.gallon')),
            ],
        ];

        $this->icons_types = [
            'arrow' => trans('front.arrow'),
            'rotating' => trans('front.rotating_icon'),
            'icon' => trans('front.icon')
        ];

        $this->device_icon_colors = [
            'green'  => trans('front.green'),
            'yellow' => trans('front.yellow'),
            'red'    => trans('front.red'),
            'blue'   => trans('front.blue'),
            'orange' => trans('front.orange'),
            'black'  => trans('front.black'),
            'gray' => trans('front.gray'),
            'cyan' => trans('front.cyan'),
            'white' => trans('front.white'),
        ];

        $this->fuel_detect_stop_durations = [
            60 => '1 ' . trans('front.minute_short'),
            120 => '2 ' . trans('front.minute_short'),
            180 => '3 ' . trans('front.minute_short'),
            300 => '5 ' . trans('front.minute_short'),
        ];

        $this->expiration_date_select = [
            '0000-00-00 00:00:00' => trans('front.unlimited'),
            '1' => trans('validation.attributes.expiration_date')
        ];

        $this->configService = $configService;
        $this->deviceService = $deviceService;
        $this->customValueService = $customValueService;
        $this->transformerService = $transformerService->setSerializer(WithoutDataArraySerializer::class);
        $this->deviceUsersService = new DeviceUsersService();

        $this->usersLoader = new UsersLoader($this->user);
        $this->usersLoader->setRequestKey('user_id');

        $this->talentusSyncService = app(TalentusDeviceSyncService::class);
    }

    public function createData()
    {
        $perm = request()->get('perm');

        if ($perm == null || ($perm != null && $perm != 1)) {
            if ($perm != null && $perm != 2) {
                if ($this->deviceUsersService->isLimitReached($this->user)) {
                    throw new DeviceLimitException();
                }
            }

            $this->checkException('devices', 'create');
        }

        $icons_type = $this->icons_types;
        $device_icon_colors = $this->device_icon_colors;
        $fuel_detect_sec_after_stop_options = $this->fuel_detect_stop_durations;
        $expiration_date_select = $this->expiration_date_select;
        $device_fuel_measurements = $this->device_fuel_measurements;
        $device_fuel_measurements_select = Arr::pluck($this->device_fuel_measurements, 'title', 'id');

        $device_icons = DeviceIconRepo::getMyIcons($this->user->id);
        $device_icons_grouped = $device_icons
            ->filter(function ($icon) {
                return $icon->type != 'arrow';
            })
            ->groupBy('type');

        $users = [];

        $device_groups = DeviceGroupRepo::getWhere(['user_id' => $this->user->id])
            ->pluck('title', 'id')
            ->prepend(trans('front.ungrouped'), '0')
            ->all();

        $timezones = TimezoneRepo::order()
            ->pluck('title', 'id')
            ->prepend(trans('front.default'), '0')
            ->map(function ($timezone) {
                return str_replace('UTC ', '', $timezone);
            })
            ->all();

        $sensor_groups = isAdmin()
            ? SensorGroupRepo::getWhere([], 'title')
            ->pluck('title', 'id')
            ->prepend(trans('front.none'), '0')
            ->all()
            : [];

        $device_types = DeviceType::active()->get()->pluck('title', 'id')->prepend(trans('front.none'), '');
        $vehicle_types = VehicleType::with('states')->where('user_id', $this->user->id)->orderBy('name')->get();
        $models = $this->getDeviceModels(new Device());

        if ($this->api) {
            $timezones = apiArray($timezones);
            $device_groups = apiArray($device_groups);
            $sensor_groups = apiArray($sensor_groups);
            $models = apiArray($models);
            $users = UserRepo::getUsers($this->user)->toArray();
        }

        $device_configs = [];
        $apn_configs = [];

        if ($this->user->able('configure_device')) {
            $device_configs = DeviceConfig::active()
                ->get()
                ->pluck('fullName', 'id');
            $apn_configs = ApnConfig::active()
                ->get()
                ->pluck('name', 'id');
        }

        $item = new Device();
        $item->active = true;
        $item->expiration_date = DeviceService::getExpirationDateOffset();
        $item->setRelation('icon', DeviceIcon::find(settings('device.icon_id')));
        $item->icon_id = $item->icon->id ?? 0;
        $item->valid_by_avg_speed = true;
        $item->min_moving_speed = settings('device.min_moving_speed');
        $item->min_fuel_fillings = settings('device.min_fuel_fillings');
        $item->min_fuel_thefts = settings('device.min_fuel_thefts');
        $item->tail_length = settings('device.tail.length');
        $item->tail_color = settings('device.tail.color');
        $item->installation_date = DeviceService::isDefaultInstallationDateToday()
            ? Formatter::date()->convert(date('Y-m-d'))
            : null;

        $webservices = $this->getWebservices($item);

        return compact(
            'device_groups',
            'sensor_groups',
            'item',
            'device_fuel_measurements',
            'device_icons',
            'users',
            'timezones',
            'expiration_date_select',
            'device_fuel_measurements_select',
            'icons_type',
            'device_icons_grouped',
            'device_icon_colors',
            'device_configs',
            'apn_configs',
            'device_types',
            'vehicle_types',
            'fuel_detect_sec_after_stop_options',
            'models',
            'webservices'
        );
    }

    public function createUnregistered()
    {
        $items = UnregisteredDevice::whereIn('imei', $this->data['imeis'])->get();

        $invalidUsers = $this->deviceUsersService->getUsersUnableGetNewDevices($this->user, $items->count());

        if ($invalidUsers->isNotEmpty()) {
            throw new ValidationException(['user_id' => trans('validation.attributes.devices_limit') . ': ' . $invalidUsers->implode('email', ', ')]);
        }

        $multiItems = $items->count() > 1;
        $name = Arr::get($this->data, 'name');

        foreach ($items as $item) {
            $this->data['imei'] = $item->imei;

            if (request()->has('name')) {
                $this->data['name'] = $name . ($multiItems ? " $item->imei" : '');
            }

            $this->create();

            $item->delete();
        }

        return ['status' => 1];
    }

    public function create()
    {
        $this->checkException('devices', 'store');

        if ($this->deviceUsersService->isLimitReached($this->user)) {
            throw new DeviceLimitException();
        }

        $this->data['imei'] = isset($this->data['imei']) ? trim($this->data['imei']) : null;

        if (array_key_exists('enable_expiration_date', $this->data) && empty($this->data['enable_expiration_date'])) {
            $this->data['expiration_date'] = null;
        }

        if (!empty($this->data['expiration_date'])) {
            $this->data['expiration_date'] = Formatter::time()->reverse($this->data['expiration_date']);
        }

        $this->data['icon_colors'] = $this->setIconColors();

        $this->data = onlyEditables(new Device(), $this->user, $this->data);

        $this->setAbleUsers();
        $this->usersReachedLimit();

        DeviceFormValidator::validate('create', $this->data);

        $device = $this->deviceService->create($this->data);

        if ($this->data['configure_device'] ?? false) {
            $this->configureDevice($device);
        }

        // Sincronizar con Talentus ERP (fire-and-forget, no bloquea la respuesta)
        $this->talentusSyncService->pushDevice($device);

        return ['status' => 1, 'id' => $device->id,];
    }

    public function editData()
    {
        $device_id = $this->data['id']
            ?? request()->route('id')
            ?? $this->data['device_id']
            ?? null;

        $item = Device::find($device_id);

        $this->checkException($item && $item->isBeacon() ? 'beacons' : 'devices', 'edit', $item);

        $timezone_id = $item->timezone_id;
        $group_id = $this->user->devices()->find($item->id)->pivot->group_id ?? null;

        $icons_type = $this->icons_types;
        $device_icon_colors = $this->device_icon_colors;
        $fuel_detect_sec_after_stop_options = $this->fuel_detect_stop_durations;
        $expiration_date_select = $this->expiration_date_select;
        $device_fuel_measurements = $this->device_fuel_measurements;
        $device_fuel_measurements_select = Arr::pluck($this->device_fuel_measurements, 'title', 'id');

        $device_icons = DeviceIconRepo::getMyIcons($this->user->id);

        $device_icons_grouped = [];

        foreach ($device_icons as $dicon) {
            if ($dicon['type'] == 'arrow') {
                continue;
            }

            if (!array_key_exists($dicon['type'], $device_icons_grouped)) {
                $device_icons_grouped[$dicon['type']] = [];
            }

            $device_icons_grouped[$dicon['type']][] = $dicon;
        }

        $device_groups = DeviceGroupRepo::getWhere(['user_id' => $this->user->id])
            ->pluck('title', 'id')
            ->prepend(trans('front.ungrouped'), '0')
            ->all();

        $sensors = SensorModalHelper::paginated($item->id);
        $services = ServiceModalHelper::paginated($item->id);

        $detects = $this->getDetectSensors($item);
        $engine_hours = $detects['engine_hours'];
        $detect_engine = $detects['engine'];
        $detect_distance = $detects['distance'];
        $detect_speed = $detects['speed'];

        $timezones = TimezoneRepo::order()
            ->pluck('title', 'id')
            ->prepend(trans('front.default'), '0')
            ->map(function ($timezone) {
                return str_replace('UTC ', '', $timezone);
            })
            ->all();

        $sensor_groups = isAdmin()
            ? SensorGroupRepo::getWhere([], 'title')
            ->pluck('title', 'id')
            ->prepend(trans('front.none'), '0')
            ->all()
            : [];

        $models = $this->getDeviceModels($item);
        $webservices = $this->getWebservices($item);

        $users = [];
        $sel_users = [];
        if ($this->api) {
            $device_groups = apiArray($device_groups);
            $timezones = apiArray($timezones);
            $models = apiArray($models);
            $users = UserRepo::getUsers($this->user)->toArray();
            $sel_users = $item->users->pluck('id', 'id')->all();
        }

        $device_cameras = DeviceCameraRepo::searchAndPaginate(['filter' => ['device_id' => $device_id]], 'id', 'desc', 10);

        $device_types = DeviceType::active()->get()->pluck('title', 'id')->prepend(trans('front.none'), '');
        $vehicle_types = VehicleType::with('states')->where('user_id', $this->user->id)->orderBy('name')->get();

        return compact(
            'device_id',
            'engine_hours',
            'detect_engine',
            'detect_distance',
            'detect_speed',
            'device_groups',
            'sensor_groups',
            'item',
            'device_fuel_measurements',
            'device_icons',
            'sensors',
            'services',
            'expiration_date_select',
            'timezones',
            'users',
            'sel_users',
            'group_id',
            'timezone_id',
            'device_fuel_measurements_select',
            'icons_type',
            'device_icons_grouped',
            'device_icon_colors',
            'device_cameras',
            'device_types',
            'vehicle_types',
            'fuel_detect_sec_after_stop_options',
            'models',
            'webservices'
        );
    }

    public function edit()
    {
        $this->data['id'] = $this->data['id']
            ?? $this->data['device_id']
            ?? null;

        $item = Device::find($this->data['id']);

        $this->checkException('devices', 'update', $item);

        if ($item->isBeacon())
            throw new ValidationException(['id' => 'Device is kind of beacon.']);

        if (! empty($this->data['timezone_id']) && $this->data['timezone_id'] != 57 && $item->isCorrectUTC()) {
            throw new ValidationException(['timezone_id' => 'Device time is correct. Check your timezone Setup -> Main -> Timezone']);
        }

        if (array_key_exists('enable_expiration_date', $this->data) && empty($this->data['enable_expiration_date'])) {
            $this->data['expiration_date'] = null;
        }

        if (!empty($this->data['expiration_date'])) {
            $this->data['expiration_date'] = Formatter::time()->reverse($this->data['expiration_date']);
        }

        $this->data['icon_colors'] = $this->setIconColors($item);

        $this->data = onlyEditables($item, $this->user, $this->data);

        $this->setAbleUsers($item);

        $this->usersReachedLimit($item);

        DeviceFormValidator::validate('update', $this->data, $item->id);

        // Capturar estado anterior de active y suspended_at ANTES de guardar
        $wasActive      = (bool) $item->active;
        $suspendedAt    = $item->suspended_at;
        $newActive      = isset($this->data['active']) ? (bool) $this->data['active'] : $wasActive;
        $activeChanged  = $wasActive !== $newActive;

        $this->deviceService->update($item, $this->data);

        // Registrar mantenimiento automático si cambió el estado active
        if ($activeChanged) {
            if (! $newActive) {
                // Desactivación → suspensión
                $item->updateQuietly(['suspended_at' => now()]);
                DeviceMaintenance::create([
                    'device_id' => $item->id,
                    'tipo'      => DeviceMaintenance::TIPO_SUSPENSION,
                    'fecha'     => now()->toDateString(),
                    'motivo'    => 'Unidad desactivada automáticamente.',
                    'user_id'   => $this->user->id,
                ]);
            } else {
                // Reactivación
                $motivo = $suspendedAt
                    ? 'Unidad reactivada. Estuvo suspendida desde ' . \Carbon\Carbon::parse($suspendedAt)->format('d/m/Y') . '.'
                    : 'Unidad reactivada.';
                DeviceMaintenance::create([
                    'device_id' => $item->id,
                    'tipo'      => DeviceMaintenance::TIPO_REACTIVACION,
                    'fecha'     => now()->toDateString(),
                    'motivo'    => $motivo,
                    'user_id'   => $this->user->id,
                ]);
                $item->updateQuietly(['suspended_at' => null]);
            }
        }

        // Sincronizar con Talentus ERP (fire-and-forget, no bloquea la respuesta)
        $this->talentusSyncService->pushDevice($item->fresh());

        return ['status' => 1, 'id' => $item->id];
    }

    public function resetAppUuid(int $id): array
    {
        /** @var Device $item */
        $item = Device::findOrFail($id);

        $this->checkException('devices', 'edit', $item);

        $item->app_uuid = null;
        $success = $item->save();

        return ['status' => (int)$success, 'id' => $item->id];
    }

    public function destroy()
    {
        $imei = $this->data['imei'] ?? null;

        if (!is_null($imei)) {
            $item = DeviceRepo::whereImei($imei);
        } else {
            $device_id = $this->data['id'] ?? $this->data['device_id'] ?? null;
            $item = DeviceRepo::find($device_id);
        }

        $this->checkException('devices', 'remove', $item);

        $this->deviceService->delete($item);

        return ['status' => 1, 'id' => $item->id, 'deleted' => 1];
    }

    public function detach()
    {
        $device_id = $this->data['id']
            ?? $this->data['device_id']
            ?? null;

        $item = DeviceRepo::find($device_id);

        $this->checkException('devices', 'own', $item);

        $item->users()->detach($this->user->id);

        return ['status' => 1];
    }

    public function changeActive()
    {
        $validator = Validator::make($this->data, [
            'id' => 'required_without:group_id',
            'group_id' => 'required_without:id',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $active = (isset($this->data['active']) && filter_var($this->data['active'], FILTER_VALIDATE_BOOLEAN)) ? 1 : 0;

        if (array_key_exists('group_id', $this->data)) {
            $updateCount = $this->deviceUsersService->setVisibleGroups($this->user, $this->data['group_id'], $active);
        } else {
            $updateCount = $this->deviceUsersService->setVisibleDevices($this->user, $this->data['id'], $active);
        }

        return ['status' => $updateCount ? 1 : 0];
    }

    public function changeVehicleState(): array
    {
        $validator = Validator::make($this->data, [
            'id' => 'required|exists:devices,id',
            'vehicle_state_id' => 'nullable|exists:vehicle_states,id',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        /** @var Device $item */
        $item = Device::findOrFail($this->data['id']);

        $this->checkException('devices', 'edit', $item);

        $stateId = $this->data['vehicle_state_id'] ?? null;

        if ($stateId) {
            $state = VehicleState::find($stateId);

            if (!$state || $state->vehicle_type_id != $item->vehicle_type_id) {
                throw new ValidationException(['vehicle_state_id' => [trans('front.no_vehicle_state')]]);
            }
        }

        $item->update(['vehicle_state_id' => $stateId]);

        return ['status' => 1];
    }

    public function itemsJson()
    {
        $this->checkException('devices', 'view');

        $now  = time();
        $time = empty($this->data['time']) ? $now - 5 : intval($this->data['time']);

        $query = empty($this->data['id']) ? $this->user->devices() : $this->user->accessibleDevices();

        $items = $query
            ->filter($this->data)
            ->filter($this->data['filters'] ?? null)
            ->updatedAfter(date('Y-m-d H:i:s', $time))
            ->clearOrdersBy()
            ->get();

        $transformer = $this->api ? DeviceFullJsonTransformer::class : DeviceMapTransformer::class;

        $items = $this->transformerService->collection($items, $transformer)->toArray();

        if ($this->user->perm('events', 'view')) {
            $eventTime = ($now - $time > 300) ? $now - 300 : $time;

            $events = Event::userAccessible($this->user)->higherTime($eventTime, $this->data['id'] ?? null)->get();
            $events = $events->filter(fn($event) => Arr::get($event, 'alert.notifications.popup.active', true));
            $events = $this->transformerService->collection($events, EventLatestTransformer::class)->toArray();
        } else {
            $events = [];
        }

        return [
            'items' => $items,
            'events' => $events,
            'time' => $now,
            'version' => Config::get('tobuli.version')
        ];
    }

    private function getDeviceModels(Device $item): array
    {
        if (!$this->user->can('view', $item, 'model_id')) {
            return [];
        }

        return DeviceModel::where(
            fn(Builder $query) => $query->where('active', 1)->orWhere('id', $item->model_id)
        )
            ->pluck('title', 'id')
            ->prepend(trans('front.none'), '')
            ->all();
    }

    private function getWebservices(Device $item): array
    {
        $all = [
            'mtc'        => ['label' => 'Sutran',      'perm' => 'webservice.sutran'],
            'osinergmin' => ['label' => 'Osinergmin',  'perm' => 'webservice.osinergmin'],
            'mininter'   => ['label' => 'Mininter',    'perm' => 'webservice.mininter'],
            'consatel'   => ['label' => 'Consatel',    'perm' => 'webservice.consatel'],
            'wisetrack'  => ['label' => 'Wisetrack',   'perm' => 'webservice.wisetrack'],
            'sidersales' => ['label' => 'Sidersales',  'perm' => 'webservice.sidersales'],
            'epscenter'  => ['label' => 'EPS Center',  'perm' => 'webservice.epscenter'],
        ];

        $result = [];
        foreach ($all as $key => $info) {
            if (!$this->user->perm($info['perm'], 'edit')) {
                continue;
            }

            $result[] = [
                'key'    => $key,
                'label'  => $info['label'],
                'active' => (bool) $item->{$key},
            ];
        }

        return $result;
    }

    private function usersReachedLimit($device = null)
    {
        if (empty($this->data['user_id'])) {
            return;
        }

        // Log detallado de verificación de límites
        $logData = [
            'verification_date' => date('Y-m-d H:i:s'),
            'acting_user' => $this->user->email,
            'device_context' => [
                'id' => $device ? $device->id : 'nuevo_dispositivo',
                'name' => $device ? $device->name : 'dispositivo_nuevo'
            ],
            'users_to_verify' => is_array($this->data['user_id']) ? count($this->data['user_id']) : 'bulk_loader'
        ];

        // Obtener información detallada de cada usuario antes de la verificación
        $usersDetails = [];

        if (is_array($this->data['user_id'])) {
            $usersToCheck = User::whereIn('id', $this->data['user_id'])->whereNotNull('devices_limit')->get();
        } else {
            // Para UsersLoader (bulk_loader), obtener todos los usuarios con límite
            $usersToCheck = collect();

            if ($this->data['user_id'] instanceof \Tobuli\Services\EntityLoader\UsersLoader) {
                try {
                    $usersToCheck = $this->data['user_id']->getQuerySelected()->whereNotNull('devices_limit')->get();
                } catch (\Exception $e) {
                    // Si falla, intentar obtener al menos los usuarios problemáticos
                    $usersToCheck = $this->deviceUsersService->getUsersReachedLimit($this->data['user_id'], $device);
                }
            } else {
                // Si no es UsersLoader, obtener solo los usuarios problemáticos
                $usersToCheck = $this->deviceUsersService->getUsersReachedLimit($this->data['user_id'], $device);
            }
        }

        foreach ($usersToCheck as $user) {
            $usedLimit = $this->deviceUsersService->getUsedLimit($user);
            $userDetail = [
                'username' => $user->email,
                'limite_configurado' => $user->devices_limit,
                'limite_usado' => $usedLimit,
                'disponible' => $user->devices_limit - $usedLimit,
                'limite_alcanzado' => $usedLimit >= $user->devices_limit
            ];

            if ($user->isManager()) {
                // Obtener detalles específicos para managers
                $managerDevices = $user->devices()->count();
                $subusersLimits = $user->subusers()->sum('devices_limit');
                $userDetail['tipo'] = 'manager';
                $userDetail['calculo_detallado'] = [
                    'dispositivos_propios' => $managerDevices,
                    'limites_subusuarios' => $subusersLimits,
                    'formula' => "dispositivos_propios({$managerDevices}) + limites_subusuarios({$subusersLimits}) = {$usedLimit}"
                ];
            } else {
                $userDetail['tipo'] = 'usuario_normal';
                $userDetail['calculo_detallado'] = "dispositivos_asignados = {$usedLimit}";
            }

            $usersDetails[] = $userDetail;
        }

        $logData['usuarios_verificados'] = $usersDetails;

        $users = $this->deviceUsersService->getUsersReachedLimit($this->data['user_id'], $device);

        $logData['resultado'] = [
            'usuarios_con_limite_alcanzado' => $users->count(),
            'status' => $users->isEmpty() ? 'APROBADO' : 'RECHAZADO_POR_LIMITE',
            'usuarios_problematicos' => $users->pluck('email')->toArray()
        ];



        if (!$users->isEmpty()) {
            throw new ValidationException(['user_id' => trans('validation.attributes.devices_limit') . ': ' . $users->implode('email', ', ')]);
        }
    }

    private function configureDevice(Device $device)
    {
        if (! $this->user->able('configure_device')) {
            throw new PermissionException(['id' => trans('front.dont_have_permission')]);
        }

        DeviceConfiguratorFormValidator::validate('configure', $this->data);

        $config = DeviceConfig::find($this->data['config_id']);

        $smsManager = new SMSGatewayManager();
        $gatewayArgs = settings('sms_gateway.use_as_system_gateway')
            ? ['request_method' => 'system']
            : null;

        $smsSenderService = $smsManager->loadSender($this->user, $gatewayArgs);
        $apnData = request()->all(['apn_name', 'apn_username', 'apn_password']);

        if ($this
            ->configService
            ->setSmsManager($smsSenderService)
            ->configureDevice($device->sim_number, $apnData, $config->commands)
        ) {
            return ['status' => 2];
        }

        throw new \Exception(trans('validation.cant_configure_device'));
    }

    private function setAbleUsers($device = null)
    {
        if (!$this->api) {
            unset($this->data['user_id']);
        }

        if (!(isAdmin() && $this->user->can('edit', new User()))) {
            unset($this->data['user_id']);
        }

        if (is_null($device) && empty($this->data['user_id'])) {
            $this->data['user_id'] = [$this->user->id];
        }

        if ($this->usersLoader->hasSelect()) {
            if ($device)
                $this->usersLoader->setQueryStored($device->users());
            $this->data['user_id'] = $this->usersLoader;
        } else {
            if ($users = $this->data['user_id'] ?? []) {
                $this->data['user_id'] = User::userAccessible($this->user)->whereIn('id', $users)->pluck('id')->all();
            }
        }
    }

    protected function setIconColors($item = null)
    {
        $icon_colors = $item
            ? $item->icon_colors + settings('device.status_colors.colors')
            : settings('device.status_colors.colors');

        foreach ($icon_colors as $status => $color) {
            $key = "icon_$status";

            if (array_key_exists($key, $this->data) && array_key_exists($this->data[$key], $this->device_icon_colors)) {
                $icon_colors[$status] = $this->data[$key];
            }
        }

        return $icon_colors;
    }

    protected function getDetectSensors($device)
    {
        $engine_hours = [
            'gps' => trans('front.gps')
        ];

        $engine = [
            'gps' => trans('front.gps')
        ];

        $speed = [
            'gps' => trans('front.gps')
        ];

        $distance = [
            'gps' => trans('front.gps')
        ];

        $sensors = $device->sensors()
            ->whereIn('type', ['acc', 'engine', 'ignition', 'engine_hours', 'odometer', 'speed_ecm'])
            ->get();

        foreach ($sensors as $sensor) {

            $title = trans('front.sensor') . ': ' . $sensor->type_title;

            switch ($sensor->type) {
                case 'acc':
                case 'engine':
                case 'ignition':
                    $engine_hours[$sensor->type] = $title;
                    $engine[$sensor->type] = $title;
                    break;
                case 'engine_hours':
                    $engine_hours[$sensor->type] = $title;
                    break;
                case 'odometer':
                    $distance[$sensor->type] = $title;
                    break;
                case 'speed_ecm':
                    $speed[$sensor->type] = $title;
                    break;
            }
        }

        return [
            'engine_hours' => $engine_hours,
            'engine'       => $engine,
            'distance'     => $distance,
            'speed'        => $speed,
        ];
    }
}
