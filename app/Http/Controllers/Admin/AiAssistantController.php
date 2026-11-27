<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiQuota;
use App\Services\Ai\AiSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tobuli\Entities\AiActionLog;
use Tobuli\Entities\AiUsageLog;

/**
 * Admin > Talentus IA CHAT: nombre, proveedor (clave, modelos, esfuerzo),
 * modelo por plan, consumo del mes, costo diario, acciones confirmadas y
 * cuotas (por defecto, por plan y tope mensual en USD).
 */
class AiAssistantController extends Controller
{
    const FEATURES = [
        'chat' => 'Chat',
        'explain_report' => 'Explicar reporte',
        'report_email' => 'Resumen en correos',
        'dashboard' => 'Resumen del tablero',
        'alerts_digest' => 'Resumen diario de alertas',
    ];

    public function index()
    {
        AiSettings::apply();

        $monthStart = date('Y-m-01 00:00:00');
        $month = AiUsageLog::where('ai_usage_logs.created_at', '>=', $monthStart);

        $byFeature = (clone $month)
            ->selectRaw('feature, COUNT(*) as calls, SUM(cost_usd) as cost')
            ->groupBy('feature')->get()->keyBy('feature');

        $byModel = (clone $month)
            ->selectRaw('model, COUNT(*) as calls, SUM(cost_usd) as cost')
            ->groupBy('model')->orderByDesc('cost')->get();

        // "payer": de quien es la clave (NULL = principal, si no el correo del reseller).
        $byUser = (clone $month)
            ->leftJoin('users', 'users.id', '=', 'ai_usage_logs.user_id')
            ->leftJoin('users as owners', 'owners.id', '=', 'ai_usage_logs.key_owner_id')
            ->selectRaw("users.email, owners.email as payer, SUM(CASE WHEN feature IN ('chat','explain_report') THEN 1 ELSE 0 END) as questions, SUM(cost_usd) as cost")
            ->groupBy('users.email', 'owners.email')->orderByDesc('cost')->limit(20)->get();

        // Resellers: clave propia, permiso para usar la principal y consumo del mes.
        $resellerSettings = \Tobuli\Entities\AiResellerSetting::all()->keyBy('user_id');
        $resellers = \Tobuli\Entities\User::where('group_id', 3)->orderBy('email')->get(['id', 'email'])
            ->map(function ($r) use ($resellerSettings) {
                $s = $resellerSettings->get($r->id);

                return (object) [
                    'id' => $r->id,
                    'email' => $r->email,
                    'own_key' => (bool) ($s->api_key ?? null),
                    'allow_main' => !$s || $s->allow_main_key,
                    'spent_own' => AiQuota::spent($r->id),
                ];
            });

        $daily = AiUsageLog::where('created_at', '>=', date('Y-m-d 00:00:00', strtotime('-29 days')))
            ->selectRaw('DATE(created_at) as day, SUM(cost_usd) as cost, COUNT(*) as calls')
            ->groupBy('day')->orderBy('day')->get();

        $actions = AiActionLog::with('user:id,email')->orderByDesc('id')->limit(50)->get();

        $plans = DB::table('billing_plans')->orderBy('title')->get(['id', 'title']);

        $usersWithAccess = DB::table('user_permissions')->where('name', 'ai_assistant')->where('view', 1)->count();
        $plansWithAccess = DB::table('billing_plan_permissions')->where('name', 'ai_assistant')->where('view', 1)->pluck('plan_id')->all();

        // Excepciones de limite por usuario, con lo usado este mes.
        $userQuotaMap = (array) (AiQuota::settings()['user_quotas'] ?? []);
        $userQuotas = \Tobuli\Entities\User::whereIn('id', array_keys($userQuotaMap) ?: [0])
            ->orderBy('email')->get(['id', 'email'])
            ->map(function ($u) use ($userQuotaMap) {
                return (object) ['id' => $u->id, 'email' => $u->email,
                    'quota' => (int) $userQuotaMap[$u->id], 'used' => AiQuota::used($u)];
            });

        $provider = AiSettings::stored();
        unset($provider['api_key']); // nunca llega a la vista

        return view('admin::AiAssistant.index', [
            'configured' => AiSettings::isConfigured(),
            'assistantName' => AiSettings::name(),
            'model' => config('ai.model'),
            'lightModel' => config('ai.light_model'),
            'effort' => config('ai.effort'),
            'models' => AiSettings::models(),
            'efforts' => AiSettings::EFFORTS,
            'provider' => $provider,
            'keySource' => AiSettings::keySource(),
            'maskedKey' => AiSettings::maskedKey(),
            'features' => self::FEATURES,
            'byFeature' => $byFeature,
            'byModel' => $byModel,
            'totalCost' => AiQuota::spent(null),
            'resellerCost' => (float) (clone $month)->whereNotNull('key_owner_id')->sum('cost_usd'),
            'resellers' => $resellers,
            'totalErrors' => (clone $month)->where('status', 'error')->count(),
            'byUser' => $byUser,
            'daily' => $daily,
            'actions' => $actions,
            'plans' => $plans,
            'plansWithAccess' => $plansWithAccess,
            'usersWithAccess' => $usersWithAccess,
            'settings' => AiQuota::settings(),
            'userQuotas' => $userQuotas,
            'defaults' => [
                'quota' => (int) config('ai.monthly_questions_per_user'),
                'budget' => (float) config('ai.monthly_budget_usd'),
            ],
        ]);
    }

    public function save(Request $request)
    {
        if ($request->input('section') === 'provider') {
            return $this->saveProvider($request);
        }

        if ($request->input('section') === 'user_quotas') {
            return $this->saveUserQuotas($request);
        }

        if ($request->input('section') === 'resellers') {
            // Solo el permiso de usar la clave principal; la clave y los
            // modelos los maneja cada reseller en su panel.
            $allowed = array_map('intval', (array) $request->input('allow_main', []));
            foreach (\Tobuli\Entities\User::where('group_id', 3)->pluck('id') as $id) {
                \Tobuli\Entities\AiResellerSetting::updateOrCreate(['user_id' => $id],
                    ['allow_main_key' => in_array($id, $allowed, true)]);
            }
            AiSettings::forget();

            return redirect()->route('admin.ai_assistant.index')->with('success', trans('front.successfully_saved'));
        }

        $this->validate($request, [
            'default_quota' => 'nullable|integer|min:0|max:100000',
            'monthly_budget_usd' => 'nullable|numeric|min:0|max:1000000',
            'plan_quotas' => 'nullable|array',
            'plan_quotas.*' => 'nullable|integer|min:0|max:100000',
        ]);

        $planQuotas = [];
        foreach ((array) $request->input('plan_quotas', []) as $planId => $value) {
            if ($value !== null && $value !== '') {
                $planQuotas[(int) $planId] = (int) $value;
            }
        }

        $blank = function ($value) {
            return $value === null || $value === '';
        };

        settings('ai_assistant', [
            'default_quota' => $blank($request->input('default_quota')) ? '' : (int) $request->input('default_quota'),
            'monthly_budget_usd' => $blank($request->input('monthly_budget_usd')) ? '' : (float) $request->input('monthly_budget_usd'),
            'plan_quotas' => $planQuotas,
            // Se guardan aparte (saveUserQuotas): aqui solo se conservan.
            'user_quotas' => (array) (AiQuota::settings()['user_quotas'] ?? []),
        ]);

        return redirect()->route('admin.ai_assistant.index')->with('success', trans('front.successfully_saved'));
    }

    /**
     * Limite propio de preguntas por usuario: manda sobre el del plan y el
     * general. Valor vacio en una fila = quitar la excepcion.
     */
    private function saveUserQuotas(Request $request)
    {
        $this->validate($request, [
            'user_quotas' => 'nullable|array',
            'user_quotas.*' => 'nullable|integer|min:0|max:100000',
            'new_email' => 'nullable|email',
            'new_quota' => 'nullable|required_with:new_email|integer|min:0|max:100000',
        ], [], ['new_email' => 'correo', 'new_quota' => 'preguntas al mes']);

        $quotas = [];
        foreach ((array) $request->input('user_quotas', []) as $userId => $value) {
            if ($value !== null && $value !== '') {
                $quotas[(int) $userId] = (int) $value;
            }
        }

        if ($request->filled('new_email')) {
            $user = \Tobuli\Entities\User::where('email', trim($request->input('new_email')))->first(['id']);
            if (!$user) {
                return redirect()->route('admin.ai_assistant.index')
                    ->withErrors(['new_email' => 'No existe un usuario con ese correo.'])->withInput();
            }
            $quotas[$user->id] = (int) $request->input('new_quota');
        }

        $settings = AiQuota::settings();
        $settings['user_quotas'] = $quotas;
        settings('ai_assistant', $settings);

        return redirect()->route('admin.ai_assistant.index')->with('success', trans('front.successfully_saved'));
    }

    /** Nombre, interruptor, clave de la API, modelos, esfuerzo y modelo por plan. */
    private function saveProvider(Request $request)
    {
        $models = array_keys(AiSettings::models());

        $this->validate($request, [
            'name' => 'nullable|string|max:40',
            'enabled' => 'nullable|in:0,1',
            'api_key' => ['nullable', 'string', 'max:300', 'regex:/^sk-ant-[A-Za-z0-9_\-]+$/'],
            'model' => 'nullable|in:' . implode(',', $models),
            'light_model' => 'nullable|in:' . implode(',', $models),
            'effort' => 'nullable|in:' . implode(',', AiSettings::EFFORTS),
            'plan_models' => 'nullable|array',
            'plan_models.*' => 'nullable|in:' . implode(',', $models),
        ], [
            'api_key.regex' => 'La clave de Anthropic empieza por sk-ant-.',
        ]);

        $stored = AiSettings::stored();

        // La clave vacia conserva la guardada; "borrar" vuelve a la del .env.
        $apiKey = $stored['api_key'] ?? '';
        if ($request->boolean('remove_api_key')) {
            $apiKey = '';
        } elseif (trim((string) $request->input('api_key')) !== '') {
            $apiKey = AiSettings::encryptKey(trim($request->input('api_key')));
        }

        $planModels = [];
        foreach ((array) $request->input('plan_models', []) as $planId => $model) {
            if ($model) {
                $planModels[(int) $planId] = $model;
            }
        }

        AiSettings::save([
            'name' => trim((string) $request->input('name')),
            'enabled' => $request->input('enabled', ''),
            'api_key' => $apiKey,
            'model' => (string) $request->input('model'),
            'light_model' => (string) $request->input('light_model'),
            'effort' => (string) $request->input('effort'),
            'plan_models' => $planModels,
        ]);

        return redirect()->route('admin.ai_assistant.index')->with('success', trans('front.successfully_saved'));
    }
}
