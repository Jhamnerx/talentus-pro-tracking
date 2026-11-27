<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Tobuli\Entities\RouteCheckpoint;
use Tobuli\Reports\DeviceReport;
use Tobuli\Services\RouteAdherenceService;

/**
 * Cumplimiento de las programaciones de ruta.
 *
 * Una fila por punto de control, agrupadas por vuelta. El calculo entero vive
 * en RouteAdherenceService, que es tambien el que responde a la UI v2: el
 * reporte no puede decir "cumplida" de una vuelta que la pantalla pinta como
 * incompleta.
 */
class RouteAdherenceReport extends DeviceReport
{
    const TYPE_ID = 909;

    // Ni geocercas seleccionables ni paradas: los puntos de control ya son
    // geocercas y las elige la ruta, no el formulario.
    protected $disableFields = ['geofences', 'speed_limit', 'stops', 'zones_instead'];

    /** @var Collection */
    protected $schedules;

    /** @var RouteAdherenceService */
    protected $adherence;

    public function __construct()
    {
        parent::__construct();

        $this->formats[] = 'csv';

        $this->adherence = new RouteAdherenceService();
    }

    public function typeID()
    {
        return self::TYPE_ID;
    }

    public function title()
    {
        return trans('front.route_adherence');
    }

    public function getInputParameters(): array
    {
        $opciones = $this->adherence->schedules($this->user)
            ->pluck('name', 'id')
            ->all();

        return [
            \Field::multiSelect('route_schedules', trans('front.route_schedules'))
                ->setOptions($opciones),

            \Field::checkbox('only_breaches', trans('front.route_only_breaches'), false),
        ];
    }

    protected function beforeGenerate()
    {
        parent::beforeGenerate();

        $ids = array_filter(array_map('intval', (array) Arr::get($this->parameters, 'route_schedules', [])));

        $this->schedules = $this->adherence->schedules($this->user, $ids);
    }

    protected function generateDevice($device)
    {
        // Solo las programaciones que tienen asignado ESTE vehiculo: medir una
        // vuelta a quien no la tiene asignada seria inventar un incumplimiento.
        $suyas = $this->schedules->filter(function ($schedule) use ($device) {
            return $schedule->devices->contains('id', $device->id);
        });

        if ($suyas->isEmpty()) {
            return null;
        }

        $soloIncidencias = ! empty($this->parameters['only_breaches']);

        $vueltas = [];
        $filas   = [];

        foreach ($this->adherence->forDevice($device, $this->date_from, $this->date_to, $suyas) as $vuelta) {
            if ($soloIncidencias && $vuelta['status'] === RouteAdherenceService::ROUND_COMPLETED) {
                continue;
            }

            $vueltas[] = $this->plana($vuelta);

            foreach ($vuelta['checkpoints'] as $punto) {
                $filas[] = ['device' => $device->name] + $this->fila($vuelta, $punto);
            }

            $titulo = RouteAdherenceService::estadosVuelta()[$vuelta['status']];

            if (empty($this->totals[$titulo])) {
                $this->totals[$titulo] = 0;
            }

            $this->totals[$titulo]++;
        }

        if (empty($vueltas)) {
            return [
                'meta'  => $this->getDeviceMeta($device),
                'error' => trans('front.nothing_found_request'),
            ];
        }

        return [
            'meta'   => $this->getDeviceMeta($device),
            'rounds' => $vueltas,
            'table'  => [
                'rows' => $filas,
            ],
        ];
    }

    /**
     * Pasa la vuelta a escalares. Las entidades no pueden viajar en el item:
     * el formato JSON las serializaria enteras, con datos que nadie pidio.
     */
    private function plana(array $vuelta): array
    {
        $puntos = [];

        foreach ($vuelta['checkpoints'] as $punto) {
            $puntos[] = [
                'label'     => $punto['label'],
                'planned'   => Formatter::time()->human($punto['planned_utc']),
                'actual'    => $punto['actual_utc'] ? Formatter::time()->human($punto['actual_utc']) : null,
                'deviation' => $punto['deviation'],
                'status'    => $punto['status'],
            ];
        }

        return [
            'date'      => Formatter::date()->human($vuelta['start_utc']),
            'schedule'  => $vuelta['schedule']->name,
            'route'     => $vuelta['route']->name ?? '',
            'direction' => RouteCheckpoint::directions()[$vuelta['direction']] ?? $vuelta['direction'],
            'start'     => Formatter::time()->human($vuelta['start_utc']),
            'status'    => $vuelta['status'],
            'summary'   => $vuelta['summary'],
            'points'    => $puntos,
        ];
    }

    private function fila(array $vuelta, array $punto): array
    {
        return [
            'date'         => Formatter::date()->human($vuelta['start_utc']),
            'schedule'     => $vuelta['schedule']->name,
            'route'        => $vuelta['route']->name ?? '',
            'direction'    => RouteCheckpoint::directions()[$vuelta['direction']] ?? $vuelta['direction'],
            'start'        => Formatter::time()->human($vuelta['start_utc']),
            'round_status' => RouteAdherenceService::estadosVuelta()[$vuelta['status']],
            'checkpoint'   => $punto['label'],
            'planned'      => Formatter::time()->human($punto['planned_utc']),
            'actual'       => $punto['actual_utc'] ? Formatter::time()->human($punto['actual_utc']) : '—',
            // El signo importa: +12 es tarde, -7 es adelantado
            'deviation'    => $punto['deviation'] === null
                ? '—'
                : ($punto['deviation'] > 0 ? '+' . $punto['deviation'] : (string) $punto['deviation']),
            'cp_status'    => RouteAdherenceService::estadosPunto()[$punto['status']],
        ];
    }

    protected function toCSVData($file)
    {
        fputcsv($file, [
            trans('front.object'),
            trans('global.date'),
            trans('front.route_schedule'),
            trans('front.route'),
            trans('front.route_direction'),
            trans('front.route_round'),
            trans('front.route_round') . ' - ' . trans('front.status'),
            trans('front.route_checkpoint'),
            trans('front.route_planned_time'),
            trans('front.route_actual_time'),
            trans('front.route_deviation'),
            trans('front.route_checkpoint') . ' - ' . trans('front.status'),
        ]);

        foreach ($this->getItems() as $item) {
            // Los vehiculos sin vueltas vienen como item de 'error', sin tabla
            foreach ($item['table']['rows'] ?? [] as $fila) {
                fputcsv($file, [
                    $fila['device'],
                    $fila['date'],
                    $fila['schedule'],
                    $fila['route'],
                    $fila['direction'],
                    $fila['start'],
                    $fila['round_status'],
                    $fila['checkpoint'],
                    $fila['planned'],
                    $fila['actual'],
                    $fila['deviation'],
                    $fila['cp_status'],
                ]);
            }
        }
    }
}
