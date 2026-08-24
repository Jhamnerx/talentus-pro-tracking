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
            'pageTitle' => 'Administración e integraciones',
            'isAdmin'   => (bool) $this->user->isAdmin(),
            'isManager' => method_exists($this->user, 'isManager') ? (bool) $this->user->isManager() : false,
        ]);
    }

    /* =================================================================
     * Cumplimiento regulatorio
     * -----------------------------------------------------------------
     * Lee el resumen precalculado (tabla integration_status), NUNCA las
     * colas: son cientos de miles de filas y los jobs de envio estan
     * trabajando sobre ellas. El resumen lo refresca `integrations:status`
     * cada 10 minutos.
     * ================================================================= */
    public function integrations(Request $request): JsonResponse
    {
        try {
            if (! isAdmin()) {
                return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
            }

            $soloFallos = $request->boolean('failing');

            $filas = \DB::table('integration_status AS s')
                ->join('devices AS d', 'd.id', '=', 's.device_id')
                ->select([
                    's.device_id', 's.integration', 's.pending', 's.oldest_at',
                    's.last_at', 's.computed_at', 'd.name', 'd.plate_number',
                ])
                ->orderBy('s.pending', 'desc')
                ->orderBy('d.name')
                ->limit(2000)
                ->get();

            $items = [];
            $resumen = [];

            foreach ($filas as $f) {
                $estado = \Tobuli\Services\IntegrationStatusService::semaforo((int) $f->pending, $f->oldest_at);

                $resumen[$f->integration] = $resumen[$f->integration] ?? [
                    'integration' => $f->integration,
                    'devices' => 0, 'ok' => 0, 'curso' => 0, 'aviso' => 0, 'critico' => 0,
                    'pending' => 0,
                ];
                $resumen[$f->integration]['devices']++;
                $resumen[$f->integration][$estado]++;
                $resumen[$f->integration]['pending'] += (int) $f->pending;

                if ($soloFallos && in_array($estado, ['ok', 'curso'], true)) {
                    continue;
                }

                $items[] = [
                    'device_id'   => (int) $f->device_id,
                    'name'        => (string) $f->name,
                    'plate'       => (string) ($f->plate_number ?? ''),
                    'integration' => (string) $f->integration,
                    'pending'     => (int) $f->pending,
                    'oldest_at'   => $f->oldest_at ? \Formatter::time()->convert($f->oldest_at) : null,
                    'last_at'     => $f->last_at ? \Formatter::time()->convert($f->last_at) : null,
                    'status'      => $estado,
                ];
            }

            // Fallos recientes de los jobs de reenvio: la otra mitad de la
            // señal, porque una cola que no drena suele venir de aqui.
            $fallos = [];
            try {
                foreach (\DB::table('jobs_failed')->orderBy('failed_at', 'desc')->limit(200)->get() as $j) {
                    if (preg_match('/([A-Za-z]+)Job/', (string) $j->payload, $m)) {
                        $clase = $m[1];
                        $fallos[$clase] = ($fallos[$clase] ?? 0) + 1;
                    }
                }
            } catch (\Throwable $e) {
                $fallos = [];
            }

            $calculado = \DB::table('integration_status')->max('computed_at');

            return response()->json([
                'ok'          => true,
                'items'       => $items,
                'summary'     => array_values($resumen),
                'failed_jobs' => $fallos,
                'computed_at' => $calculado ? \Formatter::time()->convert($calculado) : null,
                'thresholds'  => [
                    'aviso'   => \Tobuli\Services\IntegrationStatusService::AVISO_MIN,
                    'critico' => \Tobuli\Services\IntegrationStatusService::CRITICO_MIN,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(
                ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
                $this->statusFor($e)
            );
        }
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
            // Soporte: entrar como un cliente (ClientsController@loginAs)
            $quick[] = ['key' => 'clients',  'label' => 'Clientes',         'desc' => 'Gestión y acceso como cliente',     'url' => $r('clients.index',                    'clients'),                          'group' => 'admin'];
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
