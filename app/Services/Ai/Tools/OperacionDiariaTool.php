<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\ToolException;
use Carbon\Carbon;
use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\DriveStop;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\EngineHours;
use Tobuli\History\Actions\FirstDrive;
use Tobuli\History\Actions\GroupDriveStop;
use Tobuli\History\Actions\LastDrive;
use Tobuli\History\Actions\Speed;

class OperacionDiariaTool extends HistoryTool
{
    public function name(): string
    {
        return 'operacion_diaria';
    }

    public function description(): string
    {
        return 'Como fue el dia de UNA unidad: hora de primer y ultimo movimiento, km, tiempo en movimiento, '
            . 'ralenti, velocidad maxima y la lista de paradas de 5 minutos o mas con hora y duracion.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unidad' => [
                    'type' => 'string',
                    'description' => 'Nombre, placa, IMEI o ID de la unidad.',
                ],
                'fecha' => [
                    'type' => 'string',
                    'description' => 'Dia a consultar en formato YYYY-MM-DD (hora local del usuario).',
                ],
            ],
            'required' => ['unidad', 'fecha'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return implode(' · ', array_filter([
            'Operacion diaria', $input['unidad'] ?? null,
            !empty($input['fecha']) ? $this->periodLabel(['desde' => $input['fecha'], 'hasta' => $input['fecha']]) : null,
        ]));
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds([$input['unidad']], false);

        if (count($ids) > 1) {
            throw new ToolException('Hay ' . count($ids) . ' unidades que coinciden con "' . $input['unidad']
                . '". Usa buscar_unidades y pregunta al usuario cual es.');
        }

        try {
            $day = Carbon::parse($input['fecha'])->format('Y-m-d');
        } catch (\Exception $e) {
            throw new ToolException('Fecha no valida. Usa YYYY-MM-DD.');
        }

        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod([
            'desde' => "$day 00:00",
            'hasta' => "$day 23:59:59",
        ]);

        $report = $this->makeReport($ids, $fromUtc, $toUtc, [
            DriveStop::class,
            Duration::class,
            Distance::class,
            Speed::class,
            EngineHours::class,
            FirstDrive::class,
            LastDrive::class,
            GroupDriveStop::class,
        ]);
        $report->setStopSeconds(300);

        $result = null;

        $report->each(function ($device, $data) use (&$result, $day) {
            if (!$data) {
                $result = [
                    'unidad' => $this->deviceLabel($device),
                    'fecha' => $day,
                    'resultado' => 'Sin datos GPS ese dia.',
                ];
                return;
            }

            $root = $data['root'];
            $first = $root->hasStat('first_drive') ? $root->getStat('first_drive')->get() : null;
            $last = $root->hasStat('last_drive') ? $root->getStat('last_drive')->get() : null;

            $stops = [];
            foreach ($data['groups']->all() as $group) {
                if ($group->getKey() !== 'stop') {
                    continue;
                }

                $duration = (int) $this->statValue($group, 'duration');
                if ($duration < 300) {
                    continue;
                }

                $start = $group->getStartPosition();
                $stops[] = array_filter([
                    'inicio' => $this->localTime($start->time ?? null),
                    'duracion' => $this->duration($duration),
                    'coordenadas' => $start ? $this->coords($start->latitude, $start->longitude) : null,
                ]);
            }

            $result = [
                'unidad' => $this->deviceLabel($device),
                'fecha' => $day,
                'primer_movimiento' => $first ? $this->localTime($first->time) : null,
                'ultimo_movimiento' => $last ? $this->localTime($last->time) : null,
            ] + $this->statsHuman($root, [
                'distance'       => 'distancia',
                'drive_duration' => 'tiempo_en_movimiento',
                'stop_duration'  => 'tiempo_detenido',
                'engine_idle'    => 'ralenti',
                'speed_max'      => 'velocidad_maxima',
            ]) + [
                'paradas_5min_o_mas' => count($stops),
                'paradas' => array_slice($stops, 0, 15),
            ];
        });

        return $result ?? ['resultado' => 'Sin datos.'];
    }
}
