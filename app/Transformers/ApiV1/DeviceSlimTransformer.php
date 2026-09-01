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
