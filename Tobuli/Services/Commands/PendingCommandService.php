<?php namespace Tobuli\Services\Commands;

use Tobuli\Entities\PendingCommand;
use Tobuli\Entities\User;
use Tobuli\Entities\UserGprsTemplate;

/**
 * Cola «enviar cuando esté en línea» (pending_commands). La comparten la UI v2
 * (Ui\V2\CommandsController) y la API de la app (Api\V1\SendCommandController):
 * los equipos desconectados se encolan y commands:dispatch-pending los envía
 * cuando vuelven a conectarse.
 */
class PendingCommandService
{
    /**
     * Encola el comando GPRS para los equipos que NO están conectados (mismo
     * criterio que SendCommandService: isConnected).
     *
     * @param  iterable  $devices     equipos ya filtrados por acceso del usuario
     * @param  array     $parameters  parámetros del comando, sin equipos ni datos de sesión
     * @return array{queued: string[], online: int[]}  nombres encolados e ids conectados
     */
    public function queueOffline(User $user, $devices, string $type, array $parameters): array
    {
        $queued = [];
        $online = [];

        foreach ($devices as $device) {
            if ($device->isConnected()) {
                $online[] = (int) $device->id;
                continue;
            }

            PendingCommand::create([
                'user_id'    => $user->id,
                'device_id'  => $device->id,
                'connection' => SendCommandService::CONNECTION_GPRS,
                'command'    => $type,
                'parameters' => $parameters,
            ]);

            $queued[] = (string) $device->name;
        }

        return ['queued' => $queued, 'online' => $online];
    }

    /** Comandos en cola del usuario, del más reciente al más antiguo. */
    public function listFor(User $user, ?int $deviceId = null): array
    {
        $query = PendingCommand::pending()
            ->where('user_id', $user->id)
            ->with('device:id,name')
            ->orderBy('id', 'desc')
            ->limit(500);

        if ($deviceId) {
            $query->where('device_id', $deviceId);
        }

        $items = $query->get();

        // Las plantillas se guardan como «template_<id>»: se muestra su título.
        $templateIds = [];
        foreach ($items as $pending) {
            if (preg_match('/^template_(\d+)$/', (string) $pending->command, $m)) {
                $templateIds[] = (int) $m[1];
            }
        }
        $templates = $templateIds
            ? UserGprsTemplate::whereIn('id', array_unique($templateIds))->pluck('title', 'id')
            : collect();

        $rows = [];

        foreach ($items as $pending) {
            $params = [];
            foreach ((array) ($pending->parameters ?? []) as $key => $value) {
                $params[] = $key . ': ' . (is_scalar($value) ? $value : json_encode($value));
            }

            $rows[] = [
                'id'         => (int) $pending->id,
                'device_id'  => (int) $pending->device_id,
                'device'     => (string) ($pending->device->name ?? '—'),
                'command'    => (string) $pending->command,
                'title'      => preg_match('/^template_(\d+)$/', (string) $pending->command, $m)
                    ? (string) ($templates[(int) $m[1]] ?? $pending->command)
                    : (string) $pending->command,
                'parameters' => implode(', ', $params),
                'attempts'   => (int) $pending->attempts,
                'last_error' => (string) ($pending->last_error ?? ''),
                'date'       => \Formatter::time()->human($pending->created_at),
            ];
        }

        return $rows;
    }

    /** Cancela un comando en cola del usuario. false si no existe o no es suyo. */
    public function cancel(User $user, int $id): bool
    {
        $pending = PendingCommand::pending()->find($id);

        if (! $pending || $pending->user_id != $user->id) {
            return false;
        }

        $pending->delete();

        return true;
    }
}
