<?php

namespace App\Services\Ai\Tools\Actions;

use App\Services\Ai\ToolException;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\User;
use Tobuli\Exceptions\ValidationException;

/**
 * Crea una alerta con la misma logica que el formulario de Alertas
 * (AlertModalHelper en modo API), despues de que el usuario confirma.
 */
class CrearAlertaTool extends ActionTool
{
    /** tipo => [tipo interno, campo del valor, etiqueta, unidad del valor] */
    const TYPES = [
        'exceso_velocidad'  => ['overspeed', 'overspeed', 'Exceso de velocidad', 'velocidad'],
        'entrada_geocerca'  => ['geofence_in', null, 'Entrada a geocerca', null],
        'salida_geocerca'   => ['geofence_out', null, 'Salida de geocerca', null],
        'parada_prolongada' => ['stop_duration', 'stop_duration', 'Parada prolongada', 'minutos'],
        'sin_conexion'      => ['offline_duration', 'offline_duration', 'Sin conexion prolongada', 'minutos'],
    ];

    public function name(): string
    {
        return 'crear_alerta';
    }

    public function description(): string
    {
        return 'Prepara una alerta nueva (avisos por notificacion en la plataforma y app, y opcionalmente correo). '
            . 'NO la crea: muestra una tarjeta que el usuario debe confirmar. Tipos: exceso_velocidad (valor = '
            . 'limite), entrada_geocerca / salida_geocerca (geocercas), parada_prolongada y sin_conexion (valor = minutos).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tipo' => ['type' => 'string', 'enum' => array_keys(self::TYPES)],
                'nombre' => ['type' => 'string', 'description' => 'Nombre de la alerta.'],
                'unidades' => $this->unitsProperty(),
                'valor' => ['type' => 'number', 'description' => 'Limite de velocidad o minutos segun el tipo.'],
                'geocercas' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Para alertas de geocerca.'],
                'correo' => ['type' => 'string', 'description' => 'Opcional. Correo que tambien recibe el aviso.'],
            ],
            'required' => ['tipo', 'nombre'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return 'Nueva alerta · ' . (self::TYPES[$input['tipo'] ?? ''][2] ?? '');
    }

    public static function allowed(User $user, array $params): bool
    {
        return (bool) $user->perm('alerts', 'edit');
    }

    protected function prepare(array $input): array
    {
        if (!self::allowed($this->user, [])) {
            throw new ToolException('El usuario no tiene permiso para crear alertas.');
        }

        [$type, $field, $label, $unit] = self::TYPES[$input['tipo']];
        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);

        $data = [
            'name' => mb_substr(trim($input['nombre']), 0, 100),
            'type' => $type,
            'devices' => $ids,
            'notifications' => [
                'popup' => ['active' => 1, 'input' => 10],
                'sound' => ['active' => 1, 'input' => 'assets/audio/hint.mp3'],
                'push' => ['active' => 1],
            ],
        ];

        $details = ['Tipo: ' . $label, 'Unidades: ' . (count($ids) > 3 ? count($ids) . ' unidades' : $this->unitsLabel($input))];

        if ($field) {
            if (empty($input['valor']) || $input['valor'] <= 0) {
                throw new ToolException("Falta el valor ($unit) para la alerta de $label.");
            }
            $data[$field] = (float) $input['valor'];
            $details[] = $unit === 'velocidad' ? 'Limite: ' . $input['valor'] . ' ' . \Formatter::speed()->getUnit()
                : 'Despues de: ' . $input['valor'] . ' minutos';
        }

        if (in_array($type, ['geofence_in', 'geofence_out'])) {
            $names = (array) ($input['geocercas'] ?? []);
            $geofences = $names ? Geofence::userAccessible($this->user)->where(function ($q) use ($names) {
                foreach ($names as $name) {
                    $q->orWhere('name', 'like', '%' . trim($name) . '%');
                }
            })->limit(50)->get() : collect();

            if ($geofences->isEmpty()) {
                throw new ToolException('Indica geocercas existentes para esta alerta.');
            }
            $data['geofences'] = $geofences->pluck('id')->all();
            $details[] = 'Geocercas: ' . $geofences->pluck('name')->implode(', ');
        }

        if (!empty($input['correo'])) {
            if (!filter_var($input['correo'], FILTER_VALIDATE_EMAIL)) {
                throw new ToolException('El correo no es valido.');
            }
            $data['notifications']['email'] = ['active' => 1, 'input' => $input['correo']];
            $details[] = 'Correo: ' . $input['correo'];
        }

        $details[] = 'Avisos: ventana emergente, sonido y notificacion push';

        return [
            'title' => 'Crear alerta "' . $data['name'] . '"',
            'details' => $details,
            'confirm' => 'Crear alerta',
            'params' => $data,
        ];
    }

    public static function execute(User $user, array $params): string
    {
        $helper = new \ModalHelpers\AlertModalHelper();
        $helper->setUser($user);
        $helper->setApi(true);
        $helper->setData($params);

        try {
            $helper->create();
        } catch (ValidationException $e) {
            throw new ToolException('No se pudo crear la alerta: ' . self::errorsText($e));
        }

        return 'Alerta "' . $params['name'] . '" creada y activa.';
    }
}
