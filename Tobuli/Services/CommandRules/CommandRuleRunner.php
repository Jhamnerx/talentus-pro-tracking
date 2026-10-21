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
 * Ejecuta las normas vencidas: envia el comando de consulta a cada vehiculo
 * y deja un marcador en cache para que CommandRuleResponder sepa que la
 * siguiente respuesta del equipo corresponde a esta norma.
 */
class CommandRuleRunner
{
    public const LOG_RETENTION_DAYS = 30;

    /**
     * @return array<int, array> estadisticas por norma
     */
    public function runDue(?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now();
        $stats = [];

        $rules = CommandRule::due($now)->with('user')->get();

        foreach ($rules as $rule) {
            $stats[$rule->id] = $this->run($rule, $now);
        }

        $this->pruneLogs($now);

        return $stats;
    }

    /**
     * Envia la consulta de una norma a todos sus vehiculos (ignora si esta vencida).
     *
     * @return array{rule:string, sent:int, failed:int, skipped:int}
     */
    public function run(CommandRule $rule, ?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now();
        $stats = ['rule' => $rule->name, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        // Se marca antes de enviar para que dos ejecuciones solapadas no dupliquen.
        $rule->forceFill(['last_run_at' => $now])->save();

        $user = $rule->user;
        if (!$user) {
            Log::channel('command_rules')->error('Norma sin usuario dueño; no se envia', ['rule_id' => $rule->id]);
            return $stats;
        }

        $devices = $rule->devices()->get();

        foreach ($devices as $device) {
            if ($rule->only_connected && !$device->isConnected()) {
                $stats['skipped']++;
                CommandRuleLog::record($rule, $device, CommandRuleLog::PHASE_PROBE_SKIPPED, 'sin conexion GPRS');
                continue;
            }

            try {
                $response = (new SendCommandService())
                    ->gprs($device, ['type' => 'custom', 'data' => $rule->query_command], $user)
                    ->first();
            } catch (Throwable $e) {
                $response = ['status' => 0, 'error' => $e->getMessage()];
            }

            if (!empty($response['status'])) {
                $stats['sent']++;
                Cache::put($rule->probeCacheKey($device->id), $now->timestamp, $now->copy()->addMinutes($rule->probeWindowMinutes()));
                CommandRuleLog::record($rule, $device, CommandRuleLog::PHASE_PROBE_SENT, $rule->query_command);
            } else {
                $stats['failed']++;
                CommandRuleLog::record($rule, $device, CommandRuleLog::PHASE_PROBE_FAILED, $response['error'] ?? 'error desconocido');
            }
        }

        Log::channel('command_rules')->info('Norma ejecutada', ['rule_id' => $rule->id] + $stats);

        return $stats;
    }

    /** Borra bitacora antigua. Un DELETE acotado por fecha, con indice en created_at. */
    public function pruneLogs(?Carbon $now = null): int
    {
        $limit = ($now ?: Carbon::now())->copy()->subDays(self::LOG_RETENTION_DAYS);

        return CommandRuleLog::where('created_at', '<', $limit)->limit(5000)->delete();
    }
}
