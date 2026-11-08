<?php

namespace Tobuli\Reports\Reports;

use Tobuli\Helpers\Teltonika\TeltonikaEvents;
use Tobuli\Reports\DeviceReport;
use Illuminate\Database\QueryException;
use Formatter;
use Illuminate\Support\Arr;

/**
 * Eventos de IO de Teltonika sacados del historial de posiciones.
 *
 * Filtra por el parámetro `event` (AVL ID que disparó el registro) y traduce
 * el evento y su valor con TeltonikaEvents, que sabe dónde deja cada IO el
 * decoder de nuestro fork de Traccar.
 */
class DevicesAlerts extends DeviceReport
{
    const TYPE_ID = 902;

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
        return trans('front.teltonika_devices_alerts');
    }

    public function getInputParameters(): array
    {
        return [
            \Field::multiSelect('event_types', trans('validation.attributes.type'))
                ->setOptions(TeltonikaEvents::options())
                ->setValidation('array'),
        ];
    }

    protected function processPosition($position, int $event): array
    {
        $parameters = $position->parameters;

        return [
            'server_time' => Formatter::time()->human($position->server_time),
            'time'        => Formatter::time()->human($position->time),
            'speed'       => Formatter::speed()->human($position->speed),
            'altitude'    => Formatter::altitude()->human($position->altitude),
            'latitude'    => $position->latitude,
            'longitude'   => $position->longitude,
            'location'    => $this->getLocation($position, $this->getAddress($position)),
            'event'       => TeltonikaEvents::label($event),
            'detail'      => TeltonikaEvents::detail($event, $parameters),
        ];
    }

    protected function generateDevice($device)
    {
        $rows = [];
        $eventTypes = array_map('intval', Arr::get($this->parameters, 'event_types') ?: TeltonikaEvents::EVENTS);

        try {
            $device->positions()
                ->orderliness('asc')
                ->whereBetween('time', [$this->date_from, $this->date_to])
                ->chunk(2000, function ($positions) use (&$rows, $eventTypes) {
                    foreach ($positions as $position) {
                        $event = $position->parameters['event'] ?? null;

                        if ($event === null || $event === '' || ! in_array((int) $event, $eventTypes, true)) {
                            continue;
                        }

                        $rows[] = $this->processPosition($position, (int) $event);
                    }
                });
        } catch (QueryException $e) {
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

    protected function toCSVData($file)
    {
        $header = null;

        foreach ($this->getItems() as $item) {
            if (empty($item['table']['rows'])) {
                continue;
            }

            if ($header === null) {
                $header = array_merge(Arr::pluck($item['meta'], 'title'), [
                    trans('front.server_time'),
                    trans('front.time'),
                    trans('front.latitude'),
                    trans('front.longitude'),
                    trans('front.address'),
                    trans('front.altitude'),
                    trans('front.speed'),
                    trans('front.teltonika_event'),
                    trans('front.teltonika_event_detail'),
                ]);

                fputcsv($file, $header);
            }

            $metas = Arr::pluck($item['meta'], 'value');

            foreach ($item['table']['rows'] as $row) {
                fputcsv($file, array_merge($metas, [
                    $row['server_time'],
                    $row['time'],
                    $row['latitude'],
                    $row['longitude'],
                    strip_tags($row['location']),
                    $row['altitude'],
                    $row['speed'],
                    $row['event'],
                    $row['detail'],
                ]));
            }
        }
    }
}
