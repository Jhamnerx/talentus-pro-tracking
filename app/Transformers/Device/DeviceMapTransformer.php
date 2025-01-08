<?php

namespace App\Transformers\Device;

use Tobuli\Entities\Device;
use Tobuli\Sensors\Types\Blocked;
use Carbon\Carbon;
use Tobuli\Helpers\Formatter\Facades\Formatter;

class DeviceMapTransformer extends DeviceTransformer
{

    protected $defaultIncludes = [
        'icon',
    ];

    protected static function requireLoads()
    {
        return ['icon', 'traccar', 'sensors' => function ($query) {
            $types = ['speed', 'anonymizer'];

            if (Blocked::isEnabled()) {
                $types[] = 'blocked';
            }

            $query->whereIn('type', $types);
        }];
    }

    public function transform(Device $entity)
    {
        $inaccuracy = config('addon.inaccuracy')
            ? $entity->getParameter('inaccuracy')
            : null;

        $status = $entity->getStatus();

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
            'lat' => $entity->lat,
            'lng' => $entity->lng,
            'speed' => $entity->speed,
            'course' => $entity->course,
            'altitude' => $entity->altitude,
            'time' => $entity->time,
            'timestamp' => (int)$entity->timestamp,
            'acktimestamp' => (int)$entity->acktimestamp,
            'engine_status' => $entity->getEngineStatus(),
            'inaccuracy' => is_null($inaccuracy) ? null : intval($inaccuracy),

            'moved_timestamp'    => (int)$entity->moved_timestamp,
            'stop_duration_sec'  => $entity->getStopDuration(),
            'total_distance'     => $entity->getTotalDistance(),
            'hoy' => [
                'inicio' => Formatter::time()->reverse(Carbon::now('America/Lima')->startOfDay()),
                'fin' => Formatter::time()->reverse(Carbon::now('America/Lima')->endOfDay()),
            ],
            'distancia_hoy'     => Formatter::distance()->format($entity->getSumDistance(Formatter::time()->reverse(Carbon::now()->startOfDay()), Formatter::time()->reverse(Carbon::now()->endOfDay()))),

        ];
    }
}
