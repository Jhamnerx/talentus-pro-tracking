<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\ToolException;
use Formatter;
use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\DriveStop;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\EngineHours;
use Tobuli\History\Actions\OverspeedStatic;
use Tobuli\History\Actions\Speed;

class CompararPeriodosTool extends HistoryTool
{
    const METRICS = [
        'distancia'    => ['distance', 'km'],
        'excesos'      => ['overspeed_count', 'count'],
        'paradas'      => ['stop_count', 'count'],
        'horas_motor'  => ['engine_hours', 'seconds'],
        'ralenti'      => ['engine_idle', 'seconds'],
        'movimiento'   => ['drive_duration', 'seconds'],
    ];

    public function name(): string
    {
        return 'comparar_periodos';
    }

    public function description(): string
    {
        return 'Compara una metrica entre dos periodos (por ejemplo esta semana contra la anterior): total de '
            . 'la flota en cada periodo, variacion % y las unidades que mas cambiaron. Metricas: '
            . implode(', ', array_keys(self::METRICS)) . '. Para "excesos" indica el limite.';
    }

    public function schema(): array
    {
        $period = function ($label) {
            return [
                'type' => 'string',
                'description' => "$label en hora local del usuario, formato \"YYYY-MM-DD HH:MM\".",
            ];
        };

        return [
            'type' => 'object',
            'properties' => [
                'metrica' => ['type' => 'string', 'enum' => array_keys(self::METRICS)],
                'unidades' => $this->unitsProperty(),
                'desde_a' => $period('Inicio del periodo A (el mas reciente)'),
                'hasta_a' => $period('Fin del periodo A'),
                'desde_b' => $period('Inicio del periodo B (el de referencia)'),
                'hasta_b' => $period('Fin del periodo B'),
                'limite' => ['type' => 'number', 'description' => 'Limite de velocidad, solo para "excesos".'],
            ],
            'required' => ['metrica', 'desde_a', 'hasta_a', 'desde_b', 'hasta_b'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return implode(' · ', array_filter([
            'Comparacion de ' . ($input['metrica'] ?? ''),
            $this->periodLabel(['desde' => $input['desde_a'] ?? '', 'hasta' => $input['hasta_a'] ?? '']) . ' vs '
                . $this->periodLabel(['desde' => $input['desde_b'] ?? '', 'hasta' => $input['hasta_b'] ?? '']),
            $this->unitsLabel($input),
        ]));
    }

    protected function run(array $input): array
    {
        [$statKey, $kind] = self::METRICS[$input['metrica']];

        if ($input['metrica'] === 'excesos' && empty($input['limite'])) {
            throw new ToolException('Para comparar excesos indica el limite de velocidad.');
        }

        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);

        $a = $this->measure($ids, ['desde' => $input['desde_a'], 'hasta' => $input['hasta_a']], $statKey, $input);
        $b = $this->measure($ids, ['desde' => $input['desde_b'], 'hasta' => $input['hasta_b']], $statKey, $input);

        $changes = [];
        foreach (array_unique(array_merge(array_keys($a['units']), array_keys($b['units']))) as $unit) {
            $va = $a['units'][$unit] ?? 0;
            $vb = $b['units'][$unit] ?? 0;
            $changes[] = [
                'unidad' => $unit,
                'periodo_a' => $this->format($va, $kind),
                'periodo_b' => $this->format($vb, $kind),
                'variacion' => $this->variation($va, $vb),
                '_diff' => abs($va - $vb),
            ];
        }

        usort($changes, function ($x, $y) {
            return $y['_diff'] <=> $x['_diff'];
        });

        return [
            'metrica' => $input['metrica'],
            'periodo_a' => $a['label'],
            'periodo_b' => $b['label'],
            'total_a' => $this->format($a['total'], $kind),
            'total_b' => $this->format($b['total'], $kind),
            'variacion_total' => $this->variation($a['total'], $b['total']),
            'unidades_que_mas_cambiaron' => array_map(function ($c) {
                unset($c['_diff']);
                return $c;
            }, array_slice($changes, 0, 10)),
        ];
    }

    private function measure(array $ids, array $period, string $statKey, array $input): array
    {
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod($period);

        $actions = [Duration::class, Distance::class, Speed::class, DriveStop::class, EngineHours::class];
        if ($statKey === 'overspeed_count') {
            $actions[] = OverspeedStatic::class;
        }

        $report = $this->makeReport($ids, $fromUtc, $toUtc, $actions);
        if ($statKey === 'overspeed_count') {
            $report->setSpeedLimit(Formatter::speed()->reverse((float) $input['limite']));
        }

        $units = [];
        $report->each(function ($device, $data) use (&$units, $statKey) {
            if ($data) {
                $units[$this->deviceLabel($device)] = (float) $this->statValue($data['root'], $statKey);
            }
        });

        return ['label' => "$fromLocal a $toLocal", 'units' => $units, 'total' => array_sum($units)];
    }

    private function format($value, string $kind): string
    {
        switch ($kind) {
            case 'km':      return $this->distance($value);
            case 'seconds': return $this->duration($value);
            default:        return (string) (int) $value;
        }
    }

    private function variation($a, $b): string
    {
        if ($b == 0) {
            return $a == 0 ? '0%' : 'nuevo (antes 0)';
        }

        $pct = round(($a - $b) * 100 / $b);

        return ($pct > 0 ? '+' : '') . $pct . '%';
    }
}
