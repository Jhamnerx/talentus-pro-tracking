<?php

namespace App\Services\Ai\Tools;

use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\Fuel;
use Tobuli\History\Actions\GroupFuelFilling;
use Tobuli\History\Actions\GroupFuelThefting;

class CombustibleTool extends HistoryTool
{
    public function name(): string
    {
        return 'combustible';
    }

    public function description(): string
    {
        return 'Combustible de las unidades en un periodo (mismos calculos que los reportes de cargas y '
            . 'robos de combustible): consumo, km recorridos, cargas detectadas y posibles robos con hora y '
            . 'litros. Solo hay datos si la unidad tiene sensor de combustible.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['unidades' => $this->unitsProperty()] + $this->periodProperties(),
            'required' => ['desde', 'hasta'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return implode(' · ', array_filter(['Combustible', $this->periodLabel($input), $this->unitsLabel($input)]));
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod($input);

        $report = $this->makeReport($ids, $fromUtc, $toUtc, [
            Distance::class,
            Fuel::class,
            GroupFuelFilling::class,
            GroupFuelThefting::class,
        ]);

        $units = [];
        $events = [];
        $withoutSensor = 0;

        $report->each(function ($device, $data) use (&$units, &$events, &$withoutSensor, $report) {
            if (!$device->sensors->whereIn('type', ['fuel_tank', 'fuel_tank_calibration', 'fuel_consumption'])->count()) {
                $withoutSensor++;
                return;
            }

            if (!$data) {
                return;
            }

            $fillings = 0;
            $thefts = 0;

            foreach ($data['groups']->all() as $group) {
                $key = $group->getKey();
                if (!in_array($key, ['fuel_filling', 'fuel_theft'])) {
                    continue;
                }

                $key === 'fuel_filling' ? $fillings++ : $thefts++;

                $row = $report->groupData($group, ['fuel_level_previous', 'fuel_level_current', 'fuel_level_difference', 'sensor_name']);
                $events[] = array_filter([
                    'tipo' => $key === 'fuel_filling' ? 'carga' : 'posible robo',
                    'unidad' => $this->deviceLabel($device),
                    'fecha' => $this->localTime($group->getStartPosition()->time ?? null),
                    'nivel_antes' => $row['fuel_level_previous'] ?? null,
                    'nivel_despues' => $row['fuel_level_current'] ?? null,
                    'diferencia' => $row['fuel_level_difference'] ?? null,
                    'sensor' => $row['sensor_name'] ?? null,
                ]);
            }

            $units[] = ['unidad' => $this->deviceLabel($device)] + $this->statsHuman($data['root'], [
                'distance' => 'distancia',
                'fuel_consumption' => 'consumo',
            ]) + ['cargas' => $fillings, 'posibles_robos' => $thefts];
        });

        usort($events, function ($a, $b) {
            return strcmp($b['fecha'] ?? '', $a['fecha'] ?? '');
        });

        return [
            'periodo' => "$fromLocal a $toLocal",
            'unidades_consultadas' => count($ids),
            'unidades_sin_sensor_de_combustible' => $withoutSensor,
            'por_unidad' => array_slice($units, 0, 20),
            'total_cargas' => array_sum(array_column($units, 'cargas')),
            'total_posibles_robos' => array_sum(array_column($units, 'posibles_robos')),
            'eventos' => array_slice($events, 0, 15),
        ];
    }
}
