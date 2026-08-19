<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\Device;
use Tobuli\Entities\Forward;
use Tobuli\Entities\User;

/**
 * TalentusPro · AdminController
 * Hub de admin/integraciones con deep-links a la UI legacy.
 */
class AdminController extends BaseController
{
    public function index(Request $request)
    {
        return View::make('ui.v2.pages.admin', [
            'pageTitle' => 'Admin & Integrations',
            'isAdmin'   => (bool) $this->user->isAdmin(),
            'isManager' => method_exists($this->user, 'isManager') ? (bool) $this->user->isManager() : false,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $user = $this->user;

        // --- API access ---
        $hasToken   = ! empty($user->api_hash);
        $expiresRaw = $user->api_hash_expire ?? null;
        $api = [
            'base_url'   => url('/api'),
            'has_token'  => $hasToken,
            'expires_at' => $expiresRaw ? (string) $expiresRaw : null,
            'login_url'  => url('/api/login'),
            'docs_url'   => url('/apidoc/'),
        ];

        // --- Webhooks (Forwards) ---
        $webhooks = ['count' => 0, 'active' => 0, 'by_type' => [], 'list' => []];
        try {
            if (class_exists(Forward::class)) {
                $rows = Forward::where('user_id', $user->id)
                    ->orderBy('id', 'desc')
                    ->limit(50)
                    ->get(['id', 'title', 'type', 'active']);

                $byType = [];
                $active = 0;
                $list   = [];
                foreach ($rows as $r) {
                    $type = (string) $r->type;
                    $byType[$type] = ($byType[$type] ?? 0) + 1;
                    if ((int) $r->active === 1) $active++;
                    $list[] = [
                        'id'     => (int) $r->id,
                        'title'  => (string) $r->title,
                        'type'   => $type,
                        'active' => (int) $r->active,
                    ];
                }

                $byTypeFlat = [];
                foreach ($byType as $t => $c) $byTypeFlat[] = ['type' => $t, 'count' => $c];

                $webhooks = [
                    'count'   => count($list),
                    'active'  => $active,
                    'by_type' => $byTypeFlat,
                    'list'    => array_slice($list, 0, 12),
                ];
            }
        } catch (\Throwable $e) {
        }

        // --- SSO (Azure) ---
        $sso = [
            'azure_enabled' => (bool) config('azure.tenant_id'),
            'tenant_id_set' => ! empty((string) config('azure.tenant_id', '')),
        ];

        // --- Branding ---
        $branding = [
            'app_name'    => (string) (function_exists('settings') ? settings('main_settings.title', config('app.name', 'TalentusPro')) : config('app.name', 'TalentusPro')),
            'theme_color' => '#122F71',
            'logo'        => (string) (function_exists('settings') ? settings('main_settings.logo', '') : ''),
        ];

        // --- Quick links (filtered by role) ---
        $isAdmin   = $user->isAdmin();
        $isManager = method_exists($user, 'isManager') ? $user->isManager() : false;
        $r = function ($name, $fallback) {
            return RouteFacade::has($name) ? route($name) : url($fallback);
        };

        $quick = [];
        if ($isAdmin) {
            $quick[] = ['key' => 'objects',  'label' => 'Dispositivos',     'desc' => 'Gestión global de equipos',         'url' => $r('admin.objects.index',              'users/objects'),                    'group' => 'admin'];
            // Alta masiva por CSV: se resuelve con el importador del panel clásico
            // (DeviceImportManager con opciones de colisión); no se duplica en v2.
            $quick[] = ['key' => 'import',   'label' => 'Importar equipos', 'desc' => 'Alta masiva por archivo',           'url' => $r('admin.objects.import',             'admin/objects/import'),             'group' => 'admin'];
            $quick[] = ['key' => 'plans',    'label' => 'Planes',           'desc' => 'Facturación y permisos',            'url' => $r('admin.billing.plans',              'admin/billing/plans'),              'group' => 'admin'];
            $quick[] = ['key' => 'billing',  'label' => 'Billing',          'desc' => 'Pasarelas y suscripciones',         'url' => $r('admin.billing.index',              'admin/billing/index'),              'group' => 'admin'];
            $quick[] = ['key' => 'settings', 'label' => 'Server Settings',  'desc' => 'Configuración global del servidor',  'url' => $r('admin.main_server_settings.index', 'users/main_server_settings/index'), 'group' => 'admin'];
            $quick[] = ['key' => 'email',    'label' => 'Email',            'desc' => 'SMTP y plantillas',                  'url' => $r('admin.email_settings.index',       'admin/email_settings/index'),       'group' => 'admin'];
            $quick[] = ['key' => 'events',   'label' => 'Catálogo eventos', 'desc' => 'Tipos y traducciones',               'url' => $r('admin.events.index',               'admin/events/index'),               'group' => 'admin'];
            $quick[] = ['key' => 'logs',     'label' => 'Logs',             'desc' => 'Auditoría y descargas',              'url' => $r('admin.logs.index',                 'admin/logs/index'),                 'group' => 'admin'];
        }
        if ($isManager || $isAdmin) {
            $quick[] = ['key' => 'clients',  'label' => 'Clientes',         'desc' => 'Sub-cuentas y dispositivos',        'url' => $r('admin.clients.index',              'users/clients'),                    'group' => 'manager'];
            $quick[] = ['key' => 'forwards', 'label' => 'Webhooks',         'desc' => 'Reenvío de posiciones y eventos',    'url' => $r('forwards.index',                   'forwards'),                         'group' => 'manager'];
        }

        $quick[] = ['key' => 'profile',      'label' => 'Mi cuenta',        'desc' => 'Perfil y contraseña',                'url' => $r('my_account.edit',                  'my_account/edit'),                  'group' => 'me'];
        $quick[] = ['key' => 'preferences',  'label' => 'Preferencias',     'desc' => 'Idioma, mapa, UI',                  'url' => $r('my_account_settings.edit',         'my_account_settings/edit'),         'group' => 'me'];
        $quick[] = ['key' => 'subscription', 'label' => 'Suscripción',      'desc' => 'Plan y renovación',                  'url' => $r('subscriptions.index',              'membership'),                       'group' => 'me'];
        $quick[] = ['key' => 'apidoc',       'label' => 'API Docs',         'desc' => 'Referencia REST',                    'url' => url('/apidoc/'),                                                            'group' => 'me'];

        // --- Counts (only admin) ---
        $counts = null;
        if ($isAdmin) {
            try {
                $counts = [
                    'users'   => (int) User::count(),
                    'devices' => (int) Device::count(),
                ];
            } catch (\Throwable $e) {
                $counts = null;
            }
        }

        return response()->json([
            'ok'       => true,
            'me'       => [
                'id'         => (int) $user->id,
                'email'      => (string) $user->email,
                'group'      => (int) ($user->group_id ?? 0),
                'is_admin'   => $isAdmin,
                'is_manager' => $isManager,
            ],
            'api'      => $api,
            'webhooks' => $webhooks,
            'sso'      => $sso,
            'branding' => $branding,
            'quick'    => $quick,
            'counts'   => $counts,
            'legacy'   => [
                'forwards'        => RouteFacade::has('forwards.index')  ? route('forwards.index')  : url('/forwards'),
                'forwards_create' => url('/forwards'),
                'user_settings'   => RouteFacade::has('my_account.edit') ? route('my_account.edit') : url('/my_account/edit'),
                'apidoc'          => url('/apidoc/'),
            ],
        ]);
    }
}
