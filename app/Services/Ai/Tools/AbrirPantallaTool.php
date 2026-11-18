<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\ToolException;

/**
 * No consulta datos: agrega un boton "Ir a ..." en la respuesta. El panel
 * solo ejecuta pantallas de una lista fija (no se envia JS desde el servidor).
 */
class AbrirPantallaTool extends AbstractTool
{
    /** clave => [etiqueta, permiso requerido, ruta de la UI v2] */
    const SCREENS = [
        'unidades'           => ['Mapa en vivo', null, '/ui/v2/live'],
        'flota'              => ['Flota', null, '/ui/v2/fleet'],
        'monitor'            => ['Monitor', null, '/ui/v2/monitor'],
        'dashboard'          => ['Tablero', null, '/ui/v2/dashboard'],
        'eventos'            => ['Eventos', 'events', '/ui/v2/events'],
        'alertas'            => ['Alertas', 'alerts', '/ui/v2/alerts'],
        'historial'          => ['Historial', 'history', '/ui/v2/history'],
        'reportes'           => ['Reportes', 'reports', '/ui/v2/reports'],
        'geocercas'          => ['Geocercas', 'geofences', '/ui/v2/live?panel=zones'],
        'rutas'              => ['Rutas', 'routes', '/ui/v2/live?panel=routes'],
        'pois'               => ['POI', 'poi', '/ui/v2/live?panel=pois'],
        'enviar_comando'     => ['Enviar comando', 'send_command', '/ui/v2/commands'],
        'compartir'          => ['Compartir', 'sharing', '/ui/v2/sharing'],
        'mantenimiento'      => ['Mantenimiento', 'maintenance', '/ui/v2/maintenance'],
        'conductores'        => ['Conductores', 'drivers', '/ui/v2/drivers'],
        'gastos'             => ['Gastos', 'device_expenses', '/ui/v2/expenses'],
        'tareas'             => ['Tareas', 'tasks', '/ui/v2/tasks'],
        'operaciones'        => ['Operaciones', 'operations', '/ui/v2/operations'],
        'seguridad'          => ['Seguridad', 'security', '/ui/v2/security'],
        'programacion_rutas' => ['Programacion de rutas', 'routes', '/ui/v2/route-schedules'],
    ];

    public function name(): string
    {
        return 'abrir_pantalla';
    }

    public function description(): string
    {
        return 'Agrega a tu respuesta un boton "Ir a <pantalla>" que el usuario puede pulsar. Usalo al explicar '
            . 'como hacer algo o cuando el usuario quiera ver el detalle. Pantallas: '
            . implode(', ', array_keys($this->available())) . '. Solo estan las que este usuario puede abrir.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pantalla' => ['type' => 'string', 'enum' => array_keys($this->available())],
            ],
            'required' => ['pantalla'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return '';
    }

    protected function run(array $input): array
    {
        [$label, $perm, $path] = self::SCREENS[$input['pantalla']];

        if ($perm && !$this->hasPerm($perm)) {
            throw new ToolException("El usuario no tiene permiso para la pantalla $label.");
        }

        $this->actions[] = [
            'type'   => 'screen',
            'screen' => $input['pantalla'],
            'url'    => url($path),
            'label'  => 'Ir a ' . $label,
        ];

        return ['boton_agregado' => 'Ir a ' . $label];
    }

    /** Pantallas que el usuario puede abrir (las demas el modelo ni las ve). */
    private function available(): array
    {
        if (!$this->user) {
            return self::SCREENS;
        }

        return array_filter(self::SCREENS, function ($screen) {
            return !$screen[1] || $this->hasPerm($screen[1]);
        });
    }

    private function hasPerm(string $perm): bool
    {
        try {
            return (bool) $this->user->perm($perm, 'view');
        } catch (\Throwable $e) {
            return true;
        }
    }
}
