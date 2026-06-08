<?php

namespace App\Transformers\ApiV1;

use App\Transformers\BaseTransformer;
use Tobuli\Entities\Device;

/**
 * Ultra-slim transformer for v2/get_devices_latest (delta polling).
 *
 * Returns ONLY the fields that change between polls:
 * position, status, sensors, tail.
 *
 * Everything static (name, icon, driver, units, device config) is excluded
 * because the app already has it from the initial v2/get_devices load.
 * The React deep-merge will only update keys present in this response.
 *
 * sensors and tail are JSON-encoded strings to reduce parsing overhead
 * on the server side (consistent with the existing get_devices_latest behaviour).
 */
class DeviceSlimJsonTransformer extends BaseTransformer
{
    protected static function requireLoads()
    {
        return ['traccar', 'sensors'];
    }

    public function transform(Device $entity)
    {
        return [
            'id'           => intval($entity->id),
            'lat'          => floatval($entity->lat),
            'lng'          => floatval($entity->lng),
            'course'       => $entity->course ?? 0,
            'speed'        => $entity->speed,
            'altitude'     => $entity->altitude,

            'online'       => $entity->getStatus(),
            'icon_color'   => $entity->getStatusColor(),
            'alarm'        => is_null($this->user->alarm) ? 0 : $this->user->alarm,

            'time'         => $entity->time,
            'timestamp'    => $entity->timestamp,
            'acktimestamp' => $entity->ack_timestamp,

            'stop_duration'     => $entity->stop_duration,
            'stop_duration_sec' => $entity->getStopDuration() ?? 0,
            'moved_timestamp'   => intval($entity->moved_timestamp),
            'engine_status'     => $entity->getEngineStatus(),
            'total_distance'    => $entity->getTotalDistance(),

            // JSON-encoded strings keep payload small (consistent with v1 get_devices_latest)
            'tail'    => json_encode($entity->tail),
            'sensors' => json_encode($this->formatSensors($entity)),
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
}
