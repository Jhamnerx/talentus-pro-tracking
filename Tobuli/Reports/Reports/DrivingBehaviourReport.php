<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Tobuli\Entities\Event;
use Tobuli\Reports\DeviceReport;
use Tobuli\Services\DeviceMileageService;
use Tobuli\Services\DrivingScoreService;

/**
 * Conducción: eventos de riesgo por cada 100 km.
 *
 * Todo el criterio (qué conductas se vigilan, cómo se reconocen y cuánto
 * pesan) vive en DrivingScoreService, que es el mismo que usa el ranking
 * del panel: así los dos dicen lo mismo del mismo vehículo.
 *
 * Cuenta por vehículo y no por conductor a propósito: en toda la base hay
 * 9 conductores registrados y 14 lecturas de RFID, así que un ranking por
 * conductor no tendría a quién rankear. El motor es el mismo el día que
 * haya adopción: solo cambia a quién se le atribuye el evento.
 */
class DrivingBehaviourReport extends DeviceReport
{
    const TYPE_ID = 907;

    // Este reporte no pinta posiciones ni direcciones: son opciones que solo
    // confundirían en el formulario.
    protected $disableFields = ['geofences', 'speed_limit', 'stops', 'show_addresses', 'zones_instead'];

    /** Atajo para la vista, que pinta una columna por conducta. */
    public const CONDUCTAS = DrivingScoreService::CONDUCTAS;

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
        return trans('front.driving_behaviour');
    }

    /** @return array<string,string> */
    public static function titulos(): array
    {
        return DrivingScoreService::titulos();
    }

    public function getInputParameters(): array
    {
        $parametros = [
            \Field::checkbox(
                'only_with_events',
                trans('front.driving_only_with_events'),
                true
            ),
        ];

        if ($this->user->isManager()) {
            $parametros[] = \Field::select('subusers', trans('front.subusers'), 0)
                ->setOptions([0 => trans('global.no'), 1 => trans('global.yes')])
                ->setValidation('in:0,1');
        }

        return $parametros;
    }

    protected function generateDevice($device)
    {
        // Mismo criterio de acceso que el reporte de Eventos: un evento no
        // es solo del dueño, también lo ve quien comparte la alerta.
        $eventos = Event::whereBetween('time', [$this->date_from, $this->date_to])
            ->where('device_id', $device->id)
            ->where(function ($query) {
                $subusuarios = $this->parameters['subusers'] ?? false;

                if ($subusuarios && $this->user->isManager()) {
                    $query->userControllable($this->user);
                } else {
                    $query->userAccessible($this->user);
                }
            })
            ->get(['type', 'message']);

        $conteo = DrivingScoreService::conteoVacio();

        foreach ($eventos as $evento) {
            $clave = DrivingScoreService::clasificar($evento->type, $evento->message);

            if ($clave !== null) {
                $conteo[$clave]++;
            }
        }

        $total = array_sum($conteo);

        if (! $total && ! empty($this->parameters['only_with_events'])) {
            return null;
        }

        // Kilometraje del MISMO periodo, con la fuente única del sistema
        $km = (new DeviceMileageService())->forRange($device, $this->date_from, $this->date_to);

        $ponderado = DrivingScoreService::ponderar($conteo);
        $indice = DrivingScoreService::indice($ponderado, $km);

        $fila = [
            'name'     => $device->name,
            'plate'    => $device->plate_number,
            'distance' => Formatter::distance()->human($km),
            'total'    => $total,
            'weighted' => $ponderado,
            // Sin kilometraje no se puede comparar: se dice, no se inventa un 0
            'index'    => $indice === null ? '—' : $indice,
        ];

        $titulos = DrivingScoreService::titulos();

        foreach ($conteo as $clave => $cantidad) {
            $fila[$clave] = $cantidad;

            if (empty($this->totals[$titulos[$clave]])) {
                $this->totals[$titulos[$clave]] = 0;
            }

            $this->totals[$titulos[$clave]] += $cantidad;
        }

        return [
            'meta'  => $this->getDeviceMeta($device),
            'table' => [
                'rows' => [$fila],
            ],
        ];
    }

    protected function toCSVData($file)
    {
        $cabecera = [
            trans('validation.attributes.name'),
            trans('validation.attributes.plate_number'),
            trans('global.distance'),
        ];

        foreach (DrivingScoreService::titulos() as $titulo) {
            $cabecera[] = $titulo;
        }

        $cabecera[] = trans('front.driving_index');

        fputcsv($file, $cabecera);

        foreach ($this->getItems() as $item) {
            // Los vehículos sin eventos vienen como item de 'error', sin tabla
            foreach ($item['table']['rows'] ?? [] as $fila) {
                $linea = [$fila['name'], $fila['plate'], $fila['distance']];

                foreach (array_keys(DrivingScoreService::CONDUCTAS) as $clave) {
                    $linea[] = $fila[$clave];
                }

                $linea[] = $fila['index'];

                fputcsv($file, $linea);
            }
        }
    }
}
