<?php

namespace App\Transformers\Device;

use Illuminate\Support\Facades\App;
use App\Transformers\BaseTransformer;
use Tobuli\Entities\Device;
use Tobuli\Helpers\OdometerOptimizer;
use Tobuli\Helpers\Formatter\Facades\Formatter;;

class DeviceMapFullTransformer extends DeviceTransformer
{

    // Tiempo de duración del caché (en minutos)
    protected $cacheDuration = 15;

    // Bandera para indicar si se debe usar el caché
    protected $useCache = true;

    // Umbral para optimización de rendimiento (número de dispositivos)
    protected $deviceThreshold = 100;

    protected $defaultIncludes = [
        'icon',
        //'sensors',
        //'services',
        'driver'
    ];
    protected static function requireLoads()
    {
        return ['icon', 'traccar', 'sensors', 'services', 'driver'];
    }

    public function transform(Device $entity)
    {
        $expirationDate = $this->canView($entity, 'expiration_date');
        $expirationDate = $expirationDate ? Formatter::time()->convert($expirationDate) : null;

        $inaccuracy = config('addon.inaccuracy')
            ? $entity->getParameter('inaccuracy')
            : null;

        // Variables para almacenar los valores del odómetro
        $total_distance = 0;
        $odometer_start_global = trans('front.not_available');
        $odometer_end_global = trans('front.not_available');

        // Usar el optimizador de odómetro para obtener los valores
        $odometerOptimizer = App::make(OdometerOptimizer::class);

        $odometerValues = $odometerOptimizer->getOdometerValues($entity);

        $total_distance = $odometerValues['total_distance'];
        $odometer_start_global = $odometerValues['odometer_start'];
        $odometer_end_global = $odometerValues['odometer_end'];

        return [
            'id'    => (int)$entity->id,
            'name'  => $entity->name,
            'image' => $entity->image,
            'plate_number'  => $entity->plate_number,
            'device_model'  => $entity->device_model,
            'tail'  => $entity->tail,
            'tail_color' => $entity->tail_color,
            'icon_color' => $entity->getStatusColor(),
            'icon_colors' => $entity->icon_colors,
            'active' => $entity->pivot ? (bool)$entity->pivot->active : null,
            'group_id' => $entity->pivot ? (int)$entity->pivot->group_id : 0,
            'online' => $entity->getStatus(),
            'lat' => $entity->lat,
            'lng' => $entity->lng,
            'speed' => $entity->speed,
            'course' => $entity->course,
            'altitude' => $entity->altitude,
            'time' => $entity->time,
            'timestamp' => (int)$entity->timestamp,
            'acktimestamp' => (int)$entity->acktimestamp,
            'moved_timestamp' => (int)$entity->moved_timestamp,

            'protocol'        => $this->canView($entity, 'protocol'),
            'expiration_date' => $expirationDate,

            'detect_engine'      => $entity->detect_engine,
            'engine_hours'       => $entity->engine_hours,

            'engine_status'      => $entity->getEngineStatus(),
            'stop_duration'      => $entity->stop_duration,
            'stop_duration_sec'  => $entity->getStopDuration(),
            'total_distance'     => $entity->getTotalDistance(),
            'inaccuracy'         => is_null($inaccuracy) ? null : intval($inaccuracy),

            'sensors'   => $this->formatSensors($entity),
            'services'  => $this->formatServices($entity),
            'distancia_hoy'     => Formatter::distance()->format($total_distance),
            'odometro_inicio'   => $odometer_start_global,
            'odometro_final'    => $odometer_end_global,
        ];
    }

    //tmp
    protected function formatSensors($entity)
    {
        if ($entity->isExpired())
            return null;

        $result = [];

        foreach ($entity->sensors->sortBy('id') as $sensor) {
            if (in_array($sensor->type, ['harsh_acceleration', 'harsh_breaking', 'harsh_turning']))
                continue;

            $value = $sensor->getValueCurrent($entity);
            $icon = $value->getIcon();

            $result[] = [
                'id'            => $sensor->id,
                'type'          => $sensor->type,
                'name'          => $sensor->formatName(),
                'show_in_popup' => $sensor->show_in_popup,

                'value'         => htmlspecialchars($value->getFormatted()),
                'val'           => $value->getValue(),
                'scale_value'   => $sensor->getValueScale($value->getValue()),
                'icon'          => $icon ? asset($icon->path) : null
            ];
        }

        return $result;
    }

    protected function formatServices($entity)
    {
        if ($entity->isExpired())
            return null;

        $result = [];

        foreach ($entity->services as $service) {
            $service->setSensors($entity->sensors);

            $result[] = [
                'id'       => $service->id,
                'name'     => $service->name,
                'value'    => $service->expiration(),
                'expiring' => $service->isExpiring()
            ];
        }

        return $result;
    }

    // Método para habilitar/deshabilitar el caché
    public function setUseCache($value)
    {
        $this->useCache = (bool)$value;
        return $this;
    }

    // Método para establecer la duración del caché
    public function setCacheDuration($minutes)
    {
        $this->cacheDuration = (int)$minutes;
        return $this;
    }

    // Método para establecer el umbral de dispositivos para optimización
    public function setDeviceThreshold($count)
    {
        $this->deviceThreshold = (int)$count;
        return $this;
    }
}
