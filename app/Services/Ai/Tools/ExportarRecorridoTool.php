<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\ToolException;

/**
 * Agrega un boton que descarga el recorrido de una unidad con la exportacion
 * de historial que ya tiene la plataforma (KML, GPX, CSV).
 */
class ExportarRecorridoTool extends AbstractTool
{
    const FORMATS = ['kml', 'gpx', 'csv'];

    public function name(): string
    {
        return 'exportar_recorrido';
    }

    public function description(): string
    {
        return 'Agrega un boton para descargar el recorrido (historial de posiciones) de UNA unidad en un '
            . 'periodo, en KML (Google Earth), GPX o CSV.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unidad' => ['type' => 'string', 'description' => 'Nombre, placa, IMEI o ID.'],
            ] + $this->periodProperties() + [
                'formato' => ['type' => 'string', 'enum' => self::FORMATS, 'description' => 'Por defecto kml.'],
            ],
            'required' => ['unidad', 'desde', 'hasta'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return implode(' · ', array_filter(['Exportar recorrido', $input['unidad'] ?? null, $this->periodLabel($input)]));
    }

    protected function run(array $input): array
    {
        if (!$this->user->perm('history', 'view')) {
            throw new ToolException('El usuario no tiene permiso para el historial.');
        }

        $ids = $this->resolveDeviceIds([$input['unidad']], false);
        if (count($ids) > 1) {
            throw new ToolException('Coinciden ' . count($ids) . ' unidades. Usa buscar_unidades y pregunta cual es.');
        }

        [$fromLocal, $toLocal] = $this->parsePeriod($input);
        $format = in_array($input['formato'] ?? 'kml', self::FORMATS) ? ($input['formato'] ?? 'kml') : 'kml';

        $this->actions[] = [
            'type' => 'history_export',
            'url' => route('history.export', [
                'device_id' => $ids[0],
                'from_date' => substr($fromLocal, 0, 10),
                'from_time' => substr($fromLocal, 11, 5),
                'to_date' => substr($toLocal, 0, 10),
                'to_time' => substr($toLocal, 11, 5),
                'format' => $format,
            ]),
            'label' => 'Descargar recorrido (' . strtoupper($format) . ')',
        ];

        return ['resultado' => 'Boton de descarga agregado.', 'formato' => $format, 'periodo' => "$fromLocal a $toLocal"];
    }
}
