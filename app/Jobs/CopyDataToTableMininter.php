<?php

namespace App\Jobs;

use DateTime;
use DateTimeZone;
use Carbon\Carbon;
use Tobuli\Entities\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tobuli\Entities\TraccarPosition as Position;
use Tobuli\Helpers\Formatter\Facades\Formatter;

class CopyDataToTableMininter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $timeout = 600;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->onQueue('web-services-reenvio');
    }
    //php /var/www/html/current/artisan queue:work --queue=web-services-reenvio --timeout=600 --verbose --no-interaction >> /var/www/html/current/storage/logs/web-services-reenvio.log 2>&1
    //Queue::pushOn('web-services-reenvio', new App\Jobs\CopyDataToTableMininter);
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->getPositions();
    }
    public function getPositions()
    {
        $devices = Device::where('mininter', 1)->get();

        foreach ($devices as $device) {

            $device->positions()
                ->whereBetween('time', ['2025-07-05 05:00:00', '2025-07-08 05:00:00'])
                ->chunk(100, function ($positions) use ($device) {
                    foreach ($positions as $position) {
                        //  Log::info('Device e: ' . $position);
                        $this->saveDataMininter($device, $position);
                    }
                });
        }

        // foreach ($devices as $device) {

        //     $device->positions()
        //         ->whereBetween('time', ['2024-09-16 03:00:00', '2024-09-16 09:15:00'])
        //         ->chunk(100, function ($positions) use ($device) {
        //             foreach ($positions as $position) {
        //                 $this->saveDataMininter($device, $position);
        //             }
        //         });
        // }


    }


    public function saveDataMininter($device, $position)
    {

        $idMunicipalidad = "";
        $ubigeo = "";

        foreach ($device->users as $user) {
            if ($user->is_municipalidad) {

                $idMunicipalidad =  $user->token_muni;
                $ubigeo = $user->ubigeo_muni;
            }
        }

        $others = $this->parseString($position->other);

        $i = $device->dataMininter()->create(
            [
                'device_id' => $device->id,
                'alarma' => 0,
                'altitud' => $position->altitude,
                'angulo' => $position->course,
                'distancia' => $others['distance'],
                'fechaHora' => $position->time, //UTC
                'timestamp' => strtotime($position->device_time),
                'horasMotor' => $position->getParameter(Position::VIRTUAL_ENGINE_HOURS_KEY),
                'idMunicipalidad' => $idMunicipalidad,
                'ignition' => $others['ignition'],
                'imei' => $device->imei,
                'latitud' => $position->latitude,
                'longitud' => $position->longitude,
                'motion' => $others['motion'],
                'placa' => $device->plate_number,
                'totalDistancia' => $others['totaldistance'],
                'totalHorasMotor' => $others['enginehours'],
                'ubigeo' => $ubigeo,
                'valid' => $position->valid,
                'velocidad' => $position->speed,
                'other' => $others,
            ]
        );
    }



    public function parseString($xmlString)
    {

        // Cargar la cadena XML
        $xml = simplexml_load_string($xmlString);

        // Convertir a array asociativo
        $array = json_decode(json_encode($xml), true);

        // Imprimir el array
        return $array;
    }
}
