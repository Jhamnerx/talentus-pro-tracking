<?php

namespace App\Listeners;

use App\Events\PositionResultRetrieved;
use Illuminate\Support\Facades\Log;
use Throwable;
use Tobuli\Services\CommandRules\CommandRuleResponder;

/**
 * Cada respuesta de un equipo a un comando GPRS (PositionResultRetrieved, ej.
 * "DI1:1 AIN1:91 DO1:1" tras un getio) se evalua contra las normas de comando
 * que incluyen a ese vehiculo. Reemplaza a AutoDisableDoutListener.
 */
class CommandRuleResponseListener
{
    public function handle(PositionResultRetrieved $event): void
    {
        try {
            (new CommandRuleResponder())->handle($event->device, (string) $event->result);
        } catch (Throwable $e) {
            // Nunca romper la escritura de posiciones por una norma mal configurada.
            Log::channel('command_rules')->error('Error evaluando normas de comando', [
                'device_id' => $event->device->id ?? null,
                'result'    => $event->result,
                'message'   => $e->getMessage(),
            ]);
        }
    }
}
