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
     * Preguntas al mes para el usuario: su limite propio (Admin > Talentus IA
     * CHAT > Limite por usuario), si no la cuota de su plan, si no la cuota
     * por defecto guardada en Admin, si no la de config/.env. 0 = sin limite.
     */
    public static function limit(User $user = null): int
    {
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

    public static function budgetExceeded(): bool
    {
        $budget = self::budget();

        if ($budget <= 0) {
            return false;
        }

        return (float) AiUsageLog::thisMonth()->sum('cost_usd') >= $budget;
    }
}
