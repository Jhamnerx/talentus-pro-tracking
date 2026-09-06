<?php

namespace App\Listeners;

use App\Events\PositionResultRetrieved;
use Tobuli\Entities\SentCommand;
use Tobuli\Entities\User;
use Tobuli\Services\Commands\SendCommandService;

/**
 * DIAGNOSTICO / MITIGACION TEMPORAL - caso DOUT1 (buzzer FTC921).
 *
 * Se dispara con el mismo evento PositionResultRetrieved que ya usa
 * PositionResultRetrievedListener (llega cada vez que el equipo responde
 * a un comando GPRS, ej. la respuesta a un "getio": "DI1:1 AIN1:91 DO1:1").
 *
 * Si detecta DO1:1 para uno de los IMEIs vigilados, deja registro del
 * momento exacto (para poder validar despues frente al cliente/Teltonika)
 * y envia automaticamente "setdigout 0" para apagar la salida.
 *
 * No sustituye la causa raiz (el reporte periodico sigue sin reflejar el
 * estado real de DOUT1): esto solo apaga el sintoma en cuanto un getio
 * lo revela.
 */
class AutoDisableDoutListener
{
    // TODO: completar con los IMEIs de la flota con el problema conocido
    private array $watchedImeis = [
        '860693084599456',
    ];

    public function handle(PositionResultRetrieved $event): void
    {
        $device = $event->device;

        if (! in_array($device->imei, $this->watchedImeis, true)) {
            return;
        }

        if (! preg_match('/\bDO1:1\b/', $event->result)) {
            return;
        }

        $detectedAt = now();

        $this->log($device, $event->result, $detectedAt, 'DOUT1_ACTIVO_DETECTADO');

        $user = $this->resolveActingUser($device);

        if (! $user) {
            $this->log($device, $event->result, $detectedAt, 'NO_SE_ENCONTRO_USUARIO_PARA_ENVIAR_SETDIGOUT_0');
            return;
        }

        $response = (new SendCommandService())
            ->gprs($device, ['type' => 'custom', 'data' => 'setdigout 0'], $user)
            ->first();

        $ok = ! empty($response['status']);

        $this->log(
            $device,
            $event->result,
            $detectedAt,
            $ok ? 'SETDIGOUT_0_ENVIADO' : ('SETDIGOUT_0_FALLO: ' . ($response['error'] ?? 'desconocido'))
        );
    }

    /**
     * Reutiliza el mismo usuario que ya tiene permiso para mandar comandos
     * a este equipo (el ultimo que le envio uno exitosamente), en vez de
     * hardcodear un usuario "sistema".
     */
    private function resolveActingUser($device): ?User
    {
        $userId = SentCommand::query()
            ->where('device_imei', $device->imei)
            ->orderByDesc('created_at')
            ->value('user_id');

        return $userId ? User::find($userId) : null;
    }

    private function log($device, string $result, $detectedAt, string $note): void
    {
        $line = sprintf(
            "[%s] %s | IMEI=%s (%s) lat=%s lng=%s time=%s result=\"%s\"\n",
            $detectedAt->format('Y-m-d H:i:s'),
            $note,
            $device->imei,
            $device->name,
            $device->lat ?? 'N/A',
            $device->lng ?? 'N/A',
            $device->time ?? 'N/A',
            $result
        );

        file_put_contents(storage_path('logs/dout_auto_off.log'), $line, FILE_APPEND);
    }
}
