<?php

namespace App\Services\Ai\Tools;

use Tobuli\Helpers\GeoLocation\GeoLocation;

class UbicacionActualTool extends DeviceStateTool
{
    public function name(): string
    {
        return 'ubicacion_actual';
    }

    public function description(): string
    {
        return 'Ubicacion actual de una o varias unidades (maximo 5): direccion, coordenadas, velocidad, '
            . 'estado y hora del ultimo dato. No necesita periodo.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unidades' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Nombres, placas, IMEI o IDs (maximo 5).',
                ],
            ],
            'required' => ['unidades'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return 'Ubicacion actual · ' . $this->unitsLabel($input);
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds($input['unidades'], false);

        if (count($ids) > 5) {
            throw new \App\Services\Ai\ToolException('Coinciden ' . count($ids)
                . ' unidades. Pide al usuario que indique cual (maximo 5), o usa buscar_unidades.');
        }

        $geo = new GeoLocation();

        return [
            'unidades' => $this->stateQuery($ids)->get()->map(function ($device) use ($geo) {
                $lat = $device->lat;
                $lng = $device->lng;
                $address = null;

                if ($lat || $lng) {
                    try {
                        $address = $geo->resolveAddress($lat, $lng);
                    } catch (\Exception $e) {
                        $address = null;
                    }
                }

                return array_filter([
                    'unidad' => $this->deviceLabel($device),
                    'estado' => self::STATES[$this->stateKey($device)],
                    'direccion' => $address ? html_entity_decode(strip_tags($address)) : null,
                    'coordenadas' => $this->coords($lat, $lng),
                    'mapa' => ($lat || $lng) ? "https://maps.google.com/?q=$lat,$lng" : null,
                    'velocidad' => $this->speed($device->getSpeed()),
                    'ultimo_dato' => $this->lastConnection($device),
                ], function ($v) {
                    return $v !== null && $v !== '';
                });
            })->values()->all(),
        ];
    }
}
