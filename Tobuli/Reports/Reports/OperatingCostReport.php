<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Tobuli\Entities\DeviceExpense;
use Tobuli\Reports\DeviceReport;
use Tobuli\Services\DeviceMileageService;

/**
 * Costo de operación por vehículo y por kilómetro.
 *
 * Hay DOS costos y el reporte no los mezcla nunca, porque no son lo mismo:
 *
 *  · REAL — la suma de Gastos (device_expenses) del periodo: dinero que se
 *    gastó y está cargado con su fecha, tipo y proveedor. Es el bueno.
 *  · ESTIMADO — el `cost_per_km` del vehículo por los kilómetros del
 *    periodo: un supuesto que sirve para valorar un recorrido cuando
 *    todavía no hay gastos cargados.
 *
 * Cada uno tiene su columna y la de origen dice cuál se pudo calcular. Un
 * vehículo sin gastos cargados muestra un guion en el real, no un cero:
 * "no lo sé" y "gastó cero" son cosas distintas y confundirlas haría que
 * un vehículo sin datos parezca el más barato de la flota.
 */
class OperatingCostReport extends DeviceReport
{
    const TYPE_ID = 908;

    // No hay posiciones que direccionar ni geocercas que filtrar.
    protected $disableFields = ['geofences', 'speed_limit', 'stops', 'show_addresses', 'zones_instead'];

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
        return trans('front.operating_cost');
    }

    public function getInputParameters(): array
    {
        return [
            \Field::checkbox(
                'only_with_data',
                trans('front.cost_only_with_data'),
                true
            ),
        ];
    }

    protected function generateDevice($device)
    {
        $gastos = DeviceExpense::where('device_id', $device->id)
            ->userAccessible($this->user)
            ->whereBetween('date', [$this->date_from, $this->date_to])
            ->get(['type_id', 'quantity', 'unit_cost']);

        $real = 0.0;
        $porTipo = [];

        foreach ($gastos as $gasto) {
            $importe = (float) $gasto->quantity * (float) $gasto->unit_cost;
            $real += $importe;
            $porTipo[(int) $gasto->type_id] = ($porTipo[(int) $gasto->type_id] ?? 0) + $importe;
        }

        $km = (new DeviceMileageService())->forRange($device, $this->date_from, $this->date_to);

        $porKm = $device->cost_per_km !== null ? (float) $device->cost_per_km : null;
        $estimado = $porKm !== null && $km > 0 ? $porKm * $km : null;

        $tieneAlgo = $gastos->isNotEmpty() || $km > 0;

        if (! $tieneAlgo && ! empty($this->parameters['only_with_data'])) {
            return null;
        }

        // Solo se divide entre kilómetros si los hay: con el vehículo parado
        // el costo por kilómetro no existe, no es infinito ni cero.
        $realPorKm = ($gastos->isNotEmpty() && $km > 0) ? $real / $km : null;

        $fila = [
            'name'  => $device->name,
            'plate' => $device->plate_number,
            'distance' => Formatter::distance()->human($km),
            'km_raw'   => round($km, 2),

            'expenses_count' => $gastos->count(),
            'real'           => $gastos->isNotEmpty() ? round($real, 2) : null,
            'real_per_km'    => $realPorKm !== null ? round($realPorKm, 4) : null,

            'cost_per_km'    => $porKm,
            'estimated'      => $estimado !== null ? round($estimado, 2) : null,

            // Real menos estimado: positivo = costó más de lo previsto.
            'difference'     => ($gastos->isNotEmpty() && $estimado !== null)
                ? round($real - $estimado, 2)
                : null,

            'source' => $gastos->isNotEmpty()
                ? trans('front.cost_source_expenses')
                : ($estimado !== null ? trans('front.cost_source_estimate') : '—'),

            'by_type' => $porTipo,
        ];

        $this->acumular(trans('front.cost_real'), $gastos->isNotEmpty() ? $real : 0);
        $this->acumular(trans('front.cost_estimated'), $estimado ?? 0);
        $this->acumular(trans('global.distance'), $km);

        return [
            'meta'  => $this->getDeviceMeta($device),
            'table' => [
                'rows' => [$fila],
            ],
        ];
    }

    private function acumular(string $clave, float $valor): void
    {
        if (! isset($this->totals[$clave])) {
            $this->totals[$clave] = 0;
        }

        $this->totals[$clave] = round($this->totals[$clave] + $valor, 2);
    }

    protected function toCSVData($file)
    {
        fputcsv($file, [
            trans('validation.attributes.name'),
            trans('validation.attributes.plate_number'),
            trans('global.distance'),
            trans('front.cost_expenses_count'),
            trans('front.cost_real'),
            trans('front.cost_real_per_km'),
            trans('front.cost_per_km'),
            trans('front.cost_estimated'),
            trans('front.cost_difference'),
            trans('front.cost_source'),
        ]);

        foreach ($this->getItems() as $item) {
            // Los vehículos sin datos vienen como item de 'error', sin tabla
            foreach ($item['table']['rows'] ?? [] as $fila) {
                fputcsv($file, [
                    $fila['name'],
                    $fila['plate'],
                    $fila['km_raw'],
                    $fila['expenses_count'],
                    $fila['real'] ?? '',
                    $fila['real_per_km'] ?? '',
                    $fila['cost_per_km'] ?? '',
                    $fila['estimated'] ?? '',
                    $fila['difference'] ?? '',
                    $fila['source'],
                ]);
            }
        }
    }
}
