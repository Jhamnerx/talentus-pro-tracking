<?php

namespace App\Transformers\Device;

use Carbon\Carbon;
use Tobuli\Entities\Device;
use Tobuli\History\DeviceHistory;
use Tobuli\Sensors\Types\Blocked;
use Tobuli\Helpers\Formatter\Facades\Formatter;
use Tobuli\History\Actions\AppendRouteColor;
use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\Drivers;
use Tobuli\History\Actions\DriveStop;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\EngineHours;
use Tobuli\History\Actions\Fuel;
use Tobuli\History\Actions\GroupDriveStop;
use Tobuli\History\Actions\GroupEvent;
use Tobuli\History\Actions\Positions;
use Tobuli\History\Actions\Speed;
use Tobuli\History\Actions\GroupDailySplit;
use Tobuli\History\Actions\GroupSingle;
use Tobuli\History\Actions\Odometer;
use Tobuli\History\Actions\AppendOdometerVirtualDistance;
use Tobuli\History\Actions\AppendOdometer;
use Tobuli\History\Group;
use Tobuli\Helpers\OdometerOptimizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\App;

class DeviceMapTransformer extends DeviceTransformer
{
    // Tiempo de duración del caché (en minutos)
    protected $cacheDuration = 15;

    // Bandera para indicar si se debe usar el caché
    protected $useCache = true;

    // Umbral para optimización de rendimiento (número de dispositivos)
    protected $deviceThreshold = 100;

    protected $defaultIncludes = [
        'icon',
    ];

    protected static function requireLoads()
    {
        return ['icon', 'traccar', 'sensors' => function ($query) {
            $types = ['speed', 'anonymizer', 'odometer'];

            if (Blocked::isEnabled()) {
                $types[] = 'blocked';
            }

            $query->whereIn('type', $types);
        }];
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

        if ($entity->speed > 90) {
            $tailColor = '#FF0000'; // Rojo
        } elseif ($entity->speed > 60) {
            $tailColor = '#FFA500'; // Naranja
        } elseif ($entity->speed > 30) {
            $tailColor = '#008000'; // Verde
        } else {
            $tailColor = $entity->tail_color; // Azul
        }

        // Calculamos fechas para el día actual (America/Lima)
        $inicio = Formatter::time()->reverse(Carbon::now('America/Lima')->startOfDay()->format('Y-m-d H:i:s'));
        $fin = Formatter::time()->reverse(Carbon::now('America/Lima')->endOfDay()->format('Y-m-d H:i:s'));

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
                'inicio' => $inicio,
                'fin' => $fin,
            ],
            'distancia_hoy'     => Formatter::distance()->format($total_distance),
            'distancia_hoy_raw' => $total_distance,
            'odometro_start'    => $odometer_start_global,
            'odometro_end'      => $odometer_end_global,
        ];
    }

    private function getDeviceSensors($device)
    {
        // Retornamos todos los sensores que deben incluirse en el gráfico o análisis
        return $device->sensors->filter(function ($sensor) {
            return $sensor->add_to_history || $sensor->add_to_graph || $sensor->type == 'odometer';
        });
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
