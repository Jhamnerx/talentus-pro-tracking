<?php

namespace App\Services\Ai\Tools;

use Tobuli\Entities\Event;

class AlertasEventosTool extends AbstractTool
{
    const TYPES = [
        'overspeed'          => 'exceso de velocidad',
        'geofence_overspeed' => 'exceso de velocidad en geocerca',
        'zone_in'            => 'entrada a geocerca',
        'zone_out'           => 'salida de geocerca',
        'ignition_on'        => 'encendido',
        'ignition_off'       => 'apagado',
        'stop_duration'      => 'parada prolongada',
        'move_start'         => 'inicio de movimiento',
        'move_duration'      => 'movimiento prolongado',
        'idle_duration'      => 'ralenti prolongado',
        'offline_duration'   => 'sin conexion prolongada',
        'fuel_fill'          => 'carga de combustible',
        'fuel_theft'         => 'posible robo de combustible',
        'custom'             => 'evento personalizado (SOS, bateria, etc.)',
    ];

    public function name(): string
    {
        return 'alertas_eventos';
    }

    public function description(): string
    {
        return 'Eventos y alertas generados por las unidades en un periodo: conteo por tipo, ranking de '
            . 'unidades con mas eventos y los ultimos eventos con hora, mensaje y velocidad. '
            . 'Tipos: ' . implode(', ', array_map(function ($k, $v) {
                return "$k ($v)";
            }, array_keys(self::TYPES), self::TYPES)) . '.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['unidades' => $this->unitsProperty()] + $this->periodProperties() + [
                'tipo' => [
                    'type' => 'string',
                    'description' => 'Opcional. Filtra por tipo de evento (clave de la lista).',
                ],
                'texto' => [
                    'type' => 'string',
                    'description' => 'Opcional. Filtra por texto del mensaje del evento (ej. "SOS", "bateria").',
                ],
            ],
            'required' => ['desde', 'hasta'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        $type = $input['tipo'] ?? null;

        return implode(' · ', array_filter([
            'Eventos', $type ? (self::TYPES[$type] ?? $type) : null,
            $this->periodLabel($input), $this->unitsLabel($input),
        ]));
    }

    protected function run(array $input): array
    {
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod($input);

        $units = (array) ($input['unidades'] ?? ['todas']);
        $isAll = empty($units) || in_array(mb_strtolower($units[0] ?? ''), ['todas', 'todos', 'all']);
        $ids = $isAll ? null : $this->resolveDeviceIds($units);

        $base = function () use ($input, $fromUtc, $toUtc, $ids) {
            return Event::query()
                ->userAccessible($this->user)
                ->where('events.deleted', 0)
                ->whereBetween('events.time', [$fromUtc, $toUtc])
                ->when($ids !== null, function ($q) use ($ids) {
                    $q->whereIn('events.device_id', $ids);
                })
                ->when(!empty($input['tipo']), function ($q) use ($input) {
                    $q->where('events.type', $input['tipo']);
                })
                ->when(!empty($input['texto']), function ($q) use ($input) {
                    $q->where('events.message', 'like', '%' . $input['texto'] . '%');
                });
        };

        $byType = $base()
            ->selectRaw('events.type, events.message, COUNT(*) as total')
            ->groupBy('events.type', 'events.message')
            ->orderByDesc('total')
            ->limit(15)
            ->get();

        $total = $base()->count();

        $byDevice = $base()
            ->join('devices', 'devices.id', '=', 'events.device_id')
            ->selectRaw('devices.name, devices.plate_number, COUNT(*) as total')
            ->groupBy('devices.id', 'devices.name', 'devices.plate_number')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $latest = $base()
            ->with('device:id,name,plate_number')
            ->orderByDesc('events.time')
            ->limit(10)
            ->get();

        return [
            'periodo' => "$fromLocal a $toLocal",
            'total_eventos' => $total,
            'por_tipo' => $byType->map(function ($row) {
                return [
                    'tipo' => self::TYPES[$row->type] ?? $row->type,
                    'mensaje' => $row->getRawOriginal('message'),
                    'cantidad' => (int) $row->total,
                ];
            })->all(),
            'unidades_con_mas_eventos' => $byDevice->map(function ($row) {
                return [
                    'unidad' => $row->plate_number && stripos($row->name, $row->plate_number) === false
                        ? "{$row->name} ({$row->plate_number})" : $row->name,
                    'cantidad' => (int) $row->total,
                ];
            })->all(),
            'ultimos_eventos' => $latest->map(function ($event) {
                return array_filter([
                    'fecha' => $this->localTime($event->time),
                    'unidad' => $event->device ? $this->deviceLabel($event->device) : null,
                    'evento' => $event->getRawOriginal('message'),
                    'velocidad' => $event->speed ? $this->speed($event->speed) : null,
                    'coordenadas' => $this->coords($event->latitude, $event->longitude),
                ]);
            })->all(),
        ];
    }
}
