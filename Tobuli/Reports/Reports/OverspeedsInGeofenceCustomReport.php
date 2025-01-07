<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Tobuli\History\Group;
use Tobuli\Entities\Geofence;
use Illuminate\Database\QueryException;
use Tobuli\Reports\DeviceReport;

class OverspeedsInGeofenceCustomReport extends DeviceReport
{
    const TYPE_ID = 94;

    protected $disableFields = ['stops', 'geofences'];

    public function typeID()
    {
        return self::TYPE_ID;
    }

    public function title()
    {
        return trans('front.overspeeds') . ' / ' . trans('front.geofences') . ' - Talentus';
    }

    protected function isEmptyResult($data)
    {
        return empty($data['groups']) || empty($data['groups']->all());
    }

    protected function processPosition($position)
    {
        $speedLimitExceeded = false;
        $speedDifference = 0;

        // Obtenemos todas las geocercas activas del usuario
        $geofences = Geofence::where('user_id', $this->user->id)->where('active', 1)->get();

        // Recorremos las geocercas para verificar si la posición está dentro de alguna
        foreach ($geofences as $geofence) {

            if (is_null($geofence->speed_limit)) {
                continue; // Si no hay límite de velocidad, saltar al siguiente
            }

            $point = [
                'latitude' => $position->latitude,
                'longitude' => $position->longitude
            ];

            // Verificamos si la posición está dentro de la geocerca
            if ($geofence->pointIn($point)) {
                // Si la geocerca tiene un límite de velocidad definido
                if (!is_null($geofence->speed_limit)) {

                    if ($position->speed > $geofence->speed_limit) {
                        // Si la velocidad de la posición es mayor al límite de velocidad de la geocerca
                        $speedDifference = $position->speed - $geofence->speed_limit;
                        $speedLimitExceeded = true;

                        return [
                            'server_time' => Formatter::time()->human($position->server_time),
                            'time'        => Formatter::time()->human($position->time),
                            'speed'       => Formatter::speed()->human($position->speed),
                            'altitude'    => Formatter::altitude()->human($position->altitude),
                            'latitude'    => $position->latitude,
                            'longitude'   => $position->longitude,
                            'location'    => $this->getLocation($position, $this->getAddress($position)),
                            'geofence_name' => $geofence->name,
                            'speed_limit' => $geofence->speed_limit,
                            'speed_difference' => $speedDifference,
                            'violation_level' => $this->getInfringementLevel($speedDifference),
                            'speed_limit_exceeded' => $speedLimitExceeded
                        ];
                    } else {
                        return [
                            'server_time' => Formatter::time()->human($position->server_time),
                            'time'        => Formatter::time()->human($position->time),
                            'speed'       => Formatter::speed()->human($position->speed),
                            'altitude'    => Formatter::altitude()->human($position->altitude),
                            'latitude'    => $position->latitude,
                            'longitude'   => $position->longitude,
                            'location'    => $this->getLocation($position, $this->getAddress($position)),
                            'geofence_name' => $geofence->name,
                            'speed_limit' => $geofence->speed_limit,
                            'speed_difference' => '',
                            'violation_level' => '',
                            'speed_limit_exceeded' => false

                        ];
                    }
                }
            }
        }

        // Si no se excede el límite de velocidad, devolvemos null
        return null;
    }

    protected function getInfringementLevel($speedDifference)
    {
        if ($speedDifference <= 10) {
            return 'Moderado';
        } elseif ($speedDifference <= 20) {
            return 'Grave';
        } else {
            return 'Muy Grave';
        }
    }

    protected function generateDevice($device)
    {
        $rows = [];
        try {
            // Obtenemos las posiciones del dispositivo
            $device->positions()
                ->orderliness('asc')
                ->whereBetween('time', [$this->date_from, $this->date_to])
                ->where('speed', '>', 0)
                ->chunk(
                    2000,
                    function ($positions) use (&$rows) {
                        // Recorremos cada posición
                        foreach ($positions as $position) {
                            // Procesamos la posición y solo agregamos al array si no es null
                            $processed = $this->processPosition($position);
                            if ($processed !== null) {
                                $rows[] = $processed;
                            }
                        }
                    }
                );
        } catch (QueryException $e) {
            // Manejo de errores si es necesario
            return null;
        }

        if (empty($rows)) {
            return [
                'meta'  => $this->getDeviceMeta($device),
                'error' => trans('front.nothing_found_request'),
            ];
        }

        return [
            'meta'  => $this->getDeviceMeta($device),
            'table' => [
                'rows' => $rows,
            ],
        ];
    }

    protected function getTotals(Group $group, array $only = [])
    {
        return parent::getTotals($group, ['overspeed_count']);
    }
}
