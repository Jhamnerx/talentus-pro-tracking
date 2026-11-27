<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiQuota;
use App\Services\Ai\AiSettings;
use Illuminate\Http\Request;
use Tobuli\Entities\AiResellerSetting;
use Tobuli\Entities\AiUsageLog;
use Tobuli\Entities\User;

/**
 * Talentus IA CHAT para un reseller: su propia clave de la API, modelos,
 * esfuerzo, cuotas de sus usuarios, su tope de gasto y el consumo de su
 * clave. El permiso para usar la clave principal lo decide el admin.
 */
class AiResellerController extends Controller
{
    public function index(Request $request)
    {
        $reseller = $this->reseller($request);
        $settings = AiResellerSetting::find($reseller->id);
        $config = AiSettings::configFor($reseller);
        $ownKey = (int) ($config['key_owner_id'] ?? 0) === $reseller->id;
        $users = $this->users($reseller);
        $quotas = (array) ($settings->user_quotas ?? []);

        $month = AiUsageLog::thisMonth()->where('key_owner_id', $reseller->id);

        $usage = $users->map(function (User $u) use ($quotas) {
            return (object) [
                'id' => $u->id,
                'email' => $u->email,
                'access' => (bool) $u->perm('ai_assistant', 'view'),
                'used' => AiQuota::used($u),
                'quota' => $quotas[$u->id] ?? '',
                'limit' => AiQuota::limit($u),
            ];
        });

        return view('admin::AiReseller.index', [
            'name' => AiSettings::name(),
            'settings' => $settings,
            'ownKey' => $ownKey,
            'maskedKey' => $ownKey ? '••••' . substr((string) $config['api_key'], -4) : '',
            'mainAllowed' => !$settings || $settings->allow_main_key,
            'active' => !empty($config['api_key']) && $config['enabled'],
            'models' => AiSettings::models(),
            'efforts' => AiSettings::EFFORTS,
            'users' => $usage,
            'spent' => AiQuota::spent($reseller->id),
            'byFeature' => (clone $month)->selectRaw('feature, COUNT(*) as calls, SUM(cost_usd) as cost')
                ->groupBy('feature')->get(),
            'byModel' => (clone $month)->selectRaw('model, COUNT(*) as calls, SUM(cost_usd) as cost')
                ->groupBy('model')->get(),
            'mainSpent' => (float) AiUsageLog::thisMonth()->whereNull('key_owner_id')
                ->whereIn('user_id', $users->pluck('id'))->sum('cost_usd'),
        ]);
    }

    public function save(Request $request)
    {
        $reseller = $this->reseller($request);
        $models = array_keys(AiSettings::models());

        $this->validate($request, [
            'api_key' => ['nullable', 'string', 'max:300', 'regex:/^sk-ant-[A-Za-z0-9_\-]+$/'],
            'model' => 'nullable|in:' . implode(',', $models),
            'light_model' => 'nullable|in:' . implode(',', $models),
            'effort' => 'nullable|in:' . implode(',', AiSettings::EFFORTS),
            'default_quota' => 'nullable|integer|min:0|max:100000',
            'monthly_budget_usd' => 'nullable|numeric|min:0|max:1000000',
            'user_quotas' => 'nullable|array',
            'user_quotas.*' => 'nullable|integer|min:0|max:100000',
        ], ['api_key.regex' => 'La clave de Anthropic empieza por sk-ant-.']);

        $settings = AiResellerSetting::find($reseller->id);

        $apiKey = $settings->api_key ?? null;
        if ($request->boolean('remove_api_key')) {
            $apiKey = null;
        } elseif (trim((string) $request->input('api_key')) !== '') {
            $apiKey = AiSettings::encryptKey(trim($request->input('api_key')));
        }

        // Solo se aceptan cuotas de sus propios usuarios.
        $own = $this->users($reseller)->pluck('id')->all();
        $quotas = [];
        foreach ((array) $request->input('user_quotas', []) as $userId => $value) {
            if ($value !== null && $value !== '' && in_array((int) $userId, $own, true)) {
                $quotas[(int) $userId] = (int) $value;
            }
        }

        $blank = function ($v) {
            return $v === null || $v === '';
        };

        AiSettings::saveReseller($reseller->id, [
            'api_key' => $apiKey,
            'model' => $request->input('model') ?: null,
            'light_model' => $request->input('light_model') ?: null,
            'effort' => $request->input('effort') ?: null,
            'default_quota' => $blank($request->input('default_quota')) ? null : (int) $request->input('default_quota'),
            'monthly_budget_usd' => $blank($request->input('monthly_budget_usd')) ? null : (float) $request->input('monthly_budget_usd'),
            'user_quotas' => $quotas,
            // allow_main_key no se toca aqui: lo decide el admin principal.
        ]);

        return redirect()->route('admin.ai_reseller.index')->with('success', trans('front.successfully_saved'));
    }

    private function reseller(Request $request): User
    {
        $user = $request->user();

        if (!$user || !$user->isReseller()) {
            abort(403);
        }

        return $user;
    }

    /** El reseller y sus usuarios (directos y los de sus operadores/supervisores). */
    private function users(User $reseller)
    {
        $direct = User::where('manager_id', $reseller->id)->pluck('id')->all();

        return User::where('id', $reseller->id)
            ->orWhereIn('id', $direct)
            ->orWhereIn('manager_id', $direct ?: [0])
            ->orderBy('email')
            ->get();
    }
}
