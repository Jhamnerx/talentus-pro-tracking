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
    //public $timeout = 600;
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
    //php /var/www/html/current/artisan queue:work --queue=web-services-reenvio --timeout=6000 --verbose --no-interaction >> /var/www/html/current/storage/logs/web-services-reenvio.log 2>&1
    //Queue::pushOn('web-services-reenvio', new App\Jobs\CopyDataToTableMininter);
    /**
     * Execute the job.
     *
     * @return void
     */

    public function handle()
    {
        $this->getPositions();
        //$this->clonePositions();
    }

    public function getPositions()
    {
        //$devices = Device::where('mininter', 1)->get();
        $devices = Device::where('mininter', 1)
            ->whereIn('plate_number', ['3749-NM', 'EAP-218'])
            ->get();

        foreach ($devices as $device) {

            $device->positions()
                ->whereBetween('time', ['2026-02-01 05:00:00', '2026-04-01 05:00:00'])
                ->chunk(100, function ($positions) use ($device) {
                    foreach ($positions as $position) {
                        //  Log::info('Device e: ' . $position);
                        $this->saveDataMininter($device, $position);
                    }
                });
            // $device->positions()
            //     ->whereBetween('time', ['2025-10-03 05:00:00', '2025-10-05 05:00:00'])
            //     ->chunk(100, function ($positions) use ($device) {
            //         foreach ($positions as $position) {
            //             //  Log::info('Device e: ' . $position);
            //             $this->saveDataMininter($device, $position);
            //         }
            //     });
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

    /**
     * Clona posiciones de un dispositivo origen a un dispositivo destino
     * Mantiene el imei, device_id y placa del dispositivo destino
     * Solo copia las coordenadas, velocidad, curso, altitud y horas de motor
     */
    public function clonePositions()
    {
        // Configuración de dispositivos y rango de fechas
        $placaOrigen = 'M6H-912';
        $placaDestino = '3749-NM';
        $fechaInicio = '2025-10-24 05:00:00';
        $fechaFin = '2025-10-24 22:20:00';

        // Obtener dispositivos
        $deviceOrigen = Device::where('plate_number', $placaOrigen)->first();
        $deviceDestino = Device::where('plate_number', $placaDestino)->first();

        if (!$deviceOrigen) {
            Log::error("Dispositivo origen no encontrado: {$placaOrigen}");
            return;
        }

        if (!$deviceDestino) {
            Log::error("Dispositivo destino no encontrado: {$placaDestino}");
            return;
        }

        Log::info("Iniciando clonación de posiciones de {$placaOrigen} a {$placaDestino}");
        Log::info("Rango de fechas: {$fechaInicio} - {$fechaFin}");

        $totalClonadas = 0;

        // Obtener posiciones del dispositivo origen en el rango de fechas
        $deviceOrigen->positions()
            ->whereBetween('time', [$fechaInicio, $fechaFin])
            ->orderBy('time', 'asc')
            ->chunk(100, function ($positions) use ($deviceDestino, &$totalClonadas) {
                foreach ($positions as $posicionOrigen) {
                    $this->clonarPosicion($deviceDestino, $posicionOrigen);
                    $totalClonadas++;
                }
            });

        Log::info("Clonación completada. Total de posiciones clonadas: {$totalClonadas}");
    }

    /**
     * Clona una posición individual del origen al destino en la tabla dataMininter
     */
    private function clonarPosicion($deviceDestino, $posicionOrigen)
    {
        // Verificar que la posición sea válida antes de clonar
        if (!$posicionOrigen->valid) {
            Log::info("Posición no válida, se omite: {$posicionOrigen->time}");
            return;
        }

        // Verificar si ya existe en dataMininter para evitar duplicados
        $existente = $deviceDestino->dataMininter()
            ->where('fechaHora', $posicionOrigen->time)
            ->exists();

        if ($existente) {
            Log::warning("Registro ya existe en dataMininter para tiempo: {$posicionOrigen->time}");
            return;
        }

        // Obtener datos del destino (municipalidad y ubigeo)
        $idMunicipalidad = "";
        $ubigeo = "";
        foreach ($deviceDestino->users as $user) {
            if ($user->is_municipalidad) {
                $idMunicipalidad = $user->token_muni;
                $ubigeo = $user->ubigeo_muni;
            }
        }

        // Parsear el campo other de la posición origen
        $others = $this->parseString($posicionOrigen->other);

        // Crear registro en dataMininter con datos del destino pero posiciones del origen
        $nuevoRegistro = $deviceDestino->dataMininter()->create([
            'device_id' => $deviceDestino->id,  // ID del dispositivo destino
            'alarma' => 0,
            'altitud' => $posicionOrigen->altitude,
            'angulo' => $posicionOrigen->course,
            'distancia' => $others['distance'] ?? 0,
            'fechaHora' => $posicionOrigen->time, //UTC
            'timestamp' => strtotime($posicionOrigen->device_time),
            'horasMotor' => $posicionOrigen->getParameter(Position::VIRTUAL_ENGINE_HOURS_KEY),
            'idMunicipalidad' => $idMunicipalidad,
            'ignition' => $others['ignition'] ?? 0,
            'imei' => $deviceDestino->imei,  // IMEI del dispositivo destino
            'latitud' => $posicionOrigen->latitude,  // Coordenadas del origen
            'longitud' => $posicionOrigen->longitude,  // Coordenadas del origen
            'motion' => $others['motion'] ?? 0,
            'placa' => $deviceDestino->plate_number,  // Placa del dispositivo destino
            'totalDistancia' => $others['totaldistance'] ?? 0,
            'totalHorasMotor' => $others['enginehours'] ?? 0,
            'ubigeo' => $ubigeo,
            'valid' => $posicionOrigen->valid,
            'velocidad' => $posicionOrigen->speed,
            'other' => $others,
        ]);

        Log::info("Registro clonado en dataMininter: {$posicionOrigen->time} - Placa destino: {$deviceDestino->plate_number} - Lat: {$posicionOrigen->latitude}, Lng: {$posicionOrigen->longitude}");

        return $nuevoRegistro;
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
