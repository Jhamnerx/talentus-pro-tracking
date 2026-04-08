<?php

namespace Tobuli\Helpers;

use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\Device;
use Carbon\Carbon;
use Tobuli\History\DeviceHistory;
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
use Tobuli\History\Actions\Odometer;

class OdometerOptimizer
{
    // Duración del caché en minutos
    protected $cacheDuration = 15;

    /**
     * Obtiene los valores del odómetro para un dispositivo
     * Intenta usar la caché si está disponible, de lo contrario calcula los valores
     * 
     * @param Device $device
     * @param bool $forceCalculate
     * @return array
     */
    public function getOdometerValues(Device $device, $forceCalculate = false)
    {
        $cacheKey = $this->getCacheKey($device->id);

        if (!$forceCalculate && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $values = $this->calculateOdometerValues($device);

        Cache::put($cacheKey, $values, $this->cacheDuration);

        return $values;
    }

    /**
     * Genera la clave de caché para un dispositivo
     * 
     * @param int $deviceId
     * @return string
     */
    public function getCacheKey($deviceId)
    {
        return "device_odometer_{$deviceId}_" . date('Ymd');
    }
    private function getDeviceSensors($device)
    {
        return $device->sensors->filter(function ($sensor) {
            return $sensor->add_to_graph;
        });
    }
    /**
     * Calcula los valores del odómetro para un dispositivo
     * 
     * @param Device $device
     * @return array
     */
    public function calculateOdometerValues(Device $device)
    {
        // Calculamos fechas para el día actual (America/Lima)
        $inicio = Formatter::time()->reverse(Carbon::now('America/Lima')->startOfDay()->format('Y-m-d H:i:s'));
        $fin = Formatter::time()->reverse(Carbon::now('America/Lima')->endOfDay()->format('Y-m-d H:i:s'));

        $total_distance = 0;
        $odometer_start_global = trans('front.not_available');
        $odometer_end_global = trans('front.not_available');

        // Optimización: solo cargar las propiedades y sensores necesarios
        if (!$device->relationLoaded('sensors')) {
            $device->load(['sensors' => function ($query) {
                $query->where('type', 'odometer');
            }]);
        }

        // Creamos la instancia de historial con configuración optimizada
        $history = new DeviceHistory($device);
        $history->setConfig([
            'stop_seconds' => 180,
            'stop_speed' => $device->min_moving_speed,
            'min_fuel_fillings' => $device->min_fuel_fillings,
            'min_fuel_thefts' => $device->min_fuel_thefts,
        ]);
        $history->setRange($inicio, $fin);

        // Registramos solo las acciones requeridas para el odómetro
        $history->registerActions([
            AppendRouteColor::class,
            DriveStop::class,
            Duration::class,
            Distance::class,
            Speed::class,
            Fuel::class,
            EngineHours::class,
            Drivers::class,
            Positions::class,
            GroupDriveStop::class,
            Odometer::class,
        ]);

        // Establecer solo los sensores de odómetro
        $history->setSensors($this->getDeviceSensors($device));


        // Obtenemos los datos del historial (esto es lo que más recursos consume)
        $data = $history->get();


        // Calculamos los valores necesarios
        if ($data['root']->getStartPosition()) {


            // Si hay grupos, calculamos la distancia total sumando los segmentos

            foreach ($data['groups']->all() as $group) {
                $odometer_start = trans('front.not_available');
                $odometer_end = trans('front.not_available');
                $odometer = $group->stats()->has('odometer') ? $group->stats()->get('odometer') : null;

                $startPosition = $group->getStartPosition();

                if ($odometer) {
                    if ($startPosition && isset($startPosition->odometer)) {
                        $odometer->set($startPosition->odometer);
                        $odometer_start = round($odometer->format(), 1);
                        if ($odometer_start_global == trans('front.not_available')) {
                            $odometer_start_global = $odometer_start;
                        }
                    }

                    $endPosition = $group->getEndPosition();
                    if ($endPosition && isset($endPosition->odometer)) {
                        $odometer->set($endPosition->odometer);
                        $odometer_end = round($odometer->format(), 1);
                        $odometer_end_global = $odometer_end;
                    }
                }

                if (is_numeric($odometer_end) && is_numeric($odometer_start)) {
                    $segment_distance = $odometer_end - $odometer_start;
                } else {
                    $segment_distance = $group->stats()->get('distance')->format();
                }

                $total_distance += $segment_distance;
            }
        }

        return [
            'total_distance' => $total_distance,
            'odometer_start' => $odometer_start_global,
            'odometer_end' => $odometer_end_global,
        ];
    }

    /**
     * Inicia el precálculo de valores de odómetro para un conjunto de dispositivos
     * 
     * @param array $deviceIds
     * @return int
     */
    public function precalculateValues($deviceIds = [])
    {
        $query = Device::query()->where('active', 1);

        if (!empty($deviceIds)) {
            $query->whereIn('id', $deviceIds);
        }

        $devices = $query->with(['sensors' => function ($query) {
            $query->where('type', 'odometer');
        }])->get();

        $count = 0;
        foreach ($devices as $device) {
            $values = $this->calculateOdometerValues($device);
            Cache::put($this->getCacheKey($device->id), $values, $this->cacheDuration);
            $count++;
        }

        return $count;
    }

    /**
     * Establece la duración del caché
     * 
     * @param int $minutes
     * @return $this
     */
    public function setCacheDuration($minutes)
    {
        $this->cacheDuration = (int)$minutes;
        return $this;
    }
}
