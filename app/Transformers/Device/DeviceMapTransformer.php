<?php

namespace App\Transformers\Device;

use Carbon\Carbon;
use Tobuli\Entities\Device;
use Tobuli\Sensors\Types\Blocked;
use Tobuli\Helpers\Formatter\Facades\Formatter;;

use Tobuli\Helpers\OdometerOptimizer;
use Illuminate\Support\Facades\App;

class DeviceMapTransformer extends DeviceTransformer
{

    protected $defaultIncludes = [
        'icon',
    ];

    protected static function requireLoads()
    {
        return ['icon', 'traccar', 'vehicleState', 'sensors' => function ($query) {
            $types = ['speed', 'anonymizer', 'odometer'];

            if (Blocked::isEnabled()) {
                $types[] = 'blocked';
            }

            $query->whereIn('type', $types);
        }];
    }

    /**
     * Config de color de marcador de la unidad, o null si no lo usa.
     *
     * Solo se incluye lo que difiere de los valores por defecto: si el
     * vehiculo no define rangos propios, el navegador usa los de la pagina.
     */
    protected function markerColors(Device $entity): ?array
    {
        if (! $entity->marker_colors_enabled) {
            return null;
        }

        $rangos = json_decode((string) ($entity->marker_speed_ranges ?? ''), true);

        return array_filter([
            'ranges' => is_array($rangos) && $rangos ? $rangos : null,
            'margin' => $entity->marker_geofence_margin !== null
                ? (int) $entity->marker_geofence_margin
                : null,
            'near'   => $entity->marker_geofence_near_color ?: null,
            'over'   => $entity->marker_geofence_over_color ?: null,
        ], function ($v) {
            return $v !== null;
        });
    }

    public function transform(Device $entity)
    {
        // Si los sensores no están cargados, los cargamos explícitamente
        if (!$entity->relationLoaded('sensors')) {
            $entity->load('sensors');
        }

        $inaccuracy = config('addon.inaccuracy')
            ? $entity->getParameter('inaccuracy')
            : null;

        $status = $entity->getStatus();

        // Estado del sensor blocked (por device): null = no tiene sensor, true =
        // bloqueado, false = desbloqueado. Alimenta el candado rojo/verde de la lista.
        $blockedState = null;
        if (Blocked::isEnabled()) {
            $blockedSensor = $entity->getSensorByType('blocked');
            if ($blockedSensor) {
                $blockedState = (bool) $blockedSensor->getValueCurrent($entity)->getValue();
            }
        }

        if ($entity->speed > 90) {
            $tailColor = '#FF0000'; // Rojo
        } elseif ($entity->speed > 60) {
            $tailColor = '#FFA500'; // Naranja
        } elseif ($entity->speed > 30) {
            $tailColor = '#008000'; // Verde
        } else {
            $tailColor = $entity->tail_color; // Azul
        }

        return [
            'id'    => (int)$entity->id,
            'name'  => $entity->name,
            'image' => $entity->image,
            'plate_number'  => $entity->plate_number,
            'device_model'  => $entity->device_model,
            'sim_number'    => $entity->sim_number,
            'imei'          => $entity->imei,
            'tail'  => $entity->tail,
            'tail_color' => $tailColor,
            'icon_color' => $entity->getStatusColor($status),
            'icon_colors' => $entity->icon_colors,
            'active' => $entity->pivot ? (bool)$entity->pivot->active : null,
            'group_id' => $entity->pivot ? (int)$entity->pivot->group_id : 0,
            'online' => $status,
            'blocked' => $blockedState,
            // Estado de vehículo (tipo con estados: VACÍO/CARGADO…) → badge junto
            // al nombre. label corto (fallback al name) + color (fallback gris).
            'vehicle_state' => ($entity->vehicle_state_id && $entity->vehicleState) ? [
                'name'  => (string) $entity->vehicleState->name,
                'label' => (string) ($entity->vehicleState->label ?: $entity->vehicleState->name),
                'color' => (string) ($entity->vehicleState->color ?: '#64748b'),
            ] : null,
            // Color del marcador por velocidad. Solo viaja si la unidad lo
            // tiene activado, para no engordar el feed de 1.400 vehiculos con
            // una config que casi nadie personaliza; los valores por defecto
            // los manda la pagina una sola vez (mapConfig.markerColors).
            'marker_colors' => $this->markerColors($entity),
            // Tipo y estado de vehiculo: el navegador los necesita para
            // resolver el limite de la geocerca, que puede diferenciarlos.
            'vehicle_type_id'  => $entity->vehicle_type_id ? (int) $entity->vehicle_type_id : null,
            'vehicle_state_id' => $entity->vehicle_state_id ? (int) $entity->vehicle_state_id : null,
            'lat' => $entity->lat,
            'lng' => $entity->lng,
            'speed' => $entity->speed,
            'speed_text' => Formatter::speed()->human($entity->getSpeed()),
            // Velocidad NUMERICA ya en la unidad del usuario. 'speed' va en la
            // unidad de almacenamiento y el limite de la geocerca llega
            // convertido: sin esto, el coloreado compararia km/h contra mph.
            'speed_value' => round((float) Formatter::speed()->convert($entity->getSpeed()), 1),
            'course' => $entity->course,
            'altitude' => $entity->altitude,
            'time' => $entity->time,
            'timestamp' => (int)$entity->timestamp,
            'acktimestamp' => (int)$entity->acktimestamp,
            'engine_status' => $entity->getEngineStatus(),
            'inaccuracy' => is_null($inaccuracy) ? null : intval($inaccuracy),

            // Video a bordo: lo necesita el widget "Video en vivo", que es una
            // sola caja compartida y reacciona al vehiculo seleccionado, asi
            // que no puede decidirlo en servidor como hace el menu del objeto.
            'video' => app(\Tobuli\Services\DeviceVideoService::class)->isEnabled($entity),

            'moved_timestamp'    => (int)$entity->moved_timestamp,
            'stop_duration_sec'  => $entity->getStopDuration(),
            'total_distance'     => $entity->getTotalDistance(),
        ];
    }
}
