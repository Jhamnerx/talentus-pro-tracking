<?php

namespace App\Transformers\ApiV1;

use App\Transformers\BaseTransformer;
use Formatter;
use Carbon\Carbon;
use Tobuli\Entities\Device;
use Tobuli\Helpers\OdometerOptimizer;
use Illuminate\Support\Facades\App;

/**
 * Slim transformer for v2/get_devices (initial load).
 *
 * Eliminates the ~3-5 KB `device_data` blob sent by DeviceFullTransformer.
 * Only fields actually used by the React app are included.
 * Elevates tail_color and uniqueId to top level so device_data is not needed.
 */
class DeviceSlimTransformer extends BaseTransformer
{
    protected static function requireLoads()
    {
        return ['icon', 'traccar', 'sensors', 'services', 'driver'];
    }

    public function transform(Device $entity)
    {
        $driver = $entity->driver;
        $classification = $this->classification($entity);

        $odometerOptimizer = App::make(OdometerOptimizer::class);
        $odometerValues    = $odometerOptimizer->getOdometerValues($entity);

        $sim_expiration_date = settings('plugins.additional_installation_fields.status')
            ? $this->canView($entity, 'sim_expiration_date')
            : null;

        return [
            'id'           => intval($entity->id),
            'name'         => $entity->name,
            'plate_number' => $entity->plate_number,
            'device_model' => $entity->device_model,
            'sim_number'   => $entity->sim_number,
            'imei'         => $entity->imei,
            // uniqueId from traccar — used as IMEI fallback in the app
            'uniqueId'     => $entity->traccar ? $entity->traccar->uniqueId : null,
            'group_id'     => intval($entity->pivot->group_id ?? 0),
            'active'       => intval($entity->pivot->active ?? 0),

            'alarm'        => is_null($this->user->alarm) ? 0 : $this->user->alarm,
            'online'       => $entity->getStatus(),
            'time'         => $entity->time,
            'timestamp'    => $entity->timestamp,
            'acktimestamp' => $entity->ack_timestamp,

            'lat'      => floatval($entity->lat),
            'lng'      => floatval($entity->lng),
            'course'   => $entity->course ?? '-',
            'speed'    => $entity->speed,
            'altitude' => $entity->altitude,

            'icon_type'   => $entity->icon->type,
            'icon_color'  => $entity->getStatusColor(),
            'icon_colors' => $entity->icon_colors,
            'icon'        => [
                'id'     => $entity->icon->id,
                'type'   => $entity->icon->type,
                'width'  => $entity->icon->width,
                'height' => $entity->icon->height,
                'path'   => $entity->icon->path,
            ],

            'driver'      => ($driver ? $driver->name : '-'),
            'driver_data' => $driver ? $driver->only(['id', 'user_id', 'device_id', 'name', 'rfid', 'phone', 'email', 'description', 'created_at', 'updated_at']) : [
                'id' => null, 'user_id' => null, 'device_id' => null, 'name' => null,
                'rfid' => null, 'phone' => null, 'email' => null, 'description' => null,
                'created_at' => null, 'updated_at' => null,
            ],

            // Canales de video JT1078 (como DeviceFullTransformer): [] sin video o sin el
            // permiso device_video. La app muestra el botón de cámara solo si no está vacío.
            'camera_channels' => $entity->hasVideo() && $this->canView($entity, 'camera_channels')
                ? array_values(array_map('intval', (array)$entity->camera_channels))
                : [],

            // Clasificación (tipo y estado) del usuario actuante, como DeviceMapTransformer:
            // la app la usa para el badge de estado y el límite por tipo/estado en geocercas.
            // getDevicesV2 la carga por lotes (Device::classificationRelationFor).
            'vehicle_type_id'  => $classification['vehicle_type_id'],
            'vehicle_state_id' => $classification['vehicle_state_id'],
            'vehicle_state'    => $classification['vehicle_state'],

            // Color del marcador por velocidad (como DeviceMapTransformer): null si la unidad no
            // lo tiene activado; los valores por defecto van en get_user_data.marker_speed_colors.
            'marker_colors' => $this->markerColors($entity),

            // tail_color elevated from device_data so device_data is not needed
            'tail_color' => $entity->tail_color,
            'tail'       => $entity->tail,
            'sensors'    => $this->formatSensors($entity),
            'services'   => $this->formatServices($entity),

            'stop_duration'     => $entity->stop_duration,
            'stop_duration_sec' => $entity->getStopDuration() ?? 0,
            'moved_timestamp'   => $entity->moved_timestamp,
            'engine_status'     => $entity->getEngineStatus(),
            'detect_engine'     => $entity->detect_engine,
            'engine_hours'      => $entity->engine_hours,
            'total_distance'    => $entity->getTotalDistance(),

            'distance_unit_hour' => Formatter::speed()->getUnit(),
            'unit_of_distance'   => Formatter::distance()->getUnit(),
            'unit_of_altitude'   => Formatter::altitude()->getUnit(),
            'unit_of_capacity'   => Formatter::capacity()->getUnit(),

            'sim_expiration_date' => $sim_expiration_date ? Formatter::time()->human($sim_expiration_date) : null,

            'distancia_hoy'   => Formatter::distance()->format($odometerValues['total_distance']),
            'odometro_inicio' => $odometerValues['odometer_start'],
            'odometro_final'  => $odometerValues['odometer_end'],
        ];
    }

    /** Config del color por velocidad de la unidad (solo lo que difiere de los valores por defecto). */
    protected function markerColors(Device $entity): ?array
    {
        if (! $entity->marker_colors_enabled) {
            return null;
        }

        $rangos = json_decode((string) ($entity->marker_speed_ranges ?? ''), true);

        return array_filter([
            'ranges' => is_array($rangos) && $rangos ? $rangos : null,
            'margin' => $entity->marker_geofence_margin !== null ? (int) $entity->marker_geofence_margin : null,
            'near'   => $entity->marker_geofence_near_color ?: null,
            'over'   => $entity->marker_geofence_over_color ?: null,
        ], fn ($v) => $v !== null);
    }

    protected function classification(Device $entity): array
    {
        $classification = $this->user ? $entity->classificationFor((int) $this->user->id) : null;
        $state = $classification ? $classification->vehicleState : null;

        return [
            'vehicle_type_id'  => ($classification && $classification->vehicle_type_id) ? (int) $classification->vehicle_type_id : null,
            'vehicle_state_id' => ($classification && $classification->vehicle_state_id) ? (int) $classification->vehicle_state_id : null,
            'vehicle_state'    => $state ? [
                'name'  => (string) $state->name,
                'label' => (string) ($state->label ?: $state->name),
                'color' => (string) ($state->color ?: '#64748b'),
            ] : null,
        ];
    }

    protected function formatSensors($entity)
    {
        if ($entity->isExpired()) {
            return [];
        }

        $result = [];

        foreach ($entity->sensors as $sensor) {
            if (in_array($sensor->type, ['harsh_acceleration', 'harsh_breaking', 'harsh_turning'])) {
                continue;
            }

            $value = $sensor->getValueCurrent($entity);

            $result[] = [
                'id'            => $sensor->id,
                'type'          => $sensor->type,
                'name'          => $sensor->formatName(),
                'show_in_popup' => $sensor->show_in_popup,
                'value'         => htmlspecialchars($value->getFormatted()),
                'val'           => $value->getValue(),
                'scale_value'   => $sensor->getValueScale($value->getValue()),
                'tag_name'      => $sensor->tag_name,
            ];
        }

        return $result;
    }

    protected function formatServices($entity)
    {
        if ($entity->isExpired()) {
            return [];
        }

        $result = [];

        foreach ($entity->services as $service) {
            $service->setDevice($entity)->setSensors($entity->sensors);

            $result[] = [
                'id'      => $service->id,
                'name'    => $service->name,
                'value'   => $service->expiration(),
                'expiring' => $service->isExpiring(),
            ];
        }

        return $result;
    }
}
