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

        // Tablero (dashboard): habilitado + bloques con su etiqueta/estado
        $dash = (array) $u->getSettings('dashboard');
        // Sin ajustes previos el usuario no tiene 'blocks': hay que partir del
        // catálogo (si no, el tab salía vacío y los toggles no persistían).
        $dashCatalog = (array) config('tobuli.dashboard.blocks', []);
        $dashSaved   = (array) ($dash['blocks'] ?? []);
        $dashBlocks  = [];
        foreach (($dashSaved ?: $dashCatalog) as $key => $cfg) {
            $cfg  = (array) $cfg;
            $opts = (array) ($cfg['options'] ?? []);
            $dashBlocks[] = [
                'key'     => $key,
                'label'   => trans('front.' . $key),
                'enabled' => ! empty($cfg['enabled']),
                // 'period' es la única opción editable hoy (trait HasPeriodOption);
                // null = el bloque no la soporta.
                'period'  => array_key_exists('period', $opts) ? (string) $opts['period'] : null,
            ];
        }

        // Mapa: toggles per-usuario (map_controls). Fallback a settings globales.
        $mc = (array) $u->map_controls;

        return response()->json([
            'ok' => true,
            'caps' => [
                'demo'                => (bool) isDemoUser(),
                'view_sms'            => (bool) $u->perm('sms_gateway', 'view'),
                'edit_sms'            => (bool) $u->perm('sms_gateway', 'edit'),
                'view_client'         => (bool) $u->perm('user.client_id', 'view'),
                'edit_client'         => (bool) $u->perm('user.client_id', 'edit'),
                'view_drivers'        => (bool) $u->perm('drivers', 'view'),
                'view_custom_events'  => (bool) $u->perm('custom_events', 'view'),
                'view_sms_templates'  => (bool) $u->perm('user_sms_templates', 'view'),
                'view_gprs_templates' => (bool) $u->perm('user_gprs_templates', 'view'),
                'main_login'          => (bool) $u->isMainLogin(),
                // Gates EXACTOS del legacy para los tabs opcionales
                'view_media_cats'     => (bool) (config('addon.media_categories') && $u->perm('media_categories', 'view')),
                'view_secondary'      => (bool) (config('auth.secondary_credentials') && $u->isMainLogin()),
                'require_current'     => (bool) settings('password.change_required_current'),
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
            'dashboard' => [
                'enabled' => ! empty($dash['enabled']),
                'blocks'  => $dashBlocks,
            ],
            'map' => [
                // Mostrar velocidad al costado del nombre en el marcador
                'show_speed' => array_key_exists('m_show_speed', $mc)
                    ? (bool) $mc['m_show_speed']
                    : (bool) settings('main_settings.map_show_device_speed'),
                // Colorear la etiqueta del marcador según el estado del vehículo
                'status_color' => (bool) ($mc['m_status_color'] ?? false),
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

    /**
     * Confirmar email con el código único. Paridad exacta con el legacy
     * EmailConfirmationController@update: compara contra users.email_activation
     * y activa el email. POST account/email-confirmation {code}.
     */
    public function confirmEmail(Request $request): JsonResponse
    {
        $item = UserRepo::find($this->user->id);

        if (! empty($item->email_active)) {
            return response()->json(['ok' => true]); // ya confirmado: idempotente
        }

        $code = trim((string) $request->get('code'));

        if ($code === '' || $code != $item->email_activation) {
            return response()->json(['ok' => false, 'errors' => ['code' => trans('front.wrong_code')]], 422);
        }

        UserRepo::update($item->id, [
            'email_active'     => 1,
            'email_activation' => '',
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Reenviar el código de activación. Paridad exacta con el legacy
     * resendActivationCodeSubmit: rate limit de 60s, código de 5 dígitos y
     * envío con la plantilla 'email_activation'. POST .../resend.
     */
    public function resendEmailCode(Request $request): JsonResponse
    {
        $item = UserRepo::find($this->user->id);

        if (strtotime((string) $item->email_activation_sent) > (time() - 60)) {
            return response()->json(['ok' => false, 'errors' => ['id' => trans('front.cant_resend_please_wait')]], 422);
        }

        $input = [
            'email_activation'      => rand(10000, 99999),
            'email_activation_sent' => date('Y-m-d H:i:s'),
        ];

        // OJO paridad: el legacy llamaba sendTemplateEmail($template, $email, ...)
        // con los args INVERTIDOS (la firma es ($to, EmailTemplate, $data)) y con
        // la clave '[code]' en vez de 'code' → el reenvío legacy siempre fatales.
        // Aquí va corregido; el builder EmailActivationTemplate lee $data['code'].
        $template = app(\Tobuli\Repositories\EmailTemplate\EmailTemplateRepositoryInterface::class)
            ->whereName('email_activation');

        if (! $template) {
            return response()->json(['ok' => false, 'errors' => ['id' => 'Plantilla de email no configurada.']], 422);
        }

        try {
            sendTemplateEmail($item->email, $template, ['code' => $input['email_activation']]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'errors' => ['id' => 'No se pudo enviar el correo.']], 422);
        }

        UserRepo::update($item->id, $input);

        return response()->json(['ok' => true]);
    }

    /* =================================================================
     * Categorías de medios (addon media_categories + perm) — paridad
     * Frontend\MediaCategoriesController (MediaCategoryValidator).
     * ================================================================= */

    public function mediaCategories(Request $request): JsonResponse
    {
        $this->checkException('media_categories', 'view');

        $rows = \Tobuli\Entities\MediaCategory::where('user_id', $this->user->id)
            ->orderBy('title')->get(['id', 'title']);

        return response()->json([
            'ok'    => true,
            'items' => $rows->map(function ($r) {
                return ['id' => (int) $r->id, 'title' => (string) $r->title];
            })->all(),
        ]);
    }

    public function storeMediaCategory(Request $request): JsonResponse
    {
        $this->checkException('media_categories', 'store');

        try {
            $data = ['title' => trim((string) $request->get('title')), 'user_id' => $this->user->id];
            \CustomFacades\Validators\MediaCategoryValidator::validate('create', $data);
            $item = \Tobuli\Entities\MediaCategory::create($data);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }

        return response()->json(['ok' => true, 'id' => (int) $item->id]);
    }

    public function updateMediaCategory(Request $request, $id): JsonResponse
    {
        $item = \Tobuli\Entities\MediaCategory::find((int) $id);
        if (! $item) {
            abort(404);
        }
        $this->checkException('media_categories', 'edit', $item);

        try {
            $data = ['title' => trim((string) $request->get('title')), 'user_id' => $this->user->id];
            \CustomFacades\Validators\MediaCategoryValidator::validate('update', $data, $item->id);
            $item->update(['title' => $data['title']]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function destroyMediaCategory(Request $request, $id): JsonResponse
    {
        $item = \Tobuli\Entities\MediaCategory::find((int) $id);
        if (! $item) {
            abort(404);
        }
        $this->checkException('media_categories', 'destroy', $item);
        $item->delete();

        return response()->json(['ok' => true]);
    }

    /* =================================================================
     * Credenciales secundarias (config auth.secondary_credentials +
     * isMainLogin) — paridad Frontend\SecondaryCredentialsController.
     * ================================================================= */

    private function assertSecondaryAllowed(): void
    {
        if (! config('auth.secondary_credentials') || ! $this->user->isMainLogin()) {
            abort(403);
        }
    }

    public function secondaryCredentials(Request $request): JsonResponse
    {
        $this->assertSecondaryAllowed();

        $rows = $this->user->secondaryCredentials()->orderBy('email')->get();

        return response()->json([
            'ok'    => true,
            'items' => $rows->map(function ($r) {
                return [
                    'id'       => (int) $r->id,
                    'email'    => (string) $r->email,
                    'readonly' => (bool) $r->readonly,
                ];
            })->all(),
        ]);
    }

    public function storeSecondaryCredential(Request $request): JsonResponse
    {
        $this->assertSecondaryAllowed();

        try {
            $data = $request->all();
            $data['user_id'] = $this->user->id;
            $notify = ! empty($data['account_created']);
            $item = (new \Tobuli\Services\UserSecondaryCredentialsService())
                ->setNotifyUser($notify)->store($data);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }

        return response()->json(['ok' => true, 'id' => (int) $item->id]);
    }

    public function updateSecondaryCredential(Request $request, $id): JsonResponse
    {
        $this->assertSecondaryAllowed();

        $item = $this->user->secondaryCredentials()->find((int) $id);
        if (! $item) {
            abort(404);
        }

        try {
            $data = $request->except('user_id');
            $notify = ! empty($data['password_generate']);
            (new \Tobuli\Services\UserSecondaryCredentialsService())
                ->setNotifyUser($notify)->update($data, $item);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function destroySecondaryCredential(Request $request, $id): JsonResponse
    {
        $this->assertSecondaryAllowed();

        $item = $this->user->secondaryCredentials()->find((int) $id);
        if (! $item) {
            abort(404);
        }
        $item->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Enviar SMS de prueba por el gateway configurado (paridad legacy
     * SmsGatewayModalHelper::sendTestSms → SMSGatewayFormValidator +
     * SendTestSmsFormValidator + SMSGatewayManager).
     */
    public function testSms(Request $request): JsonResponse
    {
        if (! $this->user->perm('sms_gateway', 'edit')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        try {
            $this->assertNotDemo();

            $data = $request->all();
            // El gateway guardado completa lo que el modal no reenvía
            // (contraseña incluida: nunca vuelve al cliente).
            $data = array_merge((array) ($this->user->sms_gateway_params ?? []), array_filter(
                $data,
                function ($v) { return $v !== null && $v !== ''; }
            ));
            $data['sms_gateway_url'] = $request->get('sms_gateway_url', $this->user->sms_gateway_url);

            $helper = \CustomFacades\ModalHelpers\SmsGatewayModalHelper::getFacadeRoot();
            $helper->setUser($this->user);
            $helper->setData($data);
            $res = $helper->sendTestSms();
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (DemoAccountException $e) {
            return response()->json(['ok' => false, 'message' => trans('front.demo_account')], 403);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        // status 0 = el gateway respondió con un aviso/error de envío
        if (empty($res['status'])) {
            return response()->json([
                'ok'       => false,
                'warnings' => array_map('strval', (array) ($res['warnings'] ?? [])),
            ], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Vaciar la cola de SMS pendientes (paridad SmsGatewayModalHelper::clearQueue). */
    public function clearSmsQueue(Request $request): JsonResponse
    {
        if (! $this->user->perm('sms_gateway', 'edit')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        try {
            $this->assertNotDemo();
            SmsEventQueueRepo::deletewhere(['user_id' => $this->user->id]);
        } catch (DemoAccountException $e) {
            return response()->json(['ok' => false, 'message' => trans('front.demo_account')], 403);
        }

        return response()->json(['ok' => true]);
    }

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
                $current = (array) ($this->user->sms_gateway_params ?? []);
                $update['sms_gateway_params'] = [];
                foreach ($fields as $field) {
                    $value = $data[$field] ?? null;

                    // El input de contraseña se renderiza SIN valor (no se
                    // devuelve al cliente), así que siempre llega ''. Con
                    // `?? current` no bastaba: '' no es null y BORRABA la
                    // contraseña del gateway en cada guardado. Vacío = conservar.
                    if ($field === 'password' && ($value === null || $value === '')) {
                        $value = $current[$field] ?? '';
                    }

                    $update['sms_gateway_params'][$field] = $value ?? ($current[$field] ?? '');
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

    /* ================= Tablero (dashboard) ================= */

    public function updateDashboard(Request $request): JsonResponse
    {
        try {
            $this->assertNotDemo();

            $current = (array) $this->user->getSettings('dashboard');
            $in = (array) $request->get('dashboard', []);

            $current['enabled'] = ! empty($in['enabled']) ? 1 : 0;

            // Semilla del catálogo cuando el usuario NO tiene ajustes previos:
            // antes se iteraba sobre $current['blocks'] (vacío) y los toggles se
            // DESCARTABAN silenciosamente (solo persistía 'enabled').
            if (empty($current['blocks'])) {
                $current['blocks'] = (array) config('tobuli.dashboard.blocks', []);
            }

            $enabledBlocks = (array) ($in['blocks'] ?? []);
            $inOptions     = (array) ($in['options'] ?? []);

            foreach ((array) $current['blocks'] as $key => $cfg) {
                $cfg = (array) $cfg;
                $cfg['enabled'] = in_array($key, $enabledBlocks, true) ? 1 : 0;

                // Opciones por bloque (hoy: 'period' de latest_events /
                // latest_tasks). Se MEZCLAN para no perder las que la UI no
                // edita (colores de device_overview, devices de distance…).
                if (isset($inOptions[$key]) && is_array($inOptions[$key])) {
                    $opts = (array) ($cfg['options'] ?? []);
                    foreach ($inOptions[$key] as $ok => $ov) {
                        if ($ok === 'period' && ! in_array($ov, ['day', 'week', 'month'], true)) {
                            continue; // whitelist del trait HasPeriodOption
                        }
                        $opts[$ok] = $ov;
                    }
                    $cfg['options'] = $opts;
                }

                $current['blocks'][$key] = $cfg;
            }

            $this->user->setSettings('dashboard', $current, true);

            return response()->json(['ok' => true]);
        } catch (DemoAccountException $e) {
            return response()->json(['ok' => false, 'message' => trans('front.demo_account')], 403);
        }
    }

    /* ================= Mapa (preferencias del marcador) ================= */

    public function updateMap(Request $request): JsonResponse
    {
        try {
            $this->assertNotDemo();

            $mc = (array) $this->user->map_controls;
            $mc['m_show_speed']   = $request->boolean('show_speed') ? 1 : 0;
            $mc['m_status_color'] = $request->boolean('status_color') ? 1 : 0;

            UserRepo::update($this->user->id, ['map_controls' => $mc]);

            return response()->json(['ok' => true]);
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
