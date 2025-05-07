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


class DeviceMapTransformer extends DeviceTransformer
{

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

        // Utilizamos exactamente la misma lógica que el HistoryModalHelper
        $history = new \Tobuli\History\DeviceHistory($entity);
        $history->setConfig([
            'stop_seconds' => 180,
            'stop_speed' => $entity->min_moving_speed,
            'min_fuel_fillings' => $entity->min_fuel_fillings,
            'min_fuel_thefts' => $entity->min_fuel_thefts,
        ]);
        $history->setRange($inicio, $fin);

        // IMPORTANTE: Registramos las mismas acciones en el mismo orden que HistoryModalHelper
        $history->registerActions([
            \Tobuli\History\Actions\AppendOdometerVirtualDistance::class,
            \Tobuli\History\Actions\AppendOdometer::class,
            \Tobuli\History\Actions\AppendRouteColor::class,
            \Tobuli\History\Actions\DriveStop::class,
            \Tobuli\History\Actions\Duration::class,
            \Tobuli\History\Actions\Distance::class,
            \Tobuli\History\Actions\Speed::class,
            \Tobuli\History\Actions\Fuel::class,
            \Tobuli\History\Actions\EngineHours::class,
            \Tobuli\History\Actions\Drivers::class,
            \Tobuli\History\Actions\Positions::class,
            \Tobuli\History\Actions\GroupDriveStop::class,
            \Tobuli\History\Actions\Odometer::class,
        ]);

        // IMPORTANTE: Establecer los sensores antes de obtener los datos
        $history->setSensors($this->getDeviceSensors($entity));

        // Obtenemos datos del historial
        $data = $history->get();

        // Variable para almacenar la suma de distancias
        $total_distance = 0;
        $odometer_start_global = trans('front.not_available');
        $odometer_end_global = trans('front.not_available');

        // Recorremos todos los grupos para calcular la distancia total exactamente como en HistoryModalHelper
        if ($data['root']->getStartPosition()) {
            foreach ($data['groups']->all() as $group) {
                // Calculamos la distancia para cada grupo EXACTAMENTE como en OdometerDailyReport::getRow
                $odometer_start = trans('front.not_available');
                $odometer_end = trans('front.not_available');
                $odometer = $group->stats()->has('odometer') ? $group->stats()->get('odometer') : null;

                $startPosition = $group->getStartPosition();

                if ($odometer) {
                    if ($startPosition && isset($startPosition->odometer)) {
                        $odometer->set($startPosition->odometer);
                        $odometer_start = round($odometer->format(), 1);
                        // Guardamos el primer valor de odómetro que encontremos como valor inicial global
                        if ($odometer_start_global == trans('front.not_available')) {
                            $odometer_start_global = $odometer_start;
                        }
                    }

                    $endPosition = $group->getEndPosition();
                    if ($endPosition && isset($endPosition->odometer)) {
                        $odometer->set($endPosition->odometer);
                        $odometer_end = round($odometer->format(), 1);
                        // Actualizamos el valor final global con cada valor final que encontremos
                        $odometer_end_global = $odometer_end;
                    }
                }

                if (is_numeric($odometer_end) && is_numeric($odometer_start)) {
                    $segment_distance = $odometer_end - $odometer_start;
                } else {
                    $segment_distance = $group->stats()->has('distance') ? $group->stats()->get('distance')->format() : 0;
                }

                // Sumamos la distancia de este segmento al total
                $total_distance += $segment_distance;
            }
        }

        // Si no se encontraron grupos o no se calculó distancia, intentamos con el root
        if ($total_distance == 0 || $odometer_start_global == trans('front.not_available')) {
            $odometer_root = $data['root']->stats()->has('odometer') ? $data['root']->stats()->get('odometer') : null;

            if ($odometer_root) {
                $startPosition = $data['root']->getStartPosition();
                if ($startPosition && isset($startPosition->odometer)) {
                    $odometer_root->set($startPosition->odometer);
                    $odometer_start_global = round($odometer_root->format());
                }

                $endPosition = $data['root']->getEndPosition();
                if ($endPosition && isset($endPosition->odometer)) {
                    $odometer_root->set($endPosition->odometer);
                    $odometer_end_global = round($odometer_root->format());
                }
            }

            if (is_numeric($odometer_end_global) && is_numeric($odometer_start_global)) {
                $total_distance = $odometer_end_global - $odometer_start_global;
            } else {
                $total_distance = $data['root']->stats()->has('distance') ? $data['root']->stats()->get('distance')->format() : 0;
            }
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
}
