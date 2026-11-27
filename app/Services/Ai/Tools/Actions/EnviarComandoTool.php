<?php

namespace App\Services\Ai\Tools\Actions;

use App\Services\Ai\ToolException;
use Tobuli\Entities\Device;
use Tobuli\Entities\User;
use Tobuli\Services\Commands\CommandService;
use Tobuli\Services\Commands\SendCommandService;

class EnviarComandoTool extends ActionTool
{
    public function name(): string
    {
        return 'enviar_comando';
    }

    public function description(): string
    {
        return 'Prepara el envio de un comando GPRS a UNA unidad (por ejemplo corte o restablecimiento de motor, '
            . 'reinicio, pedir ubicacion). NO lo envia: muestra una tarjeta que el usuario debe confirmar. '
            . 'Si no sabes el codigo del comando, llama sin "comando" para recibir la lista disponible.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unidad' => ['type' => 'string', 'description' => 'Nombre, placa, IMEI o ID.'],
                'comando' => ['type' => 'string', 'description' => 'Codigo del comando (de la lista disponible).'],
            ],
            'required' => ['unidad'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return 'Comando · ' . ($input['unidad'] ?? '') . (!empty($input['comando']) ? ' · ' . $input['comando'] : '');
    }

    public static function allowed(User $user, array $params): bool
    {
        return (bool) $user->perm('send_command', 'view');
    }

    protected function prepare(array $input): array
    {
        if (!self::allowed($this->user, [])) {
            throw new ToolException('El usuario no tiene permiso para enviar comandos.');
        }

        $ids = $this->resolveDeviceIds([$input['unidad']], false);
        if (count($ids) > 1) {
            throw new ToolException('Coinciden ' . count($ids) . ' unidades. Usa buscar_unidades y pregunta cual es.');
        }

        $devices = $this->user->devices()->with('traccar')->where('devices.id', $ids[0])->get();
        $device = $devices->first();
        $commands = self::commandList($this->user, $devices);

        if (empty($input['comando']) || !isset($commands[$input['comando']])) {
            throw new ToolException((empty($input['comando']) ? '' : 'Comando no disponible para esta unidad. ')
                . 'Comandos disponibles (codigo: nombre): '
                . implode('; ', array_map(function ($code, $title) {
                    return "$code: $title";
                }, array_keys($commands), $commands)) . '. Pregunta al usuario cual enviar si no es claro.');
        }

        $details = [
            'Unidad: ' . $this->deviceLabel($device),
            'Comando: ' . $commands[$input['comando']],
            'Estado actual: ' . $this->speedNote($device),
        ];

        if (preg_match('/engineStop|corte|apagar\s*motor|bloque/i', $input['comando'] . ' ' . $commands[$input['comando']])) {
            $details[] = 'Atencion: corta el motor. Hazlo solo con el vehiculo detenido o a baja velocidad.';
        }

        return [
            'title' => 'Enviar comando "' . $commands[$input['comando']] . '" a ' . $this->deviceLabel($device),
            'details' => $details,
            'confirm' => 'Enviar comando',
            'params' => ['device_id' => $device->id, 'type' => $input['comando'], 'title' => $commands[$input['comando']]],
        ];
    }

    public static function execute(User $user, array $params): string
    {
        $devices = $user->devices()->with('traccar')->where('devices.id', $params['device_id'])->get();

        if ($devices->isEmpty()) {
            throw new ToolException('La unidad ya no esta disponible.');
        }

        $data = ['type' => $params['type']];

        (new CommandService($user))->validateGPRS($devices, $data);
        $responses = (new SendCommandService())->gprs($devices, $data, $user);

        $failed = collect($responses)->filter(function ($r) {
            return ($r['status'] ?? 0) == 0;
        });

        if ($failed->count()) {
            throw new ToolException('No se pudo enviar: ' . $failed->map(function ($r) {
                return ($r['error'] ?? 'error');
            })->implode('; '));
        }

        return 'Comando "' . $params['title'] . '" enviado. El resultado aparece en el registro de comandos.';
    }

    /** @return array codigo => nombre */
    /** @param \Illuminate\Database\Eloquent\Collection $devices (CommandService exige coleccion Eloquent) */
    private static function commandList(User $user, $devices): array
    {
        $list = [];

        foreach ((new CommandService($user))->getGprsCommands($devices) as $command) {
            $type = data_get($command, 'type');
            if ($type) {
                $list[$type] = (string) (data_get($command, 'title') ?: $type);
            }
        }

        return $list;
    }

    private function speedNote(Device $device): string
    {
        $speed = (float) $device->getSpeed();

        return $speed > 0 ? 'en movimiento a ' . $this->speed($speed) : 'detenida o sin datos recientes';
    }
}
