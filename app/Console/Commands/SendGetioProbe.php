<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Entities\Device;
use Tobuli\Entities\SentCommand;
use Tobuli\Entities\User;
use Tobuli\Services\Commands\SendCommandService;

/**
 * DIAGNOSTICO / MITIGACION TEMPORAL - caso DOUT1 (buzzer FTC921).
 *
 * Envia periodicamente "getio" a los equipos vigilados, porque el reporte
 * periodico normal no refleja el estado real de DOUT1 (AVL179 desincronizado).
 * La respuesta del equipo dispara PositionResultRetrieved (via
 * PositionsWriter::proccess), que ya escucha AutoDisableDoutListener:
 * si detecta "DO1:1" registra el momento exacto y envia "setdigout 0"
 * automaticamente.
 *
 * Programado en Kernel.php cada 5 minutos.
 *
 * Uso manual:
 *   php artisan dout:probe-getio
 *   php artisan dout:probe-getio --imei=860693084599456 --imei=OTRO_IMEI
 */
class SendGetioProbe extends Command
{
    protected $signature = 'dout:probe-getio {--imei=* : IMEIs a consultar (por defecto, la lista interna)}';

    protected $description = 'Envia getio a los equipos vigilados por el caso DOUT1 para revelar el estado real de la salida';

    // TODO: agregar el resto de la flota FyM Maquinarias cuando se confirmen los IMEIs
    private array $defaultImeis = [
        '860693084599456',
    ];

    public function handle(): int
    {
        $imeis = $this->option('imei') ?: $this->defaultImeis;

        foreach ($imeis as $imei) {
            $device = Device::where('imei', $imei)->first();

            if (! $device) {
                $this->error("{$imei}: dispositivo no encontrado");
                continue;
            }

            if (! $device->isConnected()) {
                $this->warn("{$imei}: sin conexion GPRS ahora mismo, se omite");
                continue;
            }

            $user = $this->resolveActingUser($device);

            if (! $user) {
                $this->error("{$imei}: no se encontro un usuario con permiso para enviar comandos (revisar sent_commands)");
                continue;
            }

            $response = (new SendCommandService())
                ->gprs($device, ['type' => 'custom', 'data' => 'getio'], $user)
                ->first();

            if (! empty($response['status'])) {
                $this->info("{$imei}: getio enviado correctamente");
            } else {
                $this->error("{$imei}: fallo al enviar getio - " . ($response['error'] ?? 'desconocido'));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Reutiliza al ultimo usuario que ya envio un comando exitoso a este
     * equipo, en vez de hardcodear un usuario "sistema".
     */
    private function resolveActingUser(Device $device): ?User
    {
        $userId = SentCommand::query()
            ->where('device_imei', $device->imei)
            ->orderByDesc('created_at')
            ->value('user_id');

        return $userId ? User::find($userId) : null;
    }
}
