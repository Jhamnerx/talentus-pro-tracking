<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Manager;
use App\Exceptions\PermissionException;
use App\Http\Controllers\Controller;
use App\Http\Wrapper;
use App\Transformers\ApiV1\DeviceAllTransformer;
use App\Transformers\ApiV1\DeviceFullTransformer;
use CustomFacades\ModalHelpers\DeviceModalHelper;
use CustomFacades\RemoteUser;
use CustomFacades\Repositories\DeviceGroupRepo;
use CustomFacades\Repositories\SmsEventQueueRepo;
use CustomFacades\Repositories\UserRepo;
use CustomFacades\Server;
use Formatter;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\ControllerDispatcher;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Routing\SortedMiddleware;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tobuli\Entities\Device;
use Tobuli\Entities\User;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\FcmService;
use Tobuli\Services\FractalSerializers\WithoutDataArraySerializer;
use Tobuli\Services\FractalTransformerService;
use Validator;

class ApiController extends Controller
{
    protected $transformerService;

    public function __construct(FractalTransformerService $transformerService)
    {
        parent::__construct();

        $this->transformerService = $transformerService->setSerializer(WithoutDataArraySerializer::class);
    }

    public function login()
    {
        $validator = Validator::make(request()->all(), [
            'email' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails())
            return response()->json(['status' => 0, 'errors' => $validator->errors()], 422);

        if (isPublic()) {
            return $this->loginPublic();
        }

        return $this->loginCredentials();
    }

    private function loginPublic()
    {
        $user = RemoteUser::getByCredencials($this->data['email'], $this->data['password']);

        return $user
            ? $this->getLoginSuccessResponse($user)
            : response()->json(['status' => 0, 'message' => trans('front.login_failed')], 401);
    }

    private function loginCredentials()
    {
        if (!Auth::attempt(['email' => $this->data['email'], 'password' => $this->data['password']])) {
            return response()->json(['status' => 0, 'message' => trans('front.login_failed')], 401);
        }

        if ($error = $this->checkAuthUserValidity(Auth::user())) {
            Auth::logout();
            return response()->json(['status' => 0, 'message' => $error], 401);
        }

        if (request()->has('as') && $error = $this->loginAs(request('as'))) {
            Auth::logout();
            return response()->json(['status' => 0, 'message' => $error], 401);
        }

        return $this->getLoginSuccessResponse(Auth::user());
    }

    private function loginAs(string $email)
    {
        if (!Auth::user()) {
            return trans('front.login_failed');
        }

        $user = User::firstWhere(['email' => $email]);

        if (!$user) {
            return trans('front.user_not_found');
        }

        (new Manager(Auth::user()))->check('users', 'login_as', $user);

        if ($error = $this->checkAuthUserValidity($user)) {
            return $error;
        }

        Auth::logout();
        Auth::loginUsingId($user->id);

        return null;
    }

    private function checkAuthUserValidity($user)
    {
        if (!$user) {
            return trans('front.login_failed');
        }

        if (!$user->active) {
            return trans('front.login_suspended');
        }

        if ($user->isExpired()) {
            return trans('front.subscription_expired');
        }

        return null;
    }

    private function getLoginSuccessResponse(User $user): array
    {
        if (empty($user->api_hash)) {
            while (!empty(User::firstWhere(['api_hash' => $hash = Hash::make("$user->email:$user->password")])));

            $user->api_hash = $hash;
            $user->save();
        }

        return [
            'status'        => 1,
            'user_api_hash' => $user->api_hash,
            'permissions'   => $user->getPermissions()
        ];
    }

    public function getSmsEvents()
    {
        UserRepo::updateWhere(['id' => $this->user->id], ['sms_gateway_app_date' => date('Y-m-d H:i:s')]);
        $items = SmsEventQueueRepo::getWhereSelect(['user_id' => $this->user->id], ['id', 'phone', 'message'], 'created_at')->toArray();


        if (!empty($items))
            SmsEventQueueRepo::deleteWhereIn(Arr::pluck($items, 'id'));

        return [
            'status' => 1,
            'items' => $items
        ];
    }

    #
    # Devices
    #

    public function getDevices()
    {
        if (!$this->user->perm('devices', 'view')) {
            return [];
        }

        Server::setMemoryLimit(config('server.device_memory_limit'));

        $device_groups = DeviceGroupRepo::getWhere(['user_id' => $this->user->id])
            ->pluck('title', 'id')
            ->prepend(trans('front.ungrouped'), '0')
            ->all();

        $grouped = [];

        $query = $this->user->devices()
            ->with(['users'])
            ->search(request()->get('s'))
            ->filter(request()->all());

        if (request()->has('page') || request()->has('limit')) {
            $page  = request()->get('page', 1);
            $limit = request()->get('limit', 100);
            $limit = max(1, $limit);

            $devices = $query->forPage($page, $limit)->get();

            $this->groupDevices($grouped, $device_groups, $devices);
        } else {
            $query->chunk(500, function ($devices) use (&$grouped, $device_groups) {
                $this->groupDevices($grouped, $device_groups, $devices);
            });
        }

        return array_values($grouped);
    }

    public function getDevicesAll($hash)
    {
        if (empty($hash))
            abort(404);

        if (config('addon.devices_all') !== $hash)
            abort(404);

        $time = empty($this->data['time']) ? time() - 60 : intval($this->data['time']);

        $items = Device::filter($this->data)
            ->updatedAfter(date('Y-m-d H:i:s', $time))
            ->clearOrdersBy()
            ->get();

        return [
            'items' => $this->transformerService->collection($items, DeviceAllTransformer::class)->toArray(),
        ];
    }

    protected function groupDevices(&$grouped, $device_groups, $devices)
    {
        DeviceFullTransformer::loadRelations($devices);

        foreach ($devices as $device) {
            $group_id = empty($device->pivot->group_id) ? 0 : $device->pivot->group_id;
            $group_id = empty($device_groups[$group_id]) ? 0 : $group_id;

            if (!isset($grouped[$group_id])) {
                $grouped[$group_id] = [
                    'id' => $group_id,
                    'title' => $device_groups[$group_id],
                    'items' => []
                ];
            }

            $grouped[$group_id]['items'][] = $this->transformerService->item($device, DeviceFullTransformer::class)->toArray();
        }
    }

    public function getDevicesJson()
    {
        $data = DeviceModalHelper::itemsJson();

        return $data;
    }

    public function getDevicesByPlate()
    {
        if (!$this->user->perm('devices', 'view')) {
            return response()->json(['status' => 0, 'message' => trans('front.dont_have_permission')], 403);
        }

        $plateNumber = request()->get('plate_number');

        if (empty($plateNumber)) {
            return response()->json(['status' => 0, 'message' => 'plate_number parameter is required'], 400);
        }

        $device = $this->user->devices()
            ->where('plate_number', $plateNumber)
            ->select(['id', 'imei', 'sim_number', 'name', 'plate_number'])
            ->first();

        if (!$device) {
            return response()->json([
                'status' => 0,
                'message' => 'Device not found with plate number: ' . $plateNumber
            ], 404);
        }

        return [
            'status' => 1,
            'device' => $device->toArray()
        ];
    }

    public function getDevicesTransmissionStatus()
    {
        if (!$this->user->perm('devices', 'view')) {
            return response()->json(['status' => 0, 'message' => trans('front.dont_have_permission')], 403);
        }

        Server::setMemoryLimit(config('server.device_memory_limit'));

        // Usar zona horaria de Lima para cálculos correctos
        $now = \Carbon\Carbon::now('America/Lima');

        $devices = $this->user->devices()
            ->where('devices.active', 1)
            ->traccarJoin()
            ->select(['devices.*', 'traccar_devices.server_time', 'traccar_devices.time'])
            ->with(['traccar'])
            ->get();

        // Categorías de emergencia
        $categories = [
            'ok' => [
                'title' => 'OK (< 1 hora)',
                'description' => 'Transmitiendo normalmente',
                'priority' => 0,
                'items' => []
            ],
            'warning' => [
                'title' => 'Advertencia (1-24 horas)',
                'description' => 'Sin transmisión reciente',
                'priority' => 1,
                'items' => []
            ],
            'critical' => [
                'title' => 'Crítico (1-7 días)',
                'description' => 'Requiere atención inmediata',
                'priority' => 2,
                'items' => []
            ],
            'emergency' => [
                'title' => 'Emergencia (> 7 días)',
                'description' => 'Requiere revisión urgente',
                'priority' => 3,
                'items' => []
            ],
            'never_connected' => [
                'title' => 'Nunca conectado',
                'description' => 'Dispositivo sin conexión',
                'priority' => 4,
                'items' => []
            ]
        ];

        foreach ($devices as $device) {
            $deviceData = [
                'id' => $device->id,
                'name' => $device->name,
                'imei' => $device->imei,
                'plate_number' => $device->plate_number,
                'sim_number' => $device->sim_number,
                'last_connection' => null,
                'days_offline' => null,
                'hours_offline' => null,
                'status' => $device->getStatus()
            ];

            if ($device->server_time) {
                // Convertir de UTC a hora de Lima
                $lastConnection = \Carbon\Carbon::parse($device->server_time, 'UTC')->setTimezone('America/Lima');

                $diffHours = $now->diffInHours($lastConnection);
                $diffDays = $now->diffInDays($lastConnection);

                $deviceData['last_connection'] = $lastConnection->format('Y-m-d H:i:s');
                $deviceData['last_connection_utc'] = $device->server_time;
                $deviceData['days_offline'] = round($diffDays + ($diffHours % 24) / 24, 1);
                $deviceData['hours_offline'] = round($diffHours, 1);

                // Clasificar según tiempo sin transmitir
                if ($diffHours < 1) {
                    $categories['ok']['items'][] = $deviceData;
                } elseif ($diffHours < 24) {
                    $categories['warning']['items'][] = $deviceData;
                } elseif ($diffDays < 7) {
                    $categories['critical']['items'][] = $deviceData;
                } else {
                    $categories['emergency']['items'][] = $deviceData;
                }
            } else {
                // Nunca se ha conectado
                $categories['never_connected']['items'][] = $deviceData;
            }
        }

        // Preparar respuesta con estadísticas
        $response = [
            'status' => 1,
            'total_active_devices' => $devices->count(),
            'summary' => [
                'ok' => count($categories['ok']['items']),
                'warning' => count($categories['warning']['items']),
                'critical' => count($categories['critical']['items']),
                'emergency' => count($categories['emergency']['items']),
                'never_connected' => count($categories['never_connected']['items'])
            ],
            'categories' => []
        ];

        // Ordenar por prioridad y añadir solo categorías con dispositivos
        foreach ($categories as $key => $category) {
            if (count($category['items']) > 0 || request()->get('show_empty', false)) {
                $response['categories'][] = [
                    'key' => $key,
                    'title' => $category['title'],
                    'description' => $category['description'],
                    'priority' => $category['priority'],
                    'count' => count($category['items']),
                    'items' => $category['items']
                ];
            }
        }

        return $response;
    }

    public function getUserData()
    {
        $dStart = new \DateTime(date('Y-m-d H:i:s'));
        $dEnd = new \DateTime($this->user->subscription_expiration);
        $dDiff = $dStart->diff($dEnd);
        $days_left = $dDiff->days;

        $plan = isset($this->user->billing_plan->title)
            ? $this->user->billing_plan->title
            : trans('admin.group_' . $this->user->group_id);

        return [
            'email' => $this->user->email,
            'expiration_date' => $this->user->subscription_expiration != '0000-00-00 00:00:00'
                ? Formatter::time()->human($this->user->subscription_expiration)
                : NULL,
            'days_left' => $this->user->subscription_expiration != '0000-00-00 00:00:00' ? $days_left : NULL,
            'plan' => $plan,
            'devices_limit' => intval($this->user->devices_limit),
            'group_id'      => $this->user->group_id,
            'role_id'       => $this->user->group_id,
            'permissions'   => $this->user->getPermissions()
        ];
    }

    public function setDeviceExpiration()
    {
        if (!isAdmin())
            return response()->json(['status' => 0, 'error' => trans('front.dont_have_permission')], 403);

        $validator = Validator::make(request()->all(), [
            'imei' => 'required',
            'expiration_date' => 'required|date',
        ]);

        if ($validator->fails())
            return response()->json(['status' => 0, 'errors' => $validator->errors()], 400);

        $device = \Tobuli\Entities\Device::where('imei', request()->get('imei'))->first();

        if (!$device)
            return response()->json(['status' => 0, 'errors' => ['imei' => dontExist('global.device')]], 400);

        if (! $this->user->can('edit', $device, 'expiration_date'))
            throw new PermissionException();

        $device->expiration_date = request()->get('expiration_date');
        $device->save();

        return response()->json(['status' => 1], 200);
    }

    public function enableDeviceActive()
    {
        $validator = Validator::make(request()->all(), ['id' => 'required']);

        if ($validator->fails())
            throw new ValidationException($validator->errors());

        $device = \Tobuli\Entities\Device::find(request('id'));

        $this->checkException('devices', 'enable', $device);

        if (!$device->active) {
            $device->active = true;
            $device->Save();
        }

        return response()->json(['status' => 1], 200);
    }

    public function disableDeviceActive()
    {
        $validator = Validator::make(request()->all(), ['id' => 'required']);

        if ($validator->fails())
            throw new ValidationException($validator->errors());

        $device = \Tobuli\Entities\Device::find(request('id'));

        $this->checkException('devices', 'disable', $device);

        if ($device->active) {
            $device->active = false;
            $device->Save();
        }

        return response()->json(['status' => 1], 200);
    }

    public function geoAddress()
    {
        if (empty($this->data['lat']) || empty($this->data['lon']))
            return '-';

        return getGeoAddress($this->data['lat'], $this->data['lon']);
    }

    public function setFcmToken(FcmService $fcmService)
    {
        $validator = Validator::make(request()->all(), ['token' => 'required']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $fcmService->setFcmToken($this->user, $this->data['token'], $input['project_id'] ?? null);

        return response()->json(['status' => 1]);
    }

    public function destroyFcmToken(FcmService $fcmService)
    {
        $validator = Validator::make(request()->all(), ['token' => 'required']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $token = request('token');
        $result = $fcmService->destroyFcmToken($this->user, $token);

        if (!$result) {
            return response()->json(['status' => 0, 'message' => 'El token no existe'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Token eliminado con éxito.']);
    }

    public function getServicesKeys()
    {
        $services = [];

        $services['maps']['google']['key'] = settings('main_settings.google_maps_key');

        return response()->json(['status' => 1, 'items' => $services], 200);
    }

    public function __call($name, $arguments)
    {
        list($class, $method) = explode('#', $name);

        try {
            $controller = App::make("App\Http\Controllers\Frontend\\" . $class);
            $response = (new Wrapper())->run($controller, $method, $arguments);
        } catch (\ReflectionException $e) {
            return response()->json(['status' => 0, 'message' => 'Method does not exist!'], 500);
        }

        if (! is_array($response))
            return $response;

        if (!array_key_exists('status', $response))
            $response['status'] = 1;

        $status_code = 200;
        if ($response['status'] == 0)
            $status_code = 400;

        if (array_key_exists('perm', $response))
            $status_code = 403;

        return response()->json($response, $status_code);
    }

    public function getDevicesWithSensors()
    {
        if (!$this->user->perm('devices', 'view')) {
            return response()->json(['status' => 0, 'message' => trans('front.dont_have_permission')], 403);
        }

        Server::setMemoryLimit(config('server.device_memory_limit'));

        $plateNumber = request()->get('plate_number');

        $query = $this->user->devices()
            ->with(['sensors', 'traccar'])
            ->select([
                'devices.name',
                'devices.plate_number',
                'devices.active',
            ]);

        if (!empty($plateNumber)) {
            $query->where('devices.plate_number', $plateNumber);
        }

        $devices = $query->get();

        $items = $devices->map(function ($device) {
            $traccar = $device->traccar;

            $sensors = $device->sensors->map(function ($sensor) {
                return [
                    'id'                  => $sensor->id,
                    'name'                => $sensor->name,
                    'type'                => $sensor->type,
                    'tag_name'            => $sensor->tag_name,
                    'value'               => $sensor->value,
                    'unit_of_measurement' => $sensor->unit_of_measurement,
                    'add_to_history'      => (bool) $sensor->add_to_history,
                ];
            })->values()->all();

            return [
                'name'        => $device->name,
                'plate_number' => $device->plate_number,
                'active'      => (bool) $device->active,
                'lat'         => $traccar ? $traccar->lat : null,
                'lng'         => $traccar ? $traccar->lng : null,
                'speed'       => $traccar ? $traccar->speed : null,
                'course'      => $traccar ? $traccar->course : null,
                'time'        => $traccar ? $traccar->time : null,
                'server_time' => $traccar ? $traccar->server_time : null,
                'sensors'     => $sensors,
            ];
        })->values()->all();

        return response()->json([
            'status' => 1,
            'total'  => count($items),
            'items'  => $items,
        ]);
    }
}
