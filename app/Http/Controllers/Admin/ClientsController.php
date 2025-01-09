<?php

namespace App\Http\Controllers\Admin;

use PgSql\Lob;
use Validator;
use Tobuli\Entities\User;
use Tobuli\Entities\Alert;
use Illuminate\Support\Arr;
use Tobuli\Entities\Company;
use Tobuli\Entities\MapIcon;
use Tobuli\Helpers\Password;
use Tobuli\Services\AuthManager;
use Tobuli\Services\UserService;
use Illuminate\Support\Facades\DB;
use Tobuli\Entities\EmailTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Tobuli\Services\ScheduleService;
use Tobuli\Services\PermissionService;
use Tobuli\Services\UserClientService;
use App\Exceptions\PermissionException;
use Illuminate\Support\Facades\Request;
use Tobuli\Services\DeviceUsersService;
use Tobuli\Services\UserCompanyService;
use App\Exceptions\DeviceLimitException;
use CustomFacades\Repositories\UserRepo;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Response;
use Tobuli\Services\CustomValuesService;
use Illuminate\Database\Eloquent\Builder;
use Tobuli\Reports\ReportTypesUserConfig;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Importers\POI\POIImportManager;
use Tobuli\Validation\ClientFormValidator;
use Tobuli\Importers\Route\RouteImportManager;
use Tobuli\Services\EntityLoader\DevicesLoader;
use Tobuli\Importers\Geofence\GeofenceImportManager;
use Tobuli\Services\EntityLoader\DevicesGroupLoader;
use CustomFacades\Validators\ObjectsListSettingsFormValidator;
use Tobuli\Repositories\Device\DeviceRepositoryInterface as Device;
use Tobuli\Repositories\BillingPlan\BillingPlanRepositoryInterface as BillingPlan;
use Tobuli\Repositories\TraccarDevice\TraccarDeviceRepositoryInterface as TraccarDevice;

class ClientsController extends BaseController
{
    /**
     * @var ClientFormValidator
     */
    private $clientFormValidator;

    private $section = 'clients';
    /**
     * @var Device
     */
    private $device;
    /**
     * @var TraccarDevice
     */
    private $traccarDevice;

    private $permissionService;

    /**
     * @var UserService
     */
    private $userService;

    private $userClientService;
    private $userCompanyService;
    private ReportTypesUserConfig $reportTypesConfig;

    /**
     * @var DeviceUsersService
     */
    private $deviceUsersService;

    /**
     * @var CustomValuesService
     */
    private $customValueService;

    /**
     * @var DevicesLoader
     */
    private $devicesLoader;

    function __construct(
        ClientFormValidator $clientFormValidator,
        Device $device,
        TraccarDevice $traccarDevice,
        PermissionService $permissionService
    ) {
        parent::__construct();

        $this->clientFormValidator = $clientFormValidator;
        $this->device = $device;
        $this->traccarDevice = $traccarDevice;
        $this->permissionService = $permissionService;

        $this->userService = new UserService();

        $this->userClientService = new UserClientService();
        $this->userCompanyService = new UserCompanyService();
        $this->reportTypesConfig = new ReportTypesUserConfig();
        $this->customValueService = new CustomValuesService();
        $this->deviceUsersService = new DeviceUsersService();
    }

    protected function afterAuth($user)
    {
        $this->devicesLoader = new DevicesGroupLoader($user);
        $this->devicesLoader->setRequestKey('objects');
    }

    public function index()
    {
        $this->checkException('users', 'view');

        $input = array_merge(['limit' => 25], request()->input());
        $sort = $input['sorting'] ?? ['sort_by' => 'email', 'sort' => 'asc'];

        $items = User::userAccessible($this->user)
            ->withCount('subusers')
            ->withCount('devices')
            ->with('manager:id,email')
            ->with('billing_plan:id,title')
            ->search($input['search_phrase'] ?? '')
            ->filter($input['filter'] ?? [])
            ->when(!empty($input['search_device']), function (Builder $query) use ($input) {
                $query->whereHas('devices', function (Builder $query) use ($input) {
                    $query->where('devices.imei', 'LIKE', '%' . $input['search_device'] . '%');
                });
            })
            ->toPaginator($input['limit'], $sort['sort_by'], $sort['sort']);

        $section = $this->section;

        return $this->api
            ? $items
            : View::make('admin::' . ucfirst($this->section) . '.' . (Request::ajax() ? 'table' : 'index'))
            ->with(compact('items', 'input', 'section'));
    }

    public function create(BillingPlan $billingPlanRepo)
    {
        $this->checkException('users', 'create');

        $managers = UserRepo::getOtherManagers(0)
            ->pluck('email', 'id')
            ->prepend('-- ' . trans('admin.select') . ' --', '0')
            ->all();

        $maps = getAvailableMaps();

        $plans = [];

        if (settings('main_settings.enable_plans')) {
            $plans = $billingPlanRepo->getWhere([], 'objects', 'asc')
                ->pluck('title', 'id')
                ->prepend('-- ' . trans('admin.select') . ' --', '0')
                ->all();
        }

        $objects_limit = null;

        if ($this->user->hasDeviceLimit()) {
            $objects_limit = $this->deviceUsersService->getManagerFreeLimit($this->user);
        }

        $grouped_permissions = $this->permissionService->group(
            $this->permissionService->getByUserRole($this->user->isAdmin() ? null : $this->user)
        );

        $permission_values = $this->permissionService->getUserDefaults();

        //$devices = groupDevices($this->user->accessibleDevicesWithGroups()->get(), $this->user);
        $devices = [];
        $numeric_sensors = config('tobuli.numeric_sensors');
        $settings = UserRepo::getListViewSettings(null);
        $fields = config('tobuli.listview_fields');
        listviewTrans(null, $settings, $fields);

        $companies = Company::orderBy('name')
            ->userAccessible($this->user)
            ->pluck('name', 'id')
            ->prepend('-- ' . trans('admin.select') . ' --', '')
            ->all();

        return View::make('admin::' . ucfirst($this->section) . '.create')->with(compact(
            'managers',
            'maps',
            'plans',
            'objects_limit',
            'grouped_permissions',
            'devices',
            'fields',
            'settings',
            'numeric_sensors',
            'permission_values',
            'companies'
        ));
    }

    public function store(BillingPlan $billingPlanRepo)
    {
        $this->checkException('users', 'store');

        $input = Request::all();
        unset($input['id']);

        $input = onlyEditables(new \Tobuli\Entities\User(), $this->user, $input);

        if ($this->user->hasDeviceLimit()) {
            $input['enable_devices_limit'] = 1;
        }

        if (isset($input['expiration_date'])) {
            $input['expiration_date'] = \Formatter::time()->reverse($input['expiration_date']);
            $input['subscription_expiration'] = $input['expiration_date'];
        }

        if (isset($input['role_id'])) {
            $input['group_id'] = $input['role_id'];
        }

        if (!empty($input['password_generate'])) {
            $input['password'] = $input['password_confirmation'] = Password::generate();
        }

        $this->clientFormValidator->validate('create', $input);
        $this->validateLoginPeriods(new \Tobuli\Entities\User(), $input);

        if (request()->input('columns', [])) {
            ObjectsListSettingsFormValidator::validate('update', request()->all(['columns']));
        }

        if ($this->user->hasDeviceLimit()) {
            $objects_limit = $this->deviceUsersService->getManagerFreeLimit($this->user);
            if ($objects_limit < $input['devices_limit']) {
                throw new ValidationException(['devices_limit' => trans('front.devices_limit_reached')]);
            }
        }

        $plan = array_key_exists('billing_plan_id', $input)
            ? $billingPlanRepo->find($input['billing_plan_id'])
            : null;

        if (! empty($plan)) {
            $input['devices_limit'] = $plan->objects;

            if (empty($input['subscription_expiration'])) {
                $input['subscription_expiration'] = date(
                    'Y-m-d H:i:s',
                    strtotime(date('Y-m-d H:i:s') . " + {$plan->duration_value} {$plan->duration_type}")
                );
            }
        }


        $this->checkDeviceLimit(null, $input);

        if (!$this->user->can('edit', new \Tobuli\Entities\User(), 'client_id')) {
            unset($input['company_id'], $input['client_id'], $input['client'], $input['company']);
        }

        if (isset($input['company'])) {
            unset($input['company_id']);
        }

        beginTransaction();

        try {
            $user = $this->userService->create($input);

            if (empty($input['email_verification'])) {
                $user->markEmailAsVerified();
            }

            $this->syncDevices($user, $input);

            if (isset($input['client'])) {
                $this->userClientService->setUser($user)->update($input['client']);
            }

            if (isset($input['company'])) {
                $this->userCompanyService->setUser($user)->update($input['company']);
            }

            if (isset($input['report_types'])) {
                $this->reportTypesConfig->store($user, $input['report_types'] ?: []);
            }

            if (empty($plan)) {
                if (array_key_exists('perms', $input)) {
                    $permissions = $this->permissionService->getByUser($user, $input['perms']);
                } else {
                    $permissions = $this->permissionService->getUserDefaults();
                }

                $this->userService->setPermissions($user, $permissions);
            }

            if (request()->input('columns', [])) {
                $user->setSettings('listview', ['columns' => array_values(request()->get('columns', []))]);
            }

            if ($this->user->can('edit', $user, 'custom_fields')) {
                $customValues = $input['custom_fields'] ?? null;
                $this->customValueService->saveCustomValues($user, $customValues);
            }

            $this->writeLoginMethods($user, $input);

            commitTransaction();
        } catch (\Exception $e) {
            rollbackTransaction();
            throw $e;
        }

        if (! empty($input['account_created'])) {
            $this->notifyUser($input, 'account_created');
        }


        $alerts = Alert::userOwned($this->user)->with('events_custom')->get();

        $events_custom_ids = [];

        foreach ($alerts as $alert) {
            // Crear la nueva alerta para el usuario
            $newAlert = $user->alerts()->create(
                [
                    'name' => $alert->name,
                    'type' => $alert->type,
                    'active' => $alert->active,
                    'notifications' => $alert->notifications,
                ]
            );

            // Obtener los IDs de los eventos personalizados asociados a la alerta
            $events_custom_ids = array_merge($events_custom_ids, $alert->events_custom->pluck('id')->toArray());

            // Sincronizar los eventos personalizados con la nueva alerta creada
            $newAlert->events_custom()->sync($alert->events_custom->pluck('id'));
        }


        return Response::json($this->api ? ['status' => 1, 'item' => $user] : ['status' => 1]);
    }

    public function edit(BillingPlan $billingPlanRepo, $id = null)
    {
        $item = User::find($id);

        $this->checkException('users', 'edit', $item);

        $managers = UserRepo::getOtherManagers($item->id)
            ->pluck('email', 'id')
            ->prepend('-- ' . trans('admin.select') . ' --', '0')
            ->all();
        $maps = getAvailableMaps();
        $plans = [];

        if (settings('main_settings.enable_plans')) {
            $plans = $billingPlanRepo->getWhere([], 'objects', 'asc')
                ->pluck('title', 'id')
                ->prepend('-- ' . trans('admin.select') . ' --', '0')
                ->all();
        }

        $objects_limit = null;

        if ($this->user->hasDeviceLimit()) {
            $objects_limit = $this->deviceUsersService->getManagerFreeLimit($this->user, $item);
        }

        $numeric_sensors = config('tobuli.numeric_sensors');
        $settings = UserRepo::getListViewSettings($id);
        $fields = config('tobuli.listview_fields');
        listviewTrans($id, $settings, $fields);
        //$devices = groupDevices($this->user->accessibleDevicesWithGroups()->get(), $this->user);
        $devices = [];
        $grouped_permissions = $this->permissionService->group(
            $this->permissionService->getByUser($item)
        );
        $permission_values = $item->getPermissions();

        $companies = Company::orderBy('name')
            ->userAccessible($this->user)
            ->pluck('name', 'id')
            ->prepend('-- ' . trans('admin.select') . ' --', '')
            ->all();

        return View::make('admin::' . ucfirst($this->section) . '.edit')->with(compact(
            'item',
            'permission_values',
            'managers',
            'maps',
            'plans',
            'objects_limit',
            'grouped_permissions',
            'devices',
            'fields',
            'settings',
            'numeric_sensors',
            'companies'
        ));
    }

    public function update(BillingPlan $billingPlanRepo)
    {
        $input = Request::all();
        $id = $input['id'];
        $item = \Tobuli\Entities\User::find($id);

        $this->checkException('users', 'update', $item);

        if (config('app.server') == 'demo' && $item->isGod() && ! $this->user->isGod()) {
            return Response::json(['errors' => ['id' => "Can't edit main admin account."]]);
        }

        $input = onlyEditables($item, $this->user, $input);

        if ($this->user->hasDeviceLimit()) {
            $input['enable_devices_limit'] = 1;
            $input['devices_limit'] = $input['devices_limit'] ?? $this->user->devices_limit;
        }

        if (!empty($input['password_generate'])) {
            $input['password'] = $input['password_confirmation'] = Password::generate();
        }

        $this->clientFormValidator->validate('update', $input, $id);
        $this->validateLoginPeriods($item, $input);

        if (isset($input['role_id'])) {
            $input['group_id'] = $input['role_id'];
        }

        if (empty($input['password'])) {
            unset($input['password']);
        }

        if (! empty($input['manager_id']) && $this->managerInfinity($item, $input['manager_id'])) {
            throw new ValidationException([
                'manager_id' => 'Managers infinity loop'
            ]);
        }

        if (!$this->user->can('edit', $item, 'client_id')) {
            unset($input['company_id'], $input['client_id'], $input['client'], $input['company']);
        }

        if (isset($input['company'])) {
            unset($input['company_id']);
        }

        if (isset($input['services']) && $input['services'] == '[]') {
            $input['services'] = null;
        } else {
            $services = $input['services'];
            $defaultServices = [
                'sutran' => ['active' => 0, 'token' => null],
                'osinergmin' => ['active' => 0, 'token' => null],
                'consatel' => ['active' => 0, 'user' => null, 'pass' => null]
            ];

            foreach ($defaultServices as $service => $defaults) {
                if (isset($services[$service])) {
                    $defaultServices[$service]['active'] = $services[$service]['active'] ?? 0;
                    if ($defaultServices[$service]['active']) {
                        $defaultServices[$service]['token'] = $services[$service]['token'] ?? null;
                        if ($service == 'consatel') {
                            $defaultServices[$service]['user'] = $services[$service]['user'] ?? null;
                            $defaultServices[$service]['pass'] = $services[$service]['pass'] ?? null;
                        }
                    }
                }
            }

            $input['services'] = $defaultServices;

            $item->update(['services' => $input['services']]);
        }

        beginTransaction();

        try {
            if (request()->input('columns', [])) {
                ObjectsListSettingsFormValidator::validate('update', request()->all(['columns']));
                $item->setSettings('listview', ['columns' => array_values(request()->get('columns', []))]);
            }

            if (isset($input['expiration_date'])) {
                $input['expiration_date'] = \Formatter::time()->reverse($input['expiration_date']);
                $input['subscription_expiration'] = $input['expiration_date'];
            }

            $plan = null;

            if (array_key_exists('billing_plan_id', $input)) {
                $plan = $billingPlanRepo->find($input['billing_plan_id']);

                if (!empty($plan)) {
                    $input['devices_limit'] = $plan->objects;

                    if (empty($input['subscription_expiration'])) {
                        $input['subscription_expiration'] = date(
                            'Y-m-d H:i:s',
                            strtotime(date('Y-m-d H:i:s') . " + {$plan->duration_value} {$plan->duration_type}")
                        );
                    }
                } else {
                    $input['billing_plan_id'] = null;
                }
            }

            if (empty($plan)) {
                $input['billing_plan_id'] = null;
                $input['devices_limit'] = !isset($input['enable_devices_limit']) ? null : $input['devices_limit'];
                $input['subscription_expiration'] = !isset($input['enable_expiration_date']) ? '0000-00-00 00:00:00' : $input['expiration_date'];
            }

            if ($this->user->isManager() && $this->user->id == $item->id) {
                $input['billing_plan_id'] = $item->billing_plan_id;
                $input['devices_limit'] = $item->devices_limit;
                $input['subscription_expiration'] = $item->subscription_expiration;
            } else {
                DB::table('user_permissions')->where('user_id', '=', $item->id)->delete();
                if (array_key_exists('perms', $input)) {
                    $permissions = $this->permissionService->getByUser($item, $input['perms']);
                    $this->userService->setPermissions($item, $permissions);
                }
            }

            if ($this->user->hasDeviceLimit()) {
                $objects_limit = $this->deviceUsersService->getManagerFreeLimit($this->user, $item);

                if ($objects_limit < $input['devices_limit'] && $input['devices_limit'] > $item->devices_limit) {
                    throw new ValidationException(['devices_limit' => trans('front.devices_limit_reached')]);
                }
            }

            $this->checkDeviceLimit($item, $input);

            $this->userService->update($item, $input);

            $this->syncDevices($item, $input);

            if (isset($input['client'])) {
                $this->userClientService->setUser($item)->update($input['client']);
            }

            if (isset($input['company'])) {
                $this->userCompanyService->setUser($item)->update($input['company']);
            }

            if (isset($input['report_types'])) {
                $this->reportTypesConfig->store($item, $input['report_types'] ?: []);
            }

            if (isset($input['forwards'])) {
                if (empty($input['forwards'])) $input['forwards'] = [];
                $item->forwards()->sync($input['forwards']);
            }

            if ($this->user->can('edit', $item, 'custom_fields')) {
                $customValues = $input['custom_fields'] ?? null;
                $this->customValueService->saveCustomValues($item, $customValues);
            }

            $this->writeLoginMethods($item, $input);

            commitTransaction();
        } catch (\Exception $e) {
            rollbackTransaction();
            throw $e;
        }

        if (!empty($input['password']) && !empty($input['send_account_password_changed_email'])) {
            $this->notifyUser($input, 'account_password_changed');
        }

        if ($item->alerts()->get()->count() <= 0) {

            $alerts = Alert::userOwned($this->user)->with('events_custom')->get();

            $events_custom_ids = [];

            foreach ($alerts as $alert) {
                // Crear la nueva alerta para el usuario
                $newAlert = $item->alerts()->create(
                    [
                        'name' => $alert->name,
                        'type' => $alert->type,
                        'active' => $alert->active,
                        'notifications' => $alert->notifications,
                    ]
                );

                // Obtener los IDs de los eventos personalizados asociados a la alerta
                $events_custom_ids = array_merge($events_custom_ids, $alert->events_custom->pluck('id')->toArray());

                // Sincronizar los eventos personalizados con la nueva alerta creada
                $newAlert->events_custom()->sync($alert->events_custom->pluck('id'));
            }
        }

        return Response::json(['status' => 1]);
    }

    private function validateLoginPeriods($item, &$input)
    {
        if (!$this->user->can('edit', $item, 'login_periods')) {
            unset($input['login_periods']);
        }

        if (!isset($input['login_periods'])) {
            return;
        }

        if (empty($input['login_periods'])) {
            $input['login_periods'] = null;
        } else {
            $scheduleService = new ScheduleService();
            $scheduleService->validate($input['login_periods'], 'login_periods');
            $input['login_periods'] = $scheduleService->setFormSchedules($input['login_periods']);
        }
    }

    private function writeLoginMethods(\Tobuli\Entities\User $item, array $input)
    {
        if (!settings('user_login_methods.general.user_individual_config')) {
            return;
        }

        if (!isset($input['default_login_methods']) && !isset($input['login_methods'])) {
            return;
        }

        if (!empty($input['default_login_methods'])) {
            $item->loginMethods()->delete();
        } else {
            foreach ($input['login_methods'] ?? [] as $method => $enabled) {
                $item->loginMethods()->updateOrCreate(
                    ['type' => $method],
                    ['type' => $method, 'enabled' => $enabled]
                );
            }
        }
    }

    public function importPoi()
    {
        $users = User::userAccessible($this->user)->orderby('email')->get();

        $icons = MapIcon::all();

        return View::make('admin::' . ucfirst($this->section) . '.import_poi')->with(compact('users', 'icons'));
    }

    public function importPoiSet(POIImportManager $importManager)
    {
        $this->checkException('poi', 'store');

        $validator = Validator::make(request()->all(), [
            'file'       => 'required|file',
            'map_icon_id' => 'required',
            'user_id'    => 'required|array',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $file = request()->file('file');

        $users = User::userAccessible($this->user)->whereIn('id', request()->get('user_id'))->get();

        if (empty($users)) {
            return response()->json(['status' => 0]);
        }

        foreach ($users as $user) {
            $additionals = [
                'map_icon_id' => request()->get('map_icon_id'),
                'user_id'     => $user->id
            ];
            $importManager->import($file, $additionals);
        }

        return response()->json([
            'status' => 1,
            'message' => trans('front.successfully_saved'),
        ]);
    }

    public function importGeofences()
    {
        $users = User::userAccessible($this->user)->orderby('email')->get();

        return View::make('admin::' . ucfirst($this->section) . '.import_geofences')->with(compact('users'));
    }

    public function importGeofencesSet(GeofenceImportManager $importManager)
    {
        $this->checkException('geofences', 'store');

        $validator = Validator::make(request()->all(), [
            'user_id'    => 'required|array',
            'file'       => 'required|file',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $file = request()->file('file');

        $users = User::userAccessible($this->user)->whereIn('id', request()->get('user_id'))->get();

        if (empty($users)) {
            return response()->json(['status' => 0]);
        }

        foreach ($users as $user) {
            $additionals = [
                'user_id'     => $user->id
            ];
            $importManager->import($file, $additionals);
        }

        return response()->json([
            'status' => 1,
            'message' => trans('front.successfully_saved'),
        ]);
    }

    public function importRoutes()
    {
        $users = User::userAccessible($this->user)->orderby('email')->get();

        return View::make('admin::' . ucfirst($this->section) . '.import_routes')->with(compact('users'));
    }

    public function importRoutesSet(RouteImportManager $importManager)
    {
        $this->checkException('routes', 'store');

        $validator = Validator::make(request()->all(), [
            'user_id'    => 'required|array',
            'file'       => 'required|file',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $file = request()->file('file');

        $users = User::userAccessible($this->user)->whereIn('id', request()->get('user_id'))->get();

        if (empty($users)) {
            return response()->json(['status' => 0]);
        }

        foreach ($users as $user) {
            $additionals = [
                'user_id'     => $user->id
            ];
            $importManager->import($file, $additionals);
        }

        return response()->json([
            'status' => 1,
            'message' => trans('front.successfully_saved'),
        ]);
    }

    public function getDevices($id)
    {
        $user = User::find($id);

        $this->checkException('users', 'show', $user);

        $items = $user->devices()->with('traccar')->get();

        return View::make('admin::Clients.get_devices')->with(compact('items'));
    }

    public function doDestroy()
    {
        return view('admin::' . ucfirst($this->section) . '.destroy',  ['ids' => request('id')]);
    }

    public function destroy($id = null)
    {
        $ids = Request::input('ids', $id);

        if (empty($ids)) {
            return Response::json(['status' => 1]);
        }

        if (! is_array($ids)) {
            $ids = [$ids];
        }

        $users = \Tobuli\Entities\User::whereIn('id', $ids)->get();

        foreach ($users as $user) {
            if (! $this->user->can('remove', $user)) {
                continue;
            }

            $user->delete();
        }

        return Response::json(['status' => 1]);
    }

    public function loginAs($id)
    {
        $item = User::find($id);

        $this->checkException('users', 'login_as', $item);

        return View::make('admin::Clients.login_as')->with(compact('item'));
    }

    public function loginAsAgree($id)
    {
        $item = User::find($id);

        $this->checkException('users', 'login_as', $item);

        if (! empty($item)) {
            session()->put('previous_user', $this->user->id);
            auth()->logout();
            auth()->loginUsingId($item->id);
        }

        return Redirect::route('home');
    }

    public function getPermissionsTable(BillingPlan $billingPlanRepo)
    {
        $user = User::find(request('user_id'));
        $plan = $billingPlanRepo->find(request('id'));

        if (! is_null($user)) {
            $this->checkException('users', 'show', $user);
            $permissions = $this->permissionService->getByUser($user);
        } else {
            $permissions = (request()->filled('group_id')) ?
                $this->permissionService->getByGroupId(request('group_id')) :
                $this->permissionService->getByUserRole();
        }

        $is_plan_set = (! is_null($plan));

        $item = $is_plan_set ? $plan : $user;

        if (! is_null($item)) {
            $permission_values = $item->getPermissions();
        } else {
            $permission_values = (request()->filled('group_id')) ?
                $this->permissionService->getGroupDefaults(request('group_id')) :
                $this->permissionService->getUserDefaults();
        }

        return view('Admin.Clients._perms')->with([
            'permission_values'  => $permission_values,
            'plan'    => $is_plan_set,
            'grouped_permissions' => $this->permissionService->group($permissions),
        ]);
    }

    private function notifyUser(array $data, string $templateName)
    {
        $template = EmailTemplate::getTemplate($templateName, $this->user);

        try {
            sendTemplateEmail($data['email'], $template, $data);
        } catch (\Exception $e) {
            throw new ValidationException(['id' => 'Failed to send notify mail. Check email settings.']);
        }
    }

    public function setStatus()
    {
        $validator = Validator::make(request()->all(), [
            'id'     => 'required_without:email',
            'email'  => 'required_without:id|email',
            'status' => 'required|in:1,0',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->messages());
        }

        if (request()->filled('id')) {
            $user = \Tobuli\Entities\User::find(request('id'));
        } else {
            $user = \Tobuli\Entities\User::where('email', request('email'))->first();
        }

        $this->checkException('users', 'edit', $user);

        $user->update(['active' => request('status')]);

        return Response::json(['status' => 1]);
    }

    public function setActiveMulti($active)
    {
        $this->data['active'] = (bool)$active;

        $validator = Validator::make($this->data, [
            'id'     => 'required|array',
            'id.*'   => 'integer',
            'active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->messages());
        }

        \Tobuli\Entities\User::userControllable($this->user)
            ->where('id', '!=', $this->user->id)
            ->whereIn('id', $this->data['id'])
            ->update(['active' => $this->data['active']]);

        return Response::json(['status' => 1]);
    }

    public function setLoginToken(int $id)
    {
        return $this->updateLoginToken($id, function ($user) {
            $this->userService->setLoginToken($user);
        });
    }

    public function unsetLoginToken(int $id)
    {
        return $this->updateLoginToken($id, function ($user) {
            $this->userService->unsetLoginToken($user);
        });
    }

    public function updateLoginToken(int $id, \Closure $callback)
    {
        $user = \Tobuli\Entities\User::findOrFail($id);

        $this->checkException('users', 'edit', $user);

        $can = $this->user->can('edit', $user, 'login_token');

        if (!$can) {
            throw new PermissionException();
        }

        $callback($user);

        return Response::json(['status' => 1, 'login_token' => $user->login_token]);
    }

    private function managerInfinity($user, $manager_id, $managers = [])
    {
        // User cant be his own manager
        if ($manager_id == $user->id) {
            return true;
        }

        $manager = \Tobuli\Entities\User::find($manager_id);

        if (! $manager) {
            return false;
        }

        if (! $manager->manager_id) {
            return false;
        }

        // Managers infinity loop
        if (in_array($manager->id, $managers)) {
            return true;
        }

        $managers[] = $manager->id;

        return $this->managerInfinity($user, $manager->manager_id, $managers);
    }

    private function checkDeviceLimit($user, $input)
    {
        if (is_null(Arr::get($input, 'devices_limit'))) {
            return;
        }

        if ($this->api) {
            $count = empty($input['objects']) ? 0 : count($input['objects']);
        } else {
            if ($user) {
                $this->devicesLoader->setQueryStored($user->devices());
                $count = $this->devicesLoader->getQuery()->count();
            } else {
                $count = $this->devicesLoader->getSeleted()
                    ? $this->devicesLoader->getSeleted()->count()
                    : 0;
            }
        }

        if ($count && $input['devices_limit'] < $count) {
            throw new ValidationException(['devices_limit' => trans('front.devices_limit_reached')]);
        }
    }

    private function syncDevices($user, $input)
    {
        if ($this->api) {
            if (isset($input['objects'])) {
                if (empty($input['objects'])) $input['objects'] = [];

                $user->devices()->sync($input['objects']);
            }
        } else {
            if ($this->devicesLoader->hasSelect()) {
                $user->devices()->syncLoader($this->devicesLoader);
            }
        }
    }
}
