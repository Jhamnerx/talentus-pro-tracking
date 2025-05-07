<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Entities\Device;
use Carbon\Carbon;
use Tobuli\History\DeviceHistory;
use Tobuli\Helpers\Formatter\Facades\Formatter;
use Illuminate\Support\Facades\Cache;

class CacheOdometerValuesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'odometer:cache {--devices=} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Precalcular y cachear los valores del odómetro para mejorar el rendimiento';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $deviceIds = $this->option('devices');
        $force = $this->option('force');

        $query = Device::query();

        if ($deviceIds) {
            $deviceIds = explode(',', $deviceIds);
            $query->whereIn('id', $deviceIds);
        }

        // Cargar solo los dispositivos activos y con sus sensores
        $devices = $query->where('active', 1)
            ->with(['sensors' => function ($query) {
                $query->where('type', 'odometer');
            }])
            ->get();

        $this->info("Procesando valores de odómetro para {$devices->count()} dispositivos");
        $bar = $this->output->createProgressBar($devices->count());

        $countUpdate = 0;
        $countSkip = 0;

        foreach ($devices as $device) {
            $cacheKey = "device_odometer_{$device->id}_" . date('Ymd');

            // Si ya existe en caché y no se fuerza la actualización, nos saltamos este dispositivo
            if (Cache::has($cacheKey) && !$force) {
                $countSkip++;
                $bar->advance();
                continue;
            }

            // Calculamos los valores del odómetro
            $values = $this->calculateOdometerValues($device);

            // Guardamos en caché (30 minutos)
            Cache::put($cacheKey, $values, 30);

            $countUpdate++;
            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->info("Proceso completado: {$countUpdate} dispositivos actualizados, {$countSkip} omitidos (ya en caché)");
    }

    /**
     * Calcula los valores del odómetro para un dispositivo
     *
     * @param Device $device
     * @return array
     */
    protected function calculateOdometerValues(Device $device)
    {
        // Calculamos fechas para el día actual (America/Lima)
        $inicio = Formatter::time()->reverse(Carbon::now('America/Lima')->startOfDay()->format('Y-m-d H:i:s'));
        $fin = Formatter::time()->reverse(Carbon::now('America/Lima')->endOfDay()->format('Y-m-d H:i:s'));

        $total_distance = 0;
        $odometer_start_global = trans('front.not_available');
        $odometer_end_global = trans('front.not_available');

        // Creamos la instancia de historial
        $history = new DeviceHistory($device);
        $history->setConfig([
            'stop_seconds' => 180,
            'stop_speed' => $device->min_moving_speed,
            'min_fuel_fillings' => $device->min_fuel_fillings,
            'min_fuel_thefts' => $device->min_fuel_thefts,
        ]);
        $history->setRange($inicio, $fin);

        // Registramos las acciones necesarias
        $history->registerActions([
            \Tobuli\History\Actions\AppendOdometerVirtualDistance::class,
            \Tobuli\History\Actions\AppendOdometer::class,
            \Tobuli\History\Actions\AppendRouteColor::class,
            \Tobuli\History\Actions\DriveStop::class,
            \Tobuli\History\Actions\Duration::class,
            \Tobuli\History\Actions\Distance::class,
            \Tobuli\History\Actions\Speed::class,
            \Tobuli\History\Actions\Odometer::class,
        ]);

        // Establecer los sensores necesarios
        $history->setSensors($device->sensors->filter(function ($sensor) {
            return $sensor->add_to_history || $sensor->add_to_graph || $sensor->type == 'odometer';
        }));

        // Obtenemos los datos
        $data = $history->get();

        // Calculamos los valores como en DeviceMapTransformer
        if ($data['root']->getStartPosition()) {
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
                    $segment_distance = $group->stats()->has('distance') ? $group->stats()->get('distance')->format() : 0;
                }

                $total_distance += $segment_distance;
            }
        }

        // Fallback al root si no hay grupos o no se calcularon valores
        if ($total_distance == 0 || $odometer_start_global == trans('front.not_available')) {
            $odometer_root = $data['root']->stats()->has('odometer') ? $data['root']->stats()->get('odometer') : null;

            if ($odometer_root) {
                $startPosition = $data['root']->getStartPosition();
                if ($startPosition && isset($startPosition->odometer)) {
                    $odometer_root->set($startPosition->odometer);
                    $odometer_start_global = round($odometer_root->format(), 1);
                }

                $endPosition = $data['root']->getEndPosition();
                if ($endPosition && isset($endPosition->odometer)) {
                    $odometer_root->set($endPosition->odometer);
                    $odometer_end_global = round($odometer_root->format(), 1);
                }
            }

            if (is_numeric($odometer_end_global) && is_numeric($odometer_start_global)) {
                $total_distance = $odometer_end_global - $odometer_start_global;
            } else {
                $total_distance = $data['root']->stats()->has('distance') ? $data['root']->stats()->get('distance')->format() : 0;
            }
        }

        return [
            'total_distance' => $total_distance,
            'odometer_start' => $odometer_start_global,
            'odometer_end' => $odometer_end_global,
        ];
    }
}
