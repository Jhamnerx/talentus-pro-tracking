<?php

namespace App\Services\Ai;

use Tobuli\Entities\AiUsageLog;
use Tobuli\Entities\User;

/**
 * Cuotas del asistente: preguntas por usuario al mes y tope global en USD.
 */
class AiQuota
{
    /**
     * Preguntas al mes para el usuario. Si usa la clave de su reseller:
     * limite que el reseller le puso, si no la cuota por defecto del
     * reseller. Despues (o con la clave principal): su limite propio en Admin,
     * la cuota de su plan, la cuota por defecto de Admin y la de .env.
     * 0 = sin limite.
     */
    public static function limit(User $user = null): int
    {
        if ($user && ($ownerId = AiSettings::keyOwnerFor($user))) {
            $reseller = \Tobuli\Entities\AiResellerSetting::find($ownerId);
            $quotas = (array) ($reseller->user_quotas ?? []);
            if (isset($quotas[$user->id]) && $quotas[$user->id] !== '' && $quotas[$user->id] !== null) {
                return (int) $quotas[$user->id];
            }
            if ($reseller && $reseller->default_quota !== null) {
                return (int) $reseller->default_quota;
            }
        }

        $settings = self::settings();

        if ($user && isset($settings['user_quotas'][$user->id]) && $settings['user_quotas'][$user->id] !== '') {
            return (int) $settings['user_quotas'][$user->id];
        }

        if ($user && $user->billing_plan_id && isset($settings['plan_quotas'][$user->billing_plan_id])
            && $settings['plan_quotas'][$user->billing_plan_id] !== '') {
            return (int) $settings['plan_quotas'][$user->billing_plan_id];
        }

        if (isset($settings['default_quota']) && $settings['default_quota'] !== '') {
            return (int) $settings['default_quota'];
        }

        return (int) config('ai.monthly_questions_per_user', 0);
    }

    public static function budget(): float
    {
        $settings = self::settings();

        return (float) (isset($settings['monthly_budget_usd']) && $settings['monthly_budget_usd'] !== ''
            ? $settings['monthly_budget_usd']
            : config('ai.monthly_budget_usd', 0));
    }

    public static function settings(): array
    {
        try {
            return (array) (settings('ai_assistant') ?: []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function used(User $user): int
    {
        // Solo cuenta lo que el usuario pide (chat y explicar reporte), no lo automatico.
        return AiUsageLog::where('user_id', $user->id)
            ->thisMonth()
            ->whereIn('feature', ['chat', 'explain_report'])
            ->where('status', '!=', 'error')
            ->count();
    }

    /** null = sin limite */
    public static function remaining(User $user): ?int
    {
        $limit = self::limit($user);

        return $limit > 0 ? max(0, $limit - self::used($user)) : null;
    }

    /**
     * Tope de gasto del mes de quien paga las llamadas de este usuario: el
     * del reseller (sobre su clave) o el principal (sobre la clave principal).
     */
    public static function budgetExceeded(User $user = null): bool
    {
        $ownerId = $user ? AiSettings::keyOwnerFor($user) : null;

        if ($ownerId) {
            $budget = (float) (\Tobuli\Entities\AiResellerSetting::find($ownerId)->monthly_budget_usd ?? 0);

            return $budget > 0 && self::spent($ownerId) >= $budget;
        }

        $budget = self::budget();

        return $budget > 0 && self::spent(null) >= $budget;
    }

    /** Gasto del mes con una clave: la del reseller indicado o la principal (null). */
    public static function spent($keyOwnerId): float
    {
        $query = AiUsageLog::thisMonth();

        $keyOwnerId ? $query->where('key_owner_id', $keyOwnerId) : $query->whereNull('key_owner_id');

        return (float) $query->sum('cost_usd');
    }
}
