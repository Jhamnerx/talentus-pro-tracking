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
        $this->copyMissingDaysFromSecondaryPlate();
        $this->copyMissingDaysFromPrimaryPlate();
        //$this->clonePositions();
    }

    public function getPositions()
    {
        //$devices = Device::where('mininter', 1)->get();
        $devices = Device::where('mininter', 1)
            ->whereIn('plate_number', ['3749-NM'])
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
     * Copia posiciones para los días faltantes desde la placa secundaria.
     */
    public function copyMissingDaysFromSecondaryPlate()
    {
        $sourcePlate = 'EAP-218';
        $targetPlate = '3749-NM';
        $daysToCopy = [
            '2026-01-03' => '2026-05-07',
            '2026-01-05' => '2026-05-08',
            '2026-01-06' => '2026-05-09',
            '2026-01-08' => '2026-05-10',
            '2026-01-09' => '2026-05-11',
            '2026-01-10' => '2026-05-12',
            '2026-01-11' => '2026-05-13',
            '2026-01-12' => '2026-05-14',
            '2026-01-13' => '2026-05-15',
            '2026-01-14' => '2026-05-16',
            '2026-01-15' => '2026-05-17',
            '2026-01-19' => '2026-05-18',
            '2026-01-20' => '2026-05-19',
            '2026-01-21' => '2026-05-20',
            '2026-01-22' => '2026-05-21',
            '2026-01-23' => '2026-05-22',
            '2026-01-25' => '2026-05-23',
            '2026-01-26' => '2026-05-24',
            '2026-01-27' => '2026-05-25',
            '2026-01-28' => '2026-05-26',
            '2026-01-29' => '2026-05-27',
            '2026-01-30' => '2026-05-28',
            '2026-02-01' => '2026-05-29',
            '2026-02-03' => '2026-05-30',
            '2026-02-04' => '2026-05-31',
            '2026-02-05' => '2026-06-01',
            '2026-02-06' => '2026-06-02',
            '2026-02-07' => '2026-06-03',
            '2026-02-08' => '2026-06-04',
            '2026-02-09' => '2026-06-05',
            '2026-02-10' => '2026-06-06',
            '2026-02-11' => '2026-06-07',
            '2026-02-12' => '2026-06-08',
            '2026-02-13' => '2026-06-09',
            '2026-02-14' => '2026-06-10',
            '2026-02-15' => '2026-06-11',
            '2026-02-16' => '2026-06-12',
            '2026-02-17' => '2026-06-13',
            '2026-02-18' => '2026-06-14',
            '2026-02-19' => '2026-06-15',
            '2026-02-20' => '2026-06-16',
            '2026-02-21' => '2026-06-17',
            '2026-02-22' => '2026-06-18',
            '2026-02-23' => '2026-06-19',
            '2026-02-24' => '2026-06-20',
            '2026-02-25' => '2026-06-21',
        ];

        $this->copyDaysFromPlate($sourcePlate, $targetPlate, $daysToCopy, 'placa secundaria');
    }

    /**
     * Completa los días faltantes usando la misma placa principal cuando hay días válidos.
     */
    public function copyMissingDaysFromPrimaryPlate()
    {
        $sourcePlate = '3749-NM';
        $targetPlate = '3749-NM';
        $daysToCopy = [
            '2026-04-24' => '2026-06-22',
            '2026-04-25' => '2026-06-23',
            '2026-04-26' => '2026-06-24',
            '2026-04-27' => '2026-06-25',
            '2026-04-28' => '2026-06-26',
            '2026-04-29' => '2026-06-27',
            '2026-04-30' => '2026-06-28',
            '2026-05-01' => '2026-06-29',
            '2026-05-02' => '2026-06-30',
        ];

        $this->copyDaysFromPlate($sourcePlate, $targetPlate, $daysToCopy, 'placa principal');
    }

    /**
     * Copia posiciones de un día dado desde una placa origen a una placa destino.
     */
    private function copyDaysFromPlate($sourcePlate, $targetPlate, array $daysToCopy, $sourceLabel)
    {
        $sourceDevice = Device::where('plate_number', $sourcePlate)->first();
        $targetDevice = Device::where('plate_number', $targetPlate)->first();

        if (!$sourceDevice) {
            Log::error("Dispositivo origen no encontrado: {$sourcePlate}");
            return;
        }

        if (!$targetDevice) {
            Log::error("Dispositivo destino no encontrado: {$targetPlate}");
            return;
        }

        foreach ($daysToCopy as $sourceDay => $targetDay) {
            $sourceStart = Carbon::parse($sourceDay)->startOfDay()->toDateTimeString();
            $sourceEnd = Carbon::parse($sourceDay)->endOfDay()->toDateTimeString();

            $copied = 0;

            $sourceDevice->positions()
                ->whereBetween('time', [$sourceStart, $sourceEnd])
                ->orderBy('time', 'asc')
                ->chunk(100, function ($positions) use ($targetDevice, &$copied, $targetDay) {
                    foreach ($positions as $position) {
                        $result = $this->clonarPosicionConFecha($targetDevice, $position, $targetDay);
                        if ($result) {
                            $copied++;
                        }
                    }
                });

            Log::info("Copia desde {$sourceLabel} completada para {$sourceDay} -> {$targetDay}: {$copied} posiciones clonadas a {$targetPlate}");
        }
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
        return $this->clonarPosicionConFecha($deviceDestino, $posicionOrigen, $posicionOrigen->time);
    }

    private function clonarPosicionConFecha($deviceDestino, $posicionOrigen, $targetDay)
    {
        // Verificar que la posición sea válida antes de clonar
        if (!$posicionOrigen->valid) {
            Log::info("Posición no válida, se omite: {$posicionOrigen->time}");
            return;
        }

        $sourceDateTime = Carbon::parse($posicionOrigen->time);
        $secondsSinceMidnight = $sourceDateTime->diffInSeconds($sourceDateTime->copy()->startOfDay());
        $targetDateTime = Carbon::parse($targetDay)->startOfDay()->addSeconds($secondsSinceMidnight);
        $fechaDestino = $targetDateTime->toDateTimeString();

        // Verificar si ya existe en dataMininter para evitar duplicados
        $existente = $deviceDestino->dataMininter()
            ->where('fechaHora', $fechaDestino)
            ->exists();

        if ($existente) {
            Log::warning("Registro ya existe en dataMininter para tiempo: {$fechaDestino}");
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
            'device_id' => $deviceDestino->id,
            'alarma' => 0,
            'altitud' => $posicionOrigen->altitude,
            'angulo' => $posicionOrigen->course,
            'distancia' => $others['distance'] ?? 0,
            'fechaHora' => $fechaDestino,
            'timestamp' => $targetDateTime->timestamp,
            'horasMotor' => $posicionOrigen->getParameter(Position::VIRTUAL_ENGINE_HOURS_KEY),
            'idMunicipalidad' => $idMunicipalidad,
            'ignition' => $others['ignition'] ?? 0,
            'imei' => $deviceDestino->imei,
            'latitud' => $posicionOrigen->latitude,
            'longitud' => $posicionOrigen->longitude,
            'motion' => $others['motion'] ?? 0,
            'placa' => $deviceDestino->plate_number,
            'totalDistancia' => $others['totaldistance'] ?? 0,
            'totalHorasMotor' => $others['enginehours'] ?? 0,
            'ubigeo' => $ubigeo,
            'valid' => $posicionOrigen->valid,
            'velocidad' => $posicionOrigen->speed,
            'other' => $others,
        ]);

        Log::info("Registro clonado en dataMininter: {$posicionOrigen->time} -> {$fechaDestino} - Placa destino: {$deviceDestino->plate_number} - Lat: {$posicionOrigen->latitude}, Lng: {$posicionOrigen->longitude}");

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
