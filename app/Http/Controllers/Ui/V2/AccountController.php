<?php

namespace App\Http\Controllers\Ui\V2;

use App\Exceptions\DemoAccountException;
use CustomFacades\Repositories\SmsEventQueueRepo;
use CustomFacades\Repositories\TimezoneRepo;
use CustomFacades\Repositories\UserRepo;
use CustomFacades\Validators\SMSGatewayFormValidator;
use CustomFacades\Validators\UserAccountFormValidator;
use CustomFacades\Validators\UserAccountSettingsFormValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tobuli\Entities\Client;
use Tobuli\Entities\Company;
use Tobuli\Entities\User;
use Tobuli\Entities\UserSecondaryCredentials;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\UserClientService;
use Tobuli\Services\UserCompanyService;
use Tobuli\Services\UserService;

/**
 * TalentusPro · AccountController (ui/v2)
 * -----------------------------------------------------------------
 * Ajustes de cuenta como MODAL con tabs (paridad con el legacy
 * MyAccountSettings). Reutiliza validadores/servicios/repos legacy:
 *  - Locale : UserAccountSettingsFormValidator + UserRepo::update +
 *             UserService::setDST (horario de verano).
 *  - Cliente: UserClientService / UserCompanyService.
 *  - Clave  : UserAccountFormValidator('password').
 *  - SMS    : SMSGatewayFormValidator + parse de sms_gateway_params.
 *  - Widgets: User::setSettings('widgets').
 *  - Mapa   : map_id del usuario (capa base por defecto).
 * Cada tab guarda por separado (no se pisan entre sí).
 * -----------------------------------------------------------------
 */
class AccountController extends BaseController
{
    /** Datos para poblar todos los tabs del modal. */
    public function meta(): JsonResponse
    {
        $u = $this->user;
        $item = UserRepo::find($u->id)->toArray();

        $client = Client::find($item['client_id'] ?? null) ?: new Client();
        $company = Company::find($item['company_id'] ?? null) ?: new Company();
        $userDst = DB::table('users_dst')->where('user_id', $u->id)->first();

        $sms = array_merge([
            'request_method' => '', 'encoding' => '', 'authentication' => '',
            'custom_headers' => '', 'username' => '', 'auth_id' => '',
            'auth_token' => '', 'senders_phone' => '',
        ], (array) Arr::get($item, 'sms_gateway_params', []));

        $requestMethods = [
            'get' => 'GET', 'post' => 'POST',
            'app' => trans('front.sms_gateway_app'), 'plivo' => 'Plivo',
        ];
        if (settings('sms_gateway.enabled')) {
            $requestMethods = ['server' => 'Server gateway'] + $requestMethods;
        }

        $widgets = (array) $u->getSettings('widgets');
        $widgetsCatalog = (array) config('lists.widgets');
        // Orden: primero los guardados (respeta orden del usuario), luego el resto
        $ordered = [];
        foreach ((array) ($widgets['list'] ?? []) as $k) {
            if (isset($widgetsCatalog[$k])) {
                $ordered[] = ['key' => $k, 'label' => $widgetsCatalog[$k], 'on' => true];
            }
        }
        $inList = array_column($ordered, 'key');
        foreach ($widgetsCatalog as $k => $label) {
            if (! in_array($k, $inList, true)) {
                $ordered[] = ['key' => $k, 'label' => $label, 'on' => empty($widgets['list'])];
            }
        }

        $maps = [];
        foreach ((function_exists('getAvailableMaps') ? getAvailableMaps() : []) as $id => $name) {
            $maps[] = ['id' => (int) $id, 'name' => (string) $name];
        }
        usort($maps, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return response()->json([
            'ok' => true,
            'caps' => [
                'demo'           => (bool) isDemoUser(),
                'view_sms'       => (bool) $u->perm('sms_gateway', 'view'),
                'edit_sms'       => (bool) $u->perm('sms_gateway', 'edit'),
                'view_client'    => (bool) $u->perm('user.client_id', 'view'),
                'edit_client'    => (bool) $u->perm('user.client_id', 'edit'),
                'main_login'     => (bool) $u->isMainLogin(),
                'require_current'=> (bool) settings('password.change_required_current'),
            ],
            'locale' => [
                'unit_of_distance' => $item['unit_of_distance'] ?? 'km',
                'unit_of_capacity' => $item['unit_of_capacity'] ?? 'lt',
                'unit_of_altitude' => $item['unit_of_altitude'] ?? 'mt',
                'duration_format'  => $item['duration_format'] ?? 'hh:mm:ss',
                'date_format'      => $item['date_format'] ?? \Appearance::getSetting('default_date_format'),
                'time_format'      => $item['time_format'] ?? \Appearance::getSetting('default_time_format'),
                'week_start_day'   => (string) ($item['week_start_day'] ?? '1'),
                'timezone_id'      => (int) ($item['timezone_id'] ?: 17),
            ],
            'dst' => [
                'type'          => $userDst->type ?? 'none',
                'country_id'    => $userDst->country_id ?? null,
                'date_from'     => $userDst->date_from ?? null,
                'date_to'       => $userDst->date_to ?? null,
                'month_from'    => $userDst->month_from ?? null,
                'month_to'      => $userDst->month_to ?? null,
                'week_pos_from' => $userDst->week_pos_from ?? null,
                'week_pos_to'   => $userDst->week_pos_to ?? null,
                'week_day_from' => $userDst->week_day_from ?? null,
                'week_day_to'   => $userDst->week_day_to ?? null,
                'time_from'     => $userDst->time_from ?? null,
                'time_to'       => $userDst->time_to ?? null,
            ],
            'lists' => [
                'units_of_distance' => $this->kv(['km' => trans('front.kilometer'), 'mi' => trans('front.mile'), 'nm' => trans('front.nautical_mile')]),
                'units_of_capacity' => $this->kv(['lt' => trans('front.liter'), 'gl' => trans('front.gallon')]),
                'units_of_altitude' => $this->kv(['mt' => trans('front.meter'), 'ft' => trans('front.feet')]),
                'duration_formats'  => $this->kv((array) config('tobuli.duration_formats')),
                'date_formats'      => $this->kv((array) config('tobuli.date_formats')),
                'time_formats'      => $this->kv((array) config('tobuli.time_formats')),
                'week_start_days'   => $this->kv(getWeekStartDays()),
                'timezones'         => $this->kv(TimezoneRepo::order()->pluck('title', 'id')->all()),
                'dst_types'         => $this->kv(getDSTTypes()),
                'months'            => $this->kv(getMonths()),
                'weekdays'          => $this->kv(getWeekdays()),
                'week_pos'          => $this->kv(getWeekPositions()),
                'dst_countries'     => $this->kv(getDSTCountries()),
                'request_methods'   => $this->kv($requestMethods),
                'encodings'         => $this->kv([0 => trans('global.no'), 'json' => 'JSON']),
                'authentications'   => $this->kv([0 => trans('global.no'), 1 => trans('global.yes')]),
            ],
            'client' => [
                'first_name'    => $client->first_name,
                'last_name'     => $client->last_name,
                'personal_code' => $client->personal_code,
                'birth_date'    => $client->birth_date,
                'address'       => $client->address,
            ],
            'company' => [
                'name'              => $company->name,
                'registration_code' => $company->registration_code,
                'vat_number'        => $company->vat_number,
                'address'           => $company->address,
            ],
            'sms' => [
                'enabled'         => (bool) ($item['sms_gateway'] ?? false),
                'url'             => (string) ($item['sms_gateway_url'] ?? ''),
                'request_method'  => (string) $sms['request_method'],
                'encoding'        => (string) $sms['encoding'],
                'authentication'  => (string) $sms['authentication'],
                'username'        => (string) $sms['username'],
                'custom_headers'  => (string) $sms['custom_headers'],
                'auth_id'         => (string) $sms['auth_id'],
                'auth_token'      => (string) $sms['auth_token'],
                'senders_phone'   => (string) $sms['senders_phone'],
                'queue_count'     => (int) SmsEventQueueRepo::countwhere(['user_id' => $u->id]),
                'test_url'        => route('sms_gateway.test_sms'),
            ],
            'widgets' => [
                'default' => ! empty($widgets['default']),
                'status'  => ! empty($widgets['status']),
                'items'   => $ordered,
            ],
            'map' => [
                'map_id'    => (int) ($u->map_id ?: settings('main_settings.default_map')),
                'available' => $maps,
            ],
        ]);
    }

    /* ================= Locale ================= */

    public function updateLocale(Request $request): JsonResponse
    {
        try {
            $this->assertNotDemo();

            $data = $request->all();
            UserAccountSettingsFormValidator::validate('update', $data, $this->user->id);

            UserRepo::update($this->user->id, Arr::only($data, [
                'unit_of_distance', 'unit_of_capacity', 'unit_of_altitude',
                'duration_format', 'timezone_id', 'week_start_day',
                'date_format', 'time_format',
            ]));

            if (isset($data['dst_type'])) {
                (new UserService())->setDST($this->user, array_merge($data, [
                    'type'       => $data['dst_type'] ?? 'none',
                    'country_id' => $data['dst_country_id'] ?? null,
                ]));
            }

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (DemoAccountException $e) {
            return response()->json(['ok' => false, 'message' => trans('front.demo_account')], 403);
        }
    }

    /* ================= Cliente / Empresa ================= */

    public function updateClient(Request $request): JsonResponse
    {
        if (! $this->user->perm('user.client_id', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'forbidden'], 403);
        }

        try {
            $this->assertNotDemo();

            if ($client = $request->get('client')) {
                (new UserClientService($this->user))->update((array) $client);
            }
            if ($company = $request->get('company')) {
                if ($this->user->company && ! $this->user->can('edit', $this->user->company)) {
                    return response()->json(['ok' => false, 'message' => 'forbidden'], 403);
                }
                (new UserCompanyService($this->user))->update((array) $company);
            }

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (DemoAccountException $e) {
            return response()->json(['ok' => false, 'message' => trans('front.demo_account')], 403);
        }
    }

    /* ================= Contraseña ================= */

    public function changePassword(Request $request): JsonResponse
    {
        try {
            $this->assertNotDemo();

            $data = $request->all();
            UserAccountFormValidator::validate('password', $data);

            $item = $this->user->isMainLogin()
                ? User::find($this->user->id)
                : UserSecondaryCredentials::find($this->user->getLoginSecondaryCredentials()->id);

            $item->update(Arr::only($data, ['password']));

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (DemoAccountException $e) {
            return response()->json(['ok' => false, 'message' => trans('front.demo_account')], 403);
        }
    }

    /* ================= SMS gateway ================= */

    public function updateSms(Request $request): JsonResponse
    {
        if (! $this->user->perm('sms_gateway', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'forbidden'], 403);
        }

        try {
            $this->assertNotDemo();

            $data = $request->all();
            $data['sms_gateway'] = ! empty($data['sms_gateway']);
            $data['sms_gateway_url'] = $data['sms_gateway_url'] ?? '';

            if ($data['sms_gateway'] && isset($data['request_method'])) {
                SMSGatewayFormValidator::validate($data['request_method'], $data);
            }

            $update = Arr::only($data, ['sms_gateway', 'sms_gateway_url']);

            if (isset($data['request_method'])) {
                $fields = ['request_method', 'authentication', 'username', 'password',
                    'encoding', 'auth_id', 'auth_token', 'senders_phone', 'custom_headers'];
                $update['sms_gateway_params'] = [];
                foreach ($fields as $field) {
                    $update['sms_gateway_params'][$field] = $data[$field]
                        ?? ($this->user->sms_gateway_params[$field] ?? '');
                }
            }

            UserRepo::update($this->user->id, $update);

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (DemoAccountException $e) {
            return response()->json(['ok' => false, 'message' => trans('front.demo_account')], 403);
        }
    }

    /* ================= Widgets ================= */

    public function updateWidgets(Request $request): JsonResponse
    {
        try {
            $this->assertNotDemo();

            $widgets = (array) $request->get('widgets', []);
            // Normaliza a la forma esperada por setSettings: default/status/list
            $widgets['default'] = ! empty($widgets['default']) ? 1 : 0;
            $widgets['status']  = ! empty($widgets['status']) ? 1 : 0;
            $widgets['list']    = array_values(array_filter((array) ($widgets['list'] ?? [])));

            $this->user->setSettings('widgets', $widgets, true);

            return response()->json(['ok' => true]);
        } catch (DemoAccountException $e) {
            return response()->json(['ok' => false, 'message' => trans('front.demo_account')], 403);
        }
    }

    /* ================= Mapa (capa base por defecto) ================= */

    public function updateMap(Request $request): JsonResponse
    {
        try {
            $this->assertNotDemo();

            $maps = array_flip(getAvailableMaps());
            $selected = (int) $request->get('map_id');
            $mapId = in_array($selected, $maps, true) || array_key_exists($selected, getAvailableMaps())
                ? $selected
                : (int) settings('main_settings.default_map');

            UserRepo::update($this->user->id, ['map_id' => $mapId]);

            return response()->json(['ok' => true, 'map_id' => $mapId]);
        } catch (DemoAccountException $e) {
            return response()->json(['ok' => false, 'message' => trans('front.demo_account')], 403);
        }
    }

    /* ----------------------------------------------------------------- */

    private function assertNotDemo(): void
    {
        if (isDemoUser()) {
            throw new DemoAccountException();
        }
    }

    /** ['id' => label] → [{id, label}] preservando orden. */
    private function kv(array $map): array
    {
        $out = [];
        foreach ($map as $id => $label) {
            $out[] = ['id' => (string) $id, 'label' => (string) $label];
        }

        return $out;
    }
}
