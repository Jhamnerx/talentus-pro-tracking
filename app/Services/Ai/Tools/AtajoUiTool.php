<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\ToolException;
use Tobuli\Entities\Device;

/**
 * Atajos a funciones de la UI v2: agrega un boton que abre directamente el
 * formulario o panel (editar vehiculo, video en vivo, enviar comando,
 * compartir, servicios, regla...). No cambia datos: el usuario termina la
 * tarea en el formulario de la plataforma.
 *
 * El panel solo ejecuta atajos de una lista fija (tp-ai-chat.js SHORTCUTS);
 * aqui se valida el permiso y la unidad antes de ofrecer el boton.
 */
class AtajoUiTool extends AbstractTool
{
    /**
     * clave => [etiqueta del boton, requiere unidad, comprobacion de permiso]
     * La comprobacion recibe ($user, ?Device) y devuelve null o el motivo.
     */
    private function shortcuts(): array
    {
        $perm = function (string $module, string $mode = 'view') {
            return function ($user) use ($module, $mode) {
                return $user->perm($module, $mode) ? null : "El usuario no tiene permiso ($module).";
            };
        };

        return [
            // De una unidad (menu de tres puntos del vehiculo)
            'editar_unidad'      => ['Editar vehiculo', true, $perm('devices', 'edit')],
            'enviar_comando'     => ['Abrir enviar comando', true, $perm('send_command')],
            'video_en_vivo'      => ['Ver video en vivo', true, function ($user, $device) {
                if (!$user->perm('device_video', 'view')) {
                    return 'El usuario no tiene permiso de video.';
                }
                // Sin unidad (lista de atajos del usuario) solo cuenta el permiso.
                return !$device || $device->hasVideo() ? null : 'La unidad no tiene camara con video configurada.';
            }],
            'grabaciones_video'  => ['Ver grabaciones de video', true, function ($user, $device) {
                if (!$user->perm('device_video', 'view')) {
                    return 'El usuario no tiene permiso de video.';
                }
                // Sin unidad (lista de atajos del usuario) solo cuenta el permiso.
                return !$device || $device->hasVideo() ? null : 'La unidad no tiene camara con video configurada.';
            }],
            'fotos_camara'       => ['Ver fotos y medios', true, $perm('camera')],
            'alertas_unidad'     => ['Ver alertas de la unidad', true, $perm('alerts')],
            'configurar_equipo'  => ['Configurar equipo', true, function ($user) {
                return $user->able('configure_device') ? null : 'El usuario no puede configurar equipos.';
            }],
            'compartir_unidad'   => ['Compartir ubicacion', true, $perm('sharing')],
            'enlaces_compartidos' => ['Ver enlaces compartidos', true, $perm('sharing')],
            'servicios_unidad'   => ['Ver servicios', true, $perm('maintenance')],
            'nuevo_servicio'     => ['Anadir servicio', true, $perm('maintenance', 'edit')],
            'acciones_llamada'   => ['Registrar accion de llamada', true, function ($user) {
                return $user->perm('call_actions', 'view') && $user->perm('events', 'view')
                    ? null : 'El usuario no tiene permiso de acciones de llamada.';
            }],
            'tipos_recorrido'    => ['Tipos de recorrido', true, $perm('device_route_types')],

            // Herramientas del mapa y de la cuenta
            'nueva_unidad'       => ['Nuevo vehiculo', false, $perm('devices', 'edit')],
            'regla'              => ['Medir distancia', false, null],
            'mostrar_punto'      => ['Mostrar punto por coordenadas', false, null],
            'buscar_direccion'   => ['Buscar direccion', false, null],
            'unidades_sin_transmitir' => ['Unidades sin transmitir', false, $perm('devices')],
            'reenvios'           => ['Reenvios', false, function ($user) {
                return config('addon.forwards') && $user->perm('forwards', 'view') ? null : 'Reenvios no disponible.';
            }],
            'nuevo_reporte'      => ['Crear reporte', false, $perm('reports')],
            'ajustes_cuenta'     => ['Ajustes de cuenta', false, null],
            'cambiar_contrasena' => ['Cambiar contrasena', false, null],
            'idioma'             => ['Cambiar idioma', false, null],
        ];
    }

    /**
     * Solo los atajos que el usuario puede usar: el modelo no conoce los
     * demas, asi que tampoco los ofrece ni explica como hacerlos.
     */
    private function available(): array
    {
        if (!$this->user) {
            return $this->shortcuts();
        }

        return array_filter($this->shortcuts(), function ($item) {
            $check = $item[2];

            return !$check || $check($this->user, null) === null;
        });
    }

    /** Etiquetas de los atajos que este usuario NO puede usar (para el contexto). */
    public function denied(): array
    {
        return array_values(array_map(function ($item) {
            return $item[0];
        }, array_diff_key($this->shortcuts(), $this->available())));
    }

    public function name(): string
    {
        return 'atajo_ui';
    }

    public function description(): string
    {
        $device = $general = [];
        foreach ($this->available() as $key => $item) {
            if ($item[1]) {
                $device[] = $key;
            } else {
                $general[] = $key;
            }
        }

        return 'Agrega a tu respuesta un boton que abre directamente una funcion de la plataforma (UI v2), '
            . 'para que el usuario la use sin buscarla. Usalo cuando el usuario quiera hacer algo que se hace en un '
            . 'formulario o panel de la plataforma, o cuando ofrezcas un siguiente paso. Solo estan los atajos que '
            . 'este usuario tiene permitidos. Atajos de una unidad (piden "unidad"): '
            . (implode(', ', $device) ?: 'ninguno') . '. Atajos generales: ' . (implode(', ', $general) ?: 'ninguno')
            . '. enviar_comando abre el formulario; para enviarlo tu usa la accion. regla = medir distancia en el '
            . 'mapa. No cambia nada por si solo.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'atajo' => ['type' => 'string', 'enum' => array_keys($this->available())],
                'unidad' => [
                    'type' => 'string',
                    'description' => 'Nombre, placa o IMEI de la unidad, para los atajos de una unidad.',
                ],
            ],
            'required' => ['atajo'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return '';
    }

    protected function run(array $input): array
    {
        $shortcuts = $this->shortcuts();
        [$label, $needsDevice, $check] = $shortcuts[$input['atajo']];

        $device = null;
        if ($needsDevice) {
            if (empty($input['unidad'])) {
                throw new ToolException('Este atajo necesita la unidad. Pregunta al usuario cual.');
            }

            $ids = $this->resolveDeviceIds([$input['unidad']], false);
            if (count($ids) > 1) {
                throw new ToolException('Coinciden ' . count($ids) . ' unidades. Usa buscar_unidades y pregunta cual es.');
            }

            $device = Device::find($ids[0]);
        }

        if ($check && ($reason = $check($this->user, $device))) {
            throw new ToolException($reason);
        }

        $action = [
            'type' => 'shortcut',
            'shortcut' => $input['atajo'],
            'label' => $label . ($device ? ' · ' . trim($device->name) : ''),
        ];

        if ($device) {
            $action['device_id'] = $device->id;
            $action['device_name'] = trim($device->name);
        }

        $this->actions[] = $action;

        return ['boton_agregado' => $action['label']];
    }
}
