<?php

namespace Tobuli\Services\CommandRules;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use Tobuli\Entities\CommandRule;
use Tobuli\Entities\CommandRuleLog;
use Tobuli\Entities\Device;
use Tobuli\Services\Commands\SendCommandService;

/**
 * Evalua la respuesta de un equipo contra las normas que lo incluyen y envia
 * la accion de la primera condicion que coincida (o de todas, segun stop_on_match).
 */
class CommandRuleResponder
{
    /**
     * @return array<int, array> por norma: evaluated|skipped y las acciones enviadas
     */
    public function handle(Device $device, string $response, ?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now();
        $result = [];

        $rules = CommandRule::forDevice($device->id)->with(['conditions', 'user'])->get();

        foreach ($rules as $rule) {
            $result[$rule->id] = $this->evaluate($rule, $device, $response, $now);
        }

        return $result;
    }

    private function evaluate(CommandRule $rule, Device $device, string $response, Carbon $now): array
    {
        $probeKey = $rule->probeCacheKey($device->id);

        if (!$rule->evaluate_any_response && !Cache::has($probeKey)) {
            return ['status' => 'skipped', 'reason' => 'sin consulta pendiente'];
        }

        CommandRuleLog::record($rule, $device, CommandRuleLog::PHASE_RESPONSE, $response);

        $actions = [];

        foreach ($rule->conditions as $condition) {
            if (!ConditionEvaluator::matches($condition, $response)) {
                continue;
            }

            CommandRuleLog::record($rule, $device, CommandRuleLog::PHASE_MATCHED, $condition->describe(), $condition);

            $cooldownKey = $rule->cooldownCacheKey($condition->id, $device->id);

            if ($rule->cooldown_minutes > 0 && Cache::has($cooldownKey)) {
                $actions[] = ['condition' => $condition->id, 'sent' => false, 'reason' => 'enfriamiento'];
                if ($condition->stop_on_match) {
                    break;
                }
                continue;
            }

            $sent = $this->sendAction($rule, $condition, $device);
            $actions[] = ['condition' => $condition->id, 'sent' => $sent];

            if ($sent && $rule->cooldown_minutes > 0) {
                Cache::put($cooldownKey, $now->timestamp, $now->copy()->addMinutes($rule->cooldown_minutes));
            }

            if ($condition->stop_on_match) {
                break;
            }
        }

        // La consulta ya tuvo su respuesta: la siguiente respuesta no es de esta norma
        // (salvo evaluate_any_response).
        Cache::forget($probeKey);

        return ['status' => 'evaluated', 'actions' => $actions];
    }

    private function sendAction(CommandRule $rule, $condition, Device $device): bool
    {
        $user = $rule->user;
        if (!$user) {
            CommandRuleLog::record($rule, $device, CommandRuleLog::PHASE_ACTION_FAILED, 'norma sin usuario dueño', $condition);
            return false;
        }

        try {
            $resp = (new SendCommandService())
                ->gprs($device, ['type' => 'custom', 'data' => $condition->action_command], $user)
                ->first();
        } catch (Throwable $e) {
            $resp = ['status' => 0, 'error' => $e->getMessage()];
        }

        if (!empty($resp['status'])) {
            CommandRuleLog::record($rule, $device, CommandRuleLog::PHASE_ACTION_SENT, $condition->action_command, $condition);
            Log::channel('command_rules')->info('Accion enviada', [
                'rule_id' => $rule->id, 'device_id' => $device->id, 'command' => $condition->action_command,
            ]);
            return true;
        }

        CommandRuleLog::record($rule, $device, CommandRuleLog::PHASE_ACTION_FAILED, ($resp['error'] ?? 'error desconocido') . ' | ' . $condition->action_command, $condition);
        Log::channel('command_rules')->error('Accion fallida', [
            'rule_id' => $rule->id, 'device_id' => $device->id, 'command' => $condition->action_command, 'error' => $resp['error'] ?? null,
        ]);

        return false;
    }
}
