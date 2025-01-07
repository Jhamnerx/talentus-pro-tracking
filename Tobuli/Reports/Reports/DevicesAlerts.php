<?php

namespace Tobuli\Reports\Reports;

use Tobuli\Reports\DeviceReport;
use Illuminate\Database\QueryException;
use Formatter;
use Illuminate\Support\Arr;

class DevicesAlerts extends DeviceReport
{
    const TYPE_ID = 92;

    protected $validation = ['devices' => 'same_protocol'];

    public function __construct()
    {
        parent::__construct();

        $this->formats[] = 'csv';
    }

    public function typeID()
    {
        return self::TYPE_ID;
    }

    public function title()
    {
        return 'Alerta de Dispositivos';
    }

    public function getInputParameters(): array
    {
        $inputParameters = [
            \Field::multiSelect('event_types', trans('validation.attributes.type'))
                ->setOptions(
                    [
                        // '252' => 'Alerta de Bateria',
                        '9' => 'Botón de Pánico',
                        '2' => 'Puerta Abierta',
                        '253' => 'Condiciones de Manejo',
                        '255' => 'Excesos de velocidad',
                        '247' => 'Choque',
                        '246' => 'Remolque',
                        '252' => 'Desconexión de Batería',
                        '249' => 'Detección de Jamming',
                    ]
                )
                ->setValidation('array')
        ];

        return $inputParameters;
    }

    protected function processPosition($position, &$parameters, $sensors)
    {
        foreach ($position->parameters as $key => $value) {
            if (empty($key))
                continue;

            if (in_array($key, $parameters))
                continue;

            $parameters[] = $key;
        }

        return [
            'server_time' => Formatter::time()->human($position->server_time),
            'time'       => Formatter::time()->human($position->time),
            'speed'      => Formatter::speed()->human($position->speed),
            'altitude'   => Formatter::altitude()->human($position->altitude),
            'latitude'   => $position->latitude,
            'longitude'  => $position->longitude,
            'location'   => $this->getLocation($position, $this->getAddress($position)),
            'parameters' => $position->parameters,
            'sensors'    => $sensors->mapWithKeys(function ($sensor) use ($position) {
                return [$sensor->id => $sensor->getValueFormated($position->other, false)];
            })
        ];
    }

    protected function generateDevice($device)
    {
        $parameters = [];
        $rows = [];
        $sensors = $device->sensors->filter(function ($sensor) {
            return $sensor['add_to_history'];
        });
        $eventTypes = Arr::get($this->parameters, 'event_types') ?? [9, 2, 253, 255, 247, 246, 252, 249];

        try {
            $device->positions()
                ->orderliness('asc')
                ->whereBetween('time', [$this->date_from, $this->date_to])
                ->chunk(
                    2000,
                    function ($positions) use (&$rows, &$parameters, $sensors, $eventTypes) {
                        foreach ($positions as $position) {
                            if (
                                empty($eventTypes) ||
                                (isset($position->parameters['event']) && in_array($position->parameters['event'], $eventTypes))
                            ) {
                                $rows[] = $this->processPosition($position, $parameters, $sensors);
                            }
                        }
                    }
                );
        } catch (QueryException $e) {
        }

        if (empty($rows)) {
            return [
                'meta'  => $this->getDeviceMeta($device),
                'error' => trans('front.nothing_found_request'),
                'parameters' => $parameters,
                'sensors'    => $sensors
            ];
        }

        return [
            'meta'       => $this->getDeviceMeta($device),
            'table'      => [
                'rows' => $rows,
            ],
            'parameters' => $parameters,
            'sensors'    => $sensors
        ];
    }
}
